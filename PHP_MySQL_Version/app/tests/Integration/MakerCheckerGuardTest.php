<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AmendmentRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\EmailLogRepository;
use App\Services\AmendmentService;
use App\Services\EmailDispatchService;
use App\Services\MakerCheckerGuard;
use App\Services\ReviewWorkflowService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (external QA report cross-verification): maker-checker separation
 * — the person who created/requested an item must not also be the one who
 * approves it, unless their role is ticked in Admin → Self-approval rules
 * (D-221; QA Round 8, R8-N09: no Super Admin or manage_permissions blanket
 * exemption; default MD and ED on every rule). Covers REV-05 (document self-review), EML-03 (email
 * self-approval), AMD-05 (amendment self MD-approval), plus REV-06
 * (inactive reviewer) and REV-07 (duplicate reviewer assignment stalls
 * the document).
 */
final class MakerCheckerGuardTest extends DbTestCase
{
    private ?string $originalMailSendingEnabled = null;

    // These approvals are asserted to stay 'approved' — the email-on
    // behaviour. With mail_sending_enabled off (the seed default since
    // 2026-10-25) an approved buyer-sendable document is marked 'sent'
    // instead; that path is covered by EmailOffAutoSentTest.
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMailSendingEnabled = CompanySettingsRepository::get('mail_sending_enabled');
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', $this->originalMailSendingEnabled, null);
        parent::tearDown();
    }

    private function makeSuperAdmin(int $userId): void
    {
        \App\Config\Database::connection()
            ->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    // ---------------------------------------------------------------
    // MakerCheckerGuard itself
    // ---------------------------------------------------------------

    /** QA Round 8, R8-N09: one choke point, seven rules, default MD and ED, no blanket exemption. */
    public function testEveryRuleDefaultsToMdAndEdWithNoBlanketExemption(): void
    {
        // D-240 (2 Nov 2026) added 'price_discount'.
        $rules = ['payment_terms', 'document_review', 'email_send', 'cancellation', 'purchase_award', 'amendment', 'short_payment', 'price_discount'];
        self::assertSame($rules, array_keys(\App\Services\SelfApprovalRules::RULES));
        $md = $this->createTestUser('Managing Director');
        $ed = $this->createTestUser('Executive Director');
        $admin = $this->createTestUser('Admin');
        $sysadmin = $this->createTestUser('System Administrator');
        $superExporter = $this->createTestUser('Export Executive');
        $this->makeSuperAdmin($superExporter);
        // The four rules added in QA Round 8 (the first three are covered by SelfApprovalRulesTest).
        foreach (array_slice($rules, 3) as $rule) {
            self::assertTrue(MakerCheckerGuard::selfApprovalAllowed($rule, $md), "MD: {$rule}");
            self::assertTrue(MakerCheckerGuard::selfApprovalAllowed($rule, $ed), "ED: {$rule}");
        }
        foreach ($rules as $rule) {
            self::assertFalse(MakerCheckerGuard::selfApprovalAllowed($rule, $admin), "Admin (holds manage_permissions): {$rule}");
            self::assertFalse(MakerCheckerGuard::selfApprovalAllowed($rule, $sysadmin), "System Administrator: {$rule}");
            self::assertFalse(MakerCheckerGuard::selfApprovalAllowed($rule, $superExporter), "Super Admin without a ticked role: {$rule}");
        }
        self::assertFalse(MakerCheckerGuard::selfApprovalAllowed('no_such_rule', $md));
    }

    // ---------------------------------------------------------------
    // REV-05 — document review self-approval
    // ---------------------------------------------------------------

    public function testCannotAssignTheDocumentsOwnGeneratorAsItsReviewer(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $generator = $this->createTestUser('Export Executive');
        $documentId = $this->createTestDocumentGeneratedBy($orderId, $generator);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("own generator cannot be assigned");
        ReviewWorkflowService::assignReviewers($documentId, [$generator], 1);
    }

    /** Owner decision 1 Nov 2026 (A-2, Q-NEW-3): no exception — not even a Super Admin or an Admin reviews their own document. */
    public function testNobodyReviewsTheirOwnDocumentNotEvenASuperAdminOrAdmin(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        foreach (['super admin' => true, 'admin' => false] as $who => $super) {
            $generator = $this->createTestUser('Admin');
            if ($super) {
                $this->makeSuperAdmin($generator);
            }
            $documentId = $this->createTestDocumentGeneratedBy($orderId, $generator);
            try {
                ReviewWorkflowService::assignReviewers($documentId, [$generator], 1);
                self::fail("{$who} was allowed to review their own document");
            } catch (\RuntimeException $e) {
                self::assertSame("This document's own generator cannot be assigned as its reviewer — a different person must review it.", $e->getMessage());
            }
            self::assertCount(0, DocumentReviewRepository::forDocument($documentId));
        }
    }

    // ---------------------------------------------------------------
    // REV-06 — inactive reviewer
    // ---------------------------------------------------------------

    public function testInactiveUserCannotBeAssignedAsReviewer(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT');
        $inactive = $this->createTestUser('Export Executive');
        \App\Repositories\UserRepository::setActive($inactive, false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an active user');
        ReviewWorkflowService::assignReviewers($documentId, [$inactive], 1);
    }

    // ---------------------------------------------------------------
    // REV-07 — duplicate reviewer assignment
    // ---------------------------------------------------------------

    public function testAssigningTheSameReviewerTwiceDoesNotCreateASecondRow(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT');
        $reviewer = $this->createTestUser('Export Executive');

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);

        self::assertCount(1, DocumentReviewRepository::forDocument($documentId));
    }

    public function testDocumentStillReachesApprovedAfterADuplicateAssignmentAttempt(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT');
        $reviewer = $this->createTestUser('Export Executive');
        // document_types.min_reviewers_default is a single shared row other
        // Integration test classes in this same PHPUnit process also
        // mutate (e.g. AmendmentDocumentEmailGatesTest) — pin it back to 1
        // so this test's single-reviewer approval is deterministic
        // regardless of run order.
        \App\Config\Database::connection()
            ->prepare("UPDATE document_types SET min_reviewers_default = 1 WHERE code = 'QT'")
            ->execute();

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1); // duplicate, silently ignored

        $review = DocumentReviewRepository::forDocument($documentId)[0];
        ReviewWorkflowService::approve((int) $review['id'], $reviewer, null);

        self::assertSame('approved', \App\Repositories\DocumentRepository::find($documentId)['status']);
    }

    // ---------------------------------------------------------------
    // EML-03 — email send self-approval
    // ---------------------------------------------------------------

    public function testRequesterCannotApproveTheirOwnEmailSend(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Config\Database::connection()->prepare('UPDATE clients SET email = :e WHERE id = :id')
            ->execute(['e' => 'buyer@example.test', 'id' => $clientId]);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $this->createTestFile($orderId));
        $requester = $this->createTestUser('Export Executive');
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, $requester);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You requested this send — a different person must approve it.');
        EmailDispatchService::approveSend($emailLogId, $requester);
    }

    /** Owner decision 1 Nov 2026 (A-3, Q-MAIL-3): no exception — not even a Super Admin approves their own send. */
    public function testNotEvenASuperAdminApprovesTheirOwnEmailSend(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Config\Database::connection()->prepare('UPDATE clients SET email = :e WHERE id = :id')
            ->execute(['e' => 'buyer@example.test', 'id' => $clientId]);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $this->createTestFile($orderId));
        $requester = $this->createTestUser('Export Executive');
        $this->makeSuperAdmin($requester);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, $requester);

        try {
            EmailDispatchService::approveSend($emailLogId, $requester);
            self::fail('a Super Admin approved their own send');
        } catch (\RuntimeException $e) {
            self::assertSame('You requested this send — a different person must approve it.', $e->getMessage());
        }
        self::assertSame('pending_approval', EmailLogRepository::find($emailLogId)['status']);

        // Someone else approves it.
        EmailDispatchService::approveSend($emailLogId, $this->createTestUser('Admin'));
        self::assertSame('approved', EmailLogRepository::find($emailLogId)['status']);
    }

    // ---------------------------------------------------------------
    // AMD-05 — amendment self MD-approval
    // ---------------------------------------------------------------

    private function createTestAmendment(int $orderId, ?int $createdBy): int
    {
        return AmendmentRepository::create(
            'AMD-TEST-' . bin2hex(random_bytes(4)),
            $orderId,
            'PHPUnit maker-checker amendment',
            'importer',
            ['note' => 'snapshot'],
            null, null, null, null, null, null, null,
            $createdBy
        );
    }

    public function testRequesterCannotMdApproveTheirOwnAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $amendmentId = $this->createTestAmendment($orderId, $requester);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a different privileged user must MD-approve');
        AmendmentService::approveByMd($amendmentId, $requester);
    }

    /** QA Round 8, R8-N09: an MD may MD-approve their own amendment (logged); a Super Admin without a ticked role may not. */
    public function testMdCanMdApproveTheirOwnAmendmentButASuperAdminExporterCannot(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $md = $this->createTestUser('Managing Director');
        $amendmentId = $this->createTestAmendment($orderId, $md);
        AmendmentService::approveByMd($amendmentId, $md);
        self::assertSame('md_approved', AmendmentRepository::find($amendmentId)['status']);
        self::assertSame(1, (int) \App\Config\Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AMENDMENT_SELF_APPROVED' AND entity_id = {$amendmentId}")->fetchColumn());

        $super = $this->createTestUser('Export Executive');
        $this->makeSuperAdmin($super);
        $other = $this->createTestAmendment($orderId, $super);
        try {
            AmendmentService::approveByMd($other, $super);
            self::fail('a Super Admin without a ticked role MD-approved their own amendment');
        } catch (\RuntimeException $e) {
            self::assertSame('You requested this amendment — a different privileged user must MD-approve it.', $e->getMessage());
        }
    }

    public function testAnUninvolvedUserCanStillMdApproveNormally(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Export Executive');
        $amendmentId = $this->createTestAmendment($orderId, $requester);

        AmendmentService::approveByMd($amendmentId, $md);

        self::assertSame('md_approved', AmendmentRepository::find($amendmentId)['status']);
    }

    private function createTestDocumentGeneratedBy(int $orderId, int $generatedBy): int
    {
        $pdo = \App\Config\Database::connection();
        $typeId = (int) $pdo->query("SELECT id FROM document_types WHERE code = 'QT'")->fetchColumn();
        $stmt = $pdo->prepare(
            "INSERT INTO documents (order_id, document_type_id, document_reference, status, generated_by)
             VALUES (:order_id, :type_id, :ref, 'draft', :generated_by)"
        );
        $stmt->execute([
            'order_id' => $orderId,
            'type_id' => $typeId,
            'ref' => 'PHPUNIT-DOC-' . bin2hex(random_bytes(4)),
            'generated_by' => $generatedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
