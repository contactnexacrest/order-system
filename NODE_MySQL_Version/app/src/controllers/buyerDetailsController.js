'use strict';

const flash = require('../helpers/flash');
const orderRepository = require('../repositories/orderRepository');
const orderAccessService = require('../services/orderAccessService');
const buyerDetails = require('../services/buyerDetailsRefreshService');

/**
 * Owner decision D-337 (12 Nov 2026): the order page's "buyer details" card —
 * update a draft order from its client, ask to update an order with an issued
 * document, and approve / refuse that request. The rules live in
 * buyerDetailsRefreshService. Port of App\Controllers\BuyerDetailsController.
 */

/** @returns {Promise<object|null>} the order, or null after answering */
async function load(req, res, orderId) {
  const order = await orderRepository.find(orderId);
  if (!order || !req.user) {
    res.status(404).send('Order not found.');
    return null;
  }
  const denied = await orderAccessService.denialReason(order, Number(req.user.id), req.permissions);
  if (denied !== null) {
    res.status(403).send(denied);
    return null;
  }
  return order;
}

function back(res, orderId) {
  res.redirect(`/orders/${orderId}#buyer-details-change`);
}

/** POST /orders/:id/buyer-details/refresh — nothing issued yet: copy the client's details now. */
async function refresh(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await load(req, res, orderId))) return;
  const result = await buyerDetails.refreshOrder(orderId, Number(req.user.id));
  if (result.error !== null) {
    flash.set(req, 'error', result.error);
  } else {
    flash.set(req, 'success', `Buyer details updated from the client: ${buyerDetails.changesText(result.changes)}.`);
  }
  back(res, orderId);
}

/** POST /orders/:id/buyer-details/request — an issued document exists: ask for approval. */
async function request(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await load(req, res, orderId))) return;
  const error = await buyerDetails.request(orderId, Number(req.user.id), String((req.body && req.body.reason) || ''));
  flash.set(req, error === null ? 'success' : 'error', error || 'Request sent — the buyer details change once it is approved.');
  back(res, orderId);
}

/** POST /orders/:id/buyer-details/:requestId/approve */
async function approve(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await load(req, res, orderId))) return;
  const error = await buyerDetails.approve(parseInt(req.params.requestId, 10), orderId, Number(req.user.id), String((req.body && req.body.decision_note) || ''));
  flash.set(req, error === null ? 'success' : 'error', error || 'Approved — the order now shows the client\'s current details. Issued documents keep the details they were issued with.');
  back(res, orderId);
}

/** POST /orders/:id/buyer-details/:requestId/refuse */
async function refuse(req, res) {
  const orderId = parseInt(req.params.id, 10);
  if (!(await load(req, res, orderId))) return;
  const error = await buyerDetails.refuse(parseInt(req.params.requestId, 10), orderId, Number(req.user.id), String((req.body && req.body.decision_note) || ''));
  flash.set(req, error === null ? 'success' : 'error', error || 'Refused — the order keeps its buyer details.');
  back(res, orderId);
}

module.exports = { refresh, request, approve, refuse };
