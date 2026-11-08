<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DashboardRepository;
use App\Services\AuthService;
use App\Services\PermissionService;

/**
 * Spec Section 16 — the real dashboard (Phase E). The Phase A stub just
 * listed every order in a flat table; this replaces it with the actual
 * spec'd widgets. Every widget is independently permission-gated rather
 * than gating the whole page on one permission, since a Reviewer/Auditor
 * role should still see "my pending reviews" without seeing order-level
 * financials.
 */
final class DashboardController
{
    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) $user['id'];
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        $canManageOrders = PermissionService::can($userId, $roleId, 'manage_orders');
        $canViewReports = PermissionService::can($userId, $roleId, 'view_reports');
        $canApproveEmail = PermissionService::can($userId, $roleId, 'approve_email_send');

        $data = [
            'user' => $user,
            'canManageOrders' => $canManageOrders,
            'stageBreakdown' => [],
            'activeOrderCount' => 0,
            'myPendingReviewCount' => DashboardRepository::pendingReviewCount($userId),
            'pendingEmailApprovalCount' => $canApproveEmail ? DashboardRepository::pendingEmailApprovalCount() : null,
            'pendingAmendmentApprovalCount' => null,
            'overdueBalance' => [],
            'overdueFreight' => [],
            'complianceWarnings' => ['lut' => null, 'rcmc' => null],
            'recentActivity' => [],
            'searchTerm' => trim((string) ($_GET['q'] ?? '')),
            'searchResults' => [],
        ];

        if ($canManageOrders) {
            $data['stageBreakdown'] = DashboardRepository::activeOrdersByStage();
            $data['activeOrderCount'] = DashboardRepository::activeOrderCount();
            $data['pendingAmendmentApprovalCount'] = DashboardRepository::pendingAmendmentApprovalCount();
            $data['overdueBalance'] = DashboardRepository::overdueBalancePayments();
            $data['overdueFreight'] = DashboardRepository::overdueFreightPayments();
            $data['complianceWarnings'] = DashboardRepository::complianceExpiryWarnings();

            if ($data['searchTerm'] !== '') {
                $data['searchResults'] = DashboardRepository::search($data['searchTerm']);
            }
        }

        // Owner decision D-245 P4: the MD sees how many orders are ready to lock (nothing locks by itself).
        $data['incentiveReadyCount'] = PermissionService::can($userId, $roleId, 'manage_pricing') && PermissionService::can($userId, $roleId, 'manage_incentives')
            ? count(\App\Services\IncentiveService::readyToLock()) : null;

        // D-261: the MD is asked "Ready for the Quoting level?" for Starter associates with enough completed, fully paid orders.
        // D-263: gated by the "Manage associates" permission (same as the associate page), not by the MD role.
        $data['associateUpgrades'] = PermissionService::can($userId, $roleId, 'manage_associates') ? \App\Services\AssociateAccess::upgradeSuggestions() : [];

        if ($canViewReports) {
            // D-268: the full app-wide feed only with the audit-log permission (Admin/MD); everyone else sees
            // their own actions plus actions on the orders they can open (the Orders list's visibility rule).
            $rows = PermissionService::can($userId, $roleId, 'view_audit_log')
                ? AuditLogRepository::search(null, null, null, null, null, null, 15, 0)
                : AuditLogRepository::recentVisibleTo($userId, self::openableOrderIds(), 15);
            $data['recentActivity'] = \App\Services\AuditMoneyMask::maskAll($rows);
        }

        View::render('dashboard/index', $data, 'layout/base');
    }

    /** @return list<int> D-268: the orders the signed-in user can open — the Orders list's rule (division, assignment, archived). */
    private static function openableOrderIds(): array
    {
        [$visSql, $visParams] = \App\Services\OrderAccessService::visibilitySql('o');
        $stmt = \App\Config\Database::connection()->prepare('SELECT o.id FROM orders o WHERE 1 = 1' . $visSql);
        $stmt->execute($visParams);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
