<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderRepository;

/**
 * Owner decision (2 Nov 2026, "18 yes"): when a buyer remittance (advance,
 * freight, balance or a payment milestone) differs from what is due, the
 * person recording it says why:
 *  - bank_charges: the banks deducted charges on the way. The leg counts as
 *    paid in full (received + charges); the charges become an order cost
 *    ("Bank Charges") and go to Zoho Books with the customer payment, so the
 *    invoice is settled in full there too.
 *  - short: the buyer paid less. Recorded as received; the shortfall is
 *    cleared later with a manager's reason (gap list G-5).
 *  - excess: the buyer paid more. Recorded as received.
 * A difference of more than 1% (at least 1 unit) on the first receipt of a
 * leg, or any amount above what is still due, must have a reason.
 */
final class RemittanceDifference
{
    public const REASONS = [
        'bank_charges' => 'Bank charges deducted by the banks',
        'short'        => 'Buyer paid less (short payment)',
        'excess'       => 'Buyer paid more',
    ];

    /** Owner follow-up (2 Nov 2026): how each admin-managed reason is treated. */
    public const TREATMENTS = [
        'bank_charges' => 'Bank charges deducted by the banks',
        'short'        => 'Buyer paid less (short payment)',
        'excess'       => 'Buyer paid more',
    ];

    /** The last drop-down option: a reason typed in by hand, with how to treat it. */
    public const OTHER = 'other';

    /**
     * The admin-managed reasons ("Why is it different?" drop-down), in their
     * order. Owner follow-up on point 18 (2 Nov 2026).
     *
     * @return list<array{id:int,label:string,treatment:string,sort_order:int,is_active:int}>
     */
    public static function reasons(bool $activeOnly = true): array
    {
        $rows = Database::connection()->query('SELECT id, label, treatment, sort_order, is_active FROM remittance_difference_reasons'
            . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id')->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'treatment' => (string) $r['treatment'],
            'sort_order' => (int) $r['sort_order'], 'is_active' => (int) $r['is_active']], $rows);
    }

    /**
     * Reads the posted "Why is it different?" choice: '' (amount as due), a
     * reason id from the list, or 'other' with a typed reason and how to treat it.
     *
     * @return array{treatment:?string, reason_id:?int, reason_text:?string, custom:bool, error?:string}
     */
    public static function resolvePosted(array $post): array
    {
        $none = ['treatment' => null, 'reason_id' => null, 'reason_text' => null, 'custom' => false];
        $choice = trim((string) ($post['difference_reason_choice'] ?? ''));
        if ($choice === '') {
            return $none;
        }
        if ($choice === self::OTHER) {
            $text = trim((string) preg_replace('/\s+/', ' ', (string) ($post['difference_reason_other'] ?? '')));
            $treatment = trim((string) ($post['difference_treatment'] ?? ''));
            if (mb_strlen($text) < 10) {
                return $none + ['error' => 'Type the reason the amount is different (at least 10 characters), or choose one from the list.'];
            }
            if (mb_strlen($text) > 255) {
                return $none + ['error' => 'Keep the reason to 255 characters or fewer.'];
            }
            if (!isset(self::TREATMENTS[$treatment])) {
                return $none + ['error' => 'Choose how to treat the difference: bank charges, buyer paid less, or buyer paid more.'];
            }
            return ['treatment' => $treatment, 'reason_id' => null, 'reason_text' => $text, 'custom' => true];
        }
        if (!ctype_digit($choice)) {
            return $none + ['error' => 'Choose a reason from the list, or "Other — type the reason".'];
        }
        $stmt = Database::connection()->prepare('SELECT id, label, treatment FROM remittance_difference_reasons WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => (int) $choice]);
        $row = $stmt->fetch();
        if (!$row) {
            return $none + ['error' => 'That reason is no longer in the list. Choose another one.'];
        }
        return ['treatment' => (string) $row['treatment'], 'reason_id' => (int) $row['id'], 'reason_text' => (string) $row['label'], 'custom' => false];
    }

    /**
     * Checks the posted reason against the numbers in one go and carries the
     * reason (id and text) along for the receipt and its description.
     */
    public static function evaluatePosted(array $post, string $label, string $currency, float $due, float $soFar, float $amount, ?float $chargesInput, ?float $maxPct = null): array
    {
        $posted = self::resolvePosted($post);
        if (isset($posted['error'])) {
            return ['error' => $posted['error']];
        }
        $eval = self::evaluate($label, $currency, $due, $soFar, $amount, $posted['treatment'], $chargesInput, $maxPct);
        if (isset($eval['error'])) {
            return $eval;
        }
        $own = self::postedOwnBankCharges($post);
        if (is_string($own)) {
            return ['error' => $own];
        }
        return $eval + ['reason_id' => $posted['reason_id'], 'reason_text' => $posted['treatment'] !== null ? $posted['reason_text'] : null, 'custom' => $posted['custom'], 'own_charges_inr' => $own];
    }

    /** The most "our bank's charges" one receipt may carry (₹) — a typo guard, not a business rule. */
    public const MAX_OWN_BANK_CHARGES_INR = 100000.0;

    /**
     * Owner (2 Nov 2026): "Our bank's charges on this credit" — the inward-remittance fee our own
     * bank debits separately (in rupees, incl. GST). Optional; null when left blank, else the
     * amount, or an error message.
     */
    public static function postedOwnBankCharges(array $post): float|string|null
    {
        $raw = trim((string) ($post['own_bank_charges_inr'] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw <= 0) {
            return "Our bank's charges must be an amount in rupees above 0 — or leave the field empty.";
        }
        $v = round((float) $raw, 2);
        if ($v > self::MAX_OWN_BANK_CHARGES_INR) {
            return "Our bank's charges of ₹" . number_format($v, 2) . ' look too high for one credit (more than ₹' . number_format(self::MAX_OWN_BANK_CHARGES_INR, 2) . '). Check the bank advice and enter the fee only.';
        }
        return $v;
    }

    /** Owner rule D-212: what it is, for which order and party, the amount, the bank reference. */
    public static function ownBankChargesDescription(array $order, int $orderId, string $label, float $chargesInr, string $bankRef): string
    {
        $orderRef = $order['buyer_inquiry_ref'] ?? $order['order_reference'] ?? "#{$orderId}";
        return "Our bank's inward remittance charges on " . ($order['company_legal_name'] ?? 'the buyer') . "'s " . strtolower($label)
            . ' remittance for order ' . $orderRef . ', ₹' . number_format($chargesInr, 2) . ' incl. GST · bank ref ' . trim($bankRef);
    }

    /** The first active reason of a treatment (the list's own order) — or null. */
    public static function reasonIdFor(string $treatment): ?int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM remittance_difference_reasons WHERE treatment = :t AND is_active = 1 ORDER BY sort_order, id LIMIT 1');
        $stmt->execute(['t' => $treatment]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public static function maxBankChargesPct(): float
    {
        $pct = (float) (CompanySettingsRepository::get('max_bank_charges_pct') ?? '3');
        return $pct > 0 ? $pct : 3.0;
    }

    /**
     * Checks the reason against the numbers.
     *
     * @param float $due      what the leg asks for in total (order currency)
     * @param float $soFar    what is already credited to the leg (received + charges)
     * @return array{error?: string, reason?: ?string, charges?: float, excess?: float, remaining?: float}
     */
    public static function evaluate(string $label, string $currency, float $due, float $soFar, float $amount, ?string $reason, ?float $chargesInput, ?float $maxPct = null): array
    {
        $reason = $reason !== null && isset(self::REASONS[$reason]) ? $reason : null;
        if ($due <= 0) {
            return ['reason' => null, 'charges' => 0.0, 'excess' => 0.0, 'remaining' => 0.0];
        }
        $remaining = round(max(0.0, $due - $soFar), 2);
        $diff = round($remaining - $amount, 2); // > 0: less arrived than still due; < 0: more
        $tolerance = max(1.0, $due * 0.01);
        $lower = strtolower($label);
        $money = static fn (float $v): string => PartPaymentService::money($currency, $v);

        if ($reason === null) {
            if (($soFar <= 0.005 && abs($diff) > $tolerance) || $diff < -0.005) {
                return ['error' => sprintf('The %s still due is %s. You entered %s — a difference of %s. Choose why it is different in the Reason list and save again.',
                    $lower, $money($remaining), $money($amount), $money(abs($diff)))];
            }
            return ['reason' => null, 'charges' => 0.0, 'excess' => 0.0, 'remaining' => $remaining];
        }
        if ($reason === 'bank_charges') {
            if ($diff <= 0.005) {
                return ['error' => "Bank charges apply only when less than the {$lower} still due arrived (" . $money($remaining) . '). Nothing is missing here — choose "Amount as due — no difference".'];
            }
            $charges = $chargesInput !== null && $chargesInput > 0 ? round($chargesInput, 2) : $diff;
            if ($charges > $diff + 0.005) {
                return ['error' => 'Bank charges of ' . $money($charges) . ' are more than the difference of ' . $money($diff) . ' between the ' . $lower . ' still due and what arrived.'];
            }
            $maxPct = $maxPct ?? self::maxBankChargesPct();
            if ($charges > round($due * $maxPct / 100, 2) + 0.005) {
                return ['error' => 'Bank charges of ' . $money($charges) . ' are more than ' . self::pct($maxPct) . '% of the ' . $lower . ' due (' . $money(round($due * $maxPct / 100, 2)) . '). If the buyer paid less, choose a "Buyer paid less" reason instead.'];
            }
            return ['reason' => 'bank_charges', 'charges' => $charges, 'excess' => 0.0, 'remaining' => $remaining];
        }
        if ($reason === 'short') {
            if ($diff <= 0.005) {
                return ['error' => 'Nothing is short: ' . $money($amount) . " covers the {$lower} still due (" . $money($remaining) . ').'];
            }
            return ['reason' => 'short', 'charges' => 0.0, 'excess' => 0.0, 'remaining' => $remaining];
        }
        if ($diff >= -0.005) {
            return ['error' => $money($amount) . " is not more than the {$lower} still due (" . $money($remaining) . ') — choose another reason, or "Amount as due — no difference".'];
        }
        return ['reason' => 'excess', 'charges' => 0.0, 'excess' => round(-$diff, 2), 'remaining' => $remaining];
    }

    /** What the screen and the success message say will happen. */
    public static function outcome(string $leg, string $label, string $currency, array $eval, float $amount, float $rate): string
    {
        $money = static fn (float $v): string => PartPaymentService::money($currency, $v);
        $lower = strtolower($label);
        return match ($eval['reason'] ?? null) {
            'bank_charges' => 'Bank charges ' . $money((float) $eval['charges']) . ' = ₹' . number_format(round((float) $eval['charges'] * $rate, 2), 2)
                . " recorded as an order cost — the {$lower} counts as received in full.",
            'short' => 'Short by ' . $money(round((float) $eval['remaining'] - $amount, 2)) . ' — '
                . ($leg === 'advance' ? 'record the rest when it arrives, or a manager clears the advance short and the difference is carried into the balance.'
                    : 'record the rest when it arrives, or a manager clears it short with a reason.'),
            'excess' => 'Excess ' . $money((float) $eval['excess']) . ' — '
                . ($leg === 'advance' ? 'the balance due goes down by the same amount.'
                    : 'settle it with the buyer (adjust against a later payment or refund).'),
            default => '',
        };
    }

    /**
     * After the receipt is saved (same transaction): the charges become an
     * order cost with a description built from the facts (owner rule D-212),
     * linked to the receipt, and the reason is audited.
     */
    public static function afterReceipt(int $orderId, string $leg, ?array $milestone, int $receiptId, array $eval, float $amount, float $rate, string $receivedOn, string $bankRef, int $userId): ?int
    {
        // Owner (2 Nov 2026): our own bank's fee on this credit, booked as an order cost now.
        if (!empty($eval['own_charges_inr'])) {
            $ownOrder = OrderRepository::find($orderId) ?? [];
            $ownInr = (float) $eval['own_charges_inr'];
            $ownDescription = self::ownBankChargesDescription($ownOrder, $orderId, PartPaymentService::label($leg, $milestone), $ownInr, $bankRef);
            $ownCostId = OrderCostEntryRepository::create($orderId, 'bank_charges', $ownDescription, $ownInr, substr($receivedOn, 0, 10), $userId, 'company_account');
            Database::connection()->prepare('UPDATE order_payment_receipts SET own_bank_charges_inr = :a, own_bank_charges_cost_id = :c WHERE id = :id')
                ->execute(['a' => $ownInr, 'c' => $ownCostId, 'id' => $receiptId]);
            AuditLogRepository::log($userId, 'REMITTANCE_OWN_BANK_CHARGES_RECORDED', 'order_payment_receipts', $receiptId, $leg . ($milestone !== null ? ':' . $milestone['seq'] : ''), null, (string) $ownInr, $ownDescription);
        }
        $reason = $eval['reason'] ?? null;
        if ($reason === null) {
            return null;
        }
        $order = OrderRepository::find($orderId) ?? [];
        $currency = (string) ($order['currency_code'] ?? '');
        $label = PartPaymentService::label($leg, $milestone);
        $reasonText = isset($eval['reason_text']) && $eval['reason_text'] !== null ? (string) $eval['reason_text'] : null;
        Database::connection()->prepare('UPDATE order_payment_receipts SET difference_reason_id = :rid, difference_reason_text = :rt, difference_reason_custom = :rc WHERE id = :id')
            ->execute(['rid' => $eval['reason_id'] ?? null, 'rt' => $reasonText, 'rc' => !empty($eval['custom']) ? 1 : 0, 'id' => $receiptId]);
        $costId = null;
        if ($reason === 'bank_charges') {
            $charges = (float) $eval['charges'];
            $chargesInr = round($charges * $rate, 2);
            $description = self::bankChargesDescription($order, $orderId, $label, $amount, (float) $eval['remaining'], $charges, $chargesInr, $bankRef, $reasonText);
            $costId = OrderCostEntryRepository::create($orderId, 'bank_charges', $description, $chargesInr, substr($receivedOn, 0, 10), $userId, 'company_account');
            Database::connection()->prepare('UPDATE order_payment_receipts SET bank_charges_cost_id = :c WHERE id = :id')->execute(['c' => $costId, 'id' => $receiptId]);
            AuditLogRepository::log($userId, 'REMITTANCE_BANK_CHARGES_RECORDED', 'order_payment_receipts', $receiptId, $leg . ($milestone !== null ? ':' . $milestone['seq'] : ''), null, (string) $chargesInr, $description);
        } else {
            AuditLogRepository::log($userId, $reason === 'short' ? 'REMITTANCE_SHORT_RECORDED' : 'REMITTANCE_EXCESS_RECORDED', 'order_payment_receipts', $receiptId,
                $leg . ($milestone !== null ? ':' . $milestone['seq'] : ''), (string) $eval['remaining'], (string) $amount, self::REASONS[$reason] . ($reasonText !== null ? ' — ' . $reasonText : ''));
        }
        return $costId;
    }

    /** Owner rule D-212: what it is, for which order and party, what it settles, the bank reference. */
    public static function bankChargesDescription(array $order, int $orderId, string $label, float $received, float $due, float $charges, float $chargesInr, string $bankRef, ?string $reasonText = null): string
    {
        $currency = (string) ($order['currency_code'] ?? '');
        $orderRef = $order['buyer_inquiry_ref'] ?? $order['order_reference'] ?? "#{$orderId}";
        return 'Bank charges deducted from ' . ($order['company_legal_name'] ?? 'the buyer') . "'s " . strtolower($label) . ' remittance for order ' . $orderRef
            . ': received ' . PartPaymentService::money($currency, $received) . ' of ' . PartPaymentService::money($currency, $due) . ' due, charges '
            . PartPaymentService::money($currency, $charges) . ' (₹' . number_format($chargesInr, 2) . ') · bank ref ' . trim($bankRef)
            . ($reasonText !== null && $reasonText !== '' ? ' · Reason: ' . $reasonText : '');
    }

    /**
     * Owner follow-up (2 Nov 2026): reasons typed in by hand, most used first,
     * so Admin / Finance can add the frequent ones to the list.
     *
     * @return list<array{reason:string, treatment:string, uses:int, last_used:string}>
     */
    public static function customReasonsUsed(): array
    {
        return Database::connection()->query(
            "SELECT difference_reason_text AS reason, difference_reason AS treatment, COUNT(*) AS uses, MAX(created_at) AS last_used
               FROM order_payment_receipts WHERE difference_reason_custom = 1 AND difference_reason_text IS NOT NULL
              GROUP BY difference_reason_text, difference_reason ORDER BY uses DESC, last_used DESC LIMIT 200"
        )->fetchAll();
    }

    /** The charges in rupees on a leg's (or milestone's) receipts — sent to Zoho Books with the payment. */
    public static function chargesInr(int $orderId, string $leg, ?int $milestoneId = null): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(bank_charges_inr), 0) FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m');
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
