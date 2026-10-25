<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderPaymentStatusRepository;

/**
 * 90-item backlog #75: the OC-acknowledgment and Buyer-PO-upload write
 * paths used to live only inside ClientPortalController, reachable only
 * from a logged-in portal session. This extracts the order-id-scoped core
 * of each (neither ever actually needed the client's own id — only the
 * order's) so both the session-gated client-portal route
 * (ClientPortalController::acknowledgeOc()/uploadBuyerPo()) and the new
 * no-login, tokenized BuyerActionController route can call the exact same
 * logic rather than maintaining two copies of it.
 *
 * 90-item backlog #19/#48 added two more pieces here: uploadBuyerPo() now
 * refuses once the advance payment has cleared (the Buyer PO's job is done
 * by then, on either channel — see the method's own comment), and
 * reportAdvancePayment() gives the no-login channel a narrow self-report
 * option for the one payment leg the client-portal's own reportPayment()
 * can never actually reach — the advance itself, since the portal login
 * isn't provisioned until that very payment clears.
 */
final class BuyerActionService
{
    /** @return array{ok: bool, message: string} */
    public static function acknowledgeOc(int $orderId): array
    {
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        if (!$ack) {
            // User-reported batch item 10: no OC send has ever completed
            // for this order (still awaiting Level-2 email approval, mail
            // sending misconfigured, or staff generated the Buyer Action
            // Link before the OC ever went out by email at all) — there
            // is nothing yet for EmailDispatchService::dispatch()'s own
            // recordSent() to have created. Same lazy fallback
            // OrderController::recordOcAcknowledgment() already uses for
            // the staff-side "Record Buyer's Acknowledgement" path: the OC
            // must at least have been generated/approved so there's a
            // real document to point this record at.
            $ocDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');
            // Only once the OC counts as issued (approved while email is
            // off, sent while it is on) — the same rule the Stage 4 gate applies.
            if ($ocDoc && DocumentGenerationService::stageDocumentBlockedBy($orderId, 4) === []) {
                OrderOcAcknowledgmentRepository::ensureRowExists($orderId, (int) $ocDoc['id']);
                $ack = OrderOcAcknowledgmentRepository::find($orderId);
            }
        }
        if (!$ack || $ack['acknowledged_at'] !== null) {
            return ['ok' => false, 'message' => 'There is nothing awaiting your acknowledgement on this order.'];
        }
        if (!StageGateService::isUnlocked($orderId, 4)) {
            return ['ok' => false, 'message' => 'This order is not currently awaiting acknowledgement.'];
        }
        // Owner decision (2026-10-25): the stage completes only once the OC is
        // approved (email off) / sent (email on) — same rule as the staff path.
        if (DocumentGenerationService::stageDocumentBlockedBy($orderId, 4)) {
            return ['ok' => false, 'message' => 'Your Order Confirmation has not been issued yet — please wait for it to be sent to you, then acknowledge it.'];
        }

        if (!OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'client_portal', null, null)) {
            // QA-5 EML-06: lost the race to another acknowledgment path
            // (staff-recorded, or the 48h auto-confirm cron) between this
            // action's own pre-check above and this write.
            return ['ok' => true, 'message' => 'This order has already been acknowledged and is moving to production.'];
        }
        StageGateService::passAndUnlockNext($orderId, 4, null);
        // External QA report L-30/M-07: nothing here ever told staff a
        // buyer had acknowledged their OC — the only way to notice was to
        // happen to reopen the order. AuditLogRepository::log() already
        // fans out to ActionNotificationService for every other action in
        // this codebase; this was simply the one self-service action that
        // never called it.
        AuditLogRepository::log(null, 'BUYER_OC_ACKNOWLEDGED', 'orders', $orderId, null, null, null, 'Order Confirmation acknowledged by the client (self-service).');
        return ['ok' => true, 'message' => 'Thank you — your acknowledgement has been recorded and your order is moving to production.'];
    }

    /**
     * Whether the advance payment has already been marked cleared on this
     * order — the point past which both uploadBuyerPo() and
     * reportAdvancePayment() refuse to act, since each one's whole purpose
     * (getting the signed PO on file / getting the advance itself noted)
     * is already done by then.
     */
    private static function advanceAlreadyCleared(int $orderId): bool
    {
        $status = OrderPaymentStatusRepository::find($orderId);
        return $status !== null && $status['advance_cleared_at'] !== null;
    }

    /** @return array{ok: bool, message: string} */
    public static function uploadBuyerPo(array $order): array
    {
        $orderId = (int) $order['id'];

        // 90-item backlog #19: this used to be callable "at any time" on
        // both the client-portal and no-login channels — real-world use is
        // always around Stage 2, well before the advance clears, so once
        // it has, a further self-upload serves no purpose and risks the
        // buyer silently replacing the copy staff already confirmed and
        // built later documents against.
        if (self::advanceAlreadyCleared($orderId)) {
            return ['ok' => false, 'message' => 'The advance payment has already cleared, so self-service Buyer PO upload is now closed. Contact your NexaCrest representative if you need to submit an updated copy.'];
        }

        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'buyer_po_copy',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/buyer_po',
                (int) $order['client_id'],
                $orderId,
                null,
                null,
                'Client (self-uploaded)',
                'Buyer PO copy (client-uploaded)'
            );
            OrderBuyerPoDocumentRepository::attach($orderId, $fileId);
            AuditLogRepository::log(null, 'BUYER_PO_UPLOADED_BY_CLIENT', 'orders', $orderId, null, null, null, 'Buyer PO copy uploaded by the client via the client portal.');
            return ['ok' => true, 'message' => 'Your Buyer PO copy has been uploaded.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 90-item backlog #48: ClientPortalController::reportPayment() lets a
     * logged-in buyer self-report any payment leg, but the portal login
     * itself isn't provisioned until the advance payment is marked cleared
     * (docs/SOP/12-client-portal.md) — so the advance leg is the one
     * payment a buyer could never actually self-report through it ("the
     * very first payment a buyer could self-report through the portal is
     * a later one... not this one" per that same SOP page). This gives the
     * no-login Buyer Action Link a narrow equivalent, scoped to the
     * advance leg only — freight/balance already have a working
     * self-report channel once the portal exists from Stage 4 onward, so
     * they don't need a second one here. Reads $_POST/$_FILES directly,
     * matching uploadBuyerPo()'s own style in this class.
     *
     * @return array{ok: bool, message: string}
     */
    public static function reportAdvancePayment(array $order): array
    {
        $orderId = (int) $order['id'];

        if (self::advanceAlreadyCleared($orderId)) {
            return ['ok' => false, 'message' => 'The advance payment has already been marked cleared on this order — no further report is needed.'];
        }

        $transactionRef = trim((string) ($_POST['transaction_ref'] ?? ''));
        if ($transactionRef === '') {
            return ['ok' => false, 'message' => 'Enter the transaction ID/UTR for your advance payment.'];
        }

        $screenshotFileId = null;
        if (!empty($_FILES['screenshot']['name'])) {
            try {
                $screenshotFileId = FileUploadService::handleUpload(
                    'screenshot',
                    'received_remittance',
                    'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/client_payment_reports',
                    (int) $order['client_id'],
                    $orderId,
                    null,
                    null,
                    'Client (self-reported)',
                    'Client payment self-report screenshot'
                );
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        $amount = trim((string) ($_POST['amount'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        ClientPaymentReportRepository::create(
            $orderId,
            'advance',
            $transactionRef,
            trim((string) ($_POST['payer_bank_details'] ?? '')) ?: null,
            $amount !== '' ? (float) $amount : null,
            $paymentDate !== '' ? $paymentDate : null,
            $screenshotFileId
        );
        // External QA report L-30/M-07: a client payment self-report
        // never notified staff at all — the "our team will verify this"
        // promise below depended entirely on staff happening to reopen
        // this order. See acknowledgeOc()'s own comment on the same gap.
        AuditLogRepository::log(null, 'CLIENT_PAYMENT_REPORTED', 'orders', $orderId, 'payment_type', null, 'advance', "Client self-reported an advance payment: ref {$transactionRef}.");

        return ['ok' => true, 'message' => 'Thank you — we have noted your advance payment details. Our team will verify this against our bank statement and update your order.'];
    }
}
