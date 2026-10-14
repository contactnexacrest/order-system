'use strict';

const flash = require('../helpers/flash');
const orderRepository = require('../repositories/orderRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const buyerActionService = require('../services/buyerActionService');

/**
 * Port of App\Controllers\BuyerActionController. 90-item backlog #75:
 * public, no-login counterpart to the two client-portal actions buyers
 * most often need right after receiving their Order Confirmation —
 * acknowledging it, and uploading their signed Buyer PO copy.
 * Authorization is a per-order token
 * (orderRepository.generateBuyerActionToken()/findByValidBuyerActionToken(),
 * docs/schema.sql Section BG) rather than a client-portal session,
 * modeled on the existing public /quotation-details and /pi-details
 * links. The actual write logic lives in buyerActionService, shared with
 * clientPortalController so the session-gated and token-gated routes
 * never drift apart.
 *
 * Deliberately narrower than the full client-portal order page: no
 * document downloads, payment reports, or chat — those stay
 * portal-login-only. This page exists to unblock exactly the two actions
 * the backlog item named.
 */

/**
 * Validates the token, then re-loads the full, client-joined order row
 * via find() — findByValidBuyerActionToken() stays a minimal
 * authorization check, not a second copy of find()'s whole JOIN.
 */
async function orderOrExpired(req, res, token) {
  const tokenRow = await orderRepository.findByValidBuyerActionToken(token || '');
  if (!tokenRow) {
    res.renderView('buyer_action/link_expired', {}, 'layout/bare');
    return null;
  }
  return orderRepository.find(tokenRow.id);
}

async function show(req, res) {
  const order = await orderOrExpired(req, res, req.params.token);
  if (!order) {
    return;
  }

  res.renderView('buyer_action/show', {
    token: req.params.token,
    order,
    products: await orderProductRepository.forOrder(order.id),
    totalFobValue: await orderProductRepository.totalFobValue(order.id),
    ocAcknowledgment: await orderOcAcknowledgmentRepository.find(order.id),
    buyerPoDocuments: await orderBuyerPoDocumentRepository.forOrder(order.id),
  }, 'layout/bare');
}

async function acknowledgeOc(req, res) {
  const token = req.params.token;
  const order = await orderOrExpired(req, res, token);
  if (!order) {
    return;
  }

  const result = await buyerActionService.acknowledgeOc(order.id);
  flash.set(req, result.ok ? 'success' : 'error', result.message);
  res.redirect(`/buyer-action/${token}`);
}

async function uploadBuyerPo(req, res) {
  const token = req.params.token;
  const order = await orderOrExpired(req, res, token);
  if (!order) {
    return;
  }

  const result = await buyerActionService.uploadBuyerPo(req, order);
  flash.set(req, result.ok ? 'success' : 'error', result.message);
  res.redirect(`/buyer-action/${token}`);
}

module.exports = { show, acknowledgeOc, uploadBuyerPo };
