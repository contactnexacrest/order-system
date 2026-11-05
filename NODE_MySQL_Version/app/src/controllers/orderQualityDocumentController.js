'use strict';

// Port of App\Controllers\OrderQualityDocumentController. Owner decision
// D-257 Phase 0B (5 Nov 2026): the order page's quality documents
// checklist — each document a line's category asks for is pending,
// received or approved, with its file. Internal only (never on the
// client portal).

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderRepository = require('../repositories/orderRepository');
const orderQualityDocumentRepository = require('../repositories/orderQualityDocumentRepository');
const fileUploadService = require('../services/fileUploadService');
const orderStorage = require('../helpers/orderStorage');

async function save(req, res) {
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const back = `/orders/${orderId}#quality-documents`;
  const categoryId = parseInt(req.body.category_id, 10) || 0;
  const status = String(req.body.status ?? '');
  const note = String(req.body.note ?? '').trim();
  const doc = await orderQualityDocumentRepository.required(orderId, categoryId, req.body.document_name);
  if (!doc) {
    flash.set(req, 'error', 'This order does not ask for that quality document.');
    res.redirect(back);
    return;
  }
  if (!Object.prototype.hasOwnProperty.call(orderQualityDocumentRepository.STATUSES, status)) {
    flash.set(req, 'error', `${doc.document_name}: choose Pending, Received or Approved.`);
    res.redirect(back);
    return;
  }
  if ([...note].length > 255) {
    flash.set(req, 'error', `${doc.document_name}: the note can be at most 255 characters.`);
    res.redirect(back);
    return;
  }
  const hasFile = !!(req.file && req.file.buffer && req.file.size > 0);
  if (status !== 'pending' && !hasFile && doc.file_store_id === null) {
    flash.set(req, 'error', `${doc.document_name}: attach the document before marking it ${orderQualityDocumentRepository.STATUSES[status].toLowerCase()}.`);
    res.redirect(back);
    return;
  }
  let fileId = null;
  if (hasFile) {
    try {
      fileId = await fileUploadService.handleUpload(req, 'file', 'quality_document', orderStorage.folder(order, 'supplier_po', 'quality-documents'),
        null, orderId, req.user.id, null, null, doc.document_name);
    } catch (e) {
      flash.setErrorFromException(req, e);
      res.redirect(back);
      return;
    }
  }
  await orderQualityDocumentRepository.save(orderId, doc.category_id, doc.document_name, status, fileId, note !== '' ? note : null, req.user.id);
  await auditLogRepository.log(req.user.id, 'QUALITY_DOCUMENT_UPDATED', 'orders', orderId, doc.document_name,
    doc.status, status + (fileId !== null ? ' (file attached)' : ''));
  flash.set(req, 'success', `${doc.document_name} marked ${orderQualityDocumentRepository.STATUSES[status].toLowerCase()}.`);
  res.redirect(back);
}

module.exports = { save };
