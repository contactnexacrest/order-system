'use strict';

const crypto = require('crypto');
const divisionService = require('./divisionService');
const caRepository = require('../repositories/caRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const paymentMilestoneRepository = require('../repositories/paymentMilestoneRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const gstRefundRepository = require('../repositories/gstRefundRepository');
const zohoBooksService = require('./zohoBooksService');
const zohoGuard = require('./zohoGuard');

/**
 * Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N". A record saved in
 * the app goes to Zoho Books at the next sync (hourly, or Sync now) — this
 * counts what that next sync would send, using the same selectors
 * caSyncService pushes from. Nothing is counted while Zoho is off.
 * PHP twin: ZohoPendingService.php.
 */
const TYPE_LABELS = {
  invoice: 'Commercial Invoice / Debit Note',
  buyer_payment: 'buyer payment',
  milestone_payment: 'milestone payment',
  payment_to_apply: 'payment to apply to its invoice',
  vendor_bill: 'supplier / partner bill',
  vendor_payment: 'vendor payment',
  order_expense: 'order expense',
  order_cost: 'order cost',
  export_benefit: 'export benefit journal',
  gst_refund: 'GST refund journal',
  client: 'client (customer)',
  product: 'product (item)',
  // D-257 Zoho: the new divisions' records.
  ...require('./divisionZohoSync').TYPE_LABELS,
};

const sha = (value) => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const round2 = (n) => Math.round(Number(n) * 100) / 100;

/** @return {Promise<{enabled: boolean, total: number, by_type: Object<string, number>}>} */
/**
 * Owner decision 2 Nov 2026 (D-239): while today's allowance of Zoho API
 * calls for this app is used up, what is waiting cannot go until tomorrow.
 */
async function pausedText() {
  const zohoBooksService = require('./zohoBooksService');
  return (await zohoBooksService.isEnabled()) && (await require('./zohoApiBudget').exhaustedToday())
    ? 'paused — daily Zoho allowance used, resumes tomorrow'
    : null;
}

async function counts(orderId = null) {
  if (!(await zohoBooksService.isEnabled())) {
    return { enabled: false, total: 0, by_type: {} };
  }
  const start = await zohoBooksService.syncStartDate();
  if (start === null && zohoGuard.isProduction()) {
    return { enabled: true, total: 0, by_type: {} };
  }
  const by = {};
  const add = (type, n) => {
    if (n > 0) by[type] = (by[type] || 0) + n;
  };
  // D-257: only the orders of the request's division(s).
  const inDivision = await divisionService.orderIdChecker();
  const inScope = (id) => (orderId === null || (id !== null && id !== undefined && Number(id) === Number(orderId))) && inDivision(id);
  const onOrAfter = (date) => start === null || String(date ?? '').slice(0, 10) >= start;

  let n = 0;
  for (const item of await orderZohoInvoiceRepository.documentsToSync(start)) {
    if (!inScope(item.order_id)) continue;
    const known = await orderZohoInvoiceRepository.find(Number(item.order_id), item.kind);
    if (!known || Number(known.document_id) !== Number(item.document_id)) n += 1;
  }
  add('invoice', n);
  add('buyer_payment', (await caRepository.legsPendingZohoSync()).filter((r) => inScope(r.order_id) && onOrAfter(r.cleared_at)).length);
  add('payment_to_apply', (await caRepository.legsHeldInZoho()).filter((r) => inScope(r.order_id)).length);
  for (const lc of await orderLcDetailRepository.receiptsForZohoSync()) {
    if (!inScope(lc.order_id)) continue;
    if (lc.lc_zoho_synced_at === null) add('buyer_payment', onOrAfter(lc.lc_received_at) ? 1 : 0);
    else add('payment_to_apply', 1);
  }
  add('milestone_payment', (await paymentMilestoneRepository.pendingZohoSync()).filter((r) => inScope(r.order_id) && onOrAfter(r.cleared_on)).length);
  add('payment_to_apply', (await paymentMilestoneRepository.heldInZoho()).filter((r) => inScope(r.order_id)).length);
  add('vendor_bill', (await zohoPurchaseRepository.billsToSync(start)).filter((r) => inScope(r.order_id)).length);
  add('vendor_payment', (await zohoPurchaseRepository.paymentsToSync(start)).filter((r) => inScope(r.bill && r.bill.order_id)).length);
  add('vendor_payment', (await zohoPurchaseRepository.heldPayments()).filter((r) => inScope(r.bill && r.bill.order_id)).length);
  add('order_expense', (await zohoPurchaseRepository.expensesToSync(start)).filter((r) => inScope(r.order_id)).length);

  n = 0;
  for (const cost of await zohoPurchaseRepository.costEntriesToSync(start)) {
    if (!inScope(cost.order_id)) continue;
    if ((cost.incentive_kind ?? null) === 'associate') {
      n += cost.zoho_expense_id === null ? 1 : 0;
      continue;
    }
    const hash = sha([cost.category, round2(cost.amount_inr), String(cost.cost_date), cost.paid_from, cost.description]);
    if (cost.zoho_expense_id === null || cost.zoho_pushed_hash !== hash) n += 1;
  }
  add('order_cost', n);

  n = 0;
  for (const b of await caExportBenefitRepository.forZohoSync(start)) {
    if (!inScope(b.order_id)) continue;
    const received = b.received_amount !== null && Number(b.received_amount) > 0;
    if (!received) {
      n += 1; // in Books, receipt cleared in the app: the sync removes the journal
      continue;
    }
    const hash = sha([b.scheme_name, round2(b.received_amount), String(b.received_at), b.reference_number, b.order_id]);
    if (b.zoho_journal_id === null || b.zoho_pushed_hash !== hash) n += 1;
  }
  add('export_benefit', n);

  if (orderId === null) {
    add('gst_refund', (await gstRefundRepository.creditsForZohoSync(start)).length);
  }
  if (orderId === null) {
    // D-257 Zoho: the new divisions' invoices, bills, journals and payments.
    for (const [type, n] of Object.entries(await require('./divisionZohoSync').pendingCounts(start))) add(type, n);
  }
  if (orderId === null) {
    // Owner decision 2 Nov 2026 (D-238): clients / products, only while their switch is on.
    for (const [type, n] of Object.entries(await require('./zohoMasterDataSync').pendingCounts())) add(type, n);
  }
  return { enabled: true, total: Object.values(by).reduce((a, b) => a + b, 0), by_type: by };
}

/** The menu badge: all records waiting, worked out at most once a minute per session. */
async function menuCount(session) {
  const now = Date.now();
  const cached = session && session.zohoWaitingBadge;
  const scope = divisionService.condition() || '';
  if (cached && now - cached.at < 60000 && (cached.scope || '') === scope) return cached.n;
  let n = null;
  try {
    const c = await counts(null);
    n = c.enabled ? c.total : null;
  } catch (e) {
    n = null;
  }
  if (session) session.zohoWaitingBadge = { at: now, n, scope };
  return n;
}

/** "2 order costs, 1 buyer payment" */
function summary(byType) {
  return Object.entries(byType)
    .map(([type, n]) => `${n} ${TYPE_LABELS[type] || type}${n === 1 ? '' : 's'}`)
    .join(', ');
}

module.exports = { counts, menuCount, summary, pausedText, TYPE_LABELS };
