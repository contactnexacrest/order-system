'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const orderReorderRequestRepository = require('../repositories/orderReorderRequestRepository');
const orderDuplicationService = require('../services/orderDuplicationService');

/**
 * Port of App\Controllers\ReorderRequestController — staff review queue
 * for client-initiated reorder requests. See the PHP class doc comment
 * for the full rationale.
 */

async function index(req, res) {
  res.renderView('reorder_requests/index', {
    pending: await orderReorderRequestRepository.pendingReview(),
    resolved: await orderReorderRequestRepository.recentResolved(),
  }, 'layout/base');
}

async function show(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const request = await orderReorderRequestRepository.find(id);
  if (!request) {
    flash.set(req, 'error', 'Reorder request not found.');
    res.redirect('/reorder-requests');
    return;
  }

  res.renderView('reorder_requests/show', {
    request,
    lines: await orderReorderRequestRepository.productLines(id),
    hsCodes: await hsCodeRepository.active(),
  }, 'layout/base');
}

/**
 * Staff confirm/adjust each line's HS code, unit price, and any final
 * wording before this becomes a real order — the client's submission is
 * a starting point, not something applied verbatim.
 */
async function approve(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const request = await orderReorderRequestRepository.find(id);
  if (!request || request.status !== 'pending') {
    flash.set(req, 'error', 'Reorder request not found, or already resolved.');
    res.redirect('/reorder-requests');
    return;
  }

  const body = req.body;
  const descriptions = body.description || {};
  const lines = [];
  for (const i of Object.keys(descriptions)) {
    const description = String(descriptions[i] || '').trim();
    if (description === '') continue;
    const hsCode = String((body.hs_code || {})[i] || '').trim();
    if (hsCode === '' || !(await hsCodeRepository.isActiveCode(hsCode))) {
      flash.set(req, 'error', `HS code "${hsCode}" for line "${description}" is not on the HS Code master list — add it there first, or correct it, before approving.`);
      res.redirect(`/reorder-requests/${id}`);
      return;
    }
    lines.push({
      description,
      dimensions: String((body.dimensions || {})[i] || '').trim() || null,
      finish: String((body.finish || {})[i] || '').trim() || null,
      quantity: String((body.quantity || {})[i] || '').trim() || null,
      quantity_is_tbc: !!(body.quantity_is_tbc || {})[i],
      unit: String((body.unit || {})[i] || '').trim() || null,
      unit_price: String((body.unit_price || {})[i] || '').trim() || null,
      hs_code: hsCode,
    });
  }
  if (lines.length === 0) {
    flash.set(req, 'error', 'At least one product line (with a description) is required to approve.');
    res.redirect(`/reorder-requests/${id}`);
    return;
  }

  const newOrderId = await orderDuplicationService.duplicate(request.source_order_id, req.user.id, lines);
  await orderReorderRequestRepository.markApproved(id, req.user.id, newOrderId);

  flash.set(req, 'success', 'Reorder request approved — new order created. Review and adjust it before proceeding.');
  res.redirect(`/orders/${newOrderId}/edit`);
}

async function reject(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const request = await orderReorderRequestRepository.find(id);
  if (!request || request.status !== 'pending') {
    flash.set(req, 'error', 'Reorder request not found, or already resolved.');
    res.redirect('/reorder-requests');
    return;
  }

  const reason = String(req.body.reason || '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/reorder-requests/${id}`);
    return;
  }

  await orderReorderRequestRepository.markRejected(id, req.user.id, reason);
  flash.set(req, 'success', 'Reorder request rejected.');
  res.redirect('/reorder-requests');
}

module.exports = { index, show, approve, reject };
