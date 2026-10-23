'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderComplaintRepository = require('../repositories/orderComplaintRepository');
const orderComplaintReplyRepository = require('../repositories/orderComplaintReplyRepository');
const orderRepository = require('../repositories/orderRepository');
const permissionRepository = require('../repositories/permissionRepository');
const userRepository = require('../repositories/userRepository');
const mailSenderService = require('./mailSenderService');

/**
 * docs/schema.sql Section BX — a Complaint / Process Issue. Modeled on
 * orderCommentService: a client-raised complaint or reply notifies every
 * manage_complaints/respond_to_complaints holder in-app (nobody at
 * NexaCrest gets emailed for their own staff's actions); a staff reply
 * is emailed directly to the client, the same as a staff order comment,
 * since the client has no in-app inbox of their own.
 */
const STAFF_PERMISSIONS = ['manage_complaints', 'respond_to_complaints'];

async function raiseAsClient(orderId, clientId, description) {
  const complaintId = await orderComplaintRepository.create(orderId, clientId, description);
  await notifyStaff(orderId, 'complaint_raised', 'New process complaint on order %s.');
  await auditLogRepository.log(null, 'COMPLAINT_RAISED', 'order_complaints', complaintId, null, null, description, 'Raised by the client via the client portal.');
  return complaintId;
}

async function raiseAsStaff(orderId, clientId, authorUserId, description) {
  const complaintId = await orderComplaintRepository.create(orderId, clientId, description);
  await auditLogRepository.log(authorUserId, 'COMPLAINT_RAISED', 'order_complaints', complaintId, null, null, description, "Logged by staff on the client's behalf.");
  return complaintId;
}

async function replyAsStaff(complaintId, authorUserId, body) {
  const replyId = await orderComplaintReplyRepository.createAsStaff(complaintId, authorUserId, body);
  await emailClient(complaintId, authorUserId, body);
  await auditLogRepository.log(authorUserId, 'COMPLAINT_REPLY_POSTED', 'order_complaints', complaintId, null, null, body);
  return replyId;
}

async function replyAsClient(complaintId, clientId, body) {
  const replyId = await orderComplaintReplyRepository.createAsClient(complaintId, clientId, body);
  const complaint = await orderComplaintRepository.find(complaintId);
  if (complaint) {
    await notifyStaff(complaint.order_id, 'complaint_reply_from_client', 'New client reply on a complaint for order %s.');
  }
  await auditLogRepository.log(null, 'COMPLAINT_REPLY_POSTED', 'order_complaints', complaintId, null, null, body, 'Replied by the client via the client portal.');
  return replyId;
}

async function updateStatus(complaintId, status, actingUserId) {
  const complaint = await orderComplaintRepository.find(complaintId);
  await orderComplaintRepository.updateStatus(complaintId, status);
  await auditLogRepository.log(actingUserId, 'COMPLAINT_STATUS_CHANGED', 'order_complaints', complaintId, 'status', complaint ? complaint.status : null, status);
}

async function notifyStaff(orderId, type, messageTemplate) {
  const order = await orderRepository.find(orderId);
  const orderRef = (order && order.order_reference) || `#${orderId}`;
  const message = messageTemplate.replace('%s', orderRef);
  const notified = new Set();
  for (const permission of STAFF_PERMISSIONS) {
    for (const userId of await permissionRepository.usersWithPermission(permission)) {
      if (notified.has(userId)) continue;
      notified.add(userId);
      await notificationRepository.create(userId, null, type, orderId, message);
    }
  }
}

async function emailClient(complaintId, authorUserId, body) {
  const complaint = await orderComplaintRepository.find(complaintId);
  if (!complaint) return;
  const order = await orderRepository.find(complaint.order_id);
  if (!order || !order.client_email) return;

  const sender = await userRepository.findById(authorUserId);
  const subject = `Update on your complaint for order ${order.order_reference} — ${await companySettingsRepository.get('legal_name')}`;
  const greeting = `Dear ${order.contact_person || 'Sir/Madam'},\n\n`;
  const signature = (sender && sender.email_signature ? String(sender.email_signature).trim() : '');
  const message = greeting + body + '\n\n' + (signature !== ''
    ? signature
    : `Regards,\n${(sender && sender.name) || ''}\n${await companySettingsRepository.get('md_title')}`);

  await mailSenderService.send(order.client_email, subject, message, []);
}

module.exports = { raiseAsClient, raiseAsStaff, replyAsStaff, replyAsClient, updateStatus };
