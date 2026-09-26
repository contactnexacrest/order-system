'use strict';

const caRepository = require('../repositories/caRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const userRepository = require('../repositories/userRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const financialYear = require('../helpers/financialYear');
const flash = require('../helpers/flash');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoBooksService = require('../services/zohoBooksService');
const caSyncService = require('../services/caSyncService');

// CA / Accounting module — independent of the order-pipeline system. Every
// route here is gated on ca_module_view (see server.js), never on
// manage_orders, so a CA/Accounts-only user can reach this without any
// order-management access.

async function usersById() {
  const map = {};
  for (const u of await userRepository.listActive()) {
    map[u.id] = u.name;
  }
  return map;
}

async function index(req, res) {
  res.renderView('ca/index', {
    settlements: await caRepository.settlementRegister(),
    usersById: await usersById(),
  }, 'layout/base');
}

/**
 * FY-wise or calendar-year-wise revenue report. Deliberately its own
 * report engine, sharing only the financialYear date-bucketing helper
 * with the order-pipeline Reports module — no shared tables, no shared
 * queries (Section 9 of the CA module brief: the two must never
 * intersect).
 */
async function reports(req, res) {
  const mode = req.query.mode === 'calendar' ? 'calendar' : 'fy';
  const availableFy = await caRepository.availableFinancialYears();
  const availableCalendar = await caRepository.availableCalendarYears();

  let period = String(req.query.period || '').trim();
  if (period === '') {
    period = mode === 'fy'
      ? (availableFy[0] || financialYear.current())
      : String(availableCalendar[0] || new Date().getFullYear());
  }

  const report = await caRepository.revenueReport(mode, period);

  res.renderView('ca/reports', {
    mode,
    period,
    availableFy,
    availableCalendar,
    report,
    usersById: await usersById(),
    gstin: await companySettingsRepository.get('gstin'),
    lutNumber: await companySettingsRepository.get('lut_number'),
    lutValidFy: await companySettingsRepository.get('lut_valid_fy'),
  }, 'layout/base');
}

/**
 * Zoho Books sync status page — config status, the "Sync Now" button, and
 * the independent zoho_sync_log history. Gated on ca_module_manage (see
 * server.js), a step up from ca_module_view, since this exposes sync
 * error detail and can call an external API.
 */
async function zohoSync(req, res) {
  res.renderView('ca/zoho_sync', {
    isEnabled: await zohoBooksService.isEnabled(),
    pendingCount: (await caRepository.legsPendingZohoSync()).length,
    log: await zohoSyncLogRepository.recent(100),
  }, 'layout/base');
}

async function runZohoSync(req, res) {
  const user = req.user;
  const result = await caSyncService.runFullSync('manual', user.id);
  const { revenue, expenses } = result;

  if (revenue.failed > 0 || expenses.failed > 0) {
    flash.set(req, 'warning', `Sync finished: ${revenue.synced} payment(s) pushed, ${expenses.imported} expense(s) imported, ${revenue.failed + expenses.failed} failed — see the log below for details.`);
  } else if (revenue.synced > 0 || expenses.imported > 0) {
    flash.set(req, 'success', `Sync finished: ${revenue.synced} payment(s) pushed, ${expenses.imported} expense(s) imported.`);
  } else {
    flash.set(req, 'success', "Sync ran — nothing was pending (or Zoho Books isn't configured yet). See the log below.");
  }
  res.redirect('/ca/zoho-sync');
}

/**
 * Expenses imported one-way from Zoho Books (Phase 4). No create/edit
 * path for the expense data itself here — only the local-only TDS
 * annotation, which never pushes back to Zoho.
 */
async function expenses(req, res) {
  res.renderView('ca/expenses', {
    expenses: await caExpenseRepository.all(),
    usersById: await usersById(),
    canEditTds: !!req.permissions.inr_actual_edit,
  }, 'layout/base');
}

async function setExpenseTds(req, res) {
  const id = parseInt(req.params.id, 10);
  const user = req.user;
  const isTdsApplicable = !!req.body.is_tds_applicable;
  const tdsAmount = isTdsApplicable && String(req.body.tds_amount || '').trim() !== ''
    ? parseFloat(req.body.tds_amount)
    : null;

  await caExpenseRepository.setTds(id, isTdsApplicable, tdsAmount, user.id);
  flash.set(req, 'success', 'Expense TDS details updated.');
  res.redirect('/ca/expenses');
}

module.exports = { index, reports, zohoSync, runZohoSync, expenses, setExpenseTds };
