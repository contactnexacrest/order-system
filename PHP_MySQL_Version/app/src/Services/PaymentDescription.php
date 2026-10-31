<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DocumentRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;

/**
 * Owner rule D-212 (2026-10-31): every money record carries a description
 * saying what it is, for which order and party, what it settles and its
 * bank reference — built here from the facts, never typed, so it is never
 * blank or wrong. Port: src/services/paymentDescription.js (same wording).
 */
final class PaymentDescription
{
    private const LEG_LABELS = ['advance' => 'Advance', 'balance' => 'Balance', 'freight' => 'Freight'];

    /**
     * A payment received from the buyer (advance, balance, freight or the
     * money paid under an LC), as it goes to Zoho Books.
     *
     * @param string|null $invoiceNumber the Zoho invoice it is applied to (CI, or the Debit Note for freight), when there is one
     */
    public static function buyerPayment(int $orderId, string $leg, ?string $invoiceNumber = null): string
    {
        $order = OrderRepository::find($orderId) ?? [];
        $currency = (string) ($order['currency_code'] ?? '');
        $head = ($leg === 'lc' ? 'LC money' : (self::LEG_LABELS[$leg] ?? ucfirst($leg))) . ' received from ' . ($order['company_legal_name'] ?? 'the buyer')
            . ' for order ' . ($order['buyer_inquiry_ref'] ?? $order['order_reference'] ?? "#{$orderId}");
        $parts = [$head];
        if ($leg === 'lc') {
            $lc = OrderLcDetailRepository::find($orderId) ?? [];
            if (!empty($lc['lc_number'])) {
                $parts[] = 'LC ' . $lc['lc_number'] . (!empty($lc['lc_bank_name']) ? " ({$lc['lc_bank_name']})" : '');
            }
            if (($lc['lc_received_amount'] ?? null) !== null) {
                $parts[] = "{$currency} " . number_format((float) $lc['lc_received_amount'], 2) . ', ₹' . number_format((float) $lc['lc_inr_credited'], 2) . ' credited'
                    . ((float) ($lc['lc_bank_charges_inr'] ?? 0) > 0 ? ' after ₹' . number_format((float) $lc['lc_bank_charges_inr'], 2) . ' bank charges' : '');
            }
            $bankRef = $lc['lc_bank_reference'] ?? null;
        } else {
            $ops = OrderPaymentStatusRepository::find($orderId) ?? [];
            if (($ops[$leg . '_amount'] ?? null) !== null) {
                $rate = $ops[$leg . '_exchange_rate'] ?? null;
                $parts[] = "{$currency} " . number_format((float) $ops[$leg . '_amount'], 2) . ($rate !== null ? ' at ₹' . self::rate((float) $rate) : '');
            }
            $bankRef = $ops[$leg . '_bank_reference'] ?? null;
        }
        $against = self::against($orderId, $leg, $invoiceNumber);
        if ($against !== null) {
            $parts[] = "against {$against}";
        }
        if (!empty($bankRef)) {
            $parts[] = "bank ref {$bankRef}";
        }
        return implode(' · ', $parts);
    }

    /** Batch 2: the pre-shipment inspection fee, naming the agency and its invoice. */
    public static function inspectionFee(string $agency, string $invoiceNo): string
    {
        return 'Pre-shipment inspection fee — ' . trim($agency) . ', invoice ' . trim($invoiceNo);
    }

    /** Batch 2: the interest on the bank loan taken against the LC, with the LC and the loan it was charged on. */
    public static function lcLoanInterest(?string $lcNumber, ?float $loanAmount, ?float $interestRate): string
    {
        $parts = ['Bank loan interest (LC order)'];
        if ($lcNumber !== null && trim($lcNumber) !== '') {
            $parts[] = 'LC ' . trim($lcNumber);
        }
        if ($loanAmount !== null && $loanAmount > 0) {
            $parts[] = 'loan ₹' . number_format($loanAmount, 2) . ($interestRate !== null && $interestRate > 0 ? ' at ' . self::percent($interestRate) . '%' : '');
        }
        return implode(' · ', $parts);
    }

    /** Batch 2: the provisional travel cost, with how it was worked out. */
    public static function travelExpenses(?float $distanceKm, float $pct, string $currency, float $fobTotal, float $rate): string
    {
        return 'Travel expenses (factory visits) — ' . ($distanceKm !== null ? self::percent($distanceKm) . ' km to the factory, ' : '') . self::percent($pct) . '% of the order value '
            . $currency . ' ' . number_format($fobTotal, 2) . ($currency !== 'INR' ? ' at ₹' . self::rate($rate) : '');
    }

    /**
     * Owner decision 2026-10-31 (GST under LUT): a GST refund the bank
     * credited — "GST refund under LUT, Oct–Dec 2026, claim AA27… ·
     * provisional · ₹185,400.00 credited 12 Feb 2027 · bank ref RFD05-…".
     *
     * @param array<string,mixed> $credit a GstRefundRepository credit row with its claim's period_from, period_to and claim_arn
     */
    public static function gstRefund(array $credit): string
    {
        return 'GST refund under LUT, ' . self::periodLabel((string) $credit['period_from'], (string) $credit['period_to'])
            . ', claim ' . $credit['claim_arn']
            . ' · ' . (\App\Repositories\GstRefundRepository::PARTS[$credit['part']] ?? (string) $credit['part'])
            . ' · ₹' . number_format((float) $credit['amount'], 2) . ' credited ' . date('j M Y', strtotime((string) $credit['credited_on']))
            . ' · bank ref ' . $credit['bank_reference'];
    }

    /** 2026-10-01 … 2026-12-31 → "Oct–Dec 2026"; one month → "Oct 2026"; across years → "Dec 2026–Jan 2027". */
    public static function periodLabel(string $from, string $to): string
    {
        $f = strtotime($from);
        $t = strtotime($to);
        if (date('Y-m', $f) === date('Y-m', $t)) {
            return date('M Y', $f);
        }
        return date('Y', $f) === date('Y', $t) ? date('M', $f) . '–' . date('M Y', $t) : date('M Y', $f) . '–' . date('M Y', $t);
    }

    /** 5.0 → "5", 7.5 → "7.5". */
    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** What the payment settles: its invoice in Zoho, or, for an advance not yet applied, the Proforma Invoice it was asked for. */
    private static function against(int $orderId, string $leg, ?string $invoiceNumber): ?string
    {
        if ($invoiceNumber !== null && $invoiceNumber !== '') {
            return ($leg === 'freight' ? 'Debit Note ' : 'Commercial Invoice ') . $invoiceNumber;
        }
        if ($leg === 'advance') {
            $pi = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'PI');
            return $pi !== null && !empty($pi['document_reference']) ? 'Proforma Invoice ' . $pi['document_reference'] : null;
        }
        return null;
    }

    /** 84.1 → "84.10", 83.3333 → "83.3333": two decimals at least, the rate as recorded otherwise. */
    private static function rate(float $rate): string
    {
        $text = rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.');
        return str_contains($text, '.') && strlen(substr($text, strpos($text, '.') + 1)) >= 2 ? $text : number_format($rate, 2, '.', '');
    }
}
