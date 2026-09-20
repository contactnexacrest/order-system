'use strict';

const flash = require('../helpers/flash');
const documentRepository = require('../repositories/documentRepository');
const emailLogRepository = require('../repositories/emailLogRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const orderRepository = require('../repositories/orderRepository');
const emailDispatchService = require('../services/emailDispatchService');

// Port of App\Controllers\EmailDispatchController. Spec Section 10 — Email
// & Deferred Send System, Level 1 + Level 2.

/**
 * Level 1 — compose/preview screen for one document. Loading the page with
 * ?template_key=... also renders the exact merged subject/body that would
 * be queued — the spec's "Preview: exact watermarked PDF + email content +
 * recipient" before submission for Level-2 approval.
 */
async function compose(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const documentId = parseInt(req.params.documentId, 10);
  const templateKey = String(req.query.template_key || '').trim() || null;

  let preview = null;
  let previewError = null;
  if (templateKey) {
    try {
      preview = await emailDispatchService.buildPreview(orderId, documentId, templateKey, req.user.id);
    } catch (e) {
      previewError = e.message;
    }
  }

  res.renderView(
    'email/compose',
    {
      order: await orderRepository.find(orderId),
      document: await documentRepository.find(documentId),
      orderId,
      documentId,
      templates: await emailTemplateRepository.all(),
      templateKey,
      preview,
      previewError,
    },
    'layout/base'
  );
}

/** Level 1 — submit for Level 2 approval. */
async function requestSend(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const documentId = parseInt(req.params.documentId, 10);
  const templateKey = String(req.body.template_key || '');
  const scheduledAtRaw = String(req.body.scheduled_at || '').trim(); // empty = immediate
  // <input type="datetime-local"> posts "YYYY-MM-DDTHH:MM" — normalize to a
  // MySQL DATETIME/TIMESTAMP-compatible string.
  const scheduledAt = scheduledAtRaw !== '' ? `${scheduledAtRaw.replace('T', ' ')}:00` : null;

  try {
    await emailDispatchService.requestSend(orderId, documentId, templateKey, scheduledAt, req.user.id);
    flash.set(req, 'success', 'Send submitted for approval.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${orderId}`);
}

/** Level 2 — approval queue. */
async function approvalQueue(req, res) {
  res.renderView('email/approvals', { pending: await emailLogRepository.pendingApproval() }, 'layout/base');
}

async function approve(req, res) {
  const id = parseInt(req.params.emailLogId, 10);
  try {
    await emailDispatchService.approveSend(id, req.user.id);
    flash.set(req, 'success', 'Send approved — will go out once its scheduled time arrives (dispatched by the background job).');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect('/email-approvals');
}

async function reject(req, res) {
  const id = parseInt(req.params.emailLogId, 10);
  try {
    await emailDispatchService.rejectSend(id, req.user.id, String(req.body.reason || '').trim());
    flash.set(req, 'success', 'Send rejected.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect('/email-approvals');
}

module.exports = { compose, requestSend, approvalQueue, approve, reject };
