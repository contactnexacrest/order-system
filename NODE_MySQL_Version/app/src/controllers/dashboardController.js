'use strict';

const dashboardRepository = require('../repositories/dashboardRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Port of App\Controllers\DashboardController. Spec Section 16 — the real
 * dashboard (Phase E). Every widget is independently permission-gated
 * rather than gating the whole page on one permission, since a
 * Reviewer/Auditor role should still see "my pending reviews" without
 * seeing order-level financials.
 */
async function index(req, res) {
  const user = req.user;
  const permissions = req.permissions || {};

  const canManageOrders = !!permissions.manage_orders;
  const canViewReports = !!permissions.view_reports;
  const canApproveEmail = !!permissions.approve_email_send;

  const searchTerm = String(req.query.q || '').trim();

  const data = {
    user,
    canManageOrders,
    stageBreakdown: [],
    activeOrderCount: 0,
    myPendingReviewCount: await dashboardRepository.pendingReviewCount(user.id),
    pendingEmailApprovalCount: canApproveEmail ? await dashboardRepository.pendingEmailApprovalCount() : null,
    pendingAmendmentApprovalCount: null,
    overdueBalance: [],
    overdueFreight: [],
    complianceWarnings: { lut: null, rcmc: null },
    recentActivity: [],
    searchTerm,
    searchResults: [],
  };

  if (canManageOrders) {
    data.stageBreakdown = await dashboardRepository.activeOrdersByStage();
    data.activeOrderCount = await dashboardRepository.activeOrderCount();
    data.pendingAmendmentApprovalCount = await dashboardRepository.pendingAmendmentApprovalCount();
    data.overdueBalance = await dashboardRepository.overdueBalancePayments();
    data.overdueFreight = await dashboardRepository.overdueFreightPayments();
    data.complianceWarnings = await dashboardRepository.complianceExpiryWarnings();

    if (searchTerm !== '') {
      data.searchResults = await dashboardRepository.search(searchTerm);
    }
  }

  if (canViewReports) {
    data.recentActivity = await auditLogRepository.search({ limit: 15, offset: 0 });
  }

  res.renderView('dashboard/index', data, 'layout/base');
}

module.exports = { index };
