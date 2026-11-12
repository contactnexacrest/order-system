'use strict';

const flash = require('../helpers/flash');
const fieldValidator = require('../helpers/fieldValidator');
const orderStorage = require('../helpers/orderStorage');
const reasonValidator = require('../helpers/reasonValidator');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderRepository = require('../repositories/orderRepository');
const amendmentService = require('../services/amendmentService');
const productsAmendmentService = require('../services/productsAmendmentService');
const paymentPresetRepository = require('../repositories/paymentPresetRepository');

async function presetAllowsZero(orderId) {
  const order = await orderRepository.find(orderId);
  const preset = order && order.payment_preset_id ? await paymentPresetRepository.find(Number(order.payment_preset_id)) : null;
  return !!preset && Number(preset.allow_zero_advance) === 1;
}
const fileUploadService = require('../services/fileUploadService');

// Port of App\Controllers\AmendmentController. Spec Section 8 — Payment
// Terms Amendment System (SC/AMD).


async function index(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  // Owner decision D-342: once the Quotation is approved, products and prices change only through an amendment.
  const priceLock = require('../services/priceLock');
  const productsAmendment = {
    fixed: priceLock.isFixed(order),
    message: priceLock.isFixed(order) ? priceLock.message(order) : null,
    why: priceLock.isFixed(order) ? await productsAmendmentService.cannotStart(order) : null,
  };
  res.renderView('amendments/index', { order, amendments: await amendmentRepository.forOrder(orderId), productsAmendment }, 'layout/base');
}

async function create(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const reason = String(req.body.reason || '').trim();
  const requestedBy = String(req.body.requested_by || 'importer');
  const amendedAdvancePct = req.body.amended_advance_pct !== undefined && req.body.amended_advance_pct !== '' ? parseFloat(req.body.amended_advance_pct) : null;
  const amendedAdvanceAmount = req.body.amended_advance_amount !== undefined && req.body.amended_advance_amount !== '' ? parseFloat(req.body.amended_advance_amount) : null;
  const amendedBalanceTerms = String(req.body.amended_balance_terms || '').trim() || null;
  const rawTriggerOption = String(req.body.amended_balance_trigger_option || '');
  const amendedBalanceTriggerOption = ['A_BEFORE_SHIPMENT', 'B_AGAINST_BL'].includes(rawTriggerOption) ? rawTriggerOption : null;
  const amendedBalanceDays = req.body.amended_balance_days !== undefined && req.body.amended_balance_days !== '' ? parseInt(req.body.amended_balance_days, 10) : null;
  const amendedBalanceAmount = req.body.amended_balance_amount !== undefined && req.body.amended_balance_amount !== '' ? parseFloat(req.body.amended_balance_amount) : null;
  const effectiveFrom = String(req.body.effective_from || '').trim() || null;

  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/orders/${orderId}/amendments`);
    return;
  }
  // QA Round 5, R5-28: text in a number field used to be saved as 0.
  const raw = (name) => String(req.body[name] ?? '').trim();
  const numberError = fieldValidator.decimal(raw('amended_advance_pct'), 'Amended advance %', 0, 100)
    || fieldValidator.decimal(raw('amended_advance_amount'), 'Amended advance amount')
    || fieldValidator.wholeNumber(raw('amended_balance_days'), 'Amended balance days', 1)
    || fieldValidator.decimal(raw('amended_balance_amount'), 'Amended balance amount')
    // QA Round 7, R7-N14: an impossible date (2026-02-30, 31/10/2026, text) used to reach the database and show a generic error.
    || (effectiveFrom !== null && !(/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom) && !Number.isNaN(Date.parse(`${effectiveFrom}T00:00:00Z`)) && new Date(`${effectiveFrom}T00:00:00Z`).toISOString().slice(0, 10) === effectiveFrom)
      ? 'Effective from: enter a valid date (YYYY-MM-DD).' : null)
    // Owner decision 1 Nov 2026 (C-4): an amendment cannot take the advance to 0% unless the order's preset may run without one (LC).
    || (amendedAdvancePct === 0 && !(await presetAllowsZero(orderId)) ? 'Amended advance %: 0% is allowed only on a Letter of Credit order. Enter the advance %.' : null)
    // QA batch 2 (ORD-02): the balance is what the advance and the payment milestones leave — never negative.
    || (amendedAdvancePct !== null ? await amendmentService.milestoneOverflowError(orderId, amendedAdvancePct) : null);
  if (numberError) {
    flash.set(req, 'error', numberError);
    res.redirect(`/orders/${orderId}/amendments`);
    return;
  }

  try {
    await amendmentService.createRequest(
      orderId,
      reason,
      requestedBy === 'exporter' ? 'exporter' : 'importer',
      amendedAdvancePct,
      amendedAdvanceAmount,
      amendedBalanceTerms,
      amendedBalanceTriggerOption,
      amendedBalanceDays,
      amendedBalanceAmount,
      effectiveFrom,
      req.user.id
    );
    flash.set(req, 'success', 'Amendment request created — awaiting MD approval.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${orderId}/amendments`);
}

async function mdApprove(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    res.status(404).send('Amendment not found.');
    return;
  }

  try {
    await amendmentService.approveByMd(amendmentId, req.user.id);
    flash.set(req, 'success', 'Amendment MD-approved. Generate the agreement document next.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${amendment.order_id}/amendments`);
}

async function reject(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    res.status(404).send('Amendment not found.');
    return;
  }

  try {
    await amendmentService.rejectAmendment(amendmentId, req.user.id);
    flash.set(req, 'success', 'Amendment request rejected.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${amendment.order_id}/amendments`);
}

async function generateDocument(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    res.status(404).send('Amendment not found.');
    return;
  }

  try {
    await amendmentService.generateDocument(amendmentId, req.user.id);
    // External QA report N-08: this action never reached the audit
    // log, so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'AMENDMENT_DOCUMENT_GENERATED', 'amendments', amendmentId, null, null, null);
    flash.set(req, 'success', `${(amendment.amendment_type || '') === 'products' ? 'Amendment (Products & prices)' : 'Payment Terms Amendment Agreement'} generated. Print, obtain wet signature + company stamp from the Importer, then upload the signed copy.`);
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${amendment.order_id}/amendments`);
}

async function uploadSignedCopy(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    res.status(404).send('Amendment not found.');
    return;
  }
  const order = await orderRepository.find(amendment.order_id);

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'signed_copy',
      'amendment_signed_copy',
      orderStorage.folder(order, 'amendments', `${orderStorage.segment(amendment.amendment_reference)}/received`),
      null,
      amendment.order_id,
      req.user.id,
      null,
      'Buyer',
      (amendment.amendment_type || '') === 'products' ? 'Countersigned Amendment (Products & prices)' : 'Countersigned Payment Terms Amendment Agreement'
    );
    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, req.user.id);
    flash.set(req, 'success', (amendment.amendment_type || '') === 'products'
      ? 'Signed copy uploaded — the amendment is active: the order\'s products and prices are updated (its stage does not change). See the re-issue checklist on the order page.'
      : 'Signed copy uploaded — amendment is now active and payment terms are updated.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${amendment.order_id}/amendments`);
}

/**
 * Spec Section 13 — "Amendment reference numbers" is explicitly named as an
 * Admin-editable field. Business Rule #20 (never printed on any
 * buyer-facing document) is what makes this safe to allow at all — unlike a
 * QT/PI/OC/CI reference, no external party has ever seen this string.
 * Reason mandatory, logged with old/new value.
 */
async function overrideReference(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);
  if (!amendment) {
    res.status(404).send('Amendment not found.');
    return;
  }

  const newReference = String(req.body.amendment_reference || '').trim();
  const reason = String(req.body.reason || '').trim();

  if (newReference === '' || newReference === amendment.amendment_reference) {
    flash.set(req, 'success', 'No change was made.');
    res.redirect(`/orders/${amendment.order_id}/amendments`);
    return;
  }
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/orders/${amendment.order_id}/amendments`);
    return;
  }

  await adminOverrideRepository.updateAmendmentReference(amendmentId, newReference);
  await auditLogRepository.log(req.user.id, 'FIELD_EDIT', 'amendments', amendmentId, 'amendment_reference', amendment.amendment_reference, newReference, reason);
  flash.set(req, 'success', `Amendment reference overridden to ${newReference}.`);
  res.redirect(`/orders/${amendment.order_id}/amendments`);
}

/** Owner decision D-342: New amendment — Products & prices (mockup screen 1). */
async function newProducts(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const why = await productsAmendmentService.cannotStart(order);
  if (why !== null) {
    flash.set(req, 'error', why);
    res.redirect(`/orders/${orderId}/amendments`);
    return;
  }
  await renderProducts(req, res, order, null, [], '');
}

/** Owner decision D-342: "Check the changes" (shows old → new) or "Send to MD for approval". */
async function createProducts(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const why = await productsAmendmentService.cannotStart(order);
  if (why !== null) {
    flash.set(req, 'error', why);
    res.redirect(`/orders/${orderId}/amendments`);
    return;
  }
  const reason = String(req.body.reason || '').trim();
  // "Check the changes" posts with ?step=check (formaction): a disabled submit button sends no value.
  if (String((req.query && req.query.step) || req.body.step || '') === 'check') {
    const draft = await productsAmendmentService.draft(order, req.body);
    await renderProducts(req, res, order, draft, draft.errors, reason);
    return;
  }
  let result;
  try {
    result = await productsAmendmentService.create(orderId, req.body, reason, req.user.id);
  } catch (e) {
    flash.setErrorFromException(req, e);
    res.redirect(`/orders/${orderId}/amendments`);
    return;
  }
  const [id, errors, draft] = result;
  if (id === null) {
    await renderProducts(req, res, order, draft && Object.keys(draft).length ? draft : null, errors, reason);
    return;
  }
  flash.set(req, 'success', 'Amendment request (Products & prices) created — awaiting MD approval. The order changes only when the buyer\'s signed copy is uploaded.');
  res.redirect(`/orders/${orderId}/amendments`);
}

async function renderProducts(req, res, order, draft, errors, reason) {
  const lines = await productsAmendmentService.currentLines(Number(order.id));
  const byLine = {};
  for (const d of ((draft && draft.lines) || [])) {
    if (d.order_product_id !== null) byLine[Number(d.order_product_id)] = d;
  }
  const bySlot = {};
  for (const d of ((draft && draft.lines) || [])) {
    if (d.slot !== undefined) bySlot[d.slot] = d;
  }
  res.renderView('amendments/products_new', {
    order, lines, draft, errors, reason, post: req.body || {}, byLine, bySlot,
    slots: Array.from({ length: productsAmendmentService.NEW_LINE_SLOTS }, (_, k) => k),
    seesRate: !!(req.permissions && req.permissions.view_price_buildup),
    view: productsAmendmentView(order, draft),
  }, 'layout/base');
}

/** The screen's texts worked out once (identical wording to the PHP view). */
function productsAmendmentView(order, draft) {
  const priceLock = require('../services/priceLock');
  const lineTable = require('../services/lineTable');
  const lineUnits = require('../services/lineUnits');
  const t = draft && draft.errors.length === 0 ? draft.totals : null;
  return {
    fixedDate: priceLock.fixedDate(order),
    fixedRate: priceLock.fixedRate(order),
    totals: t,
    signedDifference: t ? productsAmendmentService.signedDifference(t) : '',
    advanceNote: t ? productsAmendmentService.advanceNote(t) : '',
    money: productsAmendmentService.money,
    priceUpMessage: productsAmendmentService.priceUpMessage,
    changeLabels: productsAmendmentService.CHANGE_LABELS,
    num: (v) => (v === null || v === undefined || v === '' ? '' : lineUnits.num(Number(v))),
    qtyInput: (v) => String(Number(v)),
    qty: (v, unit) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-US', { minimumFractionDigits: lineTable.quantityDecimals(unit) ?? 3, maximumFractionDigits: lineTable.quantityDecimals(unit) ?? 3 })),
    byPieces: (l) => !Number(l.as_per_annexure || 0) && lineTable.needsPieces(l.unit, lineUnits.size(l.piece_length_cm), lineUnits.size(l.piece_width_cm), lineUnits.size(l.piece_thickness_cm)),
    pct: (v) => Number(v).toFixed(2).replace(/0+$/, '').replace(/\.$/, ''),
  };
}

/** Owner decision D-342: the re-issue checklist's tick "The signed amendment amends the Buyer PO". */
async function buyerPoAmended(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const error = await productsAmendmentService.tickBuyerPo(orderId, parseInt(req.params.amendmentId, 10), req.user.id);
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Noted: the signed amendment amends the Buyer PO.');
  res.redirect(`/orders/${orderId}#reissue-checklist`);
}

module.exports = { index, create, mdApprove, reject, generateDocument, uploadSignedCopy, overrideReference, newProducts, createProducts, buyerPoAmended };
