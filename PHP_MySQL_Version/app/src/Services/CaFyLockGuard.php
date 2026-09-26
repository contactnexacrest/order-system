<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Flash;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaFyLockRepository;

/**
 * CA / Accounting module (Phase 7) — the one narrow exception to a
 * financial year lock. Reopening a whole year (CaFyLockRepository::unlock())
 * removes the lock for every CA write path until it's re-locked; this is
 * for the much narrower case where one specific, trusted person needs to
 * push through a single genuine backdated correction without reopening
 * the year for anyone else.
 *
 * Gated on the ca_fy_lock_override permission — a Super Admin already
 * satisfies this via PermissionService's unconditional bypass, so "the
 * override is always available to a Super Admin" needs no special-casing
 * here. Every use is logged to the audit log (action CA_FY_LOCK_OVERRIDDEN)
 * and flashed as a visible warning — never silent.
 */
final class CaFyLockGuard
{
    /**
     * @param string|null $date the record's own date (a leg's cleared_at,
     *        an expense's expense_date, ...) — never today's date.
     * @return bool true if the write may proceed (either the date isn't in
     *         a locked FY, or the override was used and logged); false if
     *         blocked (an error flash has already been set — the caller
     *         must not proceed with the write).
     */
    public static function allow(
        ?string $date,
        int $userId,
        ?int $roleId,
        string $entityType,
        int $entityId,
        string $field
    ): bool {
        $lockMessage = CaFyLockRepository::lockMessageForDate($date);
        if ($lockMessage === null) {
            return true;
        }
        if (!PermissionService::can($userId, $roleId, 'ca_fy_lock_override')) {
            Flash::set('error', $lockMessage);
            return false;
        }
        AuditLogRepository::log($userId, 'CA_FY_LOCK_OVERRIDDEN', $entityType, $entityId, $field, null, $lockMessage);
        Flash::set('warning', "Financial year lock overridden to record this ({$field}) — logged for audit.");
        return true;
    }

    /** For list-filtering: does this user see/act on locked-FY items at all? */
    public static function canOverride(int $userId, ?int $roleId): bool
    {
        return PermissionService::can($userId, $roleId, 'ca_fy_lock_override');
    }
}
