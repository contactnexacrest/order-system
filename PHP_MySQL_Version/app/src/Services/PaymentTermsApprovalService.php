<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;

/**
 * QA Round 5, R5-03 (owner decision 2026-10-28, Option A): an order on a
 * payment preset marked "Requires MD approval" needs its payment terms
 * approved — by someone holding "Approve payment terms" (default Admin,
 * MD, ED) — before its Quotation can be generated or sent. The preset's
 * flag is read live, so unticking it in Admin → Payment Presets lifts the
 * requirement for orders not yet quoted.
 */
final class PaymentTermsApprovalService
{
    /**
     * @return array{required: bool, approved: bool, approved_at: ?string, approved_by_name: ?string, preset_name: ?string}
     */
    public static function state(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pp.requires_md_approval, pp.preset_name, o.payment_terms_approved_at, u.name AS approved_by_name
             FROM orders o
             JOIN payment_presets pp ON pp.id = o.payment_preset_id
             LEFT JOIN users u ON u.id = o.payment_terms_approved_by
             WHERE o.id = :id'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch();
        $required = $row && (int) $row['requires_md_approval'] === 1;
        return [
            'required'         => $required,
            'approved'         => $required && $row['payment_terms_approved_at'] !== null,
            'approved_at'      => $row['payment_terms_approved_at'] ?? null,
            'approved_by_name' => $row['approved_by_name'] ?? null,
            'preset_name'      => $row['preset_name'] ?? null,
        ];
    }

    public static function isPending(int $orderId): bool
    {
        $state = self::state($orderId);
        return $state['required'] && !$state['approved'];
    }

    public static function pendingMessage(int $orderId): string
    {
        $preset = self::state($orderId)['preset_name'] ?? 'this payment preset';
        return "The payment terms on this order (\"{$preset}\") need MD/ED approval before the Quotation can be generated or sent — approve them in Step 1, or ask someone who can.";
    }

    public static function approve(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE orders SET payment_terms_approved_by = :user_id, payment_terms_approved_at = NOW() WHERE id = :id'
        )->execute(['user_id' => $userId, 'id' => $orderId]);
        AuditLogRepository::log($userId, 'PAYMENT_TERMS_APPROVED', 'orders', $orderId, 'payment_terms_approved_at', null, date('Y-m-d H:i:s'));
    }
}
