'use strict';

const fs = require('fs');
const crypto = require('crypto');

const flash = require('../helpers/flash');
const passwordHash = require('../helpers/passwordHash');
const reasonValidator = require('../helpers/reasonValidator');
const clientPasswordResetTokenRepository = require('../repositories/clientPasswordResetTokenRepository');
const clientLoginRepository = require('../repositories/clientLoginRepository');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const disputeRepository = require('../repositories/disputeRepository');
const documentRepository = require('../repositories/documentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderCommentRepository = require('../repositories/orderCommentRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderReorderRequestRepository = require('../repositories/orderReorderRequestRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientPortalService = require('../services/clientPortalService');
const orderCommentService = require('../services/orderCommentService');
const passwordPolicyService = require('../services/passwordPolicyService');
const fileUploadService = require('../services/fileUploadService');
const stageGateService = require('../services/stageGateService');
const workingDaysCalculator = require('../services/workingDaysCalculator');

function todayYmd() {
  return new Date().toISOString().slice(0, 10);
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
  res.renderView('client_portal/dashboard', {
    client: await clientPortalService.currentClient(req),
    orders: await orderRepository.forClient(clientId),
  }, 'layout/client');
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

  res.renderView('client_portal/order_show', {
    client: await clientPortalService.currentClient(req),
    order,
    products: await orderProductRepository.forOrder(orderId),
    totalFobValue: await orderProductRepository.totalFobValue(orderId),
    documents: await documentRepository.customerFacingForOrder(orderId),
    paymentReports: await clientPaymentReportRepository.forOrder(orderId),
    ocAcknowledgment: await orderOcAcknowledgmentRepository.find(orderId),
    comments: await orderCommentRepository.forOrder(orderId),
  }, 'layout/client');
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
    flash.set(req, 'error', e.message);
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
      flash.set(req, 'error', e.message);
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

  const ack = await orderOcAcknowledgmentRepository.find(orderId);
  if (!ack || ack.acknowledged_at !== null) {
    flash.set(req, 'error', 'There is nothing awaiting your acknowledgement on this order.');
    res.redirect(`/client/orders/${orderId}`);
    return;
  }

  await orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'client_portal', null, null);
  await stageGateService.passAndUnlockNext(orderId, 4, null);
  flash.set(req, 'success', 'Thank you — your acknowledgement has been recorded and your order is moving to production.');
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
  await auditLogRepository.log(null, 'DISPUTE_RAISED', 'disputes', disputeId, null, null, description, 'Raised by the client via the client portal.');

  flash.set(req, 'success', `Your dispute has been logged — our team will respond by ${responseDueDate}.`);
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
  showLogin, login, logout, showSetPassword, setPassword, dashboard, showOrder,
  showReorderForm, submitReorder,
  downloadDocument, showAccount, changePassword, reportPayment, acknowledgeOc, raiseDispute,
  postComment, downloadCommentAttachment, downloadPaymentScreenshot,
};
