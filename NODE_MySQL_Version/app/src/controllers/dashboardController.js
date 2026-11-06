'use strict';

const auditMoneyMask = require('../services/auditMoneyMask');

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

  // Owner decision D-245 P4: the MD sees how many orders are ready to lock (nothing locks by itself).
  // D-261: the MD is asked "Ready for the Quoting level?" for Starter associates with enough completed, fully paid orders.
  data.associateUpgrades = (await require('../services/incentiveService').isMd(req.user)) ? await require('../services/associateAccess').upgradeSuggestions() : [];
  data.incentiveReadyCount = (permissions.manage_pricing && permissions.manage_incentives)
    ? (await require('../services/incentiveService').readyToLock()).length : null;
  if (data.incentiveReadyCount) {
    data.incentiveNextRun = await require('../services/associateCommission').payoutRunOn(new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10));
  }

  if (canViewReports) {
    data.recentActivity = await auditMoneyMask.maskAll(req.permissions, await auditLogRepository.search({ limit: 15, offset: 0 }));
  }

  data.agreementsExpiring = await require('./agreementController').expiringSoon(req);
  data.agreementPartyTypes = require('../repositories/agreementRepository').PARTY_TYPES;
  res.renderView('dashboard/index', data, 'layout/base');
}

module.exports = { index };
