<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\PurchasingController;
use App\Helpers\Flash;
use App\Repositories\AmendmentRepository;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PurchasingRepository;
use App\Repositories\UserRepository;
use App\Services\AmendmentService;
use App\Services\PartPaymentService;
use App\Services\PurchasingService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 8, R8-N09: the four actions that until now had their own
 * hard-coded self-approval exemption — order cancellation, own purchase
 * award, amendment MD-approval and short-payment clearing — follow Admin →
 * Self-approval rules like the other three (default Managing Director and
 * Executive Director). Per action: an MD may (and it is audited), an Admin
 * and a System Administrator may not (even holding the action's own
 * permission), and a role may not once it is unticked (a role of the test's
 * own is ticked and unticked, so the shared MD/ED rules are never changed). The Node
 * suite has the same test (selfApprovalActions.test.js).
 */
final class SelfApprovalActionsTest extends DbTestCase
{
    private int $md;
    private int $admin;
    private int $sysadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->md = $this->createTestUser('Managing Director');
        $this->admin = $this->createTestUser('Admin');
        $this->sysadmin = $this->createTestUser('System Administrator');
        // The System Administrator is given each action's own permission, so only the self-approval rule can refuse them.
        foreach (['edit_locked_data', 'approve_purchases', 'approve_short_payment'] as $key) {
            Database::connection()->prepare(
                "INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) SELECT :u, id, 1, 'PHPUnit' FROM permissions WHERE permission_key = :k"
            )->execute(['u' => $this->sysadmin, 'k' => $key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->customRoles as $roleId) {
            Database::connection()->exec("DELETE FROM self_approval_rules WHERE role_id = {$roleId}");
        }
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function roleId(string $name): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM roles WHERE name = :n');
        $stmt->execute(['n' => $name]);
        return (int) $stmt->fetchColumn();
    }

    private function as(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
    }

    /** @var list<int> */
    private array $customRoles = [];

    /**
     * A user whose own (new) role is ticked for the rule, holding the action's permissions.
     * Only that role's row is added and later removed, so the shared MD/ED rules are never
     * touched (other test files run against the same rules).
     *
     * @return array{0:int,1:int} user id, role id
     */
    private function tickedRoleUser(string $rule): array
    {
        $roleId = \App\Repositories\RoleRepository::create('Self Test ' . bin2hex(random_bytes(4)), 'PHPUnit self-approval role');
        $this->customRoles[] = $roleId;
        Database::connection()->prepare('INSERT INTO self_approval_rules (rule_key, role_id) VALUES (:k, :r)')->execute(['k' => $rule, 'r' => $roleId]);
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled) VALUES ('PHPUnit Test User', :e, NULL, 'x', :r, 1, 0, 0)")
            ->execute(['e' => 'phpunit-self-' . bin2hex(random_bytes(4)) . '@nexacrest.test', 'r' => $roleId]);
        $userId = (int) $pdo->lastInsertId();
        foreach (['edit_locked_data', 'approve_purchases', 'approve_short_payment'] as $key) {
            $pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) SELECT :u, id, 1, 'PHPUnit' FROM permissions WHERE permission_key = :k")->execute(['u' => $userId, 'k' => $key]);
        }
        return [$userId, $roleId];
    }

    /** The role is taken off the rule again. */
    private function untick(string $rule, int $roleId): void
    {
        Database::connection()->prepare('DELETE FROM self_approval_rules WHERE rule_key = :k AND role_id = :r')->execute(['k' => $rule, 'r' => $roleId]);
    }

    private function auditCount(string $action, int $entityId): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = '{$action}' AND entity_id = {$entityId}")->fetchColumn();
    }

    // ---- order cancellation ----

    private function cancel(string $action, int $userId): array
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer withdrew the order in writing.', $userId);
        $this->as($userId);
        $_POST = ['refund_status' => 'not_applicable', 'decision_notes' => 'Buyer confirmed by e-mail.'];
        ob_start();
        (new OrderController())->{$action}(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();
        $_POST = [];
        $all = Flash::pull();
        return [$orderId, $cancellationId, end($all)['message'] ?? ''];
    }

    public function testOrderCancellationSelfApprovalFollowsTheRule(): void
    {
        [$orderId, $id] = $this->cancel('approveCancellation', $this->md);
        self::assertSame('cancelled', OrderRepository::find($orderId)['status'], 'MD approves their own request');
        self::assertSame(1, $this->auditCount('ORDER_CANCELLATION_SELF_APPROVED', $id));
        [$orderId, $id] = $this->cancel('rejectCancellation', $this->md);
        self::assertSame('rejected', OrderCancellationRepository::find($id)['status'], 'MD rejects their own request');
        self::assertSame(1, $this->auditCount('ORDER_CANCELLATION_SELF_REJECTED', $id));

        foreach (['Admin' => $this->admin, 'System Administrator' => $this->sysadmin] as $who => $userId) {
            [$orderId, $id, $message] = $this->cancel('approveCancellation', $userId);
            self::assertSame('You requested this cancellation — a different privileged person must approve it.', $message, $who);
            self::assertSame('active', OrderRepository::find($orderId)['status'], $who);
            [, $id, $message] = $this->cancel('rejectCancellation', $userId);
            self::assertSame('You requested this cancellation — a different privileged person must decide on it.', $message, $who);
            self::assertSame('pending', OrderCancellationRepository::find($id)['status'], $who);
        }

        [$user, $role] = $this->tickedRoleUser('cancellation');
        [$orderId] = $this->cancel('approveCancellation', $user);
        self::assertSame('cancelled', OrderRepository::find($orderId)['status'], 'any role ticked on the page qualifies');
        $this->untick('cancellation', $role);
        [$orderId, , $message] = $this->cancel('approveCancellation', $user);
        self::assertSame('You requested this cancellation — a different privileged person must approve it.', $message, 'once unticked');
        self::assertSame('active', OrderRepository::find($orderId)['status']);
    }

    // ---- own purchase award ----

    private function purchase(string $action, array $body, array $params): array
    {
        $_POST = $body;
        (new PurchasingController())->{$action}($params);
        $_POST = [];
        $all = Flash::pull();
        return end($all) ?: ['type' => null, 'message' => ''];
    }

    public function testOwnPurchaseAwardSelfApprovalFollowsTheRule(): void
    {
        $pdo = Database::connection();
        foreach (['MD' => $this->md, 'Admin' => $this->admin, 'System Administrator' => $this->sysadmin] as $who => $userId) {
            $user = UserRepository::findById($userId);
            $refusal = PurchasingService::approvalRefusal($userId, (int) $user['role_id'], ['awarded_by' => $userId], 0.0);
            self::assertSame($who === 'MD' ? null : 'You awarded this purchase, so someone else must approve it.', $refusal, $who);
        }

        // Through the page: an award waiting for approval, made by the MD, approved by the MD.
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $pdo->prepare('INSERT INTO suppliers (supplier_legal_name, is_active) VALUES (:n, 1)')->execute(['n' => 'Quarry Self ' . bin2hex(random_bytes(3))]);
        $supplierId = (int) $pdo->lastInsertId();
        $this->as($this->admin);
        $this->purchase('raise', ['description' => 'Black granite slabs 2cm', 'quantity' => '120', 'unit' => 'sqm', 'needed_by' => date('Y-m-d', strtotime('+20 days'))], ['id' => $orderId]);
        $id = (int) $pdo->query("SELECT MAX(id) FROM purchase_requirements WHERE order_id = {$orderId}")->fetchColumn();
        $this->purchase('addQuote', ['supplier_id' => $supplierId, 'unit_price_inr' => '10', 'gst_percent' => '0', 'lead_time_days' => '14'], ['id' => $id]);
        $quote = PurchasingRepository::quotes($id)[0];
        $this->purchase('award', ['quote_id' => $quote['id']], ['id' => $id]);
        // Put it back to "waiting for approval", as if the MD had awarded it above a limit.
        $pdo->prepare("UPDATE purchase_requirements SET status = 'awarded', approved_by = NULL, approved_at = NULL, awarded_by = :u WHERE id = :id")->execute(['u' => $this->md, 'id' => $id]);

        [$user, $role] = $this->tickedRoleUser('purchase_award');
        self::assertNull(PurchasingService::approvalRefusal($user, $role, ['awarded_by' => $user], 0.0), 'any role ticked on the page qualifies');
        $this->untick('purchase_award', $role);
        self::assertSame('You awarded this purchase, so someone else must approve it.', PurchasingService::approvalRefusal($user, $role, ['awarded_by' => $user], 0.0), 'once unticked');
        $this->as($this->md);
        self::assertStringStartsWith('Purchase PR-' . $id . ' approved', $this->purchase('approve', [], ['id' => $id])['message']);
        self::assertSame(1, $this->auditCount('PURCHASE_AWARD_SELF_APPROVED', $id));
    }

    // ---- amendment MD-approval ----

    private function amendment(int $createdBy): int
    {
        return AmendmentRepository::create('AMD-SELF-' . bin2hex(random_bytes(4)), $this->createTestOrder($this->createTestClient()),
            'PHPUnit self-approval amendment', 'importer', ['note' => 'snapshot'], null, null, null, null, null, null, null, $createdBy);
    }

    public function testAmendmentSelfApprovalFollowsTheRule(): void
    {
        $id = $this->amendment($this->md);
        AmendmentService::approveByMd($id, $this->md);
        self::assertSame('md_approved', AmendmentRepository::find($id)['status']);
        self::assertSame(1, $this->auditCount('AMENDMENT_SELF_APPROVED', $id));

        [$user, $role] = $this->tickedRoleUser('amendment');
        $id = $this->amendment($user);
        AmendmentService::approveByMd($id, $user);
        self::assertSame('md_approved', AmendmentRepository::find($id)['status'], 'any role ticked on the page qualifies');
        $this->untick('amendment', $role);
        foreach (['Admin' => $this->admin, 'System Administrator' => $this->sysadmin, 'once unticked' => $user] as $who => $userId) {
            $id = $this->amendment($userId);
            try {
                AmendmentService::approveByMd($id, $userId);
                self::fail("{$who} MD-approved their own amendment");
            } catch (\RuntimeException $e) {
                self::assertSame('You requested this amendment — a different privileged user must MD-approve it.', $e->getMessage(), $who);
            }
            self::assertSame('pending', AmendmentRepository::find($id)['status'], $who);
        }
    }

    // ---- short-payment clearing ----

    /** A USD order with its advance (USD 1,800) due and USD 1,500 received, recorded by $recordedBy. */
    private function shortAdvance(int $recordedBy): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE orders SET advance_pct = 40 WHERE id = :o')->execute(['o' => $orderId]);
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        $this->createTestDocument($orderId, 'PI', 'approved');
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        PartPaymentService::addLegReceipt($orderId, 'advance', 1500.0, '2026-10-20', 84.0, 'UTR' . random_int(10000, 99999), $recordedBy);
        return $orderId;
    }

    public function testShortPaymentClearingByWhoeverRecordedTheReceiptsFollowsTheRule(): void
    {
        $refused = 'You recorded this payment\'s receipts, so a different manager with "Approve short payment" must clear it short. It now waits in Approvals for them.';
        foreach (['Admin' => $this->admin, 'System Administrator' => $this->sysadmin] as $who => $userId) {
            $orderId = $this->shortAdvance($userId);
            $check = PartPaymentService::clearCheck($orderId, 'advance', null, UserRepository::findById($userId), 'Buyer confirmed the rest comes with the balance.');
            self::assertSame($refused, $check['error'] ?? null, $who);
            $other = PartPaymentService::clearCheck($orderId, 'advance', null, UserRepository::findById($this->md), 'Buyer confirmed the rest comes with the balance.');
            self::assertArrayHasKey('short', $other, 'a different manager may clear it');
        }

        [$user, $role] = $this->tickedRoleUser('short_payment');
        $orderId = $this->shortAdvance($user);
        self::assertArrayHasKey('short', PartPaymentService::clearCheck($orderId, 'advance', null, UserRepository::findById($user), 'Buyer confirmed the rest comes with the balance.'), 'any role ticked on the page qualifies');
        $this->untick('short_payment', $role);
        self::assertSame($refused, PartPaymentService::clearCheck($orderId, 'advance', null, UserRepository::findById($user), 'Buyer confirmed the rest comes with the balance.')['error'] ?? null, 'once unticked');

        $orderId = $this->shortAdvance($this->md);

        $this->as($this->md);
        $_POST = ['advance_cleared_at' => '2026-10-21', 'short_reason' => 'Buyer confirmed the rest comes with the balance.'];
        ob_start();
        (new OrderController())->clearAdvancePayment(['id' => (string) $orderId]);
        ob_end_clean();
        $_POST = [];
        $flash = Flash::pull();
        self::assertStringContainsString('Short by USD 300.00: approved by PHPUnit Test User — carried into the balance.', end($flash)['message'] ?? '');
        self::assertSame(1, $this->auditCount('SHORT_PAYMENT_SELF_APPROVED', $orderId));
    }
}
