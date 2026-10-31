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
    /**
     * The approval limit (null = no limit). QA Round 7, R7-N01: the limit
     * applies whatever the department state. The designation the person is
     * working as this session (or previewing) decides; with none chosen,
     * the designation(s) tied to their own role (the lowest limit). No limit
     * set means "any amount" only at director level; below that, and for a
     * role with no designation, it fails closed (0 = nothing self-approves),
     * except for Admin / Super Admin.
     */
    public static function limitFor(int $userId): ?float
    {
        $m = ActiveDepartmentService::current($userId) ?? ActiveDepartmentService::preview();
        if ($m !== null) {
            return self::designationLimit($m);
        }
        $user = \App\Repositories\UserRepository::findById($userId);
        if ($user === null) {
            return 0.0;
        }
        $stmt = \App\Config\Database::connection()->prepare(
            'SELECT dd.level, dd.approval_limit_inr FROM department_designations dd WHERE dd.role_id = :r AND dd.is_active = 1'
        );
        $stmt->execute(['r' => (int) ($user['role_id'] ?? 0)]);
        $limits = array_map(static fn (array $dd): ?float => self::designationLimit($dd), $stmt->fetchAll(\PDO::FETCH_ASSOC));
        if ($limits === []) {
            $role = $user['role_id'] !== null ? \App\Repositories\RoleRepository::find((int) $user['role_id']) : null;
            return !empty($user['is_super_admin']) || ($role['name'] ?? null) === 'Admin' ? null : 0.0;
        }
        $numeric = array_filter($limits, static fn (?float $l): bool => $l !== null);
        return $numeric === [] ? null : min($numeric);
    }

    /** A designation's limit: its own figure; none set = any amount for a director, nothing below that. */
    private static function designationLimit(array $dd): ?float
    {
        if ($dd['approval_limit_inr'] !== null) {
            return (float) $dd['approval_limit_inr'];
        }
        return ($dd['level'] ?? null) === 'director' ? null : 0.0;
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
