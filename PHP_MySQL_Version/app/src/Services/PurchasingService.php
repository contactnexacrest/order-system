<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-214 (2026-10-31), phase 3: approval limits. An award
 * above the approval limit of the designation the person is working as
 * waits for someone with "Approve purchases" whose own limit covers it
 * (no limit set = any amount); nobody approves their own award unless the
 * maker-checker rule allows it. Port: purchasingService.js.
 */
final class PurchasingService
{
    /** The award's value: unit price × quantity, with GST. */
    public static function awardValue(array $quote, float $quantity): float
    {
        return round((float) $quote['unit_price_inr'] * $quantity * (1 + (float) $quote['gst_percent'] / 100), 2);
    }

    /** The approval limit of the designation this person is working as; null = none set (no limit). */
    public static function limitFor(int $userId): ?float
    {
        $m = ActiveDepartmentService::current($userId);
        return $m !== null && $m['approval_limit_inr'] !== null ? (float) $m['approval_limit_inr'] : null;
    }

    public static function needsApproval(int $userId, float $value): bool
    {
        $limit = self::limitFor($userId);
        return $limit !== null && $value > $limit;
    }

    /** @return string|null why this person may not approve this award, or null when they may */
    public static function approvalRefusal(int $userId, ?int $roleId, array $requirement, float $value): ?string
    {
        if (!PermissionService::can($userId, $roleId, 'approve_purchases')) {
            return 'Approving a purchase needs the "Approve purchases" permission.';
        }
        $limit = self::limitFor($userId);
        if ($limit !== null && $value > $limit) {
            return 'This award (₹' . number_format($value, 2) . ') is above your approval limit of ₹' . number_format($limit, 2) . ' — a higher approver must approve it.';
        }
        if ((int) $requirement['awarded_by'] === $userId && !MakerCheckerGuard::selfApprovalAllowed($userId)) {
            return 'You awarded this purchase, so someone else must approve it.';
        }
        return null;
    }
}
