<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;

/**
 * Owner request (2026-10-27): the no-login Payment Action Link. The client
 * gets it with the Proforma Invoice (advance), the Debit Note (freight &
 * insurance, CFR/CIF only) and the Commercial Invoice (balance), and sends
 * their payment details there — transaction ID, amount, date and a
 * screenshot. It shows payment only, one payment at a time, never the
 * Buyer PO or anything else. Like every client self-report it is purely
 * informational: staff still verify the bank statement and record the
 * remittance themselves (docs/schema.sql Section AD).
 */
final class PaymentActionService
{
    /** Each payment the client can make, in order, with the document it is paid against. */
    public const LEGS = [
        'advance' => ['doc' => 'PI', 'label' => 'Advance Payment', 'document' => 'Proforma Invoice'],
        'freight' => ['doc' => 'FDN', 'label' => 'Freight & Insurance', 'document' => 'Debit Note'],
        'balance' => ['doc' => 'CI', 'label' => 'Balance Payment', 'document' => 'Commercial Invoice'],
    ];

    /** The document a leg is paid against, once it is issued to the buyer (approved, or sent while email is on). */
    public static function issuedDocument(int $orderId, string $leg): ?array
    {
        $code = self::LEGS[$leg]['doc'] ?? null;
        $latest = $code !== null ? DocumentRepository::findLatestForOrderAndTypeCode($orderId, $code) : null;
        if (!$latest || empty($latest['pdf_file_id'])) {
            return null;
        }
        $document = DocumentRepository::find((int) $latest['id']);
        if (!$document || $document['status'] === 'superseded'
            || $document['document_type_category'] !== 'customer_facing'
            || !empty($document['document_type_never_shown_to_buyer'])
            || !DocumentGenerationService::isDocumentComplete($document)) {
            return null;
        }
        return $document;
    }

    /** @return array<int, string> the legs this order has: no advance on an LC order, no freight on an FOB one */
    public static function legsFor(array $order): array
    {
        $legs = [];
        if (($order['payment_method'] ?? 'CASH_ADVANCE') !== 'LC') {
            $legs[] = 'advance';
        }
        if (strtoupper((string) ($order['incoterm_code'] ?? 'FOB')) !== 'FOB') {
            $legs[] = 'freight';
        }
        $legs[] = 'balance';
        return $legs;
    }

    /** What the client owes on a leg — the advance due by the terms, the Debit Note amount, or FOB minus the advance actually received. */
    public static function amountDue(array $order, string $leg): ?float
    {
        $orderId = (int) $order['id'];
        $fob = OrderProductRepository::totalFobValue($orderId);
        if ($leg === 'advance') {
            return round($fob * (float) ($order['advance_pct'] ?? 0) / 100, 2);
        }
        if ($leg === 'freight') {
            $freight = OrderFreightRepository::find($orderId);
            return $freight && $freight['confirmed_freight_rate'] !== null
                // Q-R5-2: only a CIF seller insures; on CFR the Debit Note is freight only.
                ? round((float) $freight['confirmed_freight_rate'] + (strtoupper((string) ($order['incoterm_code'] ?? '')) === 'CIF' ? (float) ($freight['insurance_amount'] ?? 0) : 0.0), 2)
                : null;
        }
        return OrderPaymentStatusRepository::balanceDue($fob, (float) ($order['advance_pct'] ?? 0), OrderPaymentStatusRepository::find($orderId), \App\Repositories\PaymentMilestoneRepository::receivedTotal($orderId));
    }

    /**
     * The one payment the page shows now: the first leg not yet cleared.
     * 'due' while its document is issued and nothing is reported yet,
     * 'reported' once the client (or staff) has sent the details, 'waiting'
     * while its document is not issued yet, 'done' when every leg cleared.
     *
     * @return array{leg: ?string, state: string, legs: array<string, string>}
     */
    public static function current(array $order): array
    {
        $orderId = (int) $order['id'];
        $payment = OrderPaymentStatusRepository::find($orderId) ?? [];
        $reports = ClientPaymentReportRepository::forOrder($orderId);
        $legs = [];
        $current = null;
        $state = 'done';
        foreach (self::legsFor($order) as $leg) {
            if (!empty($payment[$leg . '_cleared_at'])) {
                $legs[$leg] = 'done';
                continue;
            }
            if ($current !== null) {
                $legs[$leg] = 'todo';
                continue;
            }
            $current = $leg;
            $legs[$leg] = 'current';
            $reported = !empty($payment[$leg . '_remittance_received_at']);
            foreach ($reports as $report) {
                $reported = $reported || ($report['payment_type'] === $leg && $report['status'] === 'new');
            }
            $state = $reported ? 'reported' : (self::issuedDocument($orderId, $leg) ? 'due' : 'waiting');
        }
        return ['leg' => $current, 'state' => $state, 'legs' => $legs];
    }

    /** @return array{ok: bool, message: string} */
    public static function report(array $order): array
    {
        $orderId = (int) $order['id'];
        $current = self::current($order);
        if ($current['state'] !== 'due') {
            return ['ok' => false, 'message' => 'There is no payment waiting for your details on this order right now.'];
        }
        $leg = (string) $current['leg'];

        $transactionRef = trim((string) ($_POST['transaction_ref'] ?? ''));
        if ($transactionRef === '') {
            return ['ok' => false, 'message' => 'Enter the transaction ID / UTR of your payment.'];
        }
        $amount = trim((string) ($_POST['amount'] ?? ''));
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            return ['ok' => false, 'message' => 'Enter the amount you paid.'];
        }
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        if ($paymentDate === '' || \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate) === false) {
            return ['ok' => false, 'message' => 'Enter the date you paid.'];
        }
        if (empty($_FILES['screenshot']['name'])) {
            return ['ok' => false, 'message' => 'Attach the payment screenshot or bank remittance advice.'];
        }
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

        ClientPaymentReportRepository::create(
            $orderId,
            $leg,
            $transactionRef,
            trim((string) ($_POST['payer_bank_details'] ?? '')) ?: null,
            (float) $amount,
            $paymentDate,
            $screenshotFileId
        );
        $label = strtolower(self::LEGS[$leg]['label']);
        NotificationRepository::notifyOrderTeam($orderId, 'client_payment_reported', 'The buyer sent their ' . $label . ' details on order ' . ($order['order_reference'] ?? "#{$orderId}") . ' through the Payment Action Link — verify it against the bank statement.');
        AuditLogRepository::log(null, 'CLIENT_PAYMENT_REPORTED', 'orders', $orderId, 'payment_type', null, $leg, "Client sent {$label} details through the Payment Action Link: ref {$transactionRef}.");

        return ['ok' => true, 'message' => 'Thank you — we have your payment details. Our team will check them against our bank statement and confirm.'];
    }
}
