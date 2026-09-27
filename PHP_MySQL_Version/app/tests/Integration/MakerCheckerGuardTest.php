<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AmendmentRepository;
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
 * approves it, EXCEPT a Super Admin or anyone holding manage_permissions
 * (owner decision: that tier can already grant itself any approval role
 * through the permission system, so enforcing separation on them isn't a
 * real control). Covers REV-05 (document self-review), EML-03 (email
 * self-approval), AMD-05 (amendment self MD-approval), plus REV-06
 * (inactive reviewer) and REV-07 (duplicate reviewer assignment stalls
 * the document).
 */
final class MakerCheckerGuardTest extends DbTestCase
{
    private function makeSuperAdmin(int $userId): void
    {
        \App\Config\Database::connection()
            ->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    // ---------------------------------------------------------------
    // MakerCheckerGuard itself
    // ---------------------------------------------------------------

    public function testSelfApprovalAllowedForSuperAdminButNotOrdinaryUser(): void
    {
        $ordinary = $this->createTestUser('Export Executive');
        self::assertFalse(MakerCheckerGuard::selfApprovalAllowed($ordinary));

        $this->makeSuperAdmin($ordinary);
        self::assertTrue(MakerCheckerGuard::selfApprovalAllowed($ordinary));
    }

    public function testSelfApprovalAllowedForManagePermissionsHolderWithoutSuperAdmin(): void
    {
        // Admin role holds every permission (including manage_permissions)
        // via seed.sql's wildcard grant, but createTestUser() never sets
        // is_super_admin — this exercises the "not Super Admin, but holds
        // manage_permissions" branch specifically.
        $adminRoleUser = $this->createTestUser('Admin');
        self::assertTrue(MakerCheckerGuard::selfApprovalAllowed($adminRoleUser));
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

    public function testSuperAdminGeneratorCanReviewTheirOwnDocument(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $generator = $this->createTestUser('Export Executive');
        $this->makeSuperAdmin($generator);
        $documentId = $this->createTestDocumentGeneratedBy($orderId, $generator);

        ReviewWorkflowService::assignReviewers($documentId, [$generator], 1);

        $reviews = DocumentReviewRepository::forDocument($documentId);
        self::assertCount(1, $reviews);
        self::assertSame($generator, (int) $reviews[0]['reviewer_id']);
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
        $this->expectExceptionMessage('a different privileged user must approve');
        EmailDispatchService::approveSend($emailLogId, $requester);
    }

    public function testSuperAdminCanApproveTheirOwnEmailSend(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Config\Database::connection()->prepare('UPDATE clients SET email = :e WHERE id = :id')
            ->execute(['e' => 'buyer@example.test', 'id' => $clientId]);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $this->createTestFile($orderId));
        $requester = $this->createTestUser('Export Executive');
        $this->makeSuperAdmin($requester);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, $requester);

        EmailDispatchService::approveSend($emailLogId, $requester);

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

    public function testSuperAdminCanMdApproveTheirOwnAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $this->makeSuperAdmin($requester);
        $amendmentId = $this->createTestAmendment($orderId, $requester);

        AmendmentService::approveByMd($amendmentId, $requester);

        self::assertSame('md_approved', AmendmentRepository::find($amendmentId)['status']);
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
