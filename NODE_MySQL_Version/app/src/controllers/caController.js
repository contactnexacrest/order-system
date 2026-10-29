'use strict';

const orderAccessService = require('../services/orderAccessService');
const caRepository = require('../repositories/caRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const caBankStatementRepository = require('../repositories/caBankStatementRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderRepository = require('../repositories/orderRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const userRepository = require('../repositories/userRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const financialYear = require('../helpers/financialYear');
const flash = require('../helpers/flash');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const zohoBooksService = require('../services/zohoBooksService');
const caSyncService = require('../services/caSyncService');
const zohoInvoiceData = require('../services/zohoInvoiceData');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderZohoMatchService = require('../services/orderZohoMatchService');
const caFyLockGuard = require('../services/caFyLockGuard');
const bankStatementCsvParser = require('../services/bankStatementCsvParser');
const csv = require('../helpers/csv');
const crypto = require('crypto');

// CA / Accounting module — independent of the order-pipeline system. Every
// route here is gated on ca_module_view (see server.js), never on
// manage_orders, so a CA/Accounts-only user can reach this without any
// order-management access.

/**
 * 90-item backlog item #13: the CA module had no CSV export anywhere
 * (only CSV import, for a bank statement). Extracted as their own
 * functions — same reasoning as reportController's *CsvRows() helpers —
 * purely to mirror the PHP side's testable-without-exit() structure 1:1.
 */
function settlementRegisterCsvRows(legRows) {
  const rows = legRows.map((r) => ({
    'Order Ref': r.buyer_inquiry_ref,
    Client: r.company_legal_name,
    Leg: r.leg.charAt(0).toUpperCase() + r.leg.slice(1),
    'Cleared On': r.cleared_at,
    'Foreign Amount': r.foreign_amount,
    Currency: r.currency_code,
    'INR Actual': r.inr_actual,
    'Forex Gain/(Loss)': r.forex_gain_loss,
  }));

  return { headers: ['Order Ref', 'Client', 'Leg', 'Cleared On', 'Foreign Amount', 'Currency', 'INR Actual', 'Forex Gain/(Loss)'], rows };
}

function expensesCsvRows(expenses) {
  const rows = expenses.map((e) => ({
    Date: e.expense_date,
    Category: e.category,
    Vendor: e.vendor_name ?? '',
    Description: e.description ?? '',
    Amount: e.amount,
    Currency: e.currency_code,
    'Order Ref': e.order_reference ?? '',
    'TDS Applicable': e.is_tds_applicable ? 'Yes' : 'No',
    'TDS Amount': e.tds_amount ?? '',
  }));

  return { headers: ['Date', 'Category', 'Vendor', 'Description', 'Amount', 'Currency', 'Order Ref', 'TDS Applicable', 'TDS Amount'], rows };
}

function exportBenefitsCsvRows(benefits) {
  const rows = benefits.map((b) => ({
    'Claimed On': b.claimed_at,
    Scheme: b.scheme_name,
    'Order Ref': b.order_reference ?? '',
    Reference: b.reference_number ?? '',
    'Claimed Amount': b.claimed_amount,
    Currency: b.currency_code,
    'Received Amount': b.received_amount ?? '',
    'Received On': b.received_at ?? '',
    Status: b.received_amount !== null ? 'Received' : 'Claimed',
  }));

  return { headers: ['Claimed On', 'Scheme', 'Order Ref', 'Reference', 'Claimed Amount', 'Currency', 'Received Amount', 'Received On', 'Status'], rows };
}

async function usersById() {
  const map = {};
  for (const u of await userRepository.listActive()) {
    map[u.id] = u.name;
  }
  return map;
}

async function index(req, res) {
  res.renderView('ca/index', {
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
    settlements: await caRepository.settlementRegister(),
    usersById: await usersById(),
    // Point 3 (2026-10-01): zoho-sync and fy-locks are gated on the
    // stricter ca_module_manage, not the plain ca_module_view every other
    // link here needs — hiding their cards for a view-only CA user avoids
    // offering a link that 403s.
    canManageCa: !!(req.permissions && req.permissions.ca_module_manage),
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

  // 90-item backlog item #13: same format=csv convention as
  // reportController's reports, and the exact same columns the table
  // below shows, so it can never disagree with what's on screen.
  if (req.query.format === 'csv') {
    const { headers, rows } = settlementRegisterCsvRows(report.rows);
    csv.stream(res, `ca_inr_settlement_register_${period}.csv`, headers, rows);
    return;
  }

  res.renderView('ca/reports', {
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
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
  const startDate = await zohoBooksService.syncStartDate();
  const lcReceipts = await orderLcDetailRepository.receiptsForZohoSync();
  res.renderView('ca/zoho_sync', {
    startDate,
    startDateLabel: startDate ? new Date(`${startDate}T00:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null,
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
    isEnabled: await zohoBooksService.isEnabled(),
    pendingCount: (await caRepository.legsPendingZohoSync()).filter((leg) => startDate === null || String(leg.cleared_at).slice(0, 10) >= startDate).length
      + lcReceipts.filter((lc) => lc.lc_zoho_synced_at === null && (startDate === null || String(lc.lc_received_at) >= startDate)).length,
    heldCount: (await caRepository.legsHeldInZoho()).length + lcReceipts.filter((lc) => lc.lc_zoho_synced_at !== null).length,
    purchasesPendingCount: (await zohoPurchaseRepository.billsToSync(startDate)).length + (await zohoPurchaseRepository.paymentsToSync(startDate)).length + (await zohoPurchaseRepository.expensesToSync(startDate)).length
      + (await zohoPurchaseRepository.costEntriesToSync(startDate)).filter((c) => c.zoho_expense_id === null).length,
    benefitsPendingCount: (await caExportBenefitRepository.forZohoSync(startDate)).filter((b) => b.zoho_journal_id === null).length,
    legLabels: zohoInvoiceData.KIND_LABELS,
    log: await zohoSyncLogRepository.recent(100),
  }, 'layout/base');
}

/**
 * Owner decision 2026-10-29: the per-order "App vs Zoho" check — every
 * record of the order in the app beside what Zoho Books holds for it.
 * Reads only. (sessionAuth already applies the order's visibility rules to
 * every /orders/:id/ path.)
 */
async function orderZohoCheck(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  let result = null;
  let error = null;
  if (!(await zohoBooksService.isEnabled())) {
    error = 'Zoho Books is not enabled on this server, so there is nothing to compare with.';
  } else {
    try {
      result = await orderZohoMatchService.check(orderId);
    } catch (e) {
      error = `Could not read Zoho Books: ${e.message}`;
    }
  }
  const startDate = await zohoBooksService.syncStartDate();
  res.renderView('ca/order_zoho_check', {
    order,
    result,
    error,
    startDateLabel: startDate ? new Date(`${startDate}T00:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : null,
    statusLabels: orderZohoMatchService.STATUS_LABELS,
  }, 'layout/base');
}

async function runZohoSync(req, res) {
  const user = req.user;
  const result = await caSyncService.runFullSync('manual', user.id);
  const { revenue, purchases, expenses, benefits } = result;

  const done = `${revenue.invoices} invoice(s) sent or updated, ${revenue.synced} payment(s) pushed, ${revenue.applied} payment(s) applied to invoices, `
    + `${purchases.bills} bill(s), ${purchases.payments} vendor payment(s) (${purchases.applied} applied to bills) and ${purchases.expenses} order expense(s) / cost(s) sent, ${benefits.journals} export benefit journal(s) sent or updated, ${expenses.imported} expense(s) imported`;
  const failed = revenue.failed + purchases.failed + expenses.failed + benefits.failed;
  if (failed > 0) {
    flash.set(req, 'warning', `Sync finished: ${done}, ${failed} failed — see the log below for details.`);
  } else if (revenue.synced > 0 || revenue.invoices > 0 || revenue.applied > 0 || purchases.bills + purchases.payments + purchases.applied + purchases.expenses > 0 || benefits.journals + benefits.removed > 0 || expenses.imported > 0) {
    flash.set(req, 'success', `Sync finished: ${done}.`);
  } else {
    flash.set(req, 'success', "Sync ran — nothing was pending (or Zoho Books isn't configured yet). See the log below.");
  }
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'CA_ZOHO_SYNC_RUN', 'zoho_sync_log', null, null, null, `revenue: ${revenue.invoices} invoices, ${revenue.synced} synced, ${revenue.applied} applied/${revenue.failed} failed, purchases: ${purchases.bills} bills, ${purchases.payments} payments, ${purchases.applied} applied, ${purchases.expenses} expenses/${purchases.failed} failed, benefits: ${benefits.journals} journals, ${benefits.removed} removed/${benefits.failed} failed, expenses: ${expenses.imported} imported/${expenses.failed} failed`);
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

  // 90-item backlog item #13: same CSV-export convention as reports()
  // above — columns match ca/expenses.njk's table exactly.
  if (req.query.format === 'csv') {
    const { headers, rows } = expensesCsvRows(allExpenses);
    csv.stream(res, 'ca_expenses.csv', headers, rows);
    return;
  }

  res.renderView('ca/expenses', {
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
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
 * Point 2 follow-up — links (or, given a blank reference, unlinks) an
 * imported expense to the specific order it was actually incurred for
 * (e.g. ECGC insurance or a third-party inspection fee paid for one
 * shipment). Zoho Books has no concept of this app's order IDs, so this
 * is always a manual, local-only step — never pushed back to Zoho,
 * exactly like recordExportBenefit()'s order-reference lookup.
 */
async function linkExpenseToOrder(req, res) {
  const id = parseInt(req.params.id, 10);
  const user = req.user;

  const expense = await caExpenseRepository.find(id);
  if (!expense) {
    flash.set(req, 'error', 'Expense not found.');
    res.redirect('/ca/expenses');
    return;
  }

  const orderRef = String(req.body.order_reference || '').trim();
  let orderId = null;
  if (orderRef !== '') {
    orderId = await orderRepository.findIdByReference(orderRef);
    if (orderId === null) {
      flash.set(req, 'error', `No order found with reference "${orderRef}" — the expense was not linked. Leave the field blank to unlink.`);
      res.redirect('/ca/expenses');
      return;
    }
  }

  await caExpenseRepository.linkToOrder(id, orderId);
  await auditLogRepository.log(
    user.id,
    orderId !== null ? 'CA_EXPENSE_LINKED_TO_ORDER' : 'CA_EXPENSE_UNLINKED_FROM_ORDER',
    'ca_expenses',
    id,
    'order_id',
    expense.order_id !== null ? String(expense.order_id) : null,
    orderId !== null ? String(orderId) : null
  );
  flash.set(req, 'success', orderId !== null ? `Expense linked to ${orderRef}.` : 'Expense unlinked from its order.');
  res.redirect('/ca/expenses');
}

/**
 * Point 2 follow-up — the one switch that controls whether this order's
 * linked government export benefits/expenses can ever be assembled into
 * a document at all, even an internal-only one. Off by default for every
 * order; gated on ca_internal_doc_manage (NOT ca_module_view/
 * inr_actual_edit) — Admin/MD/ED and Super Admin only, unless explicitly
 * granted to someone else via Roles & Permissions. The document itself,
 * once generated, can never reach a client — see
 * documentGenerationService.generateCaInternalAnnexure()'s docblock for
 * the structural (not just permission-based) reason why.
 */
async function toggleInternalDoc(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;

  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders');
    return;
  }

  const enabled = !!req.body.enabled;
  await orderRepository.setCaInternalDocEnabled(orderId, enabled);

  await auditLogRepository.log(
    user.id,
    enabled ? 'CA_INTERNAL_DOC_ENABLED' : 'CA_INTERNAL_DOC_DISABLED',
    'orders',
    orderId,
    'ca_internal_doc_enabled',
    String(Number(!!order.ca_internal_doc_enabled)),
    String(Number(enabled))
  );

  flash.set(
    req,
    'success',
    enabled
      ? 'Internal CA financial annexure enabled for this order — it can now be generated below.'
      : 'Internal CA financial annexure disabled for this order.'
  );
  res.redirect(`/orders/${orderId}`);
}

/**
 * Generates (or regenerates) the internal-only CA Financial Annexure for
 * one order — refused unless toggleInternalDoc() has already turned it
 * on for this specific order, so generation is never possible from a
 * stale form left open after someone else disabled it. Same permission
 * as the toggle; viewing/downloading the result afterwards only needs
 * ca_module_view (see documentController.download()'s CAFIN-specific
 * check) since it shows nothing beyond what that permission already
 * exposes on this order's own page.
 */
async function generateInternalDoc(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const user = req.user;

  const order = await orderRepository.find(orderId);
  if (!order) {
    flash.set(req, 'error', 'Order not found.');
    res.redirect('/orders');
    return;
  }

  const documentGenerationService = require('../services/documentGenerationService');
  const result = await documentGenerationService.generateCaInternalAnnexure(orderId, user.id);

  await auditLogRepository.log(user.id, 'CA_INTERNAL_DOC_GENERATED', 'orders', orderId, 'document_reference', null, result.document_reference);

  flash.set(req, 'success', `Internal CA financial annexure generated: ${result.document_reference}.`);
  res.redirect(`/orders/${orderId}#order-cafin`);
}

/**
 * CA / Accounting module (Phase 8) — government export benefit/incentive
 * claims (RODTEP + whatever else Admin adds to
 * dropdown_options('export_benefit_scheme')). Unlike ca_expenses this is
 * money owed TO the company, entered locally since there's no Zoho Books
 * import for it.
 */
async function exportBenefits(req, res) {
  const benefits = await caExportBenefitRepository.all();
  for (const b of benefits) {
    b.lockMessage = b.received_amount === null ? await caFyLockRepository.lockMessageForDate(b.claimed_at) : null;
  }

  // 90-item backlog item #13: same CSV-export convention as reports()
  // above — columns match ca/export_benefits.njk's table exactly.
  if (req.query.format === 'csv') {
    const { headers, rows } = exportBenefitsCsvRows(benefits);
    csv.stream(res, 'ca_export_benefits.csv', headers, rows);
    return;
  }

  res.renderView('ca/export_benefits', {
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
    benefits,
    schemeOptions: await lookupRepository.dropdownOptions('export_benefit_scheme'),
    totalClaimed: await caExportBenefitRepository.totalClaimed(),
    totalReceived: await caExportBenefitRepository.totalReceived(),
    canOverrideFyLock: caFyLockGuard.canOverride(req),
  }, 'layout/base');
}

async function recordExportBenefit(req, res) {
  const user = req.user;

  const schemeName = String(req.body.scheme_name || '').trim();
  const validSchemes = (await lookupRepository.dropdownOptions('export_benefit_scheme')).map((row) => row.option_value);
  if (!validSchemes.includes(schemeName)) {
    flash.set(req, 'error', 'Choose a valid scheme.');
    res.redirect('/ca/export-benefits');
    return;
  }

  const claimedAmount = String(req.body.claimed_amount || '').trim();
  if (claimedAmount === '' || Number.isNaN(Number(claimedAmount)) || Number(claimedAmount) <= 0) {
    flash.set(req, 'error', 'Enter a valid claimed amount.');
    res.redirect('/ca/export-benefits');
    return;
  }

  const claimedAt = String(req.body.claimed_at || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(claimedAt)) {
    flash.set(req, 'error', 'Enter a valid claim date.');
    res.redirect('/ca/export-benefits');
    return;
  }

  if (!(await caFyLockGuard.allow(req, claimedAt, 'ca_export_benefits', 0, 'record'))) {
    res.redirect('/ca/export-benefits');
    return;
  }

  let orderId = null;
  const orderRef = String(req.body.order_reference || '').trim();
  if (orderRef !== '') {
    orderId = await orderRepository.findIdByReference(orderRef);
    if (orderId === null) {
      flash.set(req, 'error', `No order found with reference "${orderRef}" — the claim was not recorded. Leave the field blank if this benefit isn't tied to one order.`);
      res.redirect('/ca/export-benefits');
      return;
    }
  }

  const referenceNumber = String(req.body.reference_number || '').trim() || null;
  const notes = String(req.body.notes || '').trim() || null;
  const currencyCode = String(req.body.currency_code || '').trim() || 'INR';

  const id = await caExportBenefitRepository.record(orderId, schemeName, referenceNumber, parseFloat(claimedAmount), claimedAt, currencyCode, notes, user.id);
  await auditLogRepository.log(user.id, 'CA_EXPORT_BENEFIT_RECORDED', 'ca_export_benefits', id, null, null, schemeName);
  flash.set(req, 'success', `${schemeName} claim recorded.`);
  res.redirect('/ca/export-benefits');
}

async function markExportBenefitReceived(req, res) {
  const id = parseInt(req.params.id, 10);
  const user = req.user;

  const benefit = await caExportBenefitRepository.find(id);
  if (!benefit) {
    res.status(404).send('Claim not found.');
    return;
  }

  const receivedAmount = String(req.body.received_amount || '').trim();
  if (receivedAmount === '' || Number.isNaN(Number(receivedAmount)) || Number(receivedAmount) < 0) {
    flash.set(req, 'error', 'Enter a valid received amount.');
    res.redirect('/ca/export-benefits');
    return;
  }
  const receivedAt = String(req.body.received_at || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(receivedAt)) {
    flash.set(req, 'error', 'Enter a valid received date.');
    res.redirect('/ca/export-benefits');
    return;
  }

  if (!(await caFyLockGuard.allow(req, receivedAt, 'ca_export_benefits', id, 'mark_received'))) {
    res.redirect('/ca/export-benefits');
    return;
  }

  await caExportBenefitRepository.markReceived(id, parseFloat(receivedAmount), receivedAt);
  await auditLogRepository.log(user.id, 'CA_EXPORT_BENEFIT_RECEIVED', 'ca_export_benefits', id, 'received_amount', null, receivedAmount);
  flash.set(req, 'success', 'Marked as received.');
  res.redirect('/ca/export-benefits');
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
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
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
    flash.set(req, 'error', `Could not read the file: ${flash.describeException(e, 'read failed — check the server error log.')}`);
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

  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'CA_BANK_STATEMENT_UPLOADED', 'ca_bank_statement_lines', null, null, null, `imported: ${imported}, duplicates: ${duplicates}, skipped: ${parsed.skipped}`);
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
    canOpenOrder: await orderAccessService.orderLinkChecker(req.user && req.user.id, req.permissions),
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

  const lockedYears = await caFyLockRepository.lockedYears();

  // FY-Close Readiness (closes a real gap: locking previously showed
  // nothing about whether a year was actually ready to close) — only
  // computed for still-open years, since a locked year's readiness is
  // moot.
  const readinessByYear = {};
  for (const fy of years) {
    if (!lockedYears.includes(fy)) {
      const r = await caRepository.fyReadiness(fy);
      const issues = [];
      if (r.revenue.legsMissingInr > 0) issues.push(`${r.revenue.legsMissingInr} leg(s) missing INR actual`);
      if (r.pendingZohoSyncCount > 0) issues.push(`${r.pendingZohoSyncCount} pending Zoho sync`);
      if (r.unmatchedRevenueLegCount > 0) issues.push(`${r.unmatchedRevenueLegCount} unmatched revenue leg(s)`);
      if (r.unmatchedExpenseCount > 0) issues.push(`${r.unmatchedExpenseCount} unmatched expense(s)`);
      if (r.unmatchedBankLineCount > 0) issues.push(`${r.unmatchedBankLineCount} unmatched bank line(s)`);
      r.issuesText = issues.join(', ');
      readinessByYear[fy] = r;
    }
  }

  res.renderView('ca/fy_locks', {
    years,
    lockedYears,
    readinessByYear,
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
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'CA_FINANCIAL_YEAR_LOCKED', 'ca_fy_locks', null, 'financial_year', null, fy);
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
  // External QA report N-08: this action never reached the audit log,
  // so it never notified anyone either.
  await auditLogRepository.log(user.id, 'CA_FINANCIAL_YEAR_UNLOCKED', 'ca_fy_locks', null, 'financial_year', fy, null, reason);
  flash.set(req, 'success', `FY ${fy} reopened for CA data entry.`);
  res.redirect('/ca/fy-locks');
}

/** TDS Payable Summary — closes a real gap: the per-expense TDS flag existed but was never rolled up for compliance filing. */
async function tdsSummary(req, res) {
  res.renderView('ca/tds_summary', { rows: await caExpenseRepository.tdsSummary() }, 'layout/base');
}

module.exports = {
  index, reports, zohoSync, runZohoSync, orderZohoCheck, expenses, setExpenseTds, linkExpenseToOrder, tdsSummary,
  exportBenefits, recordExportBenefit, markExportBenefitReceived,
  bankStatement, uploadBankStatement, matchBankLineToRevenue, matchBankLineToExpense, unmatchBankLine, reconciliation,
  fyLocks, lockFinancialYear, unlockFinancialYear,
  toggleInternalDoc, generateInternalDoc,
  settlementRegisterCsvRows, expensesCsvRows, exportBenefitsCsvRows,
};
