<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\SuperAdminService;

/**
 * Super Admin management: the permanent tier list, active/expired
 * delegations, and the actions to grant/revoke a delegation or
 * promote/demote the permanent flag. Gated by SuperAdminOnly, not a
 * `permissions` row — see that middleware's docblock.
 */
final class SuperAdminController
{
    public function index(array $params): void
    {
        View::render('super_admin/index', [
            'superAdmins' => SuperAdminService::permanentSuperAdmins(),
            'activeDelegations' => SuperAdminService::activeDelegations(),
            'history' => SuperAdminService::delegationHistory(),
            'allUsers' => UserRepository::listAllForAdmin(),
            'minReasonLength' => SuperAdminService::MIN_REASON_LENGTH,
        ], 'layout/base');
    }

    public function grantDelegation(array $params): void
    {
        $user = AuthService::currentUser();
        $delegateUserId = (int) ($_POST['delegate_user_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $expiresAt = trim((string) ($_POST['expires_at'] ?? '')) ?: null;

        try {
            SuperAdminService::grantDelegation($delegateUserId, (int) $user['id'], $reason, $expiresAt);
            Flash::set('success', 'Super Admin delegation granted.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /super-admin');
    }

    public function revokeDelegation(array $params): void
    {
        $user = AuthService::currentUser();
        $delegationId = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        try {
            SuperAdminService::revokeDelegation($delegationId, (int) $user['id'], $reason);
            Flash::set('success', 'Delegation revoked.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /super-admin');
    }

    public function setPermanent(array $params): void
    {
        $user = AuthService::currentUser();
        $targetUserId = (int) ($_POST['target_id'] ?? 0);
        $makeSuperAdmin = ($_POST['is_super_admin'] ?? '0') === '1';
        $reason = trim((string) ($_POST['reason'] ?? ''));

        try {
            SuperAdminService::setPermanentFlag($targetUserId, $makeSuperAdmin, (int) $user['id'], $reason);
            Flash::set('success', $makeSuperAdmin ? 'User promoted to Super Admin.' : 'Super Admin status removed.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /super-admin');
    }
}
