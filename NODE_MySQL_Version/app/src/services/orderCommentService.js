'use strict';

const env = require('../config/env');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderCommentRepository = require('../repositories/orderCommentRepository');
const orderRepository = require('../repositories/orderRepository');
const permissionRepository = require('../repositories/permissionRepository');
const userRepository = require('../repositories/userRepository');
const mailSenderService = require('./mailSenderService');

/**
 * docs/schema.sql Section AI — the order progress chat. A staff post is
 * immediately emailed to the client (attachments included, up to
 * MAX_DIRECT_ATTACH_BYTES combined — anything larger becomes a portal
 * download link instead, so a big video can never silently cause the
 * whole send to be rejected by the mail transport). A client post never
 * emails the client back; it raises an in-app notification to every user
 * with manage_orders instead.
 */

// Combined size cap for attachments sent directly on the email itself.
// Kept comfortably under typical SMTP/Zoho Mail attachment limits
// (usually ~20-25MB before MIME base64 overhead inflates it further).
const MAX_DIRECT_ATTACH_BYTES = 15 * 1024 * 1024;

function normalizeBody(body, fileStoreIds) {
  const trimmed = body != null ? String(body).trim() : null;
  if ((!trimmed || trimmed === '') && (!fileStoreIds || fileStoreIds.length === 0)) {
    throw new Error('Write a message or attach a file before posting.');
  }
  return trimmed !== '' ? trimmed : null;
}

async function postAsStaff(orderId, authorUserId, body, fileStoreIds) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  const bodyTrim = normalizeBody(body, fileStoreIds);
  const commentId = await orderCommentRepository.create(orderId, 'staff', authorUserId, null, bodyTrim);
  for (const fileId of fileStoreIds) {
    await orderCommentRepository.attachFile(commentId, fileId);
  }

  if (order.client_email) {
    await emailClient(order, authorUserId, commentId, bodyTrim, fileStoreIds);
  }

  await auditLogRepository.log(authorUserId, 'ORDER_COMMENT_POSTED', 'orders', orderId);
  return commentId;
}

async function postAsClient(orderId, clientId, body, fileStoreIds) {
  const bodyTrim = normalizeBody(body, fileStoreIds);
  const commentId = await orderCommentRepository.create(orderId, 'client', null, clientId, bodyTrim);
  for (const fileId of fileStoreIds) {
    await orderCommentRepository.attachFile(commentId, fileId);
  }

  const order = await orderRepository.find(orderId);
  const orderRef = (order && order.order_reference) || `#${orderId}`;
  for (const userId of await permissionRepository.usersWithPermission('manage_orders')) {
    await notificationRepository.create(userId, null, 'order_comment_from_client', orderId, `New client message on order ${orderRef}.`);
  }

  await auditLogRepository.log(null, 'ORDER_COMMENT_POSTED', 'orders', orderId);
  return commentId;
}

async function emailClient(order, authorUserId, commentId, body, fileStoreIds) {
  const sender = await userRepository.findById(authorUserId);
  const subject = `Update on your order ${order.order_reference} — ${await companySettingsRepository.get('legal_name')}`;

  const greeting = `Dear ${order.contact_person || 'Sir/Madam'},\n\n`;
  let message = greeting + (body || 'Please see the attached file(s).') + '\n\n';

  const direct = [];
  const linked = [];
  let totalSize = 0;
  for (const fileId of fileStoreIds) {
    const file = await fileStoreRepository.find(fileId);
    if (!file) continue;
    const size = parseInt(file.file_size_bytes, 10);
    if (totalSize + size <= MAX_DIRECT_ATTACH_BYTES) {
      direct.push({ path: file.server_path, name: file.original_filename });
      totalSize += size;
    } else {
      linked.push(file);
    }
  }

  if (linked.length) {
    message += 'The following file(s) are too large to attach directly to this email — view or download them securely from your order portal:\n';
    const baseUrl = (env.get('APP_URL', '') || '').replace(/\/+$/, '');
    for (const file of linked) {
      message += `- ${file.original_filename}: ${baseUrl}/client/orders/${order.id}/comment-attachments/${file.id}/download\n`;
    }
    message += '\n';
  }

  const signature = (sender && sender.email_signature ? String(sender.email_signature).trim() : '');
  message += signature !== ''
    ? signature
    : `Regards,\n${(sender && sender.name) || ''}\n${await companySettingsRepository.get('md_title')}`;

  const sent = await mailSenderService.send(order.client_email, subject, message, direct);
  if (sent) {
    await orderCommentRepository.markEmailSent(commentId);
  }
}

module.exports = { postAsStaff, postAsClient };
