'use strict';

const flash = require('../helpers/flash');
const orderRepository = require('../repositories/orderRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const documentRepository = require('../repositories/documentRepository');
const buyerActionService = require('../services/buyerActionService');
const { sendStoredFile } = require('../helpers/sendStoredFile');
const documentGenerationService = require('../services/documentGenerationService');

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
 * document downloads or chat — those stay portal-login-only. 90-item
 * backlog #48 added one narrow exception to "no payment reports": the
 * advance-payment self-report below, since that's the one payment leg
 * the portal itself can never reach (its login isn't provisioned until
 * the advance clears — see buyerActionService.reportAdvancePayment()).
 * Freight/balance self-reports stay portal-only, as before.
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

  const paymentStatus = await orderPaymentStatusRepository.find(order.id);

  // User-reported batch item 10: the acknowledgement section below only
  // ever rendered when an order_oc_acknowledgments row already existed —
  // but that row was only ever created by emailDispatchService.dispatch()
  // when the OC email actually went out. A Buyer Action Link generated
  // before that email was sent (still awaiting Level-2 approval, mail
  // misconfigured, or staff just generated the link to hand the buyer
  // directly) led to a page with nothing to acknowledge at all. Same lazy
  // fallback ordersController.recordOcAcknowledgment() already uses for
  // the staff-side "Record Buyer's Acknowledgement" path.
  let ocAcknowledgment = await orderOcAcknowledgmentRepository.find(order.id);
  if (!ocAcknowledgment) {
    // Only once the OC counts as issued (approved while email is off, sent
    // while it is on) — the same rule the Stage 4 gate itself applies.
    const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(order.id, 'OC');
    if (ocDoc && !(await documentGenerationService.stageDocumentBlockedBy(order.id, 4)).length) {
      await orderOcAcknowledgmentRepository.ensureRowExists(order.id, ocDoc.id);
      ocAcknowledgment = await orderOcAcknowledgmentRepository.find(order.id);
    }
  }

  res.renderView('buyer_action/show', {
    wrapClass: 'intake-wrap',
    task: await buyerActionService.currentTask(order, ocAcknowledgment),
    issuedBuyerPo: await buyerActionService.issuedBuyerDocument(order.id, 'BUYERPO'),
    issuedPi: await buyerActionService.issuedBuyerDocument(order.id, 'PI'),
    token: req.params.token,
    order,
    products: await orderProductRepository.forOrder(order.id),
    totalFobValue: await orderProductRepository.totalFobValue(order.id),
    ocAcknowledgment,
    buyerPoDocuments: await orderBuyerPoDocumentRepository.forOrder(order.id),
    advanceCleared: !!paymentStatus && paymentStatus.advance_cleared_at !== null,
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

async function reportPayment(req, res) {
  const token = req.params.token;
  const order = await orderOrExpired(req, res, token);
  if (!order) {
    return;
  }

  const result = await buyerActionService.reportAdvancePayment(req, order);
  flash.set(req, result.ok ? 'success' : 'error', result.message);
  res.redirect(`/buyer-action/${token}`);
}

/**
 * Owner request (2026-10-26): from the link the buyer downloads the Buyer
 * PO (to sign) and, later, the Proforma Invoice (to pay against) — each only
 * once issued, and nothing else.
 */
async function downloadIssued(req, res, typeCode) {
  const order = await orderOrExpired(req, res, req.params.token);
  if (!order) {
    return;
  }
  const document = await buyerActionService.issuedBuyerDocument(order.id, typeCode);
  await sendStoredFile(res, document ? document.pdf_file_id : null);
}

const downloadBuyerPo = (req, res) => downloadIssued(req, res, 'BUYERPO');
const downloadProformaInvoice = (req, res) => downloadIssued(req, res, 'PI');

module.exports = { show, acknowledgeOc, uploadBuyerPo, reportPayment, downloadBuyerPo, downloadProformaInvoice };
