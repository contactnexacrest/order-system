'use strict';

const fs = require('fs');
const crypto = require('crypto');

const env = require('../config/env');
const flash = require('../helpers/flash');
const emailService = require('../services/emailService');
const passwordHash = require('../helpers/passwordHash');
const reasonValidator = require('../helpers/reasonValidator');
const clientPasswordResetTokenRepository = require('../repositories/clientPasswordResetTokenRepository');
const clientLoginRepository = require('../repositories/clientLoginRepository');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const disputeRepository = require('../repositories/disputeRepository');
const orderComplaintRepository = require('../repositories/orderComplaintRepository');
const orderComplaintReplyRepository = require('../repositories/orderComplaintReplyRepository');
const orderFeedbackRepository = require('../repositories/orderFeedbackRepository');
const testimonialSuggestionService = require('../services/testimonialSuggestionService');
const documentRepository = require('../repositories/documentRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const orderCommentRepository = require('../repositories/orderCommentRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderReorderRequestRepository = require('../repositories/orderReorderRequestRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const permissionRepository = require('../repositories/permissionRepository');
const notificationRepository = require('../repositories/notificationRepository');
const clientPortalService = require('../services/clientPortalService');
const buyerActionService = require('../services/buyerActionService');
const documentGenerationService = require('../services/documentGenerationService');
const orderCommentService = require('../services/orderCommentService');
const orderComplaintService = require('../services/orderComplaintService');
const passwordPolicyService = require('../services/passwordPolicyService');
const fileUploadService = require('../services/fileUploadService');
const workingDaysCalculator = require('../services/workingDaysCalculator');
const { todayIst, formatForDb } = require('../helpers/dbDateTime');

function todayYmd() {
  return todayIst();
}

function sanitizePathSegment(value) {
  return String(value).replace(/[^A-Za-z0-9_-]+/g, '-');
}

/**
 * Port of App\Controllers\ClientPortalController. The client-facing
 * portal — structurally separate screens from the staff app (own layout,
 * own nav, own session key via clientPortalService). Read-only for the
 * client's own profile/order data throughout (no editing, no audit
 * visibility) — the exceptions are the client's own password;
 * reportPayment(), which is purely an informational note to staff and
 * never writes to the order/payment records itself; and acknowledgeOc(),
 * the one real state change a client can trigger, gated to only ever
 * move the Stage 4->5 gate forward, never anything else.
 */

function showLogin(req, res) {
  if (clientPortalService.currentClientId(req)) {
    res.redirect('/client');
    return;
  }
  res.renderView('client_portal/login', {}, 'layout/bare');
}

async function login(req, res) {
  const email = String(req.body.email || '').trim().toLowerCase();
  const password = String(req.body.password || '');

  const result = await clientPortalService.attemptLogin(req, email, password);

  switch (result.status) {
    case 'ok':
      if (result.force_password_change) {
        res.redirect('/client/account');
        return;
      }
      res.redirect('/client');
      return;
    case 'locked_out':
      flash.set(req, 'error', `Too many failed attempts. Try again after ${result.locked_until}.`);
      break;
    case 'account_disabled':
      flash.set(req, 'error', 'This account is disabled. Contact NexaCrest if you believe this is a mistake.');
      break;
    default:
      flash.set(req, 'error', 'Incorrect email or password.');
  }
  res.redirect('/client/login');
}

async function logout(req, res) {
  await clientPortalService.logout(req);
  res.redirect('/client/login');
}

/**
 * docs/schema.sql Section AV — ends a staff-started impersonation and
 * returns the staff member to that client's own staff-side page. Their
 * own staff session (preserved through regeneratePreserving() the whole
 * time) is still intact, so this is "stop viewing as the client", not a
 * logout. No permission gate here deliberately — matches /client/logout
 * — the worst case of calling this with nothing to end is a no-op
 * redirect to /client/login.
 */
async function endImpersonation(req, res) {
  const clientId = await clientPortalService.endImpersonation(req);
  res.redirect(clientId ? `/clients/${clientId}` : '/client/login');
}

async function showForgotPassword(req, res) {
  res.renderView('client_portal/forgot_password', {}, 'layout/bare');
}

/**
 * Port of ClientPortalController::forgotPassword() (PHP). 90-item backlog
 * #88: the client portal had no self-service password recovery at all —
 * client_password_reset_tokens and clientPasswordResetTokenRepository
 * already existed (built for the initial "set your password" email) but
 * nothing ever let a client who forgot their password request a fresh
 * one. Mirrors authController.forgotPassword() exactly: a generic "if
 * that address exists..." response regardless of whether the email
 * matched, reusing the same /client/set-password/{token} flow and token
 * table the original provisioning email already uses.
 */
async function forgotPassword(req, res) {
  const email = String(req.body.email || '').trim().toLowerCase();
  const genericMessage = `If ${email} matches an account, a password reset link has been sent to it. The link expires in 72 hours.`;

  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  if (email === '' || !emailValid) {
    flash.set(req, 'success', genericMessage);
    res.redirect('/client/login');
    return;
  }

  const login = await clientLoginRepository.findByEmail(email);

  if (login && login.client_is_active && login.is_active) {
    if ((await clientPasswordResetTokenRepository.countRecentForClient(login.client_id, 60)) < 5) {
      const rawToken = crypto.randomBytes(32).toString('hex');
      const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
      // External QA report L-21/L-02: toISOString() is always UTC — see
      // dbDateTime.formatForDb()'s docblock.
      const expiresAt = formatForDb(new Date(Date.now() + 72 * 3600000));
      const ip = req.ip || null;

      await clientPasswordResetTokenRepository.create(login.client_id, tokenHash, expiresAt, ip);

      const resetUrl = `${env.get('APP_URL', '').replace(/\/+$/, '')}/client/set-password/${rawToken}`;

      // 90-item backlog #37: admin-editable (Admin > Settings > Email
      // Templates) — this exact original wording is the fallback.
      const rendered = await emailTemplateRepository.renderOrDefault(
        'client_portal_password_reset',
        { '{email}': email, '{reset_link}': resetUrl },
        'Reset your NexaCrest order portal password',
        'Hello,\n\n'
          + 'A password reset was requested for your NexaCrest order portal account ({email}).\n\n'
          + 'To set a new password, open this link within 72 hours:\n{reset_link}\n\n'
          + "If you didn't request this, you can ignore this email — your password will not be changed."
      );

      await emailService.sendPlainText(email, rendered.subject, rendered.body, { isSecurityEmail: true });
      await auditLogRepository.log(null, 'CLIENT_PASSWORD_RESET_REQUESTED', 'clients', login.client_id, null, null, null, `Requested from IP ${ip}`);
    }
  }

  flash.set(req, 'success', genericMessage);
  res.redirect('/client/login');
}

async function showSetPassword(req, res) {
  const token = String(req.params.token || '');
  const row = await clientPasswordResetTokenRepository.findValidByHash(crypto.createHash('sha256').update(token).digest('hex'));
  if (!row) {
    flash.set(req, 'error', 'This link is invalid or has expired. Contact NexaCrest for a new one.');
    res.redirect('/client/login');
    return;
  }
  res.renderView('client_portal/set_password', { token, email: row.client_email }, 'layout/bare');
}

async function setPassword(req, res) {
  const token = String(req.params.token || '');
  const row = await clientPasswordResetTokenRepository.findValidByHash(crypto.createHash('sha256').update(token).digest('hex'));
  if (!row) {
    flash.set(req, 'error', 'This link is invalid or has expired. Contact NexaCrest for a new one.');
    res.redirect('/client/login');
    return;
  }

  const newPassword = String(req.body.new_password || '');
  const confirm = String(req.body.confirm_password || '');
  const policyError = await passwordPolicyService.validate(newPassword);
  if (policyError !== null) {
    flash.set(req, 'error', policyError);
    res.redirect(`/client/set-password/${token}`);
    return;
  }
  if (newPassword !== confirm) {
    flash.set(req, 'error', 'Passwords do not match.');
    res.redirect(`/client/set-password/${token}`);
    return;
  }

  await clientPortalService.changePassword(row.client_id, newPassword);
  await clientPasswordResetTokenRepository.markUsed(row.id);
  await clientPasswordResetTokenRepository.invalidateAllForClient(row.client_id);

  flash.set(req, 'success', 'Password set. Sign in below.');
  res.redirect('/client/login');
}

async function dashboard(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const client = await clientPortalService.currentClient(req);
  const historyHidden = !(await clientRepository.orderHistoryVisible(client));
  res.renderView('client_portal/dashboard', {
    client,
    orderGroups: groupOrdersByClientFacingStatus(await orderRepository.forClient(clientId, historyHidden)),
    historyHidden,
  }, 'layout/client');
}

/**
 * A lightweight "group by status" split for the client's own My Orders
 * list — not a full Kanban board (the client portal stays the simple
 * list it is), just a quick visual split once a client has more than a
 * couple of orders on file. Grouped by the client-FACING label
 * (docs/schema.sql Section BU), not the raw status, so shipped_docs_sent
 * and complete share one "Complete" group rather than rendering as two
 * separate ones — the client was never meant to see that internal
 * distinction at all, let alone as two buckets. Only labels that
 * actually have an order are returned, in this fixed, most-relevant-
 * first order; a future status not in this list still renders, appended
 * at the end.
 */
function groupOrdersByClientFacingStatus(orders) {
  const clientStatusLabels = { shipped_docs_sent: 'Complete' };
  const labelOrder = ['Active', 'Complete', 'Disputed', 'Lost', 'Cancelled'];

  const grouped = {};
  for (const o of orders) {
    const label = clientStatusLabels[o.status] || (o.status.charAt(0).toUpperCase() + o.status.slice(1));
    if (!grouped[label]) grouped[label] = [];
    grouped[label].push(o);
  }

  const seen = new Set();
  const orderedLabels = [];
  for (const label of labelOrder) {
    if (grouped[label] && !seen.has(label)) { orderedLabels.push(label); seen.add(label); }
  }
  for (const label of Object.keys(grouped)) {
    if (!seen.has(label)) { orderedLabels.push(label); seen.add(label); }
  }

  return orderedLabels.map((label) => ({ label, orders: grouped[label] }));
}

async function showOrder(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);

  // Ownership check — the one thing this whole controller exists to
  // enforce: a client can never reach another client's order by
  // guessing/changing the id in the URL. Treated identically to "not
  // found" so no information about other clients' orders leaks.
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  const paymentStatus = await orderPaymentStatusRepository.find(orderId);

  // User-reported batch item 10 — same lazy fallback as
  // buyerActionController's show(): the acknowledgement section only ever
  // rendered when an order_oc_acknowledgments row already existed, which
  // only emailDispatchService.dispatch() (a sent OC email) ever created. A
  // client logging into the portal before that email went out saw no
  // acknowledgement option at all.
  let ocAcknowledgment = await orderOcAcknowledgmentRepository.find(orderId);
  if (!ocAcknowledgment) {
    // Only once the OC counts as issued (approved while email is off, sent
    // while it is on) — the same rule the Stage 4 gate itself applies.
    const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'OC');
    if (ocDoc && !(await documentGenerationService.stageDocumentBlockedBy(orderId, 4)).length) {
      await orderOcAcknowledgmentRepository.ensureRowExists(orderId, ocDoc.id);
      ocAcknowledgment = await orderOcAcknowledgmentRepository.find(orderId);
    }
  }

  const client = await clientPortalService.currentClient(req);

  res.renderView('client_portal/order_show', {
    client,
    order,
    products: await orderProductRepository.forOrder(orderId),
    totalFobValue: await orderProductRepository.totalFobValue(orderId),
    documents: await documentRepository.customerFacingForOrder(orderId),
    // External QA report M-02: global + per-client download visibility,
    // independent of the order-history one above.
    downloadsHidden: !(await clientRepository.documentDownloadsVisible(client)),
    paymentReports: await clientPaymentReportRepository.forOrder(orderId),
    ocAcknowledgment,
    comments: await orderCommentRepository.forOrder(orderId),
    buyerPoDocuments: await orderBuyerPoDocumentRepository.forOrder(orderId),
    // 90-item backlog #19: gates the self-upload form below once the
    // advance has cleared — see buyerActionService.uploadBuyerPo(), which
    // this page's own form submits to.
    advanceCleared: !!paymentStatus && paymentStatus.advance_cleared_at !== null,
    orderFeedback: await orderFeedbackRepository.find(orderId),
    testimonialSuggestion: await testimonialSuggestionService.forOrder(orderId),
    orderComplaints: await complaintsWithReplies(orderId),
  }, 'layout/client');
}

/** each complaint plus its 'replies' array. */
async function complaintsWithReplies(orderId) {
  const complaints = await orderComplaintRepository.forOrder(orderId);
  for (const c of complaints) {
    c.replies = await orderComplaintReplyRepository.forComplaint(c.id);
  }
  return complaints;
}

/**
 * Lets the client attach their own scanned/signed copy of the Buyer PO at
 * any time — a self-service supplement to the staff-side upload
 * (ordersController.uploadBuyerPoDocument), sharing the same
 * order_buyer_po_documents table so every copy, staff- or client-uploaded,
 * shows up in one place with full version history (never overwrites a
 * prior upload).
 */
async function uploadBuyerPo(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  // 90-item backlog #75: shared with the no-login buyerActionController
  // route — see buyerActionService's docblock.
  const result = await buyerActionService.uploadBuyerPo(req, order);
  flash.set(req, result.ok ? 'success' : 'error', result.message);
  res.redirect(`/client/orders/${orderId}`);
}

/**
 * Order-Edit feature — a client wants to place a repeat order from one
 * they've already placed, even long after it closed. Pre-fills the form
 * from this order's own product lines; the client may edit, remove, or
 * add lines before submitting. Never becomes a live order itself — lands
 * in the staff reorder-request review queue exactly like the intake
 * queues (see reorderRequestController).
 */
async function showReorderForm(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  res.renderView('client_portal/reorder', {
    client: await clientPortalService.currentClient(req),
    order,
    products: await orderProductRepository.forOrder(orderId),
    units: await lookupRepository.dropdownOptions('unit'),
    surfaceFinishes: await lookupRepository.dropdownOptions('surface_finish'),
  }, 'layout/client');
}

async function submitReorder(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  const body = req.body;
  const hasAtLeastOneProduct = Object.values(body.product_description || {}).some((d) => String(d || '').trim() !== '');
  if (!hasAtLeastOneProduct) {
    flash.set(req, 'error', 'At least one product line (with a description) is required.');
    res.redirect(`/client/orders/${orderId}/reorder`);
    return;
  }

  const notes = String(body.notes || '').trim() || null;
  const requestId = await orderReorderRequestRepository.create(clientId, orderId, notes);

  let lineNo = 1;
  for (const i of Object.keys(body.product_description || {})) {
    const description = String(body.product_description[i] || '').trim();
    if (description === '') continue;
    await orderReorderRequestRepository.addProductLine(
      requestId,
      lineNo++,
      description,
      String((body.product_dimensions || {})[i] || '').trim() || null,
      String((body.product_finish || {})[i] || '').trim() || null,
      String((body.product_quantity || {})[i] || '').trim() || null,
      !!(body.product_quantity_tbc || {})[i],
      String((body.product_unit || {})[i] || '').trim() || null,
      null, // pricing is never client-set — staff fills it in when reviewing
      null  // HS code likewise — staff confirms/assigns from the master list on review
    );
  }

  for (const staffUserId of await permissionRepository.usersWithPermission('manage_orders')) {
    await notificationRepository.create(staffUserId, null, 'reorder_request_submitted', orderId, `Client submitted a reorder request based on order ${order.order_reference}.`);
  }
  await auditLogRepository.log(null, 'REORDER_REQUEST_SUBMITTED', 'orders', orderId, null, null, null, 'Reorder request submitted by the client via the client portal.');

  flash.set(req, 'success', 'Reorder request submitted — our team will review it and confirm the new order with you shortly.');
  res.redirect(`/client/orders/${orderId}`);
}

/** docs/schema.sql Section AI — the client's side of the order progress chat. */
async function postComment(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  const body = String(req.body.body || '').trim();
  try {
    const subPath = `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/comment_attachments`;
    const fileIds = await fileUploadService.handleMultipleUploads(
      req,
      'attachments',
      'order_comment_media',
      subPath,
      clientId,
      orderId,
      null,
      'Buyer',
      'Order Update Attachment'
    );
    await orderCommentService.postAsClient(orderId, clientId, body !== '' ? body : null, fileIds);
    flash.set(req, 'success', 'Message sent.');
  } catch (e) {
    flash.setErrorFromException(req, e);
  }
  res.redirect(`/client/orders/${orderId}#order-updates`);
}

/** Client download of a comment attachment — same ownership check as every other client-facing file. */
async function downloadCommentAttachment(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const fileId = parseInt(req.params.fileId, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

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

/** A client's own previously-uploaded payment screenshot — same ownership-check pattern as downloadCommentAttachment(). */
async function downloadPaymentScreenshot(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const reportId = parseInt(req.params.reportId, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  const report = await clientPaymentReportRepository.find(reportId);
  if (!report || report.order_id !== orderId || !report.screenshot_file_id) {
    res.status(404).send('Attachment not found.');
    return;
  }

  const file = await fileStoreRepository.find(report.screenshot_file_id);
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('File is missing from storage.');
    return;
  }
  const safeDownloadName = file.original_filename.replace(/[\x00-\x1F\x7F"/\\]/g, '');
  res.setHeader('Content-Type', file.mime_type || 'application/octet-stream');
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  fs.createReadStream(file.server_path).pipe(res);
}

/**
 * Client's own "I've paid" note — transaction ref + optional screenshot
 * of the remittance advice. Purely informational (docs/schema.sql
 * Section AD): staff still verify the real bank statement by hand before
 * recording the payment the normal way; nothing here ever touches
 * order_payment_status or unlocks a stage gate.
 */
async function reportPayment(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  const paymentType = String(req.body.payment_type || '');
  const transactionRef = String(req.body.transaction_ref || '').trim();
  if (!['advance', 'balance', 'freight'].includes(paymentType) || transactionRef === '') {
    flash.set(req, 'error', 'Select which payment this is for and enter the transaction ID/UTR.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  let screenshotFileId = null;
  if (req.file) {
    try {
      screenshotFileId = await fileUploadService.handleUpload(
        req,
        'screenshot',
        'received_remittance',
        `clients/${sanitizePathSegment(order.client_unique_number)}/${sanitizePathSegment(order.order_reference)}/client_payment_reports`,
        clientId,
        orderId,
        null,
        null,
        'Client (self-reported)',
        'Client payment self-report screenshot'
      );
    } catch (e) {
      flash.setErrorFromException(req, e);
      res.redirect(`/client/orders/${orderId}`);
      return;
    }
  }

  const amount = String(req.body.amount || '').trim();
  const paymentDate = String(req.body.payment_date || '').trim();
  await clientPaymentReportRepository.create(
    orderId,
    paymentType,
    transactionRef,
    String(req.body.payer_bank_details || '').trim() || null,
    amount !== '' ? parseFloat(amount) : null,
    paymentDate !== '' ? paymentDate : null,
    screenshotFileId
  );
  // External QA report L-30/M-07: same missing-notification gap as
  // buyerActionService.reportAdvancePayment() — see its own comment.
  await notificationRepository.notifyOrderTeam(orderId, 'client_payment_reported', `The buyer reported a ${paymentType} payment on order ${order.order_reference} — verify it against the bank statement.`);
  await auditLogRepository.log(null, 'CLIENT_PAYMENT_REPORTED', 'orders', orderId, 'payment_type', null, paymentType, `Client self-reported a ${paymentType} payment: ref ${transactionRef}.`);

  flash.set(req, 'success', 'Thank you — we have noted your payment details. Our team will verify this against our bank statement and update your order.');
  res.redirect(`/client/orders/${orderId}`);
}

/**
 * The client's own Order Confirmation acknowledgment — the single button
 * offered (docs/schema.sql Section AE). Deliberately no decline/dispute
 * option here: raising doubt at this exact step isn't the confirmed
 * design, and a genuine dispute has its own channel (the per-order
 * dispute button, where enabled) once the order is further along.
 */
async function acknowledgeOc(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  // 90-item backlog #75: shared with the no-login buyerActionController
  // route — see buyerActionService's docblock.
  const result = await buyerActionService.acknowledgeOc(orderId);
  flash.set(req, result.ok ? 'success' : 'error', result.message);
  res.redirect(`/client/orders/${orderId}`);
}

/**
 * docs/schema.sql Section AF — only reachable when staff have switched
 * the per-order flag on; re-checked here server-side (not just hidden in
 * the view) so a client can't file one on an order it wasn't enabled for
 * by guessing the URL.
 */
async function raiseDispute(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }
  if (order.dispute_button_visible_to_client !== 1) {
    flash.set(req, 'error', 'Disputes cannot be raised on this order from the portal.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  const description = String(req.body.description || '').trim();
  if (description === '') {
    flash.set(req, 'error', 'Please describe the issue before submitting.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  const noticeDate = todayYmd();
  const responseDays = parseInt((await companySettingsRepository.get('dispute_response_days_n')) || '10', 10);
  const responseDueDate = await workingDaysCalculator.addWorkingDays(noticeDate, responseDays);
  const disputeId = await disputeRepository.create(orderId, noticeDate, 'Buyer (via client portal)', description, null, responseDueDate);
  // External QA report N-07: this only ever wrote the audit log (the
  // catch-all notification email), so the one team actually responsible
  // for disputes — manage_disputes holders — never got a targeted
  // in-app alert, same dual-channel pattern submitFeedback() above
  // already uses for manage_orders.
  for (const staffUserId of await permissionRepository.usersWithPermission('manage_disputes')) {
    await notificationRepository.create(staffUserId, null, 'dispute_raised', orderId, `Client raised a dispute on order ${order.order_reference}.`);
  }
  await auditLogRepository.log(null, 'DISPUTE_RAISED', 'disputes', disputeId, null, null, description, 'Raised by the client via the client portal.');

  flash.set(req, 'success', `Your dispute has been logged — our team will respond by ${responseDueDate}.`);
  res.redirect(`/client/orders/${orderId}`);
}

/**
 * docs/schema.sql Section BX — a Complaint / Process Issue, not a
 * Dispute: no refund/cancellation intent, always available on any
 * order regardless of the dispute_button_visible_to_client toggle.
 */
async function raiseComplaint(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  const description = String(req.body.description || '').trim();
  if (description === '') {
    flash.set(req, 'error', 'Please describe the issue before submitting.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  await orderComplaintService.raiseAsClient(orderId, clientId, description);

  flash.set(req, 'success', 'Thank you — your feedback has been logged and our team will follow up.');
  res.redirect(`/client/orders/${orderId}`);
}

async function postComplaintReply(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const complaintId = parseInt(req.params.complaintId, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }
  const complaint = await orderComplaintRepository.find(complaintId);
  if (!complaint || complaint.order_id !== orderId || complaint.client_id !== clientId) {
    res.status(404).send('Complaint not found.');
    return;
  }

  const body = String(req.body.body || '').trim();
  if (body === '') {
    flash.set(req, 'error', 'Write a message before posting.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  await orderComplaintService.replyAsClient(complaintId, clientId, body);

  flash.set(req, 'success', 'Your message has been sent.');
  res.redirect(`/client/orders/${orderId}`);
}

/**
 * docs/schema.sql Section BV — export process step 14. Resubmission is
 * allowed (upsert), not a one-shot submission — there's no stated reason
 * to stop a client revising their own rating/comments.
 */
async function submitFeedback(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const orderId = parseInt(req.params.id, 10) || 0;
  const order = await orderRepository.find(orderId);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Order not found.');
    return;
  }

  // External QA report N-12: step 14 is a post-shipment step — feedback
  // on an order that's still active (production/shipping in progress)
  // asks the client to rate work that isn't finished yet. Same status
  // pair addExportBenefit() already gates on.
  if (!['shipped_docs_sent', 'complete'].includes(order.status)) {
    flash.set(req, 'error', "Feedback can be submitted once this order's documents have been sent to you.");
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  const rating = parseInt(req.body.rating, 10);
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) {
    flash.set(req, 'error', 'Please choose a rating between 1 and 5 stars.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }
  const comments = String(req.body.comments || '').trim() || null;

  await orderFeedbackRepository.upsert(orderId, clientId, rating, comments);

  // "SYSTEM MUST SEND NOTIFICATION FOR ALL ACTIONS ... THIS IS NON
  // NEGOTIABLE" — same dual-channel pattern as
  // orderCommentService.postAsClient(): an in-app notification per
  // manage_orders holder, plus the catch-all email via the audit log.
  for (const staffUserId of await permissionRepository.usersWithPermission('manage_orders')) {
    await notificationRepository.create(staffUserId, null, 'order_feedback_submitted', orderId, `Client submitted feedback (${rating}/5) on order ${order.order_reference}.`);
  }
  await auditLogRepository.log(null, 'ORDER_FEEDBACK_SUBMITTED', 'orders', orderId, null, null, String(rating), 'Order feedback submitted by the client via the client portal.');

  flash.set(req, 'success', 'Thank you — your feedback has been recorded.');
  res.redirect(`/client/orders/${orderId}`);
}

async function downloadDocument(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const documentId = parseInt(req.params.id, 10) || 0;
  const document = await documentRepository.find(documentId);

  if (!document || document.order_id === null) {
    res.status(404).send('Document not found.');
    return;
  }
  const order = await orderRepository.find(document.order_id);
  if (!order || order.client_id !== clientId) {
    res.status(404).send('Document not found.');
    return;
  }
  // External QA report M-02: the "Download PDF" link is hidden on the
  // page itself when downloads are off, but a client could still
  // guess/replay this exact URL — so it's refused here too, not just
  // hidden client-side.
  if (!(await clientRepository.documentDownloadsVisible(await clientPortalService.currentClient(req)))) {
    res.status(404).send('Document not found.');
    return;
  }
  // A client only ever sees the FINAL, approved/sent PDF of a
  // customer-facing document type — never a draft, never an internal-only
  // DOCX, regardless of what's asked for in the URL.
  if (!['approved', 'sent'].includes(document.status) || document.document_type_category === 'internal') {
    res.status(404).send('Document not found.');
    return;
  }
  if (!document.pdf_file_id) {
    res.status(404).send('Document not found.');
    return;
  }

  const file = await fileStoreRepository.find(document.pdf_file_id);
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('File is missing from storage.');
    return;
  }

  let safeDownloadName = file.original_filename.replace(/[\\/]/g, '-');
  // eslint-disable-next-line no-control-regex
  safeDownloadName = safeDownloadName.replace(/[\x00-\x1F\x7F"]/g, '');
  res.setHeader('Content-Type', file.mime_type);
  res.setHeader('Content-Disposition', `attachment; filename="${safeDownloadName}"`);
  res.setHeader('Content-Length', String(file.file_size_bytes));
  fs.createReadStream(file.server_path).pipe(res);
}

async function showAccount(req, res) {
  res.renderView('client_portal/account', { client: await clientPortalService.currentClient(req) }, 'layout/client');
}

async function changePassword(req, res) {
  const clientId = clientPortalService.currentClientId(req);
  const current = String(req.body.current_password || '');
  const newPassword = String(req.body.new_password || '');
  const confirm = String(req.body.confirm_password || '');

  const login = await clientLoginRepository.findByClientId(clientId);
  if (!login || !(await passwordHash.verify(current, login.password_hash))) {
    flash.set(req, 'error', 'Current password is incorrect.');
    res.redirect('/client/account');
    return;
  }
  const policyError = await passwordPolicyService.validate(newPassword);
  if (policyError !== null) {
    flash.set(req, 'error', policyError);
    res.redirect('/client/account');
    return;
  }
  if (newPassword !== confirm) {
    flash.set(req, 'error', 'New passwords do not match.');
    res.redirect('/client/account');
    return;
  }

  await clientPortalService.changePassword(clientId, newPassword);
  flash.set(req, 'success', 'Password updated.');
  res.redirect('/client/account');
}

module.exports = {
  showLogin, login, logout, endImpersonation, showForgotPassword, forgotPassword, showSetPassword, setPassword, dashboard, showOrder,
  showReorderForm, submitReorder,
  downloadDocument, showAccount, changePassword, reportPayment, acknowledgeOc, raiseDispute,
  raiseComplaint, postComplaintReply,
  postComment, downloadCommentAttachment, downloadPaymentScreenshot, uploadBuyerPo, submitFeedback,
};
