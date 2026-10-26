<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EmailLogRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\SignatoryRepository;
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
    /**
     * $documentId is nullable so a generic, non-document template (e.g. a
     * payment reminder — docs/schema.sql Section AI's email template CRUD)
     * can be previewed/composed against just the order. A template tied to
     * an actual document send still requires it to be approved/sent first.
     *
     * @return array{subject:string, body:string, recipient_email:string, document:?array, order:array}
     */
    /** Owner request (2026-10-27): appended to the PI, Debit Note and CI emails. */
    public const PAYMENT_LINK_PARAGRAPH = "PAYMENT DETAILS: once you have completed the payment, please upload the payment screenshot together with the transaction details (Transaction ID / UTR, amount and date) on this secure link — no login needed:\n{payment_action_link}\nOur team will check them against our bank statement and confirm.";

    public static function buildPreview(int $orderId, ?int $documentId, string $templateKey, int $senderUserId): array
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }
        // The order's client_email is a snapshot frozen at order creation
        // (only re-synced by applyClientSnapshot() when a PI intake is
        // applied) — an email added to the client record afterwards never
        // reached it, so sends failed with "no email address on file" even
        // though the client now has one. Fall back to the live record.
        if (empty($order['client_email'])) {
            $order['client_email'] = ClientRepository::find((int) $order['client_id'])['email'] ?? null;
        }

        $document = null;
        if ($documentId !== null) {
            $document = DocumentRepository::find($documentId);
            if (!$document || (int) $document['order_id'] !== $orderId) {
                throw new \RuntimeException("Document {$documentId} not found for this order");
            }
            if ($document['status'] !== 'approved' && $document['status'] !== 'sent') {
                throw new \RuntimeException('Only an approved document can be sent to the buyer — it must clear internal review first (Section 9).');
            }
            // QA-5 L-40: document_types.never_shown_to_buyer (Business
            // Rule #20) is a hard, server-side gate — the order page only
            // ever offers "Send to Buyer" for category='customer_facing'
            // documents, but that's a UI convenience, not a security
            // boundary; the send route itself must refuse any type this
            // flag covers (Supplier PO with its INR unit prices, BL
            // Instruction Sheet, COO Prep, internal checklists, Amendment
            // drafts, ...) even hit directly by a typed URL.
            if (!empty($document['document_type_never_shown_to_buyer'])) {
                throw new \RuntimeException("{$document['document_type_name']} is an internal document and can never be sent to the buyer.");
            }
            // External QA report L-41: any active template could be picked
            // for any document — a Supplier PO going out under the subject
            // "Quotation SC/SPO/…" — because nothing tied templateKey to
            // the document actually being sent. Every buyer-facing document
            // type has exactly one seeded template, send_<lowercase code>
            // (docs/schema.sql Section AU's own convention, also what
            // EmailDispatchController::renderCompose() guesses from), so a
            // document-bound send must use exactly that one.
            $expectedTemplateKey = 'send_' . strtolower((string) $document['document_type_code']);
            if ($templateKey !== $expectedTemplateKey) {
                throw new \RuntimeException("Wrong template for this document: a {$document['document_type_name']} must be sent with the \"{$expectedTemplateKey}\" template, not \"{$templateKey}\".");
            }
        }
        if (empty($order['client_email'])) {
            throw new \RuntimeException('This client has no email address on file.');
        }
        // External QA report N-06: bl_copy_sent could be requested and
        // approved with no scanned BL on file — dispatchGeneric() then
        // failed silently at send time (status 'failed', nobody told).
        // Refuse up front instead, with a clear message, at the same
        // point L-42 added this template's own document-less branch.
        if ($templateKey === 'bl_copy_sent') {
            $shipping = \App\Repositories\OrderShippingRepository::find($orderId);
            if (!$shipping || !$shipping['bl_copy_file_id']) {
                throw new \RuntimeException('Upload the scanned Bill of Lading copy for this order before requesting this email.');
            }
        }

        $template = EmailTemplateRepository::find($templateKey);
        if (!$template || !$template['is_active']) {
            throw new \RuntimeException("Unknown or inactive email template: {$templateKey}");
        }

        $sender = UserRepository::findById($senderUserId);
        $tokens = self::tokensFor($order, $document, $sender);

        // Owner request (2026-10-27): the PI, Debit Note and CI emails tell
        // the client where to send their payment details — the order's
        // Payment Action Link. Added unless an admin-edited template
        // already places {payment_action_link} itself.
        $body = $template['body'];
        if ($document !== null && in_array($document['document_type_code'], ['PI', 'FDN', 'CI'], true)) {
            $tokens['{payment_action_link}'] = rtrim(\App\Config\Env::get('APP_URL', ''), '/') . '/payment-action/' . OrderRepository::ensurePaymentActionToken($orderId);
            if (!str_contains($body, '{payment_action_link}')) {
                $body = rtrim($body) . "\n\n" . self::PAYMENT_LINK_PARAGRAPH;
            }
        }

        return [
            'subject'         => strtr($template['subject'], $tokens),
            'body'            => strtr($body, $tokens) . "\n\n" . strtr((string) ($template['footer'] ?? ''), $tokens),
            'recipient_email' => $order['client_email'],
            'document'        => $document,
            'order'           => $order,
        ];
    }

    /**
     * Level 1 — submits for Level 2 approval. Never sends directly, whatever
     * the scheduled time — UNLESS company_settings.mail_approval_queue_enabled
     * is off (docs/schema.sql Section AU), in which case there is no Level 2
     * to wait for: the row is still created (so the email log stays a
     * complete record either way), immediately marked approved by the
     * system, and dispatched synchronously in this same request instead of
     * waiting for the cron's next tick.
     *
     * @return array{id:int, dispatched:bool, sent:?bool} dispatched is true
     *   when the queue was bypassed (sent tells you whether that immediate
     *   attempt actually succeeded); false means it's queued for Level 2 as
     *   usual and sent is always null.
     */
    public static function requestSend(
        int $orderId,
        ?int $documentId,
        string $templateKey,
        ?string $scheduledAt,
        int $requestedByUserId
    ): array {
        $preview = self::buildPreview($orderId, $documentId, $templateKey, $requestedByUserId);

        // External QA report L-42: four generic templates (no document —
        // payment_followup, shipment_readiness, bl_copy_sent,
        // balance_receipt_confirmation) had no way to actually submit a
        // send at all — this method's own signature required a real
        // documentId, so a document-less generic request could never even
        // be constructed, let alone reach the "already in flight" check
        // below. hasActiveSendFor() is keyed on document_id, which is
        // meaningless (and never matches NULL in SQL) for a generic send.
        if ($documentId !== null) {
            if (EmailLogRepository::hasActiveSendFor($documentId)) {
                throw new \RuntimeException('A send for this document is already awaiting approval or scheduled to go out — wait for it to send, be rejected, or fail before submitting another.');
            }
        } elseif (EmailLogRepository::hasActiveGenericSendFor($orderId, $templateKey)) {
            throw new \RuntimeException('A send of this email is already awaiting approval or scheduled to go out for this order — wait for it to send, be rejected, or fail before submitting another.');
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

        if (CompanySettingsRepository::get('mail_approval_queue_enabled') === '0') {
            EmailLogRepository::approve($id, $requestedByUserId);
            AuditLogRepository::log($requestedByUserId, 'EMAIL_SEND_REQUESTED', 'email_log', $id, 'recipient_email', null, $preview['recipient_email']);
            AuditLogRepository::log($requestedByUserId, 'EMAIL_SEND_AUTO_APPROVED', 'email_log', $id, null, null, null, 'mail_approval_queue_enabled is off — no Level-2 approval required.');
            $row = EmailLogRepository::find($id);
            $sent = $row ? self::dispatch($row) : false;
            return ['id' => $id, 'dispatched' => true, 'sent' => $sent];
        }

        // Notify whoever can actually approve this (approve_email_send —
        // the same permission this exact approval route is gated on).
        // Checked by permission, not a hardcoded role name, since roles
        // can be renamed via /admin/roles.
        foreach (PermissionRepository::usersWithPermission('approve_email_send') as $userId) {
            NotificationRepository::create($userId, null, 'email_send_pending_approval', $orderId, "A send to {$preview['recipient_email']} is awaiting your approval.");
        }

        AuditLogRepository::log($requestedByUserId, 'EMAIL_SEND_REQUESTED', 'email_log', $id, 'recipient_email', null, $preview['recipient_email']);
        return ['id' => $id, 'dispatched' => false, 'sent' => null];
    }

    public static function approveSend(int $emailLogId, int $approverUserId): void
    {
        $row = EmailLogRepository::find($emailLogId);
        if (!$row || $row['status'] !== 'pending_approval') {
            throw new \RuntimeException('This send is not awaiting approval.');
        }
        // QA-5 maker-checker (EML-03): whoever requested this send must not
        // also be the one who approves it, unless they're a Super Admin or
        // hold manage_permissions (owner decision).
        if ($row['requested_by'] !== null
            && (int) $row['requested_by'] === $approverUserId
            && !MakerCheckerGuard::selfApprovalAllowed($approverUserId)
        ) {
            throw new \RuntimeException('You requested this send — a different privileged user must approve it.');
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
     * 90-item backlog Phase 4: the "future 'send now' button" the cron
     * script's own docblock anticipated — an approver who approves a
     * deferred send before its scheduled_at window used to have no way
     * to actually make it go out immediately; it still sat waiting for
     * the next cron tick (or for scheduled_at itself to pass). Only
     * usable on a row already 'approved' (not 'pending_approval') —
     * forcing dispatch skips nothing but the TIME it waits, never the
     * Level-2 approval step itself. Shares dispatch()'s exact code path,
     * so a forced send can never diverge from what the cron would have
     * done at the scheduled time.
     */
    public static function sendNowOverride(int $emailLogId, int $userId): bool
    {
        $row = EmailLogRepository::find($emailLogId);
        if (!$row || $row['status'] !== 'approved') {
            throw new \RuntimeException('Only an already-approved send still waiting to go out can be sent now.');
        }

        AuditLogRepository::log($userId, 'EMAIL_SEND_FORCED_NOW', 'email_log', $emailLogId, 'scheduled_at', $row['scheduled_at'], null);
        return self::dispatch($row);
    }

    /**
     * The actual send — called by app/cron/dispatch_deferred_emails.php on
     * its own schedule, or synchronously from sendNowOverride() above when
     * an approver explicitly chooses to skip the remaining wait. Returns
     * true on send success.
     */
    public static function dispatch(array $emailLogRow): bool
    {
        // QA-5 EML-06: atomically claim this row before doing anything
        // else, so an overlapping dispatch run (or a duplicate manual
        // trigger) can never send the same approved email twice. A lost
        // race is not a failure — it just means another run already has
        // it — so it returns false without touching email_log again (a
        // markFailed() here would clobber the winner's later markSent()).
        if (!EmailLogRepository::claimForSend((int) $emailLogRow['id'])) {
            return false;
        }
        // External QA report L-42: a generic (document-less) send used to
        // be marked failed unconditionally here — even once requestSend()
        // above is fixed to accept one, dispatch() (called by both the
        // cron and sendNowOverride()) would still refuse to ever actually
        // send it.
        if ($emailLogRow['document_id'] === null) {
            return self::dispatchGeneric($emailLogRow);
        }
        $document = DocumentRepository::find((int) $emailLogRow['document_id']);
        if (!$document || !$document['pdf_file_id']) {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
            return false;
        }
        // Defense in depth (QA-4 P0.4): buildPreview()/requestSend() only
        // ever queued this row while the document was 'approved', but a
        // reviewer can still be assigned to, and reject, an already-
        // approved/sent document afterward — ReviewWorkflowService::reject()
        // unconditionally reverts it to 'draft' without touching pdf_file_id
        // or looking at any pending send. Without this re-check here, a
        // stale approved PDF would still go out to the buyer from a send
        // queued before that rejection.
        if ($document['status'] !== 'approved') {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
            return false;
        }
        $file = FileStoreRepository::find((int) $document['pdf_file_id']);
        if (!$file || !is_file($file['server_path'])) {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
            return false;
        }

        $sent = MailSenderService::send(
            $emailLogRow['recipient_email'],
            $emailLogRow['subject'],
            $emailLogRow['body_snapshot'],
            [['path' => $file['server_path'], 'name' => $file['original_filename']]]
        );

        if ($sent) {
            EmailLogRepository::markSent((int) $emailLogRow['id']);
            DocumentRepository::markSent((int) $document['id']);
            AuditLogRepository::log(null, 'EMAIL_SENT', 'email_log', (int) $emailLogRow['id'], 'recipient_email', null, $emailLogRow['recipient_email']);

            // docs/schema.sql Section AE — the Order Confirmation just went
            // out to the buyer, so this is the moment the 48-hour
            // acknowledgment clock starts (OrderController::
            // recordOcAcknowledgment() / ClientPortalController::
            // acknowledgeOc() / the auto_confirm_oc_acknowledgments.php cron
            // are the three ways it later resolves).
            self::startOcAcknowledgmentClock($document);
            // With email sending on, the Quotation completes Stage 1 when it's
            // sent, not when it's approved (ReviewWorkflowService).
            if ($document['document_type_code'] === 'QT' && $document['order_id'] !== null) {
                StageGateService::passAndUnlockNext((int) $document['order_id'], 1, null);
            }
        } else {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
        }

        return $sent;
    }

    private static function startOcAcknowledgmentClock(array $document): void
    {
        if ($document['document_type_code'] === 'OC' && $document['order_id'] !== null) {
            $now = new \DateTimeImmutable();
            // docs/schema.sql Section AU: admin-configurable, default 48
            // (matches the original hardcoded value exactly).
            $hours = (int) (CompanySettingsRepository::get('oc_ack_auto_confirm_hours') ?? '48');
            \App\Repositories\OrderOcAcknowledgmentRepository::recordSent(
                (int) $document['order_id'],
                (int) $document['id'],
                $now->format('Y-m-d H:i:s'),
                $now->modify("+{$hours} hours")->format('Y-m-d H:i:s')
            );
        }
    }

    public static function mailSendingEnabled(): bool
    {
        return CompanySettingsRepository::get('mail_sending_enabled') !== '0';
    }

    /**
     * Owner decision (2026-10-25): with mail_sending_enabled off there is
     * no "Send to Buyer" action at all — staff send the PDF themselves
     * outside the app — so a buyer-sendable document counts as sent the
     * moment it is approved. Does exactly what a real send does to the
     * document (status 'sent', the OC acknowledgment clock) minus the
     * email itself. Internal never-shown-to-buyer types are left as-is.
     * Called right after every point a document becomes approved.
     */
    public static function markSentIfMailDisabled(int $documentId): void
    {
        if (self::mailSendingEnabled()) {
            return;
        }
        $document = DocumentRepository::find($documentId);
        if (!$document || $document['status'] !== 'approved' || !empty($document['document_type_never_shown_to_buyer'])) {
            return;
        }
        DocumentRepository::markSent($documentId);
        self::startOcAcknowledgmentClock($document);
        AuditLogRepository::log(
            null, 'DOCUMENT_MARKED_SENT_MAIL_DISABLED', 'documents', $documentId, 'status', 'approved', 'sent',
            'mail_sending_enabled is off — counted as sent on approval; staff send the PDF outside the app.'
        );
    }

    /**
     * External QA report L-42: dispatch path for the 4 generic (no
     * document) templates — payment_followup/shipment_readiness/
     * balance_receipt_confirmation go out with no attachment;
     * bl_copy_sent (the report's explicit named exception) attaches
     * orders' scanned Bill of Lading copy (order_shipping.bl_copy_file_id
     * — see OrderShippingRepository::attachBlCopy(), the same file L-22
     * fixed the upload for) exactly like a document send attaches its PDF.
     */
    private static function dispatchGeneric(array $emailLogRow): bool
    {
        $attachments = [];
        if ($emailLogRow['template_key'] === 'bl_copy_sent') {
            $shipping = \App\Repositories\OrderShippingRepository::find((int) $emailLogRow['order_id']);
            if (!$shipping || !$shipping['bl_copy_file_id']) {
                EmailLogRepository::markFailed((int) $emailLogRow['id']);
                return false;
            }
            $file = FileStoreRepository::find((int) $shipping['bl_copy_file_id']);
            if (!$file || !is_file($file['server_path'])) {
                EmailLogRepository::markFailed((int) $emailLogRow['id']);
                return false;
            }
            $attachments[] = ['path' => $file['server_path'], 'name' => $file['original_filename']];
        }

        $sent = MailSenderService::send(
            $emailLogRow['recipient_email'],
            $emailLogRow['subject'],
            $emailLogRow['body_snapshot'],
            $attachments
        );

        if ($sent) {
            EmailLogRepository::markSent((int) $emailLogRow['id']);
            AuditLogRepository::log(null, 'EMAIL_SENT', 'email_log', (int) $emailLogRow['id'], 'recipient_email', null, $emailLogRow['recipient_email']);
        } else {
            EmailLogRepository::markFailed((int) $emailLogRow['id']);
        }

        return $sent;
    }

    /** @return array<string,string> */
    private static function tokensFor(array $order, ?array $document, ?array $sender): array
    {
        $orderId = (int) $order['id'];
        $qtDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'QT');
        $piDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI');
        $ocDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');

        // QA-5 EML-08: {sender_title} used to always resolve to the
        // company-wide md_title setting ("Founder & Managing Director")
        // regardless of who actually sent the email — a Logistics or
        // Accounts user's approved send would go out signed with the MD's
        // own title. Use the real sender's own designation when they have
        // one.
        // External QA report L-12: a sender with no designation set still
        // fell all the way back to md_title, so e.g. an Export Executive's
        // email went out signed "Founder & Managing Director". Fall back to
        // the sender's own role name instead; md_title is now only the
        // fallback when there is no specific sender at all (system emails).
        $senderTitle = $sender ? '' : (string) CompanySettingsRepository::get('md_title');
        if ($sender && $sender['designation_id']) {
            $designation = SignatoryRepository::findDesignation((int) $sender['designation_id']);
            if ($designation) {
                $senderTitle = $designation['title'];
            }
        }
        if ($sender && $senderTitle === '' && $sender['role_id']) {
            $role = RoleRepository::find((int) $sender['role_id']);
            if ($role) {
                $senderTitle = $role['name'];
            }
        }
        if ($senderTitle === '') {
            $senderTitle = (string) CompanySettingsRepository::get('md_title');
        }

        $signature = trim((string) ($sender['email_signature'] ?? ''));
        if ($signature === '') {
            $signature = ($sender['name'] ?? (string) CompanySettingsRepository::get('md_name'))
                . "\n" . $senderTitle;
        }

        return [
            '{buyer_contact_person}' => $order['contact_person'] ?: 'Sir/Madam',
            '{buyer_company_name}'   => $order['company_legal_name'],
            '{document_reference}'   => $document['document_reference'] ?? '—',
            '{generated_date}'       => $document ? (new \DateTimeImmutable((string) $document['generated_at']))->format('d F Y') : '—',
            '{order_reference}'      => $order['order_reference'],
            '{buyer_inquiry_ref}'    => $order['buyer_inquiry_ref'],
            '{quotation_ref}'        => $qtDoc['document_reference'] ?? '—',
            '{quotation_valid_until}' => $order['quotation_valid_until'] ? (new \DateTimeImmutable($order['quotation_valid_until']))->format('d F Y') : '—',
            '{pi_ref}'               => $piDoc['document_reference'] ?? '—',
            '{pi_valid_until}'       => $order['pi_valid_until'] ? (new \DateTimeImmutable($order['pi_valid_until']))->format('d F Y') : '—',
            '{oc_ref}'               => $ocDoc['document_reference'] ?? '—',
            '{company_name}'         => (string) CompanySettingsRepository::get('legal_name'),
            '{company_email}'        => (string) CompanySettingsRepository::get('email'),
            '{company_phone}'        => (string) CompanySettingsRepository::get('phone'),
            '{sender_name}'          => $sender['name'] ?? (string) CompanySettingsRepository::get('md_name'),
            '{sender_title}'         => $senderTitle,
            '{sender_signature}'     => $signature,
        ];
    }
}
