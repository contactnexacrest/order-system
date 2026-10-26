'use strict';

const flash = require('../helpers/flash');
const orderRepository = require('../repositories/orderRepository');
const paymentActionService = require('../services/paymentActionService');
const { sendStoredFile } = require('../helpers/sendStoredFile');

/**
 * Port of App\Controllers\PaymentActionController. Owner request
 * (2026-10-27): public, no-login Payment Action Link — the client sends
 * payment details for the advance, freight or balance, one payment at a
 * time. Payment only: no Buyer PO, no Order Confirmation. Authorization is
 * the per-order token (orderRepository.findByValidPaymentActionToken()),
 * like the Buyer Action Link.
 */
async function orderOrExpired(req, res, token) {
  const tokenRow = await orderRepository.findByValidPaymentActionToken(token || '');
  if (!tokenRow) {
    res.renderView('payment_action/link_expired', {}, 'layout/bare');
    return null;
  }
  return orderRepository.find(tokenRow.id);
}

async function show(req, res) {
  const token = req.params.token;
  const order = await orderOrExpired(req, res, token);
  if (!order) {
    return;
  }
  const current = await paymentActionService.current(order);
  const leg = current.leg;
  res.renderView('payment_action/show', {
    wrapClass: 'intake-wrap',
    token,
    order,
    current,
    legs: paymentActionService.LEGS,
    legInfo: leg ? paymentActionService.LEGS[leg] : null,
    document: leg ? await paymentActionService.issuedDocument(order.id, leg) : null,
    amountDue: leg ? await paymentActionService.amountDue(order, leg) : null,
  }, 'layout/bare');
}

async function report(req, res) {
  const token = req.params.token;
  const order = await orderOrExpired(req, res, token);
  if (!order) {
    return;
  }
  const result = await paymentActionService.report(req, order);
  flash.set(req, result.ok ? 'success' : 'error', result.message);
  res.redirect(`/payment-action/${token}`);
}

/** The document the current payment is made against — the PI, Debit Note or CI — and nothing else. */
async function downloadDocument(req, res) {
  const order = await orderOrExpired(req, res, req.params.token);
  if (!order) {
    return;
  }
  const leg = (await paymentActionService.current(order)).leg;
  const document = leg ? await paymentActionService.issuedDocument(order.id, leg) : null;
  await sendStoredFile(res, document ? document.pdf_file_id : null);
}

module.exports = { show, report, downloadDocument };
