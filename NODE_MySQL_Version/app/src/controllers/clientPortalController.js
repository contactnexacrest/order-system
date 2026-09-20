'use strict';

const fs = require('fs');
const crypto = require('crypto');

const flash = require('../helpers/flash');
const passwordHash = require('../helpers/passwordHash');
const clientPasswordResetTokenRepository = require('../repositories/clientPasswordResetTokenRepository');
const clientLoginRepository = require('../repositories/clientLoginRepository');
const documentRepository = require('../repositories/documentRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const orderRepository = require('../repositories/orderRepository');
const clientPortalService = require('../services/clientPortalService');
const passwordPolicyService = require('../services/passwordPolicyService');

/**
 * Port of App\Controllers\ClientPortalController. The client-facing
 * portal — structurally separate screens from the staff app (own layout,
 * own nav, own session key via clientPortalService). Read-only throughout
 * except the client's own password: no editing of profile info, no audit
 * visibility, per the confirmed scope.
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
    documents: await documentRepository.customerFacingForOrder(orderId),
  }, 'layout/client');
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
  downloadDocument, showAccount, changePassword,
};
