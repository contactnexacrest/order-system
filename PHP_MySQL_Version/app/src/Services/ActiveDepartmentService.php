<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\DepartmentRepository;

/**
 * Owner decision D-214 (2026-10-31), phase 1: "Log in as". The department
 * membership a person is working as this session decides their role, and so
 * every permission check (PermissionService::can uses effectiveRoleId()).
 * A person with no membership keeps their own role. Admin's "Preview as" a
 * designation (and MD/ED's "view as" a department) borrows that
 * designation's role for the session until they exit. Port:
 * activeDepartmentService.js.
 */
final class ActiveDepartmentService
{
    public const SESSION_MEMBERSHIP = '_active_membership_id';
    public const SESSION_PREVIEW = '_preview_designation_id';

    /** @var array<string,mixed>|null|false per-request cache (false = looked up, none) */
    private static array|null|false $membership = null;
    private static ?int $membershipFor = null;
    /** @var array<string,mixed>|null|false */
    private static array|null|false $preview = null;

    public static function resetCache(): void
    {
        self::$membership = null;
        self::$membershipFor = null;
        self::$preview = null;
    }

    /**
     * After the password (and 2FA) check: one membership is chosen
     * straight away, so is the remembered default; otherwise the person is
     * asked. Returns the page to go to next, or null when nothing is needed.
     */
    public static function afterLogin(int $userId): ?string
    {
        unset($_SESSION[self::SESSION_MEMBERSHIP], $_SESSION[self::SESSION_PREVIEW]);
        self::resetCache();
        $memberships = DepartmentRepository::activeMembershipsForUser($userId);
        if ($memberships === []) {
            return null;
        }
        $default = array_values(array_filter($memberships, static fn (array $m): bool => (int) $m['is_default'] === 1));
        if (count($memberships) === 1 || $default !== []) {
            self::activate($userId, (int) ($default[0]['id'] ?? $memberships[0]['id']), false);
            return null;
        }
        return '/choose-department';
    }

    /** Whether this person still has to pick a department before using the app. */
    public static function needsChoice(int $userId): bool
    {
        if (self::current($userId) !== null) {
            return false;
        }
        return DepartmentRepository::activeMembershipsForUser($userId) !== [];
    }

    /** Starts working as one of this person's own current memberships. @return bool false when it is not theirs or not current */
    public static function activate(int $userId, int $membershipId, bool $logSwitch = true): bool
    {
        foreach (DepartmentRepository::activeMembershipsForUser($userId) as $m) {
            if ((int) $m['id'] === $membershipId) {
                $previous = self::current($userId);
                $_SESSION[self::SESSION_MEMBERSHIP] = $membershipId;
                unset($_SESSION[self::SESSION_PREVIEW]);
                self::resetCache();
                PermissionService::resetCache();
                AuditLogRepository::log($userId, $logSwitch ? 'DEPARTMENT_SWITCHED' : 'DEPARTMENT_SELECTED', 'user_department_memberships', $membershipId, 'acting_as',
                    $previous !== null ? self::label($previous) : null, self::label($m));
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null the membership this session works as (re-checked every request: an ended membership stops at once) */
    public static function current(int $userId): ?array
    {
        if (self::$membershipFor !== $userId || self::$membership === null) {
            self::$membershipFor = $userId;
            self::$membership = false;
            $id = $_SESSION[self::SESSION_MEMBERSHIP] ?? null;
            if ($id !== null) {
                foreach (DepartmentRepository::activeMembershipsForUser($userId) as $m) {
                    if ((int) $m['id'] === (int) $id) {
                        self::$membership = $m;
                    }
                }
                if (self::$membership === false) {
                    unset($_SESSION[self::SESSION_MEMBERSHIP]);
                }
            }
        }
        return self::$membership === false ? null : self::$membership;
    }

    /** Admin "Preview as" / MD-ED "view as": the designation borrowed for this session. @return array<string,mixed>|null */
    public static function preview(): ?array
    {
        if (self::$preview === null) {
            $id = $_SESSION[self::SESSION_PREVIEW] ?? null;
            self::$preview = $id !== null ? (DepartmentRepository::findDesignation((int) $id) ?? false) : false;
        }
        return self::$preview === false ? null : self::$preview;
    }

    public static function startPreview(int $userId, int $designationId): bool
    {
        $designation = DepartmentRepository::findDesignation($designationId);
        if ($designation === null) {
            return false;
        }
        $_SESSION[self::SESSION_PREVIEW] = $designationId;
        self::resetCache();
        PermissionService::resetCache();
        AuditLogRepository::log($userId, 'DEPARTMENT_PREVIEW_STARTED', 'department_designations', $designationId, null, null, $designation['title'] . ' · ' . $designation['department_name']);
        return true;
    }

    public static function endPreview(int $userId): void
    {
        $designation = self::preview();
        unset($_SESSION[self::SESSION_PREVIEW]);
        self::resetCache();
        PermissionService::resetCache();
        if ($designation !== null) {
            AuditLogRepository::log($userId, 'DEPARTMENT_PREVIEW_ENDED', 'department_designations', (int) $designation['id']);
        }
    }

    /**
     * The role every permission check of the signed-in person uses: the
     * previewed designation's, else the active membership's, else their own.
     */
    public static function effectiveRoleId(int $userId, ?int $ownRoleId): ?int
    {
        if (AuthService::currentUserId() !== $userId) {
            return $ownRoleId;
        }
        $preview = self::preview();
        if ($preview !== null) {
            return (int) $preview['role_id'];
        }
        $m = self::current($userId);
        return $m !== null ? (int) $m['role_id'] : $ownRoleId;
    }

    /** Whether the signed-in person is previewing (a preview never carries Super Admin rights). */
    public static function isPreviewing(int $userId): bool
    {
        return AuthService::currentUserId() === $userId && self::preview() !== null;
    }

    /** "Procurement Manager · Procurement" — what the audit log records the person acted as. */
    public static function actingLabel(int $userId): ?string
    {
        if (AuthService::currentUserId() !== $userId) {
            return null;
        }
        $preview = self::preview();
        if ($preview !== null) {
            return 'Preview: ' . $preview['title'] . ' · ' . $preview['department_name'];
        }
        $m = self::current($userId);
        return $m !== null ? self::label($m) : null;
    }

    /** @param array<string,mixed> $m */
    public static function label(array $m): string
    {
        return $m['designation_title'] . ' · ' . $m['department_name'];
    }
}
