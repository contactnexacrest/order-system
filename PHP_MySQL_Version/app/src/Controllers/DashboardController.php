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

        if ($canViewReports) {
            $data['recentActivity'] = AuditLogRepository::search(null, null, null, null, null, null, 15, 0);
        }

        View::render('dashboard/index', $data, 'layout/base');
    }
}
