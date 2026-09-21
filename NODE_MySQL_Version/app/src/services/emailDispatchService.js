'use strict';

const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const documentRepository = require('../repositories/documentRepository');
const emailLogRepository = require('../repositories/emailLogRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const emailService = require('./emailService');
const documentDataAssembler = require('./documentDataAssembler');
const fs = require('fs');

/**
 * Spec Section 10 — EMAIL & DEFERRED SEND SYSTEM, 2-level approval.
 * "Client only receives watermarked PDF. Never DOCX. Never clean PDF." is
 * enforced structurally here: buildPreview()/requestSend() only ever look
 * at document.pdf_file_id (which, once a document is 'approved', is the
 * finalized-watermark file per documentGenerationService.finalizeApproval()
 * — the draft-watermarked PDF's file_store row is a different, earlier id,
 * never referenced from here) and never touch docx_file_id at all.
 */

/** strtr() equivalent: replaces every literal token key with its value, in one pass. */
function strtr(str, tokens) {
  const keys = Object.keys(tokens);
  if (keys.length === 0) return str;
  const pattern = new RegExp(keys.map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g');
  return str.replace(pattern, (match) => (tokens[match] !== undefined && tokens[match] !== null ? String(tokens[match]) : match));
}

async function tokensFor(order, document, sender) {
  const orderId = order.id;
  const qtDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'QT');
  const piDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI');

  return {
    '{buyer_contact_person}': order.contact_person || 'Sir/Madam',
    '{buyer_company_name}': order.company_legal_name,
    '{document_reference}': document.document_reference || '—',
    '{generated_date}': documentDataAssembler.formatDate(document.generated_at),
    '{order_reference}': order.order_reference,
    '{buyer_inquiry_ref}': order.buyer_inquiry_ref,
    '{quotation_ref}': (qtDoc && qtDoc.document_reference) || '—',
    '{quotation_valid_until}': order.quotation_valid_until ? documentDataAssembler.formatDate(order.quotation_valid_until) : '—',
    '{pi_ref}': (piDoc && piDoc.document_reference) || '—',
    '{pi_valid_until}': order.pi_valid_until ? documentDataAssembler.formatDate(order.pi_valid_until) : '—',
    '{company_name}': (await companySettingsRepository.get('legal_name')) || '',
    '{company_email}': (await companySettingsRepository.get('email')) || '',
    '{company_phone}': (await companySettingsRepository.get('phone')) || '',
    '{sender_name}': (sender && sender.name) || (await companySettingsRepository.get('md_name')) || '',
    '{sender_title}': (await companySettingsRepository.get('md_title')) || '',
  };
}

/** @returns {subject, body, recipient_email, document, order} */
async function buildPreview(orderId, documentId, templateKey, senderUserId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }
  const document = await documentRepository.find(documentId);
  if (!document || document.order_id !== orderId) {
    throw new Error(`Document ${documentId} not found for this order`);
  }
  if (document.status !== 'approved' && document.status !== 'sent') {
    throw new Error('Only an approved document can be sent to the buyer — it must clear internal review first (Section 9).');
  }
  if (!order.client_email) {
    throw new Error('This client has no email address on file.');
  }

  const template = await emailTemplateRepository.find(templateKey);
  if (!template) {
    throw new Error(`Unknown email template: ${templateKey}`);
  }

  const sender = await userRepository.findById(senderUserId);
  const tokens = await tokensFor(order, document, sender);

  return {
    subject: strtr(template.subject, tokens),
    body: `${strtr(template.body, tokens)}\n\n${strtr(template.footer || '', tokens)}`,
    recipient_email: order.client_email,
    document,
    order,
  };
}

/** Level 1 — submits for Level 2 approval. Never sends directly, whatever the scheduled time. */
async function requestSend(orderId, documentId, templateKey, scheduledAt, requestedByUserId) {
  const preview = await buildPreview(orderId, documentId, templateKey, requestedByUserId);

  if (await emailLogRepository.hasActiveSendFor(documentId)) {
    throw new Error('A send for this document is already awaiting approval or scheduled to go out — wait for it to send, be rejected, or fail before submitting another.');
  }

  const id = await emailLogRepository.create(
    orderId,
    documentId,
    templateKey,
    preview.recipient_email,
    preview.subject,
    preview.body,
    scheduledAt,
    requestedByUserId
  );

  const activeUsers = await userRepository.listActive();
  for (const user of activeUsers) {
    if (user.role_name === 'Admin' || user.role_name === 'Managing Director') {
      await notificationRepository.create(user.id, null, 'email_send_pending_approval', orderId, `A send to ${preview.recipient_email} is awaiting your approval.`);
    }
  }

  await auditLogRepository.log(requestedByUserId, 'EMAIL_SEND_REQUESTED', 'email_log', id, 'recipient_email', null, preview.recipient_email);
  return id;
}

async function approveSend(emailLogId, approverUserId) {
  const row = await emailLogRepository.find(emailLogId);
  if (!row || row.status !== 'pending_approval') {
    throw new Error('This send is not awaiting approval.');
  }
  await emailLogRepository.approve(emailLogId, approverUserId);
  await auditLogRepository.log(approverUserId, 'EMAIL_SEND_APPROVED', 'email_log', emailLogId);
}

async function rejectSend(emailLogId, approverUserId, reason) {
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    throw new Error(reasonError);
  }
  const row = await emailLogRepository.find(emailLogId);
  if (!row || row.status !== 'pending_approval') {
    throw new Error('This send is not awaiting approval.');
  }
  await emailLogRepository.reject(emailLogId, approverUserId, reason);
  if (row.requested_by !== null && row.requested_by !== undefined) {
    await notificationRepository.create(row.requested_by, null, 'email_send_rejected', row.order_id, `Your send to ${row.recipient_email} was rejected: ${reason}`);
  }
  await auditLogRepository.log(approverUserId, 'EMAIL_SEND_REJECTED', 'email_log', emailLogId, null, null, null, reason);
}

/**
 * The actual send — called only by the deferred-email-dispatch background
 * job, never synchronously from a web request (see that job's docblock).
 * Returns true on send success.
 */
async function dispatch(emailLogRow) {
  if (emailLogRow.document_id === null || emailLogRow.document_id === undefined) {
    await emailLogRepository.markFailed(emailLogRow.id);
    return false;
  }
  const document = await documentRepository.find(emailLogRow.document_id);
  if (!document || !document.pdf_file_id) {
    await emailLogRepository.markFailed(emailLogRow.id);
    return false;
  }
  const file = await fileStoreRepository.find(document.pdf_file_id);
  if (!file || !fs.existsSync(file.server_path)) {
    await emailLogRepository.markFailed(emailLogRow.id);
    return false;
  }

  const sent = await emailService.sendWithAttachment(
    emailLogRow.recipient_email,
    emailLogRow.subject,
    emailLogRow.body_snapshot,
    file.server_path,
    file.original_filename
  );

  if (sent) {
    await emailLogRepository.markSent(emailLogRow.id);
    await documentRepository.markSent(document.id);
    await auditLogRepository.log(null, 'EMAIL_SENT', 'email_log', emailLogRow.id, 'recipient_email', null, emailLogRow.recipient_email);
  } else {
    await emailLogRepository.markFailed(emailLogRow.id);
  }

  return sent;
}

module.exports = { buildPreview, requestSend, approveSend, rejectSend, dispatch };
