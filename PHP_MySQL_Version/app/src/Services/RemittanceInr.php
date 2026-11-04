<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FieldValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderRepository;

/**
 * Owner decision D-244 (4 Nov 2026, "C yes"): a buyer remittance (advance,
 * freight, balance, payment milestone) is recorded with the rupees the bank
 * actually credited, not a typed rate. The rate is worked out from them
 * (INR credited ÷ amount received). The rate printed on the bank's advice is
 * optional; when given, it checks the INR:
 *   gap = amount × bank's rate − INR credited
 *   |gap| ≤ ₹1 → saved as it is (rounding);
 *   |gap| > ₹1 → the person recording it says why:
 *     bank_charges — the bank deducted its charges: the gap is booked at once
 *                    as a Bank Charges order cost (rule D-212 description),
 *                    and the receipt is valued at the bank's rate, so the
 *                    charges are counted once (as the cost);
 *     typo         — something was typed wrong: nothing is saved;
 *     other        — another reason (at least 10 characters), saved on the receipt.
 * "INR not known yet" keeps the old way: the rate is typed, the INR stays
 * empty, and the CA register shows the amount at the recorded rate as an
 * estimate until the INR actual is entered.
 * Node twin: src/services/remittanceInr.js.
 */
final class RemittanceInr
{
    /** Up to this many rupees between amount × bank's rate and the INR credited is rounding. */
    public const TOLERANCE_INR = 1.0;

    public const CHOICES = [
        'bank_charges' => 'The bank deducted its charges — book the difference as Bank Charges',
        'typo'         => 'I typed something wrong — let me correct it',
        'other'        => 'Other reason — type it',
    ];

    /**
     * Reads the posted INR fields of one remittance form. $prefix is the
     * fields' name prefix ('advance_', 'freight_', 'balance_', or '' on a
     * milestone form). The amount is the foreign amount received.
     *
     * @return array{error?:string, inr?:?float, rate?:float, effective_rate?:float, bank_rate?:?float, gap?:?float, choice?:?string, reason?:?string}
     */
    public static function fromPost(array $post, string $prefix, float $amount, string $currency): array
    {
        if (!empty($post[$prefix . 'inr_unknown'])) {
            $rate = (float) ($post[$prefix . 'exchange_rate'] ?? 0);
            if ($rate <= 0) {
                return ['error' => "You ticked \"INR not known yet\": enter the exchange rate (₹ per 1 {$currency}) — or untick it and enter the INR credited by the bank."];
            }
            if ($error = FieldValidator::exchangeRate($rate)) {
                return ['error' => $error];
            }
            return ['inr' => null, 'rate' => round($rate, 4), 'effective_rate' => round($rate, 4), 'bank_rate' => null, 'gap' => null, 'choice' => null, 'reason' => null];
        }
        $raw = trim((string) ($post[$prefix . 'inr_credited'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return ['error' => 'Enter the INR credited by the bank (₹) — the rupees that reached our account, from the bank\'s credit advice. If it is not known yet, tick "INR not known yet — enter the rate".'];
        }
        $inr = round((float) $raw, 2);
        if ($error = FieldValidator::moneyAmount($inr, 'The INR credited by the bank')) {
            return ['error' => $error];
        }
        $effective = $inr / $amount;
        if ($error = FieldValidator::exchangeRate($effective, "The worked-out exchange rate (₹ " . number_format($inr, 2) . " ÷ {$currency} " . number_format($amount, 2) . ')')) {
            return ['error' => $error . ' Check the amount received and the INR credited.'];
        }
        $bankRaw = trim((string) ($post[$prefix . 'bank_rate'] ?? ''));
        $bankRate = null;
        if ($bankRaw !== '') {
            if (!is_numeric($bankRaw)) {
                return ['error' => "The bank's rate on the advice must be a number (₹ per 1 {$currency}) — or leave it empty."];
            }
            $bankRate = round((float) $bankRaw, 4);
            if ($error = FieldValidator::exchangeRate($bankRate, "The bank's rate on the advice")) {
                return ['error' => $error];
            }
        }
        $gap = $bankRate !== null ? round($amount * $bankRate - $inr, 2) : null;
        $choice = null;
        $reason = null;
        if ($gap !== null && abs($gap) > self::TOLERANCE_INR) {
            $choice = trim((string) ($post['rate_gap_choice'] ?? ''));
            $gapText = self::gapSentence($currency, $amount, $bankRate, $inr, $gap);
            if ($choice === '') {
                return ['error' => $gapText . ' Say why: the bank deducted its charges, a figure was typed wrong, or another reason.'];
            }
            if ($choice === 'typo') {
                return ['error' => $gapText . ' You said a figure was typed wrong: correct the amount, the INR credited or the bank\'s rate so they agree (within ₹1), then save again.'];
            }
            if ($choice === 'bank_charges') {
                if ($gap < 0) {
                    return ['error' => $gapText . ' The bank credited more than the amount at its rate, so it cannot be bank charges. Check the figures, or choose "Other reason".'];
                }
            } elseif ($choice === 'other') {
                $reason = trim((string) preg_replace('/\s+/', ' ', (string) ($post['rate_gap_reason'] ?? '')));
                if (mb_strlen($reason) < 10) {
                    return ['error' => 'Type the reason the INR credited differs from the bank\'s rate (at least 10 characters).'];
                }
                if (mb_strlen($reason) > 255) {
                    return ['error' => 'Keep the reason to 255 characters or fewer.'];
                }
            } else {
                return ['error' => 'Choose why the INR credited differs from the bank\'s rate.'];
            }
        }
        // The bank kept its charges in rupees: the receipt is worth amount × the bank's rate, the charges are the cost.
        $bookRate = $choice === 'bank_charges' ? ($inr + (float) $gap) / $amount : $effective;
        return ['inr' => $inr, 'rate' => round($bookRate, 4), 'effective_rate' => round($effective, 4), 'bank_rate' => $bankRate, 'gap' => $gap, 'choice' => $choice, 'reason' => $reason];
    }

    /** "At the bank's rate of ₹83.5000, USD 10,000.00 is ₹835,000.00, but ₹834,500.00 was credited — ₹500.00 less." */
    public static function gapSentence(string $currency, float $amount, float $bankRate, float $inr, float $gap): string
    {
        return sprintf("At the bank's rate of ₹%s, %s is ₹%s, but ₹%s was credited — ₹%s %s.",
            number_format($bankRate, 4), PartPaymentService::money($currency, $amount), number_format(round($amount * $bankRate, 2), 2),
            number_format($inr, 2), number_format(abs($gap), 2), $gap > 0 ? 'less' : 'more');
    }

    /** Rule D-212: what it is, for which order and party, the figures it comes from, the bank reference. */
    public static function gapChargesDescription(array $order, int $orderId, string $label, float $amount, float $bankRate, float $inr, float $gap, string $bankRef): string
    {
        $currency = (string) ($order['currency_code'] ?? '');
        $orderRef = $order['buyer_inquiry_ref'] ?? $order['order_reference'] ?? "#{$orderId}";
        return 'Bank charges deducted in rupees from ' . ($order['company_legal_name'] ?? 'the buyer') . "'s " . strtolower($label) . ' remittance for order ' . $orderRef
            . ': ' . PartPaymentService::money($currency, $amount) . " at the bank's rate ₹" . number_format($bankRate, 4) . ' = ₹' . number_format(round($amount * $bankRate, 2), 2)
            . ', credited ₹' . number_format($inr, 2) . ', charges ₹' . number_format($gap, 2) . ' · bank ref ' . trim($bankRef);
    }

    /**
     * After the receipt row is written with its INR fields (same
     * transaction): for "the bank deducted its charges", books the gap as a
     * Bank Charges order cost linked to the receipt; "other" is audited.
     */
    public static function afterReceipt(int $orderId, string $leg, ?array $milestone, int $receiptId, ?array $inr, float $amount, string $receivedOn, string $bankRef, int $userId): ?int
    {
        if ($inr === null) {
            return null;
        }
        $what = $leg . ($milestone !== null ? ':' . $milestone['seq'] : '');
        if ($inr['choice'] === 'bank_charges') {
            $order = OrderRepository::find($orderId) ?? [];
            $description = self::gapChargesDescription($order, $orderId, PartPaymentService::label($leg, $milestone), $amount, (float) $inr['bank_rate'], (float) $inr['inr'], (float) $inr['gap'], $bankRef);
            // Bank Charges cost entries are not sent to Zoho as expenses (29 Oct rule): the charges go with the customer payment.
            $costId = OrderCostEntryRepository::create($orderId, 'bank_charges', $description, (float) $inr['gap'], substr($receivedOn, 0, 10), $userId, 'company_account');
            Database::connection()->prepare('UPDATE order_payment_receipts SET rate_gap_cost_id = :c WHERE id = :id')->execute(['c' => $costId, 'id' => $receiptId]);
            AuditLogRepository::log($userId, 'REMITTANCE_INR_GAP_BANK_CHARGES', 'order_payment_receipts', $receiptId, $what, null, (string) $inr['gap'], $description);
            return $costId;
        }
        if ($inr['choice'] === 'other') {
            AuditLogRepository::log($userId, 'REMITTANCE_INR_GAP_EXPLAINED', 'order_payment_receipts', $receiptId, $what, $inr['bank_rate'] !== null ? (string) $inr['bank_rate'] : null, (string) $inr['inr'], (string) $inr['reason']);
        }
        return null;
    }

    /**
     * The leg's INR actual from its receipts: the sum of the rupees credited
     * when every receipt has them, else null (not known yet — the register
     * shows an estimate at the recorded rate).
     *
     * @param list<array<string,mixed>> $receipts
     */
    public static function legInrActual(array $receipts): ?float
    {
        if ($receipts === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($receipts as $r) {
            if (($r['inr_credited'] ?? null) === null) {
                return null;
            }
            $sum += (float) $r['inr_credited'];
        }
        return round($sum, 2);
    }

    /** What the success message says about the rupees. */
    public static function statement(string $currency, float $amount, ?array $inr, float $rate): string
    {
        if ($inr === null || $inr['inr'] === null) {
            return sprintf('Received %s at the exchange rate of ₹%s (≈ ₹%s, estimated until the INR credited is known).',
                PartPaymentService::money($currency, $amount), number_format($rate, 4), number_format($amount * $rate, 2));
        }
        $text = sprintf('Received %s; ₹%s credited by the bank — exchange rate worked out ₹%s.',
            PartPaymentService::money($currency, $amount), number_format((float) $inr['inr'], 2), number_format((float) $inr['effective_rate'], 4));
        if ($inr['choice'] === 'bank_charges') {
            $text .= ' ₹' . number_format((float) $inr['gap'], 2) . " the bank kept (at its rate of ₹" . number_format((float) $inr['bank_rate'], 4) . ') is recorded as an order cost "Bank Charges".';
        } elseif ($inr['choice'] === 'other') {
            $text .= ' Difference from the bank\'s rate: ₹' . number_format(abs((float) $inr['gap']), 2) . ' — ' . $inr['reason'] . '.';
        }
        return $text;
    }

    /** The rupees on a leg's (or milestone's) receipts the bank kept, booked as Bank Charges (D-244). */
    public static function gapChargesInr(int $orderId, string $leg, ?int $milestoneId = null): float
    {
        $stmt = Database::connection()->prepare("SELECT COALESCE(SUM(rate_gap_inr), 0) FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m AND rate_gap_choice = 'bank_charges'");
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
