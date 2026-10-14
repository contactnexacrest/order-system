<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\EmailDispatchController;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * The Email Send Approvals screen used to show only Order/To/Subject/
 * Status/Scheduled/Requested/Action — an approver had no way to see the
 * actual email body or the attached PDF before deciding to Approve or
 * Reject. email_log.body_snapshot and the document behind document_id
 * were always captured at request time (Level 1); this covers the
 * approvals screen now actually surfacing both, so "approve" is an
 * informed decision rather than a blind one.
 */
final class EmailApprovalPreviewTest extends DbTestCase
{
    private function setClientEmail(int $clientId): void
    {
        Database::connection()
            ->prepare('UPDATE clients SET email = :email, contact_person = :contact WHERE id = :id')
            ->execute(['email' => 'jane@buyer.example', 'contact' => 'Jane Buyer', 'id' => $clientId]);
    }

    public function testApprovalQueueShowsTheExactBodyAndAttachmentLink(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $pdfFileId = $this->createTestFile($orderId, $clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $pdfFileId);

        $requesterId = $this->createTestUser('Export Executive');
        EmailDispatchService::requestSend($orderId, $documentId, 'send_qt', null, $requesterId);

        $approverId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $approverId;

        $controller = new EmailDispatchController();
        ob_start();
        $controller->approvalQueue([]);
        $html = ob_get_clean();

        self::assertStringContainsString('Exact Preview', $html, 'the approver must see a preview, not just a subject line');
        // buildPreview() merges the send_qt template against this exact
        // order/document — this distinctive fragment of the merged body
        // proves body_snapshot is actually being rendered, not just a label.
        self::assertStringContainsString('Please find attached our Quotation', $html);
        self::assertStringContainsString("/documents/{$documentId}/download", $html, 'the attachment link must point at the exact PDF that will be sent');
        self::assertStringContainsString('View attachment', $html);
    }

    public function testApprovalQueueHidesAttachmentLinkWithoutDownloadPermission(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $pdfFileId = $this->createTestFile($orderId, $clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $pdfFileId);

        $requesterId = $this->createTestUser('Export Executive');
        EmailDispatchService::requestSend($orderId, $documentId, 'send_qt', null, $requesterId);

        // Viewer/Auditor holds neither approve_email_send nor download_pdf
        // per seed role_permissions — exercised here purely for the
        // repository/view layer (approve_email_send's own route gate is
        // covered elsewhere), to prove the link itself is permission-aware.
        $approverId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $approverId;

        $controller = new EmailDispatchController();
        ob_start();
        $controller->approvalQueue([]);
        $html = ob_get_clean();

        self::assertStringNotContainsString("/documents/{$documentId}/download", $html);
        self::assertStringContainsString("you don't have permission to open it", $html);
    }
}
