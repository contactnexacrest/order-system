'use strict';

const caRepository = require('../repositories/caRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const caBankStatementRepository = require('../repositories/caBankStatementRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const userRepository = require('../repositories/userRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const financialYear = require('../helpers/financialYear');
const flash = require('../helpers/flash');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const zohoBooksService = require('../services/zohoBooksService');
const caSyncService = require('../services/caSyncService');
const caFyLockGuard = require('../services/caFyLockGuard');
const bankStatementCsvParser = require('../services/bankStatementCsvParser');
const crypto = require('crypto');

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
  const allExpenses = await caExpenseRepository.all();
  for (const e of allExpenses) {
    e.lockMessage = await caFyLockRepository.lockMessageForDate(e.expense_date);
  }

  res.renderView('ca/expenses', {
    expenses: allExpenses,
    usersById: await usersById(),
    canEditTds: !!req.permissions.inr_actual_edit,
    canOverrideFyLock: caFyLockGuard.canOverride(req),
  }, 'layout/base');
}

async function setExpenseTds(req, res) {
  const id = parseInt(req.params.id, 10);
  const user = req.user;

  const expense = await caExpenseRepository.find(id);
  if (!(await caFyLockGuard.allow(req, expense ? expense.expense_date : null, 'ca_expenses', id, 'tds'))) {
    res.redirect('/ca/expenses');
    return;
  }

  const isTdsApplicable = !!req.body.is_tds_applicable;
  const tdsAmount = isTdsApplicable && String(req.body.tds_amount || '').trim() !== ''
    ? parseFloat(req.body.tds_amount)
    : null;

  await caExpenseRepository.setTds(id, isTdsApplicable, tdsAmount, user.id);
  flash.set(req, 'success', 'Expense TDS details updated.');
  res.redirect('/ca/expenses');
}

/**
 * Bank statement lines (Phase 5) — upload a CSV export, then match each
 * line to a revenue leg or an expense. The two pickers only ever list
 * items not already matched to some other line, so the same revenue
 * leg/expense can't accidentally be double-matched.
 */
async function bankStatement(req, res) {
  const canOverride = caFyLockGuard.canOverride(req);
  const matchedRevenueKeys = await caBankStatementRepository.matchedRevenueKeys();
  const matchedExpenseIds = await caBankStatementRepository.matchedExpenseIds();
  const allExpenses = await caExpenseRepository.all();

  // Phase 6: a leg/expense whose own date falls in a locked financial year
  // is left off the matching pickers entirely — matching it now would be a
  // new entry against a year the CA has already closed, exactly the kind
  // of backdated change the lock exists to prevent. Phase 7: unless the
  // viewer holds ca_fy_lock_override, in which case they can still see
  // (and, on submit, log an override for) a locked-FY item.
  const candidateLegs = await caRepository.legsWithInrActualUnmatched(matchedRevenueKeys);
  const unmatchedRevenueLegs = [];
  for (const leg of candidateLegs) {
    const locked = !!(await caFyLockRepository.lockMessageForDate(leg.cleared_at));
    if (canOverride || !locked) {
      leg.locked = locked;
      unmatchedRevenueLegs.push(leg);
    }
  }
  const candidateExpenses = allExpenses.filter((e) => !matchedExpenseIds.includes(e.id));
  const unmatchedExpenses = [];
  for (const e of candidateExpenses) {
    const locked = !!(await caFyLockRepository.lockMessageForDate(e.expense_date));
    if (canOverride || !locked) {
      e.locked = locked;
      unmatchedExpenses.push(e);
    }
  }

  const lines = await caBankStatementRepository.all();
  for (const l of lines) {
    l.matchLockMessage = l.matched_order_id !== null
      ? await caFyLockRepository.lockMessageForDate(l.matched_leg_cleared_at)
      : (l.matched_expense_id !== null ? await caFyLockRepository.lockMessageForDate(l.matched_expense_date) : null);
  }

  res.renderView('ca/bank_statement', {
    lines,
    unmatchedRevenueLegs,
    unmatchedExpenses,
    canOverrideFyLock: canOverride,
  }, 'layout/base');
}

async function uploadBankStatement(req, res) {
  const user = req.user;
  if (!req.file) {
    flash.set(req, 'error', 'No file was uploaded, or the upload failed.');
    res.redirect('/ca/bank-statement');
    return;
  }
  const extension = (req.file.originalname.split('.').pop() || '').toLowerCase();
  if (extension !== 'csv') {
    flash.set(req, 'error', 'Only .csv files are supported — export your bank statement as CSV first.');
    res.redirect('/ca/bank-statement');
    return;
  }

  let parsed;
  try {
    parsed = bankStatementCsvParser.parse(req.file.buffer.toString('utf8'));
  } catch (e) {
    flash.set(req, 'error', `Could not read the file: ${e.message}`);
    res.redirect('/ca/bank-statement');
    return;
  }

  let imported = 0;
  let duplicates = 0;
  for (const row of parsed.rows) {
    const hash = crypto.createHash('sha256').update(`${row.date}|${row.description || ''}|${row.reference || ''}|${row.credit || ''}|${row.debit || ''}`).digest('hex');
    const inserted = await caBankStatementRepository.insertLine(hash, row.date, row.description, row.reference, row.credit, row.debit, user.id);
    if (inserted) imported += 1;
    else duplicates += 1;
  }

  flash.set(req, 'success', `Imported ${imported} line(s). ${duplicates} already-imported duplicate(s) skipped, ${parsed.skipped} unparseable row(s) skipped.`);
  res.redirect('/ca/bank-statement');
}

async function matchBankLineToRevenue(req, res) {
  const lineId = parseInt(req.params.id, 10);
  const user = req.user;
  const orderId = parseInt(req.body.order_id, 10) || 0;
  const leg = String(req.body.leg || '');
  if (orderId <= 0 || !['advance', 'balance', 'freight'].includes(leg)) {
    flash.set(req, 'error', 'Choose a revenue leg to match.');
    res.redirect('/ca/bank-statement');
    return;
  }

  const ops = (await orderPaymentStatusRepository.find(orderId)) || {};
  if (!(await caFyLockGuard.allow(req, ops[`${leg}_cleared_at`] ?? null, 'order_payment_status', orderId, `${leg}_bank_match`))) {
    res.redirect('/ca/bank-statement');
    return;
  }

  await caBankStatementRepository.matchToRevenue(lineId, orderId, leg, user.id);
  flash.set(req, 'success', 'Bank line matched to the settlement leg.');
  res.redirect('/ca/bank-statement');
}

async function matchBankLineToExpense(req, res) {
  const lineId = parseInt(req.params.id, 10);
  const user = req.user;
  const expenseId = parseInt(req.body.expense_id, 10) || 0;
  if (expenseId <= 0) {
    flash.set(req, 'error', 'Choose an expense to match.');
    res.redirect('/ca/bank-statement');
    return;
  }

  const expense = await caExpenseRepository.find(expenseId);
  if (!(await caFyLockGuard.allow(req, expense ? expense.expense_date : null, 'ca_expenses', expenseId, 'bank_match'))) {
    res.redirect('/ca/bank-statement');
    return;
  }

  await caBankStatementRepository.matchToExpense(lineId, expenseId, user.id);
  flash.set(req, 'success', 'Bank line matched to the expense.');
  res.redirect('/ca/bank-statement');
}

async function unmatchBankLine(req, res) {
  const lineId = parseInt(req.params.id, 10);
  const line = await caBankStatementRepository.find(lineId);
  let allowed = true;
  if (line) {
    if (line.matched_order_id !== null && line.matched_leg !== null) {
      const ops = (await orderPaymentStatusRepository.find(line.matched_order_id)) || {};
      allowed = await caFyLockGuard.allow(req, ops[`${line.matched_leg}_cleared_at`] ?? null, 'order_payment_status', line.matched_order_id, `${line.matched_leg}_bank_unmatch`);
    } else if (line.matched_expense_id !== null) {
      const expense = await caExpenseRepository.find(line.matched_expense_id);
      allowed = await caFyLockGuard.allow(req, expense ? expense.expense_date : null, 'ca_expenses', line.matched_expense_id, 'bank_unmatch');
    }
  }
  if (!allowed) {
    res.redirect('/ca/bank-statement');
    return;
  }

  await caBankStatementRepository.unmatch(lineId);
  flash.set(req, 'success', 'Match removed.');
  res.redirect('/ca/bank-statement');
}

/**
 * All-time reconciliation summary: bank statement totals vs. recorded
 * revenue/expense totals, plus what's still unmatched on each side.
 * Deliberately all-time rather than period-filtered for now — see
 * ca-05-bank-reconciliation.md.
 */
async function reconciliation(req, res) {
  const bankTotals = await caBankStatementRepository.totals();
  const matchedRevenueKeys = await caBankStatementRepository.matchedRevenueKeys();
  const matchedExpenseIds = await caBankStatementRepository.matchedExpenseIds();
  const allExpenses = await caExpenseRepository.all();
  const allLines = await caBankStatementRepository.all();

  res.renderView('ca/reconciliation', {
    bankTotals,
    totalRevenue: await caRepository.totalInrActualAll(),
    totalExpenses: await caExpenseRepository.totalAll(),
    unmatchedRevenueLegs: await caRepository.legsWithInrActualUnmatched(matchedRevenueKeys),
    unmatchedExpenses: allExpenses.filter((e) => !matchedExpenseIds.includes(e.id)),
    unmatchedBankLines: allLines.filter((l) => l.matched_order_id === null && l.matched_expense_id === null),
  }, 'layout/base');
}

/**
 * Year-end financial year lock (Phase 6) — gated on ca_module_manage, the
 * same admin tier as the Zoho Books sync page, since locking a year is an
 * administrative action with a wider blast radius than routine CA data
 * entry.
 */
async function fyLocks(req, res) {
  const years = Array.from(new Set([
    ...(await caRepository.availableFinancialYears()),
    ...(await caExpenseRepository.availableFinancialYears()),
    financialYear.current(),
  ])).sort().reverse();

  res.renderView('ca/fy_locks', {
    years,
    lockedYears: await caFyLockRepository.lockedYears(),
    history: await caFyLockRepository.history(),
    recentOverrides: await auditLogRepository.search({ actionType: 'CA_FY_LOCK_OVERRIDDEN', limit: 20 }),
    usersById: await usersById(),
  }, 'layout/base');
}

async function lockFinancialYear(req, res) {
  const user = req.user;
  const fy = String(req.body.financial_year || '').trim();
  if (!/^\d{4}-\d{2}$/.test(fy)) {
    flash.set(req, 'error', 'Choose a valid financial year to lock.');
    res.redirect('/ca/fy-locks');
    return;
  }
  if (await caFyLockRepository.isLocked(fy)) {
    flash.set(req, 'error', `FY ${fy} is already locked.`);
    res.redirect('/ca/fy-locks');
    return;
  }
  await caFyLockRepository.lock(fy, user.id);
  flash.set(req, 'success', `FY ${fy} locked. No CA data entry against that year will be accepted until it's reopened.`);
  res.redirect('/ca/fy-locks');
}

async function unlockFinancialYear(req, res) {
  const user = req.user;
  const fy = String(req.body.financial_year || '').trim();
  const reason = String(req.body.unlock_reason || '').trim();
  if (!/^\d{4}-\d{2}$/.test(fy) || reason === '') {
    flash.set(req, 'error', 'Enter a reason for reopening this financial year.');
    res.redirect('/ca/fy-locks');
    return;
  }
  if (!(await caFyLockRepository.isLocked(fy))) {
    flash.set(req, 'error', `FY ${fy} isn't currently locked.`);
    res.redirect('/ca/fy-locks');
    return;
  }
  await caFyLockRepository.unlock(fy, user.id, reason);
  flash.set(req, 'success', `FY ${fy} reopened for CA data entry.`);
  res.redirect('/ca/fy-locks');
}

module.exports = {
  index, reports, zohoSync, runZohoSync, expenses, setExpenseTds,
  bankStatement, uploadBankStatement, matchBankLineToRevenue, matchBankLineToExpense, unmatchBankLine, reconciliation,
  fyLocks, lockFinancialYear, unlockFinancialYear,
};
