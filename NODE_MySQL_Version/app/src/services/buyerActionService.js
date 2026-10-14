'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const stageGateService = require('./stageGateService');
const fileUploadService = require('./fileUploadService');

function sanitizePathSegment(value) {
  return String(value == null ? '' : value).replace(/[^A-Za-z0-9_-]+/g, '-');
}

/**
 * Port of App\Services\BuyerActionService. 90-item backlog #75: the
 * OC-acknowledgment and Buyer-PO-upload write paths used to live only
 * inside clientPortalController, reachable only from a logged-in portal
 * session. This extracts the order-id-scoped core of each (neither ever
 * actually needed the client's own id — only the order's) so both the
 * session-gated client-portal route
 * (clientPortalController.acknowledgeOc()/uploadBuyerPo()) and the new
 * no-login, tokenized buyerActionController route call the exact same
 * logic rather than maintaining two copies of it.
 */
async function acknowledgeOc(orderId) {
  const ack = await orderOcAcknowledgmentRepository.find(orderId);
  if (!ack || ack.acknowledged_at !== null) {
    return { ok: false, message: 'There is nothing awaiting your acknowledgement on this order.' };
  }
  if (!(await stageGateService.isUnlocked(orderId, 4))) {
    return { ok: false, message: 'This order is not currently awaiting acknowledgement.' };
  }

  if (!(await orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'client_portal', null, null))) {
    // QA-5 EML-06: lost the race to another acknowledgment path
    // (staff-recorded, or the 48h auto-confirm job) between this action's
    // own pre-check above and this write.
    return { ok: true, message: 'This order has already been acknowledged and is moving to production.' };
  }
  await stageGateService.passAndUnlockNext(orderId, 4, null);
  return { ok: true, message: 'Thank you — your acknowledgement has been recorded and your order is moving to production.' };
}

async function uploadBuyerPo(req, order) {
  const orderId = order.id;
  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'buyer_po_copy',
      `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/buyer_po`,
      order.client_id,
      orderId,
      null,
      null,
      'Client (self-uploaded)',
      'Buyer PO copy (client-uploaded)'
    );
    await orderBuyerPoDocumentRepository.attach(orderId, fileId);
    await auditLogRepository.log(null, 'BUYER_PO_UPLOADED_BY_CLIENT', 'orders', orderId, null, null, null, 'Buyer PO copy uploaded by the client via the client portal.');
    return { ok: true, message: 'Your Buyer PO copy has been uploaded.' };
  } catch (e) {
    return { ok: false, message: e.message };
  }
}

module.exports = { acknowledgeOc, uploadBuyerPo };
