<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\SelfApprovalController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\EmailLogRepository;
use App\Services\EmailDispatchService;
use App\Services\MakerCheckerGuard;
use App\Services\ReviewWorkflowService;
use App\Services\SelfApprovalRules;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 1 Nov 2026 (D-221): Admin → Self-approval rules. Per rule
 * (payment terms of an order you created, a document you generated, an
 * e-mail send you requested) the roles that may self-approve; defaults MD
 * and ED; Super Admins only change it; every change and every
 * self-approval is in the audit log. The Node suite has the same test
 * (selfApprovalRules.test.js).
 */
final class SelfApprovalRulesTest extends DbTestCase
{
    private ?string $mail = null;
    /** @var array<string, list<int>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = CompanySettingsRepository::get('mail_sending_enabled');
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        foreach (array_keys(SelfApprovalRules::RULES) as $rule) {
            $this->saved[$rule] = SelfApprovalRules::roleIds($rule);
        }
        Database::connection()->exec("UPDATE document_types SET min_reviewers_default = 1 WHERE code = 'QT'");
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $rule => $ids) {
            SelfApprovalRules::set($rule, $ids);
        }
        CompanySettingsRepository::set('mail_sending_enabled', $this->mail, null);
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function role(string $name): int
    {
        return (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    private function audits(string $action, int $entityId): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = '{$action}' AND entity_id = {$entityId}")->fetchColumn();
    }

    private function documentBy(int $userId): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient());
        $typeId = (int) $pdo->query("SELECT id FROM document_types WHERE code = 'QT'")->fetchColumn();
        $pdo->prepare("INSERT INTO documents (order_id, document_type_id, document_reference, status, generated_by) VALUES (:o, :t, :r, 'draft', :g)")
            ->execute(['o' => $orderId, 't' => $typeId, 'r' => 'PHPUNIT-SA-' . bin2hex(random_bytes(4)), 'g' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    private function sendRequestedBy(int $userId): int
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $this->createTestFile($orderId));
        return EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, $userId);
    }

    public function testTheDefaultsAreTheMdAndTheEdOnAllThreeRules(): void
    {
        foreach (array_keys(SelfApprovalRules::RULES) as $rule) {
            self::assertSame(['Executive Director', 'Managing Director'], (static function (array $n): array { sort($n); return $n; })(SelfApprovalRules::roleNames($rule)), $rule);
        }
    }

    public function testTheMdAndTheEdReviewTheirOwnDocumentAndItIsLogged(): void
    {
        foreach (['Managing Director', 'Executive Director'] as $role) {
            $me = $this->createTestUser($role);
            $doc = $this->documentBy($me);
            ReviewWorkflowService::assignReviewers($doc, [$me], $me);
            $review = DocumentReviewRepository::forDocument($doc)[0];
            ReviewWorkflowService::approve((int) $review['id'], $me, null);
            self::assertSame(1, $this->audits('DOCUMENT_SELF_REVIEWED', $doc), $role);
        }
    }

    public function testEveryoneElseIsStillRefusedWithTheSameMessage(): void
    {
        foreach (['Admin', 'Export Executive', 'Finance Director'] as $role) {
            $me = $this->createTestUser($role);
            try {
                ReviewWorkflowService::assignReviewers($this->documentBy($me), [$me], $me);
                self::fail("{$role} reviewed their own document");
            } catch (\RuntimeException $e) {
                self::assertSame("This document's own generator cannot be assigned as its reviewer — a different person must review it.", $e->getMessage());
            }
            try {
                EmailDispatchService::approveSend($this->sendRequestedBy($me), $me);
                self::fail("{$role} approved their own send");
            } catch (\RuntimeException $e) {
                self::assertSame('You requested this send — a different person must approve it.', $e->getMessage());
            }
            self::assertFalse(MakerCheckerGuard::paymentTermsSelfApprovalAllowed($me), $role);
        }
    }

    public function testTheMdApprovesTheirOwnSendAndItIsLogged(): void
    {
        $md = $this->createTestUser('Managing Director');
        $send = $this->sendRequestedBy($md);
        EmailDispatchService::approveSend($send, $md);
        self::assertSame('approved', EmailLogRepository::find($send)['status']);
        self::assertSame(1, $this->audits('EMAIL_SEND_SELF_APPROVED', $send));
        self::assertTrue(MakerCheckerGuard::paymentTermsSelfApprovalAllowed($md));
    }

    public function testUntickingTheEdRefusesTheEdAndTheChangeIsLogged(): void
    {
        $super = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$super}");
        $_SESSION = ['_auth_user_id' => $super];
        $md = (string) $this->role('Managing Director');
        // QA Round 8: the page holds seven rules; the other four are posted as they are (MD and ED), so only these three change.
        $both = [$md, (string) $this->role('Executive Director')];
        $others = ['cancellation' => $both, 'purchase_award' => $both, 'amendment' => $both, 'short_payment' => $both, 'price_discount' => $both];
        $_POST = ['payment_terms' => [$md], 'document_review' => [$md], 'email_send' => [$md]] + $others;
        (new SelfApprovalController())->save([]);
        self::assertSame('Self-approval rules saved. They apply from the next approval.', Flash::pull()[0]['message']);
        $log = Database::connection()->query("SELECT old_value, new_value FROM audit_log WHERE action_type = 'SELF_APPROVAL_RULE_CHANGED' AND field_name = 'document_review' ORDER BY id DESC LIMIT 1")->fetch();
        $old = explode(', ', (string) $log['old_value']);
        sort($old);
        self::assertSame([['Executive Director', 'Managing Director'], 'Managing Director'], [$old, (string) $log['new_value']], 'old and new role lists are logged');

        $ed = $this->createTestUser('Executive Director');
        try {
            ReviewWorkflowService::assignReviewers($this->documentBy($ed), [$ed], $ed);
            self::fail('the ED reviewed their own document after being unticked');
        } catch (\RuntimeException $e) {
            self::assertSame("This document's own generator cannot be assigned as its reviewer — a different person must review it.", $e->getMessage());
        }
        self::assertFalse(MakerCheckerGuard::paymentTermsSelfApprovalAllowed($ed));

        $_POST = ['payment_terms' => [$md], 'document_review' => [$md], 'email_send' => [$md]] + $others;
        (new SelfApprovalController())->save([]);
        self::assertSame('Nothing changed.', Flash::pull()[0]['message']);
    }

    public function testAnAssignedSelfReviewIsRefusedOnceTheRuleIsWithdrawn(): void
    {
        $md = $this->createTestUser('Managing Director');
        $doc = $this->documentBy($md);
        ReviewWorkflowService::assignReviewers($doc, [$md], $md);
        SelfApprovalRules::set('document_review', []);
        $review = DocumentReviewRepository::forDocument($doc)[0];
        $this->expectExceptionMessage("This document's own generator cannot be assigned as its reviewer — a different person must review it.");
        ReviewWorkflowService::approve((int) $review['id'], $md, null);
    }
}
