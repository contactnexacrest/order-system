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
  await renderCompose(req, res, orderId, documentId);
}

/**
 * Same screen, no document — for a generic template (docs/schema.sql
 * Section AI's email template CRUD; e.g. a payment reminder) that isn't
 * tied to sending a specific buyer-facing PDF. Preview + copy only, no
 * approval-queue submission (see email/compose.njk).
 */
async function composeGeneric(req, res) {
  const orderId = parseInt(req.params.id, 10);
  await renderCompose(req, res, orderId, null);
}

async function renderCompose(req, res, orderId, documentId) {
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
      document: documentId ? await documentRepository.find(documentId) : null,
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

/** Level 2 — approval queue. Shows both awaiting-approval and already-approved-but-not-yet-sent rows, since both are still cancellable. */
async function approvalQueue(req, res) {
  res.renderView('email/approvals', { pending: await emailLogRepository.awaitingDispatch() }, 'layout/base');
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

/**
 * Cancel a send still in its deferred window (pending_approval or approved,
 * not yet sent). Open to a Level-2 approver for any row, or to anyone else
 * only for a row they themselves requested — enforced in the service, not
 * by route-level permission, since ownership can't be checked until the
 * row is loaded. redirectTo is rebuilt from a validated integer order id
 * rather than trusted from the request body, so this can't be used as an
 * open redirect.
 */
async function cancel(req, res) {
  const id = parseInt(req.params.emailLogId, 10);
  const reason = String(req.body.reason || '').trim();
  const isApprover = !!(req.permissions && req.permissions.approve_email_send);

  let redirectTo = '/email-approvals';
  const orderId = parseInt(req.body.order_id, 10);
  if (Number.isInteger(orderId) && orderId > 0) {
    redirectTo = `/orders/${orderId}`;
  }

  try {
    await emailDispatchService.cancelSend(id, req.user.id, reason, isApprover);
    flash.set(req, 'success', 'Send cancelled before it went out.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  res.redirect(redirectTo);
}

module.exports = { compose, composeGeneric, requestSend, approvalQueue, approve, reject, cancel };
