<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EmailLogRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;

/**
 * Spec Section 10 — EMAIL & DEFERRED SEND SYSTEM, 2-level approval.
 * "Client only receives watermarked PDF. Never DOCX. Never clean PDF." is
 * enforced structurally here: buildPreview()/requestSend() only ever look
 * at $document['pdf_file_id'] (which, once a document is 'approved', is
 * the finalized-watermark file per DocumentGenerationService::
 * finalizeApproval() — the draft-watermarked PDF's file_store row is a
 * different, earlier id, never referenced from here) and never touch
 * docx_file_id at all.
 */
final class EmailDispatchService
{
    /** @return array{subject:string, body:string, recipient_email:string, document:array, order:array} */
    public static function buildPreview(int $orderId, int $documentId, string $templateKey, int $senderUserId): array
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }
        $document = DocumentRepository::find($documentId);
        if (!$document || (int) $document['order_id'] !== $orderId) {
            throw new \RuntimeException("Document {$documentId} not found for this order");
        }
        if ($document['status'] !== 'approved' && $document['status'] !== 'sent') {
            throw new \RuntimeException('Only an approved document can be sent to the buyer — it must clear internal review first (Section 9).');
        }
        if (empty($order['client_email'])) {
            throw new \RuntimeException('This client has no email address on file.');
        }

        $template = EmailTemplateRepository::find($templateKey);
        if (!$template) {
            throw new \RuntimeException("Unknown email template: {$templateKey}");
        }

        $sender = UserRepository::findById($senderUserId);
        $tokens = self::tokensFor($order, $document, $sender);

        return [
            'subject'         => strtr($template['subject'], $tokens),
            'body'            => strtr($template['body'], $tokens) . "\n\n" . strtr((string) ($template['footer'] ?? ''), $tokens),
            'recipient_email' => $order['client_email'],
            'document'        => $document,
            'order'           => $order,
        ];
    }

    /** Level 1 — submits for Level 2 approval. Never sends directly, whatever the scheduled time. */
    public static function requestSend(
        int $orderId,
        int $documentId,
        string $templateKey,
        ?string $scheduledAt,
        int $requestedByUserId
    ): int {
        $preview = self::buildPreview($orderId, $documentId, $templateKey, $requestedByUserId);

        if (EmailLogRepository::hasActiveSendFor($documentId)) {
            throw new \RuntimeException('A send for this document is already awaiting approval or scheduled to go out — wait for it to send, be rejected, or fail before submitting another.');
        }

        $id = EmailLogRepository::create(
            $orderId,
            $documentId,
            $templateKey,
            $preview['recipient_email'],
            $preview['subject'],
            $preview['body'],
            $scheduledAt,
            $requestedByUserId
        );

        // Notify whoever can actually approve this (approve_email_send —
        // the same permission this exact approval route is gated on).
        // Checked by permission, not a hardcoded role name, since roles
        // can be renamed via /admin/roles.
        foreach (PermissionRepository::usersWithPermission('approve_email_send') as $userId) {
            NotificationRepository::create($userId, null, 'email_send_pending_approval', $orderId, "A send to {$preview['recipient_email']} is awaiting your approval.");
        }

        AuditLogRepository::log($requestedByUserId, 'EMAIL_SEND_REQUESTED', 'email_log', $id, 'recipient_email', null, $preview['recipient_email']);
        return $id;
    }

    public static function approveSend(int $emailLogId, int $approverUserId): void
    {
        $row = EmailLogRepository::find($emailLogId);
        if (!$row || $row['status'] !== 'pending_approval') {
            throw new \RuntimeException('This send is not awaiting approval.');
        }
        EmailLogRepository::approve($emailLogId, $approverUserId);
        AuditLogRepository::log($approverUserId, 'EMAIL_SEND_APPROVED', 'email_log', $emailLogId);
    }

    public static function rejectSend(int $emailLogId, int $approverUserId, string $reason): void
    {
        if ($error = ReasonValidator::check($reason)) {
            throw new \RuntimeException($error);
        }
        $row = EmailLogRepository::find($emailLogId);
        if (!$row || $row['status'] !== 'pending_approval') {
            throw new \RuntimeException('This send is not awaiting approval.');
        }
        EmailLogRepository::reject($emailLogId, $approverUserId, $reason);
        if ($row['requested_by'] !== null) {
            NotificationRepository::create((int) $row['requested_by'], null, 'email_send_rejected', (int) $row['order_id'], "Your send to {$row['recipient_email']} was rejected: {$reason}");
        }
        AuditLogRepository::log($approverUserId, 'EMAIL_SEND_REJECTED', 'email_log', $emailLogId, null, null, null, $reason);
    }

    /**
     * Pulls a send back before it goes out. Works on both 'pending_approval'
     * (nobody has reviewed it yet) and 'approved' (already cleared Level-2
     * but still waiting out its scheduled_at window) — that whole span is
     * what the spec's "deferred send" was for: giving staff a chance to
     * stop a mail they realize needs changes after clicking send, right up
     * until the cron job actually dispatches it. Once dispatch() has run
     * and marked it 'sent', nothing here can reach it any more — the mail
     * is already gone.
     *
     * Any Level-2 approver can cancel any row (mirrors their reject power).
     * Anyone else may only cancel a row they themselves requested.
     */
    public static function cancelSend(int $emailLogId, int $userId, string $reason, bool $isApprover): void
    {
        if ($error = ReasonValidator::check($reason)) {
            throw new \RuntimeException($error);
        }
        $row = EmailLogRepository::find($emailLogId);
        if (!$row || !in_array($row['status'], ['pending_approval', 'approved'], true)) {
            throw new \RuntimeException('This send can no longer be cancelled — it has already been sent, rejected, or cancelled.');
        }
        if (!$isApprover && (int) $row['requested_by'] !== $userId) {
            throw new \RuntimeException('You can only cancel a send you requested yourself.');
        }

        EmailLogRepository::cancel($emailLogId, $userId, $reason);

        if ($row['requested_by'] !== null && (int) $row['requested_by'] !== $userId) {
            NotificationRepository::create((int) $row['requested_by'], null, 'email_send_cancelled', (int) $row['order_id'], "Your send to {$row['recipient_email']} was cancelled: {$reason}");
        }
        AuditLogRepository::log($userId, 'EMAIL_SEND_CANCELLED', 'email_log', $emailLogId, null, null, null, $reason);
    }

    /**
     * The actual send — called only by app/cron/dispatch_deferred_emails.php,
     * never synchronously from a web request (see that script's docblock).
     * Returns true on send success.
     */
    public static function dispatch(array $emailLogRow): bool
    {
        if ($emailLogRow['document_id'] === null) {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
            return false;
        }
        $document = DocumentRepository::find((int) $emailLogRow['document_id']);
        if (!$document || !$document['pdf_file_id']) {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
            return false;
        }
        $file = FileStoreRepository::find((int) $document['pdf_file_id']);
        if (!$file || !is_file($file['server_path'])) {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
            return false;
        }

        $sent = EmailService::sendWithAttachment(
            $emailLogRow['recipient_email'],
            $emailLogRow['subject'],
            $emailLogRow['body_snapshot'],
            $file['server_path'],
            $file['original_filename']
        );

        if ($sent) {
            EmailLogRepository::markSent((int) $emailLogRow['id']);
            DocumentRepository::markSent((int) $document['id']);
            AuditLogRepository::log(null, 'EMAIL_SENT', 'email_log', (int) $emailLogRow['id'], 'recipient_email', null, $emailLogRow['recipient_email']);
        } else {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
        }

        return $sent;
    }

    /** @return array<string,string> */
    private static function tokensFor(array $order, array $document, ?array $sender): array
    {
        $orderId = (int) $order['id'];
        $qtDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'QT');
        $piDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI');

        return [
            '{buyer_contact_person}' => $order['contact_person'] ?: 'Sir/Madam',
            '{buyer_company_name}'   => $order['company_legal_name'],
            '{document_reference}'   => $document['document_reference'] ?? '—',
            '{generated_date}'       => (new \DateTimeImmutable((string) $document['generated_at']))->format('d F Y'),
            '{order_reference}'      => $order['order_reference'],
            '{buyer_inquiry_ref}'    => $order['buyer_inquiry_ref'],
            '{quotation_ref}'        => $qtDoc['document_reference'] ?? '—',
            '{quotation_valid_until}' => $order['quotation_valid_until'] ? (new \DateTimeImmutable($order['quotation_valid_until']))->format('d F Y') : '—',
            '{pi_ref}'               => $piDoc['document_reference'] ?? '—',
            '{pi_valid_until}'       => $order['pi_valid_until'] ? (new \DateTimeImmutable($order['pi_valid_until']))->format('d F Y') : '—',
            '{company_name}'         => (string) CompanySettingsRepository::get('legal_name'),
            '{company_email}'        => (string) CompanySettingsRepository::get('email'),
            '{company_phone}'        => (string) CompanySettingsRepository::get('phone'),
            '{sender_name}'          => $sender['name'] ?? (string) CompanySettingsRepository::get('md_name'),
            '{sender_title}'         => (string) CompanySettingsRepository::get('md_title'),
        ];
    }
}
