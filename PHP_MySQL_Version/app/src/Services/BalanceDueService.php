<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;

/**
 * Gap list G-2 (owner, 1 Nov 2026, register D-218): a balance paid after
 * the BL — the order's balance option "against BL", and every Letter of
 * Credit order — has no due date until the scanned BL is sent to the buyer
 * (Step 11b). From that day it is due within the order's balance days. A
 * "before shipment" balance keeps no fixed date (it is due on shipment
 * readiness).
 */
final class BalanceDueService
{
    public static function dueAfterBl(array $order): bool
    {
        return ($order['balance_trigger_option'] ?? null) === 'B_AGAINST_BL' || ($order['payment_method'] ?? null) === 'LC';
    }

    /** Sets the balance due date from the day the scanned BL was sent. Returns it, or null when not applicable. */
    public static function startFromScannedBl(int $orderId, string $sentOn): ?string
    {
        $order = OrderRepository::find($orderId);
        $payment = OrderPaymentStatusRepository::find($orderId);
        if ($order === null || $payment === null || !self::dueAfterBl($order) || $payment['balance_cleared_at'] !== null) {
            return null;
        }
        $days = $order['balance_days'] !== null ? (int) $order['balance_days'] : 7;
        $due = date('Y-m-d', strtotime($sentOn . ' +' . $days . ' days'));
        Database::connection()->prepare('UPDATE order_payment_status SET balance_due_date = :d WHERE order_id = :o')->execute(['d' => $due, 'o' => $orderId]);
        return $due;
    }
}
