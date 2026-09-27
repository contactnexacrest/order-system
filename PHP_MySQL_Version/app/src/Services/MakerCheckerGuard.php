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
}
