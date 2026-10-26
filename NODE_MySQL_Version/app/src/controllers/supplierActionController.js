'use strict';

const flash = require('../helpers/flash');
const orderStorage = require('../helpers/orderStorage');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderRepository = require('../repositories/orderRepository');
const orderSupplierPoDocumentRepository = require('../repositories/orderSupplierPoDocumentRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const fileUploadService = require('../services/fileUploadService');

/**
 * Port of App\Controllers\SupplierActionController. Owner request
 * (2026-10-26, t4): public, no-login Supplier Action Link for Step 7c —
 * the supplier uploads their signed PO / acknowledgment themselves, the
 * same way the Buyer Action Link works for the buyer. Authorization is the
 * per-PO token (orderSupplierPoRepository.generateActionToken()/
 * findByValidActionToken()).
 *
 * Deliberately shows nothing about the buyer, prices or other documents:
 * just whose PO it is, what has been received so far, and the upload.
 */
async function poOrExpired(res, token) {
  const supplierPo = await orderSupplierPoRepository.findByValidActionToken(token || '');
  if (!supplierPo) {
    res.renderView('supplier_action/link_expired', {}, 'layout/bare');
    return null;
  }
  return supplierPo;
}

async function show(req, res) {
  const supplierPo = await poOrExpired(res, req.params.token);
  if (!supplierPo) {
    return;
  }
  res.renderView('supplier_action/show', {
    token: req.params.token,
    supplierPo,
    companyName: (await companySettingsRepository.get('legal_name')) || 'NexaCrest',
    documents: await orderSupplierPoDocumentRepository.forSupplierPo(supplierPo.id),
  }, 'layout/bare');
}

async function uploadAcknowledgment(req, res) {
  const token = req.params.token;
  const supplierPo = await poOrExpired(res, token);
  if (!supplierPo) {
    return;
  }
  const orderId = supplierPo.order_id;
  const order = await orderRepository.find(orderId);
  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'supplier_po_ack',
      orderStorage.forReceived(order, 'supplier_po'),
      null,
      orderId,
      null,
      null,
      'Supplier (self-uploaded)',
      'Supplier PO acknowledgment (supplier-uploaded)'
    );
    const documentId = await orderSupplierPoDocumentRepository.attach(supplierPo.id, fileId);
    await auditLogRepository.log(null, 'SUPPLIER_PO_DOCUMENT_UPLOADED_BY_SUPPLIER', 'order_supplier_po_documents', documentId, null, null, null, 'Uploaded by the supplier via the Supplier Action Link.');
    await notificationRepository.notifyOrderTeam(orderId, 'supplier_po_ack_uploaded_by_supplier', `The supplier uploaded their signed PO / acknowledgment for ${supplierPo.supplier_po_reference || 'the Supplier PO'} on order ${order.order_reference || `#${orderId}`} — check it and confirm the supplier signed (Step 7d).`);
    flash.set(req, 'success', 'Thank you — your signed PO / acknowledgment has been received.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(`/supplier-action/${token}`);
}

module.exports = { show, uploadAcknowledgment };
