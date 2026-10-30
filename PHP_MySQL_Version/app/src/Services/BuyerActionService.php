<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderStageRepository;

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
        // happen to reopen the order.
        NotificationRepository::notifyOrderTeam($orderId, 'buyer_oc_acknowledged', 'The buyer acknowledged the Order Confirmation on order ' . self::orderReference($orderId) . ' — Step 7 (Supplier PO) is now open.');
        AuditLogRepository::log(null, 'BUYER_OC_ACKNOWLEDGED', 'orders', $orderId, null, null, null, 'Order Confirmation acknowledged by the client (self-service).');
        return ['ok' => true, 'message' => 'Thank you — your acknowledgement has been recorded and your order is moving to production.'];
    }

    private static function orderReference(int $orderId): string
    {
        return (string) (OrderRepository::find($orderId)['order_reference'] ?? "#{$orderId}");
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

        // Owner request (2026-10-26): while Step 4 is still open the buyer
        // gives their PO number with the signed copy, and Step 4 completes
        // by itself — the same as staff attaching (4c) and confirming (4d).
        $awaitingPo = StageGateService::isUnlocked($orderId, 2);
        $poRef = trim((string) ($_POST['buyers_po_ref'] ?? ''));
        if ($awaitingPo && $poRef === '') {
            return ['ok' => false, 'message' => 'Please enter your Purchase Order number together with the signed copy.'];
        }

        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'buyer_po_copy',
                \App\Helpers\OrderStorage::forReceived($order, 'buyer_po'),
                (int) $order['client_id'],
                $orderId,
                null,
                null,
                'Client (self-uploaded)',
                'Buyer PO copy (client-uploaded)'
            );
            OrderBuyerPoDocumentRepository::attach($orderId, $fileId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        AuditLogRepository::log(null, 'BUYER_PO_UPLOADED_BY_CLIENT', 'orders', $orderId, null, null, $poRef !== '' ? $poRef : null, 'Buyer PO copy uploaded by the client.');
        $reference = $order['order_reference'] ?? "#{$orderId}";

        if ($awaitingPo && DocumentGenerationService::stageDocumentBlockedBy($orderId, 2) === []) {
            OrderRepository::setBuyersPoRef($orderId, $poRef, date('Y-m-d')); // the day the buyer sent it
            StageGateService::passAndUnlockNext($orderId, 2, null);
            AuditLogRepository::log(null, 'BUYER_PO_CONFIRMED_BY_CLIENT', 'orders', $orderId, 'buyers_po_ref', null, $poRef, 'The buyer uploaded their signed PO with its number — Step 4 completed.');
            NotificationRepository::notifyOrderTeam($orderId, 'buyer_po_uploaded_by_client', "The buyer uploaded their signed PO ({$poRef}) on order {$reference} — Step 4 is complete and Stage 3 (PI) is open.");
            return ['ok' => true, 'message' => "Thank you — your signed Purchase Order ({$poRef}) has been received. We'll be in touch with your Proforma Invoice."];
        }

        NotificationRepository::notifyOrderTeam($orderId, 'buyer_po_uploaded_by_client', "The buyer uploaded their signed PO copy" . ($poRef !== '' ? " ({$poRef})" : '') . " on order {$reference} — check it and confirm the Buyer PO (Step 4d).");
        return ['ok' => true, 'message' => 'Your Buyer PO copy has been uploaded.'];
    }

    /**
     * Owner request (2026-10-26): a document the buyer can download from the
     * Buyer Action Link — the Buyer PO generated in Step 4a (to sign) or the
     * Proforma Invoice (to pay against) — once it is issued (approved, or
     * sent while email is on). Null until then, and for any other type.
     */
    public static function issuedBuyerDocument(int $orderId, string $typeCode): ?array
    {
        $stage = ['BUYERPO' => 2, 'PI' => 3, 'OC' => 4][$typeCode] ?? null;
        $latest = $stage !== null ? DocumentRepository::findLatestForOrderAndTypeCode($orderId, $typeCode) : null;
        if (!$latest || empty($latest['pdf_file_id']) || DocumentGenerationService::stageDocumentBlockedBy($orderId, $stage) !== []) {
            return null;
        }
        $document = DocumentRepository::find((int) $latest['id']);
        if (!$document || !in_array($document['status'], ['approved', 'sent'], true)
            || $document['document_type_category'] !== 'customer_facing'
            || !empty($document['document_type_never_shown_to_buyer'])) {
            return null;
        }
        return $document;
    }

    /**
     * Owner request (2026-10-26): the Buyer Action Link shows the client one
     * clear step at a time — the Purchase Order while Step 4 is open, then
     * the advance payment once the PI is issued, then the Order
     * Confirmation — never a later step before its turn.
     *
     * @return 'buyer_po'|'waiting_pi'|'advance'|'advance_reported'|'waiting'|'oc'|'done'
     */
    public static function currentTask(array $order, ?array $ocAcknowledgment): string
    {
        $orderId = (int) $order['id'];
        $passed = static fn(int $n): bool => (OrderStageRepository::findByOrderAndStageNumber($orderId, $n)['status'] ?? null) === 'gate_passed';
        if (!$passed(2)) {
            return 'buyer_po';
        }
        if (!$passed(3) && !self::advanceAlreadyCleared($orderId)) {
            if (($order['payment_method'] ?? 'CASH_ADVANCE') === 'LC') {
                return 'waiting';
            }
            if (self::issuedBuyerDocument($orderId, 'PI') === null) {
                return 'waiting_pi';
            }
            foreach (ClientPaymentReportRepository::forOrder($orderId) as $report) {
                if ($report['payment_type'] === 'advance' && $report['status'] === 'new') {
                    return 'advance_reported';
                }
            }
            return 'advance';
        }
        if ($ocAcknowledgment !== null && $ocAcknowledgment['acknowledged_at'] === null) {
            return 'oc';
        }
        return $passed(4) ? 'done' : 'waiting';
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
        // QA Round 6, R6-N19/N20/N21: same amount and date checks as the portal.
        if ($error = \App\Helpers\FieldValidator::reportedPayment((string) ($_POST['amount'] ?? ''), (string) ($_POST['payment_date'] ?? ''))) {
            return ['ok' => false, 'message' => $error];
        }

        $screenshotFileId = null;
        if (!empty($_FILES['screenshot']['name'])) {
            try {
                $screenshotFileId = FileUploadService::handleUpload(
                    'screenshot',
                    'received_remittance',
                    \App\Helpers\OrderStorage::folder($order, 'payments'),
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
        NotificationRepository::notifyOrderTeam($orderId, 'client_payment_reported', 'The buyer reported an advance payment on order ' . ($order['order_reference'] ?? "#{$orderId}") . ' — verify it against the bank statement.');
        AuditLogRepository::log(null, 'CLIENT_PAYMENT_REPORTED', 'orders', $orderId, 'payment_type', null, 'advance', "Client self-reported an advance payment: ref {$transactionRef}.");

        return ['ok' => true, 'message' => 'Thank you — we have noted your advance payment details. Our team will verify this against our bank statement and update your order.'];
    }
}
