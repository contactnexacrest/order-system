'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const adminOverrideRepository = require('../repositories/adminOverrideRepository');
const amendmentRepository = require('../repositories/amendmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderRepository = require('../repositories/orderRepository');
const amendmentService = require('../services/amendmentService');
const fileUploadService = require('../services/fileUploadService');

// Port of App\Controllers\AmendmentController. Spec Section 8 — Payment
// Terms Amendment System (SC/AMD).

function sanitizePathSegment(value) {
  return String(value == null ? '' : value).replace(/[^A-Za-z0-9_-]+/g, '-');
}

async function index(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }

  res.renderView('amendments/index', { order, amendments: await amendmentRepository.forOrder(orderId) }, 'layout/base');
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
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${orderId}/amendments`);
}

async function mdApprove(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);

  try {
    await amendmentService.approveByMd(amendmentId, req.user.id);
    flash.set(req, 'success', 'Amendment MD-approved. Generate the agreement document next.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${(amendment && amendment.order_id) || ''}/amendments`);
}

async function reject(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);

  await amendmentService.rejectAmendment(amendmentId, req.user.id);
  flash.set(req, 'success', 'Amendment request rejected.');

  res.redirect(`/orders/${(amendment && amendment.order_id) || ''}/amendments`);
}

async function generateDocument(req, res) {
  const amendmentId = parseInt(req.params.amendmentId, 10);
  const amendment = await amendmentRepository.find(amendmentId);

  try {
    await amendmentService.generateDocument(amendmentId, req.user.id);
    flash.set(req, 'success', 'Payment Terms Amendment Agreement generated. Print, obtain wet signature + company stamp from the Importer, then upload the signed copy.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${(amendment && amendment.order_id) || ''}/amendments`);
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
      `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/amendments/${sanitizePathSegment(amendment.amendment_reference)}/signed`,
      null,
      amendment.order_id,
      req.user.id,
      null,
      'Buyer',
      'Countersigned Payment Terms Amendment Agreement'
    );
    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, req.user.id);
    flash.set(req, 'success', 'Signed copy uploaded — amendment is now active and payment terms are updated.');
  } catch (e) {
    flash.set(req, 'error', e.message);
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

module.exports = { index, create, mdApprove, reject, generateDocument, uploadSignedCopy, overrideReference };
