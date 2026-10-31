'use strict';

const flash = require('../helpers/flash');
const { scalarString } = require('../helpers/input');
const { todayIst } = require('../helpers/dbDateTime');
const { MIN_LENGTH } = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const purchasingRepository = require('../repositories/purchasingRepository');
const supplierRepository = require('../repositories/supplierRepository');
const orderAccessService = require('../services/orderAccessService');
const purchasingService = require('../services/purchasingService');
const activeDepartmentService = require('../services/activeDepartmentService');

// Port of App\Controllers\PurchasingController. Owner decision D-214
// (2026-10-31), phase 3: Procurement. Sales raises a purchase requirement
// on the order (no prices); Procurement records supplier quotes, compares
// and awards (above the approval limit it waits for an approver), and
// records receipt and the quality check.

const QC_RESULTS = { passed: 'Passed', passed_with_remarks: 'Passed with remarks', failed: 'Failed' };
const { fmt } = purchasingService;
const len = (s) => [...s].length;
const isNumeric = (v) => /^\s*-?(\d+\.?\d*|\.\d+)\s*$/.test(String(v));
const trimQty = (v) => String(Number(v).toFixed(3)).replace(/\.?0+$/, '');

function isDate(value) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]);
}

function fail(req, res, message, path) {
  flash.set(req, 'error', message);
  res.redirect(path);
}

/** The requirement, or null after answering 404 (missing or on an order this person cannot see). */
async function requirement(req, res) {
  const r = await purchasingRepository.find(parseInt(req.params.id, 10));
  if (!r || !(await orderAccessService.orderLinkChecker(req.user.id, req.permissions))(r.order_id)) {
    res.status(404).send('Purchase requirement not found.');
    return null;
  }
  return r;
}

/** Sales: what the order needs, from one of its product lines or described freely. */
async function raise(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const back = `/orders/${orderId}#purchase-requirements`;
  const productId = parseInt(req.body.order_product_id, 10) || 0;
  const product = productId > 0 ? await orderProductRepository.find(productId) : null;
  if (productId > 0 && (!product || Number(product.order_id) !== orderId)) {
    fail(req, res, 'Choose a product line of this order, or describe what is needed.', back);
    return;
  }
  const description = scalarString(req.body.description) || String((product && product.description) || '');
  const quantityRaw = String(req.body.quantity ?? '').trim();
  const quantity = quantityRaw !== '' ? quantityRaw : String((product && product.quantity) ?? '');
  const neededBy = String(req.body.needed_by ?? '').trim();
  let error = null;
  if (len(description) < 2 || len(description) > 500) error = 'Describe what is needed (2 to 500 characters).';
  else if (!isNumeric(quantity) || Number(quantity) <= 0 || Number(quantity) > 999999999) error = 'Enter the quantity needed (more than 0).';
  else if (!isDate(neededBy) || neededBy < todayIst()) error = 'Enter the date it is needed by (today or later).';
  if (error !== null) {
    fail(req, res, error, back);
    return;
  }
  const specRaw = String(req.body.specification ?? '').trim()
    || [product && product.material, product && product.finish, product && product.dimensions].filter((x) => x).map(String).join(' · ').trim();
  const spec = specRaw || null;
  const unitRaw = scalarString(req.body.unit) || (product && product.unit) || null;
  const qty = Math.round(Number(quantity) * 1000) / 1000;
  const id = await purchasingRepository.create(orderId, product ? productId : null, description, spec !== null ? [...spec].slice(0, 1000).join('') : null,
    qty, unitRaw !== null ? [...String(unitRaw)].slice(0, 20).join('') : null, neededBy, req.user.id, String(req.body.note ?? '').trim() || null);
  await auditLogRepository.log(req.user.id, 'PURCHASE_REQUIREMENT_RAISED', 'purchase_requirements', id, null, null,
    `${order.order_reference}: ${description} × ${trimQty(quantity)}${unitRaw ? ` ${unitRaw}` : ''} by ${neededBy}`);
  flash.set(req, 'success', `Purchase requirement PR-${id} raised for Procurement: ${description}, needed by ${neededBy}.`);
  res.redirect(back);
}

/** Procurement's queue: everything open, earliest need first (only orders this person can see). */
async function queue(req, res) {
  const status = String(req.query.status || '');
  const rows = await purchasingRepository.queue(Object.prototype.hasOwnProperty.call(purchasingRepository.STATUSES, status) ? status : null);
  const mayOpen = await orderAccessService.orderLinkChecker(req.user.id, req.permissions);
  res.renderView('procurement/queue', { rows: rows.filter((r) => mayOpen(r.order_id)), statuses: purchasingRepository.STATUSES, status, today: todayIst() }, 'layout/base');
}

async function show(req, res) {
  const r = await requirement(req, res);
  if (!r) return;
  const quotes = await purchasingRepository.quotes(r.id);
  for (const q of quotes) {
    q.total = purchasingService.awardValue(q, r.quantity);
  }
  const awardValue = r.awarded_quote_id !== null
    ? purchasingService.awardValue({ unit_price_inr: r.awarded_unit_price_inr, gst_percent: r.awarded_gst_percent }, r.quantity) : null;
  const m = await activeDepartmentService.current(req, req.user.id);
  res.renderView('procurement/show', {
    r,
    qty: trimQty(r.quantity),
    quotes,
    suppliers: await supplierRepository.all(),
    statuses: purchasingRepository.STATUSES,
    qcResults: QC_RESULTS,
    awardValue,
    canWork: !!req.permissions.manage_purchasing,
    approvalRefusal: r.status === 'awarded' && awardValue !== null ? await purchasingService.approvalRefusal(req, r, awardValue) : null,
    myLimit: await purchasingService.limitFor(req, req.user.id),
    actingLabel: m ? activeDepartmentService.label(m) : '',
    today: todayIst(),
    minReasonLength: MIN_LENGTH,
    receivedQty: r.received_quantity !== null ? trimQty(r.received_quantity) : '',
    fmt,
  }, 'layout/base');
}

async function addQuote(req, res) {
  const r = await requirement(req, res);
  if (!r) return;
  const back = `/procurement/${r.id}`;
  const supplier = await supplierRepository.find(parseInt(req.body.supplier_id, 10) || 0);
  const price = String(req.body.unit_price_inr ?? '').trim();
  const gst = String(req.body.gst_percent ?? '0').trim() || '0';
  const lead = String(req.body.lead_time_days ?? '').trim();
  const validUntil = String(req.body.valid_until ?? '').trim();
  let error = null;
  if (!['open', 'quoted'].includes(r.status)) error = 'Quotes are recorded only before the purchase is awarded.';
  else if (!supplier || Number(supplier.is_active) !== 1) error = 'Choose the supplier.';
  else if (!isNumeric(price) || Number(price) <= 0 || Number(price) > 999999999) error = 'Enter the unit price in rupees (more than 0).';
  else if (!isNumeric(gst) || Number(gst) < 0 || Number(gst) > 28) error = 'Enter the GST % (0 to 28).';
  else if (!/^\d+$/.test(lead) || Number(lead) > 365) error = 'Enter the lead time in days (0 to 365).';
  else if (validUntil !== '' && (!isDate(validUntil) || validUntil < todayIst())) error = 'The quote must be valid until today or later (or leave it empty).';
  if (error !== null) {
    fail(req, res, error, back);
    return;
  }
  const id = await purchasingRepository.addQuote(r.id, supplier.id, Math.round(Number(price) * 100) / 100, Math.round(Number(gst) * 100) / 100, Number(lead),
    validUntil || null, scalarString(req.body.quote_reference) || null, String(req.body.note ?? '').trim() || null, req.user.id);
  if (r.status === 'open') {
    await purchasingRepository.setStatus(r.id, 'quoted');
  }
  await auditLogRepository.log(req.user.id, 'PURCHASE_QUOTE_RECORDED', 'purchase_quotes', id, null, null,
    `PR-${r.id}: ${supplier.supplier_legal_name} ₹${fmt(price)} + ${gst}% GST, ${lead} days`);
  flash.set(req, 'success', `Quote from ${supplier.supplier_legal_name} recorded.`);
  res.redirect(back);
}

async function award(req, res) {
  const r = await requirement(req, res);
  if (!r) return;
  const back = `/procurement/${r.id}`;
  const quote = await purchasingRepository.findQuote(parseInt(req.body.quote_id, 10) || 0);
  if (!['quoted', 'awarded'].includes(r.status) || !quote || Number(quote.requirement_id) !== Number(r.id)) {
    fail(req, res, "Choose one of this requirement's quotes (before it is approved).", back);
    return;
  }
  const value = purchasingService.awardValue(quote, r.quantity);
  const needsApproval = await purchasingService.needsApproval(req, req.user.id, value);
  await purchasingRepository.award(r.id, quote.id, req.user.id, needsApproval);
  await auditLogRepository.log(req.user.id, 'PURCHASE_AWARDED', 'purchase_requirements', r.id, 'awarded_quote_id', r.awarded_quote_id !== null ? String(r.awarded_quote_id) : null,
    `${quote.supplier_legal_name} ₹${fmt(value)}${needsApproval ? ' (waiting for approval)' : ''}`);
  flash.set(req, 'success', `Awarded to ${quote.supplier_legal_name} for ₹${fmt(value)}. `
    + (needsApproval ? 'It is above your approval limit and waits for an approver.' : 'Approved — within your approval limit.'));
  res.redirect(back);
}

async function approve(req, res) {
  const r = await requirement(req, res);
  if (!r) return;
  const back = `/procurement/${r.id}`;
  if (r.status !== 'awarded' || r.awarded_quote_id === null) {
    fail(req, res, 'Only an award waiting for approval can be approved.', back);
    return;
  }
  const value = purchasingService.awardValue({ unit_price_inr: r.awarded_unit_price_inr, gst_percent: r.awarded_gst_percent }, r.quantity);
  const refusal = await purchasingService.approvalRefusal(req, r, value);
  if (refusal !== null) {
    fail(req, res, refusal, back);
    return;
  }
  await purchasingRepository.approve(r.id, req.user.id);
  await auditLogRepository.log(req.user.id, 'PURCHASE_APPROVED', 'purchase_requirements', r.id, null, null, `${r.awarded_supplier_name} ₹${fmt(value)}`);
  flash.set(req, 'success', `Purchase PR-${r.id} approved: ${r.awarded_supplier_name}, ₹${fmt(value)}.`);
  res.redirect(back);
}

async function receive(req, res) {
  const r = await requirement(req, res);
  if (!r) return;
  const back = `/procurement/${r.id}`;
  const qty = String(req.body.received_quantity ?? '').trim();
  const on = String(req.body.received_on ?? '').trim();
  const qc = String(req.body.qc_result ?? '');
  const note = String(req.body.qc_note ?? '').trim();
  let error = null;
  if (r.status !== 'approved') error = 'Receipt is recorded once the purchase is approved.';
  else if (!isNumeric(qty) || Number(qty) <= 0 || Number(qty) > 999999999) error = 'Enter the quantity received (more than 0).';
  else if (!isDate(on) || on > todayIst()) error = 'Enter the date it was received (not in the future).';
  else if (!Object.prototype.hasOwnProperty.call(QC_RESULTS, qc)) error = 'Choose the quality check result.';
  else if (qc !== 'passed' && len(note) < 5) error = 'Say what the quality check found (at least 5 characters).';
  if (error !== null) {
    fail(req, res, error, back);
    return;
  }
  await purchasingRepository.receive(r.id, Math.round(Number(qty) * 1000) / 1000, on, qc, note !== '' ? [...note].slice(0, 500).join('') : null, req.user.id);
  await auditLogRepository.log(req.user.id, 'PURCHASE_RECEIVED', 'purchase_requirements', r.id, 'qc_result', null, `${trimQty(qty)} received ${on}, QC ${QC_RESULTS[qc]}`);
  flash.set(req, 'success', `Receipt recorded for PR-${r.id}: quality check ${QC_RESULTS[qc].toLowerCase()}.`);
  res.redirect(back);
}

async function cancel(req, res) {
  const r = await requirement(req, res);
  if (!r) return;
  const back = `/procurement/${r.id}`;
  const reason = String(req.body.reason ?? '').trim();
  if (['received', 'cancelled'].includes(r.status) || len(reason) < MIN_LENGTH) {
    fail(req, res, `Give the reason for cancelling (at least ${MIN_LENGTH} characters); a received purchase cannot be cancelled.`, back);
    return;
  }
  await purchasingRepository.setStatus(r.id, 'cancelled');
  await auditLogRepository.log(req.user.id, 'PURCHASE_REQUIREMENT_CANCELLED', 'purchase_requirements', r.id, 'status', String(r.status), 'cancelled', reason);
  flash.set(req, 'success', `PR-${r.id} cancelled.`);
  res.redirect(back);
}

async function suppliers(req, res) {
  res.renderView('procurement/suppliers', { rows: await purchasingRepository.supplierPerformance() }, 'layout/base');
}

module.exports = { raise, queue, show, addQuote, award, approve, receive, cancel, suppliers };
