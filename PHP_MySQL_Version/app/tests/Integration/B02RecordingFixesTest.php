<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Controllers\CoordinationController;
use App\Controllers\DocumentController;
use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Helpers\Flash;
use App\Repositories\ReportRepository;
use App\Services\ActiveDepartmentService;
use App\Services\OrderAccessService;
use App\Services\PaymentTermsApprovalService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-269 (8 Nov 2026): five fixes found while recording the B02
 * tutorial. The Node suite has the same tests (b02RecordingFixes.test.js).
 *  1. Payment-terms approval: the wording names who can approve, from the
 *     "Approve payment terms" permission — not "MD/ED".
 *  2. Buyer portal: the Buyer PO "upload any time" intro is not shown when
 *     upload is closed on a cancelled or lost order.
 *  3. Staff Productivity: deactivated users are left out; the date range
 *     applies to both counts and the form says so.
 *  4. Management cockpit: every active department has a row, with zeros.
 *  5. Email Send Approvals: the reason boxes show the 10-character rule only
 *     as the validation error, not as a hint before anything is typed.
 */
final class B02RecordingFixesTest extends DbTestCase
{
    /** The seeded holders of approve_payment_terms, in role order. */
    private const SEEDED_APPROVERS = 'Admin, Managing Director, Executive Director, Sales/Export Manager, Sales Director, Finance Director';

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        Flash::pull();
        OrderAccessService::resetCache();
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        OrderAccessService::resetCache();
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        ActiveDepartmentService::resetCache();
        OrderAccessService::resetCache();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    // ---- 1. Payment-terms approval wording ---------------------------------------------------

    public function testTheApproverRolesComeFromThePermission(): void
    {
        self::assertSame(self::SEEDED_APPROVERS, implode(', ', PaymentTermsApprovalService::approverRoleNames()));
        self::assertSame(
            'someone who can approve payment terms (' . self::SEEDED_APPROVERS . ')',
            PaymentTermsApprovalService::whoCanApprove()
        );
    }

    public function testTheOrderPageNamesWhoCanApproveInsteadOfMdEd(): void
    {
        $exportId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL', 'USD', null, null, $exportId);
        $this->signIn($exportId);
        $html = $this->capture(fn () => (new OrderController())->show(['id' => (string) $orderId]));

        self::assertStringNotContainsString('MD/ED approval', $html);
        self::assertStringContainsString('<strong>Payment terms need approval first</strong>', $html);
        self::assertStringContainsString(
            "The Quotation can't be generated or sent until they are approved. Waiting for approval by someone who can approve payment terms (" . self::SEEDED_APPROVERS . ').',
            $html
        );

        // An approver sees the button, not the "Waiting for" line.
        $this->signIn($this->createTestUser('Sales Director'));
        $html = $this->capture(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('Approve payment terms</button>', $html);
        self::assertStringNotContainsString('Waiting for approval by', $html);
    }

    public function testTheRefusalMessageNamesWhoCanApprove(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Established Buyer — Post-BL');
        $this->signIn($this->createTestUser('Export Executive'));
        $_POST = ['document_type' => 'QT'];
        (new DocumentController())->generate(['id' => (string) $orderId]);

        self::assertSame(
            'The payment terms on this order ("Established Buyer — Post-BL") need approval before the Quotation can be generated or sent — approve them in Step 1, or ask someone who can approve payment terms (' . self::SEEDED_APPROVERS . ').',
            Flash::pull()[0]['message']
        );
    }

    public function testARoleGivenThePermissionIsNamedAtOnce(): void
    {
        $pdo = Database::connection();
        $name = 'PHPUnit Approver ' . bin2hex(random_bytes(3));
        $pdo->prepare('INSERT INTO roles (name, description, is_system_role) VALUES (:n, NULL, 0)')->execute(['n' => $name]);
        $roleId = (int) $pdo->lastInsertId();
        try {
            self::assertNotContains($name, PaymentTermsApprovalService::approverRoleNames());
            $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, is_enabled)
                           SELECT :r, id, 1 FROM permissions WHERE permission_key = 'approve_payment_terms'")->execute(['r' => $roleId]);
            self::assertSame(self::SEEDED_APPROVERS . ', ' . $name, implode(', ', PaymentTermsApprovalService::approverRoleNames()));
            // A role with the permission switched off is not named.
            $pdo->prepare('UPDATE role_permissions SET is_enabled = 0 WHERE role_id = :r')->execute(['r' => $roleId]);
            self::assertNotContains($name, PaymentTermsApprovalService::approverRoleNames());
        } finally {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :r')->execute(['r' => $roleId]);
            $pdo->prepare('DELETE FROM roles WHERE id = :r')->execute(['r' => $roleId]);
        }
    }

    // ---- 2. Buyer portal: the Buyer PO intro -------------------------------------------------

    private const BUYER_PO_INTRO = "After you sign your purchase order, upload a scan of it here. You can upload a corrected copy later. We keep every copy you upload.";

    private function portalPage(string $status): string
    {
        $client = $this->createTestClient();
        $order = $this->createTestOrder($client);
        Database::connection()->prepare('UPDATE orders SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $order]);
        $_SESSION['_client_portal_client_id'] = $client;
        return $this->capture(fn () => (new ClientPortalController())->showOrder(['id' => $order]));
    }

    public function testTheBuyerPoIntroIsShownWhileUploadIsOpen(): void
    {
        $html = $this->portalPage('active');
        self::assertStringContainsString(self::BUYER_PO_INTRO, $html);
        self::assertStringContainsString('/buyer-po" enctype="multipart/form-data"', $html);
    }

    public function testTheBuyerPoIntroIsHiddenOnACancelledOrLostOrder(): void
    {
        foreach (['cancelled', 'lost'] as $status) {
            $html = $this->portalPage($status);
            self::assertStringNotContainsString('upload a scan of it here', $html, $status);
            // D-330: buyer words — never the raw status ("lost").
            $word = $status === 'lost' ? 'This order did not go ahead' : 'This order was cancelled';
            self::assertStringContainsString($word . ', so you can no longer upload a purchase order.', $html, $status);
        }
    }

    // ---- 3. Staff Productivity ---------------------------------------------------------------

    /** @return array{0:int,1:string} a user with one QT generated and two audit actions (June 2025, 15 Jan 2026) */
    private function staffUserWithActivity(bool $active): array
    {
        $pdo = Database::connection();
        $userId = $this->createTestUser('Export Executive');
        $name = 'PHPUnit Staff ' . bin2hex(random_bytes(4));
        $pdo->prepare('UPDATE users SET name = :n, is_active = :a WHERE id = :id')->execute(['n' => $name, 'a' => $active ? 1 : 0, 'id' => $userId]);
        $doc = $this->createTestDocument($this->createTestOrder($this->createTestClient()), 'QT', 'approved');
        $pdo->prepare("UPDATE documents SET generated_by = :u, generated_at = '2026-01-15 11:00:00' WHERE id = :id")->execute(['u' => $userId, 'id' => $doc]);
        $insert = $pdo->prepare("INSERT INTO audit_log (user_id, action_type, entity_type, entity_id, created_at) VALUES (:u, 'PHPUNIT_STAFF_REPORT', 'users', :u2, :at)");
        $insert->execute(['u' => $userId, 'u2' => $userId, 'at' => '2025-06-10 10:00:00']);
        $insert->execute(['u' => $userId, 'u2' => $userId, 'at' => '2026-01-15 10:00:00']);
        return [$userId, $name];
    }

    /** @return array<string,mixed>|null */
    private function staffRow(array $rows, int $userId): ?array
    {
        foreach ($rows as $r) {
            if ((int) $r['user_id'] === $userId) {
                return $r;
            }
        }
        return null;
    }

    public function testStaffProductivityLeavesOutDeactivatedUsers(): void
    {
        [$activeId] = $this->staffUserWithActivity(true);
        [$inactiveId, $inactiveName] = $this->staffUserWithActivity(false);

        $rows = ReportRepository::staffProductivity(null, null);
        self::assertNotNull($this->staffRow($rows, $activeId));
        self::assertNull($this->staffRow($rows, $inactiveId), 'a deactivated user is not listed');

        $this->signIn($this->createTestUser('Admin'));
        $html = $this->capture(fn () => (new ReportController())->staff([]));
        self::assertStringNotContainsString($inactiveName, $html);
    }

    public function testStaffProductivityCountsAuditActionsInTheDateRangeOnly(): void
    {
        [$userId] = $this->staffUserWithActivity(true);

        $all = $this->staffRow(ReportRepository::staffProductivity(null, null), $userId);
        self::assertSame(2, $all['audit_actions']);
        self::assertSame(1, $all['documents_total']);

        $jan = $this->staffRow(ReportRepository::staffProductivity('2026-01-01', '2026-01-31'), $userId);
        self::assertSame(1, $jan['audit_actions'], 'only the 15 Jan 2026 action');
        self::assertSame(1, $jan['documents_total']);

        $june = $this->staffRow(ReportRepository::staffProductivity('2025-06-01', '2025-06-30'), $userId);
        self::assertSame(1, $june['audit_actions'], 'only the June 2025 action');
        self::assertSame(0, $june['documents_total']);
    }

    public function testStaffProductivityFormSaysTheDatesApplyToBothCounts(): void
    {
        $this->signIn($this->createTestUser('Admin'));
        $html = $this->capture(fn () => (new ReportController())->staff([]));
        self::assertStringNotContainsString('(documents generated)', $html);
        self::assertStringContainsString('<label>Date From<input type="date" name="date_from"', $html);
        self::assertStringContainsString('<label>Date To<input type="date" name="date_to"', $html);
        self::assertStringContainsString('The dates apply to both counts. Deactivated users are not listed.', $html);
    }

    // ---- 4. Management cockpit ---------------------------------------------------------------

    public function testTheCockpitListsEveryActiveDepartmentWithZeros(): void
    {
        $pdo = Database::connection();
        $tag = strtoupper(bin2hex(random_bytes(3)));
        $insert = $pdo->prepare('INSERT INTO departments (code, name, color, sort_order, is_active) VALUES (:c, :n, :col, 999, :a)');
        $insert->execute(['c' => "Q{$tag}", 'n' => "Quiet Dept {$tag}", 'col' => '#123456', 'a' => 1]);
        $quiet = (int) $pdo->lastInsertId();
        $insert->execute(['c' => "X{$tag}", 'n' => "Closed Dept {$tag}", 'col' => '#654321', 'a' => 0]);
        $closed = (int) $pdo->lastInsertId();
        try {
            $this->signIn($this->createTestUser('Admin'));
            $html = $this->capture(fn () => (new CoordinationController())->cockpit([]));
            self::assertMatchesRegularExpression(
                '#<span class="dept-tag" style="--dept-color:\#123456">Quiet Dept ' . $tag . '</span></td>\s*<td>0</td>\s*<td>0</td>\s*<td>0</td>\s*<td>0</td>#',
                $html
            );
            self::assertStringNotContainsString("Closed Dept {$tag}", $html, 'an inactive department has no row');
        } finally {
            $pdo->prepare('DELETE FROM departments WHERE id IN (:a, :b)')->execute(['a' => $quiet, 'b' => $closed]);
        }
    }

    // ---- 5. Email Send Approvals: the reason boxes -------------------------------------------

    public function testTheApprovalReasonBoxesShowTheLengthRuleOnlyAsTheError(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../views/email/approvals.php');
        self::assertSame(2, substr_count($view, 'data-reason-hint="on-error"'), 'the Reject and Cancel reason boxes');
        self::assertStringContainsString('<input type="text" name="reason" placeholder="Cancel reason (mandatory)" required minlength="10" data-reason-hint="on-error"', $view);
        self::assertStringContainsString('<input type="text" name="reason" placeholder="Reason (mandatory)" required minlength="10" data-reason-hint="on-error"', $view);

        $js = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/js/app.js');
        self::assertStringContainsString("var hintUpFront = field.dataset.reasonHint !== 'on-error';", $js);
        self::assertStringContainsString("existingError.textContent = 'Reason must be at least ' + MIN_LENGTH + ' characters (' + val.length + ' so far).';", $js);
    }
}
