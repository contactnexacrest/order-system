'use strict';

const fs = require('fs');
const orderStorage = require('../helpers/orderStorage');
const flash = require('../helpers/flash');
const orderCommentRepository = require('../repositories/orderCommentRepository');
const orderRepository = require('../repositories/orderRepository');
const fileUploadService = require('../services/fileUploadService');
const orderCommentService = require('../services/orderCommentService');
const emailDispatchService = require('../services/emailDispatchService');

/** docs/schema.sql Section AI — staff side of the order progress chat. */

async function post(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const body = String(req.body.body || '').trim();

  try {
    const order = await orderRepository.find(orderId);
    if (!order) {
      throw new Error(`Order ${orderId} not found`);
    }
    const subPath = orderStorage.folder(order, 'order_updates');
    const fileIds = await fileUploadService.handleMultipleUploads(
      req,
      'attachments',
      'order_comment_media',
      subPath,
      null,
      orderId,
      req.user.id,
      'Staff',
      'Order Update Attachment'
    );
    await orderCommentService.postAsStaff(orderId, req.user.id, body !== '' ? body : null, fileIds);
    // QA Round 5, R5-16: with email sending off, nothing was emailed — say so.
    flash.set(req, 'success', (await emailDispatchService.mailSendingEnabled())
      ? 'Posted — the client has been emailed.'
      : 'Posted to the client portal. Email sending is turned off, so the client was NOT emailed — tell them yourself if it is urgent.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }

  res.redirect(`/orders/${orderId}#order-updates`);
}

/** Staff download of a comment attachment — same permission as any other document download. */
async function downloadAttachment(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const fileId = parseInt(req.params.fileId, 10);
  const file = await orderCommentRepository.findAttachmentForOrder(fileId, orderId);
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('File not found.');
    return;
  }
  const safeDownloadName = file.original_filename.replace(/[\x00-\x1F\x7F"/\\]/g, '');
  res.setHeader('Content-Type', file.mime_type || 'application/octet-stream');
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  fs.createReadStream(file.server_path).pipe(res);
}

module.exports = { post, downloadAttachment };
