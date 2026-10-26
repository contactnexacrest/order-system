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
const documentRepository = require('../repositories/documentRepository');
const { sendStoredFile } = require('../helpers/sendStoredFile');

/**
 * Port of App\Controllers\SupplierActionController. Owner request
 * (2026-10-26, t4): public, no-login Supplier Action Link for Step 7c —
 * the supplier uploads their signed PO / acknowledgment themselves, the
 * same way the Buyer Action Link works for the buyer. Authorization is the
 * per-PO token (orderSupplierPoRepository.generateActionToken()/
 * findByValidActionToken()).
 *
 * Deliberately shows nothing about the buyer or any other document: just
 * whose PO it is, a download of that Supplier PO once issued, what has
 * been received so far, and the upload.
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
    wrapClass: 'intake-wrap',
    issuedSupplierPo: await issuedSupplierPoDocument(supplierPo.order_id),
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

/**
 * Owner request (2026-10-26): the supplier downloads the Supplier PO
 * (generated in Step 7b) from the link to sign it — only once issued.
 */
async function issuedSupplierPoDocument(orderId) {
  const latest = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'SUPPO');
  if (!latest || !latest.pdf_file_id || !['approved', 'sent'].includes(latest.status)) {
    return null;
  }
  return latest;
}

async function downloadSupplierPo(req, res) {
  const supplierPo = await poOrExpired(res, req.params.token);
  if (!supplierPo) {
    return;
  }
  const document = await issuedSupplierPoDocument(supplierPo.order_id);
  await sendStoredFile(res, document ? document.pdf_file_id : null);
}

module.exports = { show, uploadAcknowledgment, downloadSupplierPo };
