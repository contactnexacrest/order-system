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
