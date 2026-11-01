<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

/**
 * QA-5 maker-checker: the single choke point every self-approval check
 * (document review assignment, email-send approval, amendment MD-approval)
 * delegates to. Owner decision: the person who created/requested an item
 * must not also be the one who approves it — EXCEPT a Super Admin or
 * anyone holding manage_permissions, since that tier can already grant
 * itself any approval role through the permission system, so enforcing
 * separation on them is not a real control.
 */
final class MakerCheckerGuard
{
    public static function selfApprovalAllowed(int $userId): bool
    {
        if (SuperAdminService::isEffective($userId)) {
            return true;
        }
        $user = UserRepository::findById($userId);
        if (!$user) {
            return false;
        }
        return PermissionService::can($userId, $user['role_id'] !== null ? (int) $user['role_id'] : null, 'manage_permissions');
    }

    /**
     * Owner decision 1 Nov 2026 (A-1, Q-R6-1): the payment terms of an order
     * are approved by a second person; only the Managing Director or the
     * Executive Director may approve the terms of an order they created
     * themselves (the approval is then written to the audit log as a
     * self-approval). Admin and System Administrator no longer self-approve.
     */
    public static function paymentTermsSelfApprovalAllowed(int $userId): bool
    {
        if (SuperAdminService::isEffective($userId)) {
            return true;
        }
        $user = UserRepository::findById($userId);
        if (!$user) {
            return false;
        }
        $names = array_map(
            static fn (int $roleId): string => (string) (\App\Repositories\RoleRepository::find($roleId)['name'] ?? ''),
            GrantGuard::ownRoleIds($user)
        );
        return array_intersect($names, ['Managing Director', 'Executive Director']) !== [];
    }

    /**
     * External QA report N-04: order cancellations approve/reject through
     * this stricter variant instead of selfApprovalAllowed(), because the
     * default roles that can approve a cancellation (Admin, MD, ED) all
     * hold manage_permissions — so the general exemption made the
     * cancellation maker-checker rule a no-op for every role that can
     * actually use it. Only a Super Admin is exempt here.
     */
    public static function selfApprovalAllowedStrict(int $userId): bool
    {
        return SuperAdminService::isEffective($userId);
    }
}
