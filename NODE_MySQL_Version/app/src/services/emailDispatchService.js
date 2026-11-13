'use strict';

const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const paymentTermsApprovalService = require('./paymentTermsApprovalService');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const documentRepository = require('../repositories/documentRepository');
const emailLogRepository = require('../repositories/emailLogRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const orderRepository = require('../repositories/orderRepository');
const env = require('../config/env');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const { formatForDb } = require('../helpers/dbDateTime');
const permissionRepository = require('../repositories/permissionRepository');
const roleRepository = require('../repositories/roleRepository');
const signatoryRepository = require('../repositories/signatoryRepository');
const userRepository = require('../repositories/userRepository');
const mailSenderService = require('./mailSenderService');
const emailPolicy = require('./emailPolicy');
const documentDataAssembler = require('./documentDataAssembler');
const stageGateService = require('./stageGateService');
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
  // QA batch 3 MAIL-10: a known token with no value renders as "", as PHP's strtr() does — never the literal {token}.
  return str.replace(pattern, (match) => (tokens[match] !== undefined && tokens[match] !== null ? String(tokens[match]) : ''));
}

/**
 * QA-5 EML-08 / L-12 (and QA batch 3 MAIL-5, reused for comment and complaint
 * replies; port of PHP senderTitle()): the sender's own designation, else
 * their role name; the company md_title only when there is no sender at all.
 */
async function senderTitleFor(sender) {
  let senderTitle = sender ? '' : ((await companySettingsRepository.get('md_title')) || '');
  if (sender && sender.designation_id) {
    const designation = await signatoryRepository.findDesignation(sender.designation_id);
    if (designation) {
      senderTitle = designation.title;
    }
  }
  if (sender && senderTitle === '' && sender.role_id) {
    const role = await roleRepository.find(sender.role_id);
    if (role) {
      senderTitle = role.name;
    }
  }
  if (senderTitle === '') {
    senderTitle = (await companySettingsRepository.get('md_title')) || '';
  }
  return senderTitle;
}

async function tokensFor(order, document, sender) {
  const orderId = order.id;
  const qtDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'QT');
  const piDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI');
  const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'OC');

  // QA-5 EML-08: {sender_title} used to always resolve to the company-wide
  // md_title setting ("Founder & Managing Director") regardless of who
  // actually sent the email — a Logistics or Accounts user's approved send
  // would go out signed with the MD's own title. Use the real sender's own
  // designation when they have one.
  // External QA report L-12: a sender with no designation set still fell
  // all the way back to md_title, so e.g. an Export Executive's email went
  // out signed "Founder & Managing Director". Fall back to the sender's own
  // role name instead; md_title is now only the fallback when there is no
  // specific sender at all (system emails).
  const senderTitle = await senderTitleFor(sender);

  let signature = (sender && sender.email_signature ? String(sender.email_signature).trim() : '');
  if (signature === '') {
    signature = `${(sender && sender.name) || (await companySettingsRepository.get('md_name')) || ''}\n${senderTitle}`;
  }

  return {
    '{buyer_contact_person}': order.contact_person || 'Sir/Madam',
    '{buyer_company_name}': order.company_legal_name,
    '{document_reference}': (document && document.document_reference) || '—',
    '{generated_date}': document ? documentDataAssembler.formatDate(documentRepository.printedDate(document)) : '—',
    '{order_reference}': order.order_reference,
    '{buyer_inquiry_ref}': order.buyer_inquiry_ref,
    '{quotation_ref}': (qtDoc && qtDoc.document_reference) || '—',
    '{quotation_valid_until}': order.quotation_valid_until ? documentDataAssembler.formatDate(order.quotation_valid_until) : '—',
    '{pi_ref}': (piDoc && piDoc.document_reference) || '—',
    '{pi_valid_until}': order.pi_valid_until ? documentDataAssembler.formatDate(order.pi_valid_until) : '—',
    '{oc_ref}': (ocDoc && ocDoc.document_reference) || '—',
    '{company_name}': (await companySettingsRepository.get('legal_name')) || '',
    '{company_email}': (await companySettingsRepository.get('email')) || '',
    '{company_phone}': (await companySettingsRepository.get('phone')) || '',
    '{sender_name}': (sender && sender.name) || (await companySettingsRepository.get('md_name')) || '',
    '{sender_title}': senderTitle,
    '{sender_signature}': signature,
    // QA batch 3 MAIL-12: the PI / CI e-mail's payment request follows the order.
    '{payment_request_sentence}': await paymentRequestSentence(order, document),
  };
}

/** QA batch 3 MAIL-12 (port of PHP paymentRequestSentence). */
async function paymentRequestSentence(order, document) {
  const code = document ? document.document_type_code : null;
  if (code === 'CI') {
    const status = await require('../repositories/orderPaymentStatusRepository').find(order.id);
    if (status && (status.balance_remittance_received_at || status.balance_cleared_at)) {
      return 'Please check the invoice amount and the payment details. We have already received the balance payment — thank you.';
    }
    return 'Please check the invoice amount and the payment details, and pay the balance as agreed.';
  }
  if (parseFloat(order.advance_pct || 0) > 0) {
    return 'Please pay the advance as agreed, so that we can start production.';
  }
  if (order.payment_method === 'LC') {
    return 'Please open the letter of credit (LC) as agreed, so that we can start production.';
  }
  return 'Please pay as set out in the payment terms.';
}

/**
 * documentId is nullable so a generic, non-document template (e.g. a
 * payment reminder — docs/schema.sql Section AI's email template CRUD)
 * can be previewed/composed against just the order. A template tied to
 * an actual document send still requires it to be approved/sent first.
 *
 * @returns {subject, body, recipient_email, document, order}
 */
/** Owner request (2026-10-27): appended to the PI, Debit Note and CI emails. */
const PAYMENT_LINK_PARAGRAPH = 'PAYMENT DETAILS: once you have paid, please send us a screenshot of the payment and its details (bank reference number, amount and date) on this secure link. You do not need to sign in:\n{payment_action_link}\nOur team will check them against our bank statement and confirm.';

async function buildPreview(orderId, documentId, templateKey, senderUserId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }
  // The order's client_email is a snapshot frozen at order creation (only
  // re-synced by applyClientSnapshot() when a PI intake is applied) — an
  // email added to the client record afterwards never reached it, so sends
  // failed with "no email address on file" even though the client now has
  // one. Fall back to the live record.
  if (!order.client_email) {
    const client = await clientRepository.find(order.client_id);
    order.client_email = (client && client.email) || null;
  }

  let document = null;
  if (documentId !== null && documentId !== undefined) {
    document = await documentRepository.find(documentId);
    if (!document || document.order_id !== orderId) {
      throw new Error(`Document ${documentId} not found for this order`);
    }
    if (document.status !== 'approved' && document.status !== 'sent') {
      throw new Error('Only an approved document can be sent to the buyer — it must clear internal review first (Section 9).');
    }
    // QA-5 L-40: document_types.never_shown_to_buyer (Business Rule #20)
    // is a hard, server-side gate — the order page only ever offers
    // "Send to Buyer" for category='customer_facing' documents, but
    // that's a UI convenience, not a security boundary; the send route
    // itself must refuse any type this flag covers (Supplier PO with its
    // INR unit prices, BL Instruction Sheet, COO Prep, internal
    // checklists, Amendment drafts, ...) even hit directly by a typed URL.
    if (document.document_type_never_shown_to_buyer) {
      throw new Error(`${document.document_type_name} is an internal document and can never be sent to the buyer.`);
    }
    // QA Round 5, R5-03: terms needing MD/ED approval block sending the Quotation too.
    if (document.document_type_code === 'QT' && (await paymentTermsApprovalService.isPending(orderId))) {
      throw new Error(await paymentTermsApprovalService.pendingMessage(orderId));
    }
    // External QA report L-41: any active template could be picked for
    // any document — a Supplier PO going out under the subject "Quotation
    // SC/SPO/…" — because nothing tied templateKey to the document
    // actually being sent. Every buyer-facing document type has exactly
    // one seeded template, send_<lowercase code> (docs/schema.sql Section
    // AU's own convention, also what ordersController's compose-screen
    // guess uses), so a document-bound send must use exactly that one.
    const expectedTemplateKey = `send_${String(document.document_type_code).toLowerCase()}`;
    if (templateKey !== expectedTemplateKey) {
      throw new Error(`Wrong template for this document: a ${document.document_type_name} must be sent with the "${expectedTemplateKey}" template, not "${templateKey}".`);
    }
  }
  if (!order.client_email) {
    throw new Error('This client has no email address on file.');
  }
  // External QA report N-06: bl_copy_sent could be requested and
  // approved with no scanned BL on file — dispatchGeneric() then failed
  // silently at send time (status 'failed', nobody told). Refuse up
  // front instead, with a clear message, at the same point L-42 added
  // this template's own document-less branch.
  if (templateKey === 'bl_copy_sent') {
    const shipping = await orderShippingRepository.find(orderId);
    if (!shipping || !shipping.bl_copy_file_id) {
      throw new Error('Upload the scanned Bill of Lading copy for this order before requesting this email.');
    }
  }

  const template = await emailTemplateRepository.find(templateKey);
  if (!template || !template.is_active) {
    throw new Error(`Unknown or inactive email template: ${templateKey}`);
  }

  const sender = await userRepository.findById(senderUserId);
  const tokens = await tokensFor(order, document, sender);

  // Owner request (2026-10-27): the PI, Debit Note and CI emails tell the
  // client where to send their payment details — the order's Payment
  // Action Link. Added unless an admin-edited template already places
  // {payment_action_link} itself.
  let body = template.body;
  if (document && ['PI', 'FDN', 'CI'].includes(document.document_type_code)) {
    tokens['{payment_action_link}'] = `${env.get('APP_URL', '').replace(/\/+$/, '')}/payment-action/${await orderRepository.ensurePaymentActionToken(orderId)}`;
    if (!body.includes('{payment_action_link}')) {
      body = `${body.trimEnd()}\n\n${PAYMENT_LINK_PARAGRAPH}`;
    }
  }

  return {
    subject: strtr(template.subject, tokens),
    body: `${strtr(body, tokens)}\n\n${strtr(template.footer || '', tokens)}`,
    recipient_email: order.client_email,
    document,
    order,
  };
}

/**
 * Level 1 — submits for Level 2 approval. Never sends directly, whatever
 * the scheduled time — UNLESS company_settings.mail_approval_queue_enabled
 * is off (docs/schema.sql Section AU), in which case there is no Level 2 to
 * wait for: the row is still created (so the email log stays a complete
 * record either way), immediately marked approved by the system, and
 * dispatched synchronously in this same request instead of waiting for the
 * background job's next tick.
 *
 * @returns {{id: number, dispatched: boolean, sent: ?boolean}} dispatched
 *   is true when the queue was bypassed (sent tells you whether that
 *   immediate attempt actually succeeded); false means it's queued for
 *   Level 2 as usual and sent is always null.
 */
/** QA MTX-003: why the last dispatch() was held (testing mode / switched off), or null when it was not held. */
const dispatchState = { lastHoldReason: null };

/** QA MTX-003: what the screen says when a send was held, not failed (D-280: staff send it outside the app). */
function heldMessage(reason) {
  return `Not e-mailed: ${reason}. The send is recorded as held, not sent — send it outside the app, or submit it again once that changes.`;
}

async function requestSend(orderId, documentId, templateKey, scheduledAt, requestedByUserId) {
  const preview = await buildPreview(orderId, documentId, templateKey, requestedByUserId);

  // External QA report L-42: four generic templates (no document —
  // payment_followup, shipment_readiness, bl_copy_sent,
  // balance_receipt_confirmation) had no screen or route that ever
  // actually submitted a send — hasActiveSendFor() is keyed on
  // document_id, which is meaningless (and never matches NULL in SQL)
  // for a generic send.
  if (documentId !== null && documentId !== undefined) {
    if (await emailLogRepository.hasActiveSendFor(documentId)) {
      throw new Error('A send for this document is already awaiting approval or scheduled to go out — wait for it to send, be rejected, or fail before submitting another.');
    }
  } else if (await emailLogRepository.hasActiveGenericSendFor(orderId, templateKey)) {
    throw new Error('A send of this email is already awaiting approval or scheduled to go out for this order — wait for it to send, be rejected, or fail before submitting another.');
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

  if ((await companySettingsRepository.get('mail_approval_queue_enabled')) === '0') {
    await emailLogRepository.approve(id, requestedByUserId);
    await auditLogRepository.log(requestedByUserId, 'EMAIL_SEND_REQUESTED', 'email_log', id, 'recipient_email', null, preview.recipient_email);
    await auditLogRepository.log(requestedByUserId, 'EMAIL_SEND_AUTO_APPROVED', 'email_log', id, null, null, null, 'mail_approval_queue_enabled is off — no Level-2 approval required.');
    const row = await emailLogRepository.find(id);
    const sent = row ? await dispatch(row) : false;
    return { id, dispatched: true, sent, held: sent ? null : dispatchState.lastHoldReason };
  }

  // Notify whoever can actually approve this (approve_email_send — the
  // same permission this exact approval route is gated on). Checked by
  // permission, not a hardcoded role name, since roles can be renamed via
  // /admin/roles.
  const approverIds = await permissionRepository.usersWithPermission('approve_email_send');
  for (const userId of approverIds) {
    await notificationRepository.create(userId, null, 'email_send_pending_approval', orderId, `A send to ${preview.recipient_email} is awaiting your approval.`);
  }

  await auditLogRepository.log(requestedByUserId, 'EMAIL_SEND_REQUESTED', 'email_log', id, 'recipient_email', null, preview.recipient_email);
  return { id, dispatched: false, sent: null };
}

async function approveSend(emailLogId, approverUserId) {
  const row = await emailLogRepository.find(emailLogId);
  if (!row || row.status !== 'pending_approval') {
    throw new Error('This send is not awaiting approval.');
  }
  // QA-5 maker-checker (EML-03); owner decisions 1 Nov 2026 (A-3, then
  // D-221): whoever requested this send approves it only when one of their
  // roles is ticked in Admin → Self-approval rules.
  const self = row.requested_by !== null && row.requested_by !== undefined && Number(row.requested_by) === Number(approverUserId);
  if (self && !(await require('./makerCheckerGuard').selfApprovalAllowed('email_send', approverUserId))) {
    throw new Error('You requested this send — a different person must approve it.');
  }
  await emailLogRepository.approve(emailLogId, approverUserId);
  await auditLogRepository.log(approverUserId, 'EMAIL_SEND_APPROVED', 'email_log', emailLogId);
  if (self) {
    await auditLogRepository.log(approverUserId, 'EMAIL_SEND_SELF_APPROVED', 'email_log', emailLogId, null, null, 'approved by the person who requested it', 'Allowed by Admin → Self-approval rules.');
  }
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
 * Pulls a send back before it goes out. Works on both 'pending_approval'
 * (nobody has reviewed it yet) and 'approved' (already cleared Level-2 but
 * still waiting out its scheduled_at window) — that whole span is what the
 * spec's "deferred send" was for: giving staff a chance to stop a mail they
 * realize needs changes after clicking send, right up until the background
 * job actually dispatches it. Once dispatch() has run and marked it 'sent',
 * nothing here can reach it any more — the mail is already gone.
 *
 * Any Level-2 approver can cancel any row (mirrors their reject power).
 * Anyone else may only cancel a row they themselves requested.
 */
async function cancelSend(emailLogId, userId, reason, isApprover) {
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    throw new Error(reasonError);
  }
  const row = await emailLogRepository.find(emailLogId);
  if (!row || (row.status !== 'pending_approval' && row.status !== 'approved')) {
    throw new Error('This send can no longer be cancelled — it has already been sent, rejected, or cancelled.');
  }
  if (!isApprover && row.requested_by !== userId) {
    throw new Error('You can only cancel a send you requested yourself.');
  }

  await emailLogRepository.cancel(emailLogId, userId, reason);

  if (row.requested_by !== null && row.requested_by !== undefined && row.requested_by !== userId) {
    await notificationRepository.create(row.requested_by, null, 'email_send_cancelled', row.order_id, `Your send to ${row.recipient_email} was cancelled: ${reason}`);
  }
  await auditLogRepository.log(userId, 'EMAIL_SEND_CANCELLED', 'email_log', emailLogId, null, null, null, reason);
}

/**
 * 90-item backlog Phase 4: the "future 'send now' button" the deferred-
 * dispatch job's own docblock anticipated — an approver who approves a
 * deferred send before its scheduled_at window used to have no way to
 * actually make it go out immediately; it still sat waiting for the next
 * job tick (or for scheduled_at itself to pass). Only usable on a row
 * already 'approved' (not 'pending_approval') — forcing dispatch skips
 * nothing but the TIME it waits, never the Level-2 approval step itself.
 * Shares dispatch()'s exact code path, so a forced send can never
 * diverge from what the job would have done at the scheduled time.
 */
async function sendNowOverride(emailLogId, userId) {
  const row = await emailLogRepository.find(emailLogId);
  if (!row || row.status !== 'approved') {
    throw new Error('Only an already-approved send still waiting to go out can be sent now.');
  }

  await auditLogRepository.log(userId, 'EMAIL_SEND_FORCED_NOW', 'email_log', emailLogId, 'scheduled_at', row.scheduled_at, null);
  return dispatch(row);
}

/**
 * The actual send — called by the deferred-email-dispatch background job
 * on its own schedule, or synchronously from sendNowOverride() above
 * when an approver explicitly chooses to skip the remaining wait.
 * Returns true on send success.
 */
async function dispatch(emailLogRow) {
  dispatchState.lastHoldReason = null;
  // QA-5 EML-06: atomically claim this row before doing anything else, so
  // an overlapping dispatch run (or a duplicate manual trigger) can never
  // send the same approved email twice. A lost race is not a failure — it
  // just means another run already has it — so it returns false without
  // touching email_log again (a markFailed() here would clobber the
  // winner's later markSent()).
  if (!(await emailLogRepository.claimForSend(emailLogRow.id))) {
    return false;
  }
  // External QA report L-42: a generic (document-less) send used to be
  // marked failed unconditionally here — even once requestSend() above
  // accepts one, dispatch() (called by both the background job and
  // sendNowOverride()) would still refuse to ever actually send it.
  if (emailLogRow.document_id === null || emailLogRow.document_id === undefined) {
    return dispatchGeneric(emailLogRow);
  }
  const document = await documentRepository.find(emailLogRow.document_id);
  if (!document || !document.pdf_file_id) {
    await failed(emailLogRow);
    return false;
  }
  // Defense in depth (QA-4 P0.4): buildPreview()/requestSend() only ever
  // queued this row while the document was 'approved', but a reviewer can
  // still be assigned to, and reject, an already-approved/sent document
  // afterward — reviewWorkflowService.reject() unconditionally reverts it
  // to 'draft' without touching pdf_file_id or looking at any pending
  // send. Without this re-check here, a stale approved PDF would still go
  // out to the buyer from a send queued before that rejection.
  // QA batch 3 MAIL-9: an already-sent document may be sent again (the preview
  // offers it) — only a document pulled back to draft/review is refused.
  if (!['approved', 'sent'].includes(document.status)) {
    await failed(emailLogRow);
    return false;
  }
  const isResend = document.status === 'sent';
  const file = await fileStoreRepository.find(document.pdf_file_id);
  if (!file || !fs.existsSync(file.server_path)) {
    await failed(emailLogRow);
    return false;
  }

  const attachments = [{ path: file.server_path, name: file.original_filename }];
  const sent = await mailSenderService.send(
    emailLogRow.recipient_email,
    emailLogRow.subject,
    emailLogRow.body_snapshot,
    attachments,
    { kind: 'buyer_documents', audience: 'buyer' }
  );

  if (sent) {
    await emailLogRepository.markSent(emailLogRow.id);
    await documentRepository.markSent(document.id);
    await copyToAssociate(emailLogRow, attachments);
    await auditLogRepository.log(null, 'EMAIL_SENT', 'email_log', emailLogRow.id, 'recipient_email', null, emailLogRow.recipient_email);

    // QA batch 3 MAIL-9: a re-send does not restart the OC clock or re-pass the stage.
    if (!isResend) await startOcAcknowledgmentClock(document);
    // With email sending on, the Quotation completes Stage 1 when it's sent,
    // not when it's approved (reviewWorkflowService).
    if (!isResend && document.document_type_code === 'QT' && document.order_id !== null && document.order_id !== undefined) {
      await stageGateService.passAndUnlockNext(document.order_id, 1, null);
    }
  } else if (mailSenderService.state.lastOutcome === 'held') {
    await held(emailLogRow, 'buyer_documents', 'buyer');
  } else {
    await failed(emailLogRow);
  }

  return sent;
}

// docs/schema.sql Section AE — the Order Confirmation just went out to the
// buyer, so this is the moment the 48-hour acknowledgment clock starts
// (ordersController.recordOcAcknowledgment() /
// clientPortalController.acknowledgeOc() / the autoConfirmOcAcknowledgments
// cron are the three ways it later resolves).
/**
 * QA batch 3 MAIL-9 (port of PHP failed()): a failed send is recorded AND the
 * person who asked for it (and the approver) are told.
 */
/**
 * QA MTX-003 (port of PHP held()): the send was held by testing mode or its switch in Admin › E-mail settings
 * (D-280). Nothing failed: the log says held with the reason, the requester and approver are told why, and staff
 * send it outside the app or submit it again once the hold is lifted. Never sent later by itself.
 */
async function held(emailLogRow, kind, audience) {
  const reason = await emailPolicy.notSentReason(kind, audience);
  dispatchState.lastHoldReason = reason;
  await emailLogRepository.markHeld(emailLogRow.id, `Held, not sent: ${reason}. Send it outside the app, or submit it again once that changes.`);
  await auditLogRepository.log(null, 'EMAIL_HELD', 'email_log', emailLogRow.id, 'recipient_email', null, emailLogRow.recipient_email, reason);
  const orderId = emailLogRow.order_id ? Number(emailLogRow.order_id) : null;
  const message = `The e-mail "${emailLogRow.subject || ''}" to ${emailLogRow.recipient_email || ''} was not sent: ${reason}. Send it outside the app, or submit it again once that changes.`;
  for (const userId of [...new Set([Number(emailLogRow.requested_by || 0), Number(emailLogRow.approved_by || 0)].filter(Boolean))]) {
    await notificationRepository.createInAppOnly(userId, 'email_send_failed', orderId, message);
  }
}

async function failed(emailLogRow) {
  await emailLogRepository.markFailed(emailLogRow.id);
  const orderId = emailLogRow.order_id ? Number(emailLogRow.order_id) : null;
  const message = `The e-mail "${emailLogRow.subject || ''}" to ${emailLogRow.recipient_email || ''} could not be sent. Check the e-mail log and send it again.`;
  for (const userId of [...new Set([Number(emailLogRow.requested_by || 0), Number(emailLogRow.approved_by || 0)].filter(Boolean))]) {
    // In-app only: e-mail is what just failed.
    await notificationRepository.createInAppOnly(userId, 'email_send_failed', orderId, message);
  }
}

async function startOcAcknowledgmentClock(document) {
  if (document.document_type_code === 'OC' && document.order_id !== null && document.order_id !== undefined) {
    // docs/schema.sql Section AU: admin-configurable, default 48 (matches
    // the original hardcoded value exactly).
    const hours = parseInt((await companySettingsRepository.get('oc_ack_auto_confirm_hours')) || '48', 10);
    // External QA report L-21/L-02: toISOString() is always UTC — see
    // dbDateTime.formatForDb()'s docblock.
    const now = new Date();
    const due = new Date(now.getTime() + hours * 60 * 60 * 1000);
    await orderOcAcknowledgmentRepository.recordSent(document.order_id, document.id, formatForDb(now), formatForDb(due));
  }
}

/**
 * Whether buyer document e-mails go out (D-280: testing mode off and
 * "Documents … — Buyer" on in Admin › E-mail settings). When they do not,
 * there is no "Send to Buyer" action and an approved document counts as sent.
 */
async function mailSendingEnabled() {
  return emailPolicy.allows('buyer_documents', 'buyer');
}

/** D-280: the text of the associate's copy of a buyer document e-mail. */
function associateCopyBody(associate, client, order, buyerBody) {
  return `Hello ${associate.name},\n\nFor your information, this is a copy of the e-mail NexaCrest sent to your client `
    + `${client.company_legal_name} (order ${order.order_reference}).\n\n---\n\n${buyerBody}`;
}

/**
 * D-280: when Admin › E-mail settings has "Documents … — Associate" on (OPTIONAL,
 * off when installed), the associate who introduced the buyer gets a copy of a
 * document e-mail that really went to the buyer — only when their access level
 * shows order documents.
 */
async function copyToAssociate(emailLogRow, attachments) {
  if (!emailLogRow.order_id || !(await emailPolicy.allows('buyer_documents', 'associate'))) return;
  try {
    const order = await orderRepository.find(emailLogRow.order_id);
    const client = order && order.client_id ? await clientRepository.find(order.client_id) : null;
    const associate = client && client.associate_id ? await require('../repositories/associateRepository').find(client.associate_id) : null;
    if (!associate || !associate.email || ['ended', 'inactive'].includes(associate.status)
      || !(await require('./associateAccess').allows(associate, 'order_documents'))) {
      return;
    }
    await mailSenderService.send(String(associate.email), `Copy: ${emailLogRow.subject}`,
      associateCopyBody(associate, client, order, String(emailLogRow.body_snapshot)), attachments,
      { kind: 'buyer_documents', audience: 'associate' });
  } catch (e) {
    console.error('[ASSOCIATE COPY NOT SENT]', e.message);
  }
}

/**
 * Owner decision (2026-10-25): with buyer document e-mails held (D-280:
 * testing mode, or the switch off) there is no
 * "Send to Buyer" action at all — staff send the PDF themselves outside the
 * app — so a buyer-sendable document counts as sent the moment it is
 * approved. Does exactly what a real send does to the document (status
 * 'sent', the OC acknowledgment clock) minus the email itself. Internal
 * never-shown-to-buyer types are left as-is. Called right after every point
 * a document becomes approved.
 */
async function markSentIfMailDisabled(documentId) {
  if (await mailSendingEnabled()) {
    return;
  }
  const document = await documentRepository.find(documentId);
  if (!document || document.status !== 'approved' || Number(document.document_type_never_shown_to_buyer)) {
    return;
  }
  await documentRepository.markSent(documentId);
  // Gap list G-6 (owner, 1 Nov 2026): with e-mail off, the buyer's 48 hours start when staff
  // mark the Order Confirmation as sent to the buyer (ordersController.markOcSentToBuyer), not now.
  await auditLogRepository.log(
    null, 'DOCUMENT_MARKED_SENT_MAIL_DISABLED', 'documents', documentId, 'status', 'approved', 'sent',
    'Buyer document e-mails are held (testing mode or Admin › E-mail settings) — counted as sent on approval; staff send the PDF outside the app.'
  );
}

/**
 * External QA report L-42: dispatch path for the 4 generic (no document)
 * templates — payment_followup/shipment_readiness/
 * balance_receipt_confirmation go out with no attachment; bl_copy_sent
 * (the report's explicit named exception) attaches the order's scanned
 * Bill of Lading copy (order_shipping.bl_copy_file_id — see
 * orderShippingRepository.attachBlCopy(), the same file L-22 fixed the
 * upload for) exactly like a document send attaches its PDF.
 */
async function dispatchGeneric(emailLogRow) {
  const attachments = [];
  if (emailLogRow.template_key === 'bl_copy_sent') {
    const shipping = await orderShippingRepository.find(emailLogRow.order_id);
    if (!shipping || !shipping.bl_copy_file_id) {
      await failed(emailLogRow);
      return false;
    }
    const file = await fileStoreRepository.find(shipping.bl_copy_file_id);
    if (!file || !fs.existsSync(file.server_path)) {
      await failed(emailLogRow);
      return false;
    }
    attachments.push({ path: file.server_path, name: file.original_filename });
  }

  const kind = emailPolicy.TEMPLATE_KINDS[String(emailLogRow.template_key)] || 'buyer_followups';
  const sent = await mailSenderService.send(emailLogRow.recipient_email, emailLogRow.subject, emailLogRow.body_snapshot, attachments,
    { kind, audience: 'buyer' });

  if (sent) {
    await emailLogRepository.markSent(emailLogRow.id);
    await auditLogRepository.log(null, 'EMAIL_SENT', 'email_log', emailLogRow.id, 'recipient_email', null, emailLogRow.recipient_email);
  } else if (mailSenderService.state.lastOutcome === 'held') {
    await held(emailLogRow, kind, 'buyer');
  } else {
    await failed(emailLogRow);
  }

  return sent;
}

/**
 * QA batch 3 MAIL-4 (port of PHP clientEmailFor): the buyer's e-mail for an
 * order — the order's own snapshot, else the client record's.
 */
async function clientEmailFor(order) {
  let email = String(order.client_email || '').trim();
  if (email === '' && order.client_id) {
    email = String(((await require('../repositories/clientRepository').find(order.client_id)) || {}).email || '').trim();
  }
  return email !== '' ? email : null;
}

/**
 * QA batch 3 MAIL-2 / D-280 (port of PHP linkMailOutcome): "e-mailed to x" only when
 * the e-mail really went out; otherwise the true reason. The link itself is shown
 * under the message with a Copy button (flash.set(req, type, message, link)).
 */
async function linkMailOutcome(what, email, sent, link, kind = 'buyer_links', audience = 'buyer') {
  const party = audience === 'supplier' ? 'supplier' : 'client';
  if (email === null) return `${what} generated — not e-mailed: no ${party} e-mail on file. Copy the link and send it yourself.`;
  if (!sent) return `${what} generated — not e-mailed to ${email}: ${await emailPolicy.notSentReason(kind, audience)}. Copy the link and send it yourself.`;
  return `${what} e-mailed to ${email}. If they did not get it, copy the link and send it yourself.`;
}

module.exports = { PAYMENT_LINK_PARAGRAPH, associateCopyBody, senderTitleFor, clientEmailFor, linkMailOutcome, buildPreview, requestSend, approveSend, rejectSend, cancelSend, dispatch, sendNowOverride, mailSendingEnabled, markSentIfMailDisabled, startOcAcknowledgmentClock, dispatchState, heldMessage };
