<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\OrderCostEntryRepository;

/**
 * Owner request (3 Nov 2026): Step 13f (Close Order — document despatch) takes the courier charges for
 * the original document set at the same time as the tracking number, so the cost is never forgotten. When
 * charges are entered, an order cost "Courier Charges" is booked at once, described from the facts (owner
 * rule D-212), paid from the account chosen (Company account by default), and goes to Zoho Books at the
 * next sync like any other cost entry. Node twin: src/services/courierCharges.js.
 */
final class CourierCharges
{
    public const MAX_INR = 100000.0;

    /**
     * Reads the optional courier fields of the 13f form.
     *
     * @param array<string,mixed> $post
     * @return array{amount:float,company:string,paid_from:string}|string|null null when no charges were entered
     */
    public static function parse(array $post): array|string|null
    {
        $raw = trim((string) ($post['courier_charges_inr'] ?? ''));
        $company = mb_substr(trim((string) ($post['courier_company'] ?? '')), 0, 100);
        $paidFrom = (string) ($post['courier_paid_from'] ?? 'company_account');
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw < 0) {
            return 'Courier charges must be a number of rupees (0 or more), or leave the box blank.';
        }
        $amount = round((float) $raw, 2);
        if ($amount > self::MAX_INR) {
            return 'Courier charges of ₹' . number_format($amount, 2) . ' look too high for one document courier (more than ₹' . number_format(self::MAX_INR, 2) . '). Check the courier bill.';
        }
        if ($amount <= 0.0) {
            return null;
        }
        if (!isset(OrderCostEntryRepository::PAID_FROM[$paidFrom])) {
            return 'Choose what the courier charges were paid from.';
        }
        return ['amount' => $amount, 'company' => $company, 'paid_from' => $paidFrom];
    }

    /** Owner rule D-212: what it is, for which order and party, who carried it and the AWB. */
    public static function description(array $order, int $orderId, string $courier, string $tracking, float $amount): string
    {
        $orderRef = $order['buyer_inquiry_ref'] ?? $order['order_reference'] ?? "#{$orderId}";
        $buyer = $order['company_legal_name'] ?? 'the buyer';
        $carrier = trim($courier) !== '' ? trim($courier) . ' AWB ' : 'AWB ';
        return 'Courier charges for the original document set of order ' . $orderRef . ' to ' . $buyer . ' — '
            . $carrier . trim($tracking) . ', ₹' . number_format($amount, 2) . ' incl. GST';
    }

    /** Books the cost and audits it; returns the cost entry id. */
    public static function book(array $order, int $orderId, array $charges, string $tracking, int $userId): int
    {
        $description = self::description($order, $orderId, $charges['company'], $tracking, $charges['amount']);
        $costId = OrderCostEntryRepository::create($orderId, 'courier', $description, $charges['amount'], date('Y-m-d'), $userId, $charges['paid_from']);
        AuditLogRepository::log($userId, 'COURIER_CHARGES_RECORDED', 'order_cost_entries', $costId, 'amount_inr', null, number_format($charges['amount'], 2, '.', ''), $description);
        return $costId;
    }
}
