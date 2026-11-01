'use strict';

const flash = require('../helpers/flash');
const associateBankRepository = require('../repositories/associateBankRepository');
const associateDocumentRepository = require('../repositories/associateDocumentRepository');
const associateEventRepository = require('../repositories/associateEventRepository');
const associateInvitationRepository = require('../repositories/associateInvitationRepository');
const associatePortalRepository = require('../repositories/associatePortalRepository');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const userRepository = require('../repositories/userRepository');
const csv = require('../helpers/csv');
const associateCodeService = require('../services/associateCodeService');
const associatePortalService = require('../services/associatePortalService');
const associateService = require('../services/associateService');
const fileUploadService = require('../services/fileUploadService');
const incentiveService = require('../services/incentiveService');
const { validateBank, sendFile } = require('./associateAdminController');

/**
 * Port of AssociatePortalController.php. Owner decision 1 Nov 2026 ("AS all
 * as recommended"): the associate's own portal. Every page shows only the
 * signed-in associate's own clients, orders, commission and profile — the
 * queries are scoped to their id on the server, never by anything the
 * browser sends.
 */

const LAYOUT = 'layout/associate';
const len = (v) => [...String(v ?? '')].length;
const MONTHS3 = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

async function me(req) {
  return (await associatePortalService.current(req)) || {};
}

async function layoutData(req, a) {
  return {
    me: a && a.id ? a : null,
    unread: a && a.id ? await associateEventRepository.unreadNotices(Number(a.id)) : 0,
    viewAs: associatePortalService.viewAsStaffId(req) !== null,
  };
}

async function render(req, res, view, data = {}) {
  const a = await me(req);
  res.renderView(`associate_portal/${view}`, { ...(await layoutData(req, a)), ...data }, LAYOUT);
}

function notFound(res, text) {
  res.status(404).send(text);
}

// ---------------------------------------------------------------- login

async function showLogin(req, res) {
  res.renderView('associate_portal/login', await layoutData(req, null), LAYOUT);
}

async function login(req, res) {
  const r = await associatePortalService.attemptLogin(req, String((req.body || {}).email || '').trim(), String((req.body || {}).password || ''));
  if (r.status !== 'code_sent') {
    flash.set(req, 'error', { locked: 'Too many wrong attempts — try again in 15 minutes.', disabled: 'Your portal access is not active. Please contact your NexaCrest relationship manager.' }[r.status] || 'E-mail or password is not correct.');
    res.redirect('/associate/login');
    return;
  }
  if (associatePortalService.showCodeOnScreen()) {
    flash.set(req, 'info', `Development only — your login code is ${r.code}.`);
  }
  res.redirect('/associate/login/code');
}

async function showCode(req, res) {
  if (associatePortalService.pendingId(req) === null) {
    res.redirect('/associate/login');
    return;
  }
  res.renderView('associate_portal/code', await layoutData(req, null), LAYOUT);
}

async function verifyCode(req, res) {
  if (!(await associatePortalService.verifyCode(req, String((req.body || {}).code || '')))) {
    flash.set(req, 'error', 'That code is not correct or has expired. Log in again to get a new code.');
    res.redirect('/associate/login/code');
    return;
  }
  res.redirect('/associate');
}

async function logout(req, res) {
  if (associatePortalService.viewAsStaffId(req) !== null) {
    const id = await associatePortalService.endViewAs(req);
    res.redirect(`/associates/${Number(id)}`);
    return;
  }
  await associatePortalService.logout(req);
  res.redirect('/associate/login');
}

async function showSetPassword(req, res) {
  const a = await associatePortalService.associateForSetPassword(String(req.params.token));
  res.renderView('associate_portal/set_password', { ...(await layoutData(req, null)), valid: !!a, token: String(req.params.token), email: a ? a.email : '' }, LAYOUT);
}

async function setPassword(req, res) {
  const token = String(req.params.token);
  const p = String((req.body || {}).new_password || '');
  const c = String((req.body || {}).confirm_password || '');
  let error = null;
  if (len(p) < 10 || !/[A-Za-z]/.test(p) || !/\d/.test(p)) error = 'Use at least 10 characters, with letters and digits.';
  else if (p !== c) error = 'The two passwords do not match.';
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/associate/set-password/${token}`);
    return;
  }
  if (!(await associatePortalService.setPassword(token, p))) {
    flash.set(req, 'error', 'This link has expired or was already used. Ask NexaCrest for a new one.');
    res.redirect(`/associate/set-password/${token}`);
    return;
  }
  flash.set(req, 'success', 'Password set. Log in with your e-mail and the new password.');
  res.redirect('/associate/login');
}

// ---------------------------------------------------------------- pages

async function home(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  const invitations = await associateInvitationRepository.forAssociate(id);
  await render(req, res, 'home', {
    statuses: associateRepository.STATUSES,
    totals: await associatePortalRepository.totals(id),
    clients: await associatePortalRepository.clients(id),
    orders: (await associatePortalRepository.orders(id)).slice(0, 5),
    events: await associateEventRepository.forAssociate(id, null, 10),
    invitations,
    openInvitations: invitations.filter((i) => ['created', 'opened', 'registered'].includes(i.status)).length,
  });
  await associateEventRepository.markNoticesRead(id);
}

function codeLink(a) {
  return a && a.personal_code ? `${associateService.appUrl()}/quotation-details?code=${encodeURIComponent(a.personal_code)}` : null;
}

async function clients(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  const invitations = await associateInvitationRepository.forAssociate(id);
  for (const i of invitations) i.usable = associateInvitationRepository.isUsable(i);
  const newLink = req.session._associate_new_link || null;
  delete req.session._associate_new_link;
  await render(req, res, 'clients', {
    clients: await associatePortalRepository.clients(id),
    invitations,
    invitationStatuses: associateInvitationRepository.STATUSES,
    newLink,
    codeLink: codeLink(a),
    protectionMonths: await associateService.protectionMonths(),
  });
}

async function createInvitation(req, res) {
  const a = await me(req);
  const company = String((req.body || {}).company_name || '').trim();
  const country = String((req.body || {}).country || '').trim();
  if (a.status !== 'active') {
    flash.set(req, 'error', 'You can invite clients once your account is active.');
  } else if (len(company) < 2 || len(company) > 255 || country === '' || len(country) > 100) {
    flash.set(req, 'error', 'Enter the company name and its country.');
  } else {
    const inv = await associateInvitationRepository.createLink(Number(a.id), company, country);
    req.session._associate_new_link = `${associateService.appUrl()}/invite/${inv.token}`;
    await associateEventRepository.add(Number(a.id), 'link_created', `Invitation link created for ${company} (${country}).`, null, null, inv.id, null, false);
    flash.set(req, 'success', `Invitation link for ${company} is ready — copy it below and send it to your buyer.`);
  }
  res.redirect('/associate/clients');
}

async function cancelInvitation(req, res) {
  const a = await me(req);
  const inv = await associateInvitationRepository.find(parseInt(req.params.id, 10));
  if (!inv || Number(inv.associate_id) !== Number(a.id)) {
    notFound(res, 'Invitation not found.');
    return;
  }
  if (!associateInvitationRepository.isUsable(inv)) {
    flash.set(req, 'error', 'This invitation can no longer be cancelled.');
  } else {
    await associateInvitationRepository.setStatus(Number(inv.id), 'cancelled');
    await associateEventRepository.add(Number(a.id), 'link_cancelled', `Invitation link for ${inv.company_name} cancelled.`, null, null, Number(inv.id), null, false);
    flash.set(req, 'success', 'Invitation cancelled.');
  }
  res.redirect('/associate/clients');
}

/** The associate may choose the readable part of their code once (part 4.4-A2). */
async function chooseCode(req, res) {
  const a = await me(req);
  const part = associateCodeService.readablePart(String((req.body || {}).readable || ''));
  const chosen = (await associateEventRepository.forAssociate(Number(a.id))).some((e) => e.event === 'code_chosen');
  if (chosen) {
    flash.set(req, 'error', 'You have already chosen your code. Ask your relationship manager if it must change.');
  } else if (part.length < 3) {
    flash.set(req, 'error', 'Use 3 to 12 letters or digits.');
  } else {
    const code = await associateCodeService.issue(Number(a.id), part);
    await associateEventRepository.add(Number(a.id), 'code_chosen', `Personal code set to ${code}${a.personal_code ? ` (was ${a.personal_code}; the old code no longer brings new clients)` : ''}.`, null, null, null, null, false);
    await auditLogRepository.log(null, 'ASSOCIATE_CODE_CHOSEN', 'associates', Number(a.id), 'personal_code', a.personal_code || null, code);
    flash.set(req, 'success', `Your personal code is now ${code}.`);
  }
  res.redirect('/associate/clients');
}

async function orders(req, res) {
  const a = await me(req);
  await render(req, res, 'orders', { orders: await associatePortalRepository.orders(Number(a.id)) });
}

async function order(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  const rows = await associatePortalRepository.orders(id, parseInt(req.params.id, 10));
  if (!rows.length) {
    notFound(res, 'Order not found.');
    return;
  }
  const events = (await associateEventRepository.forAssociate(id)).filter((e) => Number(e.order_id) === Number(rows[0].id));
  await render(req, res, 'order', { o: rows[0], events });
}

async function commission(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  const payouts = await associatePortalRepository.payouts(id);
  if (req.query.format === 'csv') {
    csv.stream(res, 'commission-statement.csv', ['Incentive month', 'Order', 'Client', 'Amount (order currency)', 'Currency', 'Amount INR', 'Status', 'Paid on', 'Paid INR', 'TDS INR', 'Reference', 'Note'],
      payouts.map((p) => [p.incentive_month, p.order_reference, p.company_legal_name, p.amount_fc, p.currency_code, p.amount_inr, p.status, p.paid_on, p.paid_inr, p.tds_inr, p.paid_reference || '', p.note || p.hold_reason || '']));
    return;
  }
  const invoiceByPayout = {};
  for (const p of payouts) {
    p.month_label = incentiveService.monthLabel(String(p.incentive_month));
    const inv = await associateDocumentRepository.invoiceForPayout(Number(p.id));
    if (inv) invoiceByPayout[p.id] = { id: Number(inv.id), reference: inv.reference };
  }
  await render(req, res, 'commission', {
    payouts,
    totals: await associatePortalRepository.totals(id),
    print: req.query.print === '1',
    invoiceByPayout,
    documents: await associateDocumentRepository.forAssociate(id),
    docKinds: associateDocumentRepository.KINDS,
  });
}

async function uploadInvoice(req, res) {
  const a = await me(req);
  const payout = await incentiveRepository.findPayout(parseInt(req.params.id, 10));
  if (!payout || payout.payee_kind !== 'associate' || Number(payout.associate_id) !== Number(a.id)) {
    notFound(res, 'Payout not found.');
    return;
  }
  const no = String((req.body || {}).invoice_no || '').trim();
  if (no === '' || len(no) > 100) {
    flash.set(req, 'error', 'Enter your invoice number.');
    res.redirect('/associate/commission');
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'invoice_file', 'associate_document', 'associates/documents', null, null, null, null, a.name, 'Associate commission invoice');
  } catch (e) {
    flash.setErrorFromException(req, e);
    res.redirect('/associate/commission');
    return;
  }
  await associateDocumentRepository.add(Number(a.id), 'invoice', fileId, Number(payout.id), no, true, null);
  await associateEventRepository.add(Number(a.id), 'invoice_uploaded', `Invoice ${no} uploaded for the commission on order ${payout.order_id}.`, null, Number(payout.order_id), null, null, false);
  await auditLogRepository.log(null, 'ASSOCIATE_INVOICE_UPLOADED', 'incentive_payouts', Number(payout.id), null, null, no);
  flash.set(req, 'success', `Invoice ${no} received.`);
  res.redirect('/associate/commission');
}

/** The registration certificate (guarantee 5): a printable page, kept by the associate. */
async function certificate(req, res) {
  const a = await me(req);
  const client = (await associateRepository.clients(Number(a.id))).find((c) => Number(c.id) === parseInt(req.params.id, 10));
  if (!client) {
    notFound(res, 'Client not found.');
    return;
  }
  const events = (await associateEventRepository.forAssociate(Number(a.id), Number(client.id))).reverse();
  const now = new Date(Date.now() + 5.5 * 3600 * 1000);
  const printedAt = `${now.getUTCDate()} ${MONTHS3[now.getUTCMonth()]} ${now.getUTCFullYear()}, ${String(now.getUTCHours()).padStart(2, '0')}:${String(now.getUTCMinutes()).padStart(2, '0')}`;
  res.renderView('associate_portal/certificate', { a, c: client, events, printedAt }, null);
}

async function profile(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  const ladder = await associateRepository.globalLadder();
  const rates = [];
  for (const [label, n] of [['1–5', 1], ['6–10', 6], ['11–20', 11], ['21–40', 21], ['41+', 41]]) {
    const r = await associateRepository.rateWithSource(a, n, ladder);
    rates.push({ label, rate: r.rate, source: r.source, rate_text: `${r.rate.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')}%` });
  }
  const today = associateService.todayIst();
  const banks = await associateBankRepository.forAssociate(id);
  for (const b of banks) {
    b.masked = associateBankRepository.masked(b);
    b.usable_later = !!b.usable_from && String(b.usable_from).slice(0, 10) > today;
  }
  const usable = await associateBankRepository.usable(id);
  await render(req, res, 'profile', {
    rates,
    banks,
    usableBankMasked: usable ? associateBankRepository.masked(usable) : null,
    documents: await associateDocumentRepository.forAssociate(id),
    docKinds: associateDocumentRepository.KINDS,
    associateKinds: associateDocumentRepository.ASSOCIATE_KINDS.filter((k) => k !== 'invoice'),
    bankWaitDays: await associateService.bankWaitDays(),
    ladder,
  });
}

async function saveProfile(req, res) {
  const a = await me(req);
  const body = req.body || {};
  const phone = String(body.phone || '').trim();
  const address = String(body.address || '').trim();
  const pan = String(body.pan || '').trim().toUpperCase();
  const gstin = String(body.gstin || '').trim().toUpperCase();
  let error = null;
  if (len(phone) > 40 || len(address) > 1000) error = 'Phone or address is too long.';
  else if (pan !== '' && a.based_in === 'india' && !/^[A-Z]{5}\d{4}[A-Z]$/.test(pan)) error = 'Enter the PAN as on the card (e.g. ABCDE1234F).';
  else if (gstin !== '' && !/^\d{2}[A-Z0-9]{13}$/.test(gstin)) error = 'Enter the 15-character GSTIN, or leave it empty.';
  if (error) {
    flash.set(req, 'error', error);
  } else {
    await associateRepository.setFields(Number(a.id), { phone: phone || null, address: address || null, pan: pan || null, gstin: gstin || null });
    await auditLogRepository.log(null, 'ASSOCIATE_PROFILE_UPDATED', 'associates', Number(a.id));
    flash.set(req, 'success', 'Profile saved.');
  }
  res.redirect('/associate/profile');
}

async function uploadDocument(req, res) {
  const a = await me(req);
  const kind = String((req.body || {}).kind || '');
  const KINDS = associateDocumentRepository.KINDS;
  if (!associateDocumentRepository.ASSOCIATE_KINDS.includes(kind) || kind === 'invoice') {
    flash.set(req, 'error', 'Choose what the document is.');
    res.redirect('/associate/profile');
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'document_file', 'associate_document', 'associates/documents', null, null, null, null, a.name, KINDS[kind]);
  } catch (e) {
    flash.setErrorFromException(req, e);
    res.redirect('/associate/profile');
    return;
  }
  await associateDocumentRepository.add(Number(a.id), kind, fileId, null, null, true, null);
  await auditLogRepository.log(null, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', Number(a.id), 'kind', null, kind);
  flash.set(req, 'success', `${KINDS[kind]} received.`);
  res.redirect('/associate/profile');
}

/** Bank details: a request; the verified account keeps being used until Accounts verify the new one (AS-7). */
async function requestBank(req, res) {
  const a = await me(req);
  const result = validateBank(a, req.body || {});
  if (typeof result === 'string') {
    flash.set(req, 'error', result);
    res.redirect('/associate/profile');
    return;
  }
  try {
    result.proof_file_id = await fileUploadService.handleUpload(req, 'proof_file', 'associate_document', 'associates/bank', null, null, null, null, a.name, 'Bank proof');
  } catch (e) {
    flash.set(req, 'error', 'Attach a cancelled cheque or a bank letter (PDF or image).');
    res.redirect('/associate/profile');
    return;
  }
  const id = await associateBankRepository.request(Number(a.id), result, true, null);
  await auditLogRepository.log(null, 'ASSOCIATE_BANK_CHANGE_REQUESTED', 'associate_bank_accounts', id, null, null, 'by associate');
  await associateService.notify(a, 'bank_changed', `A change of your bank details (to ${result.bank_name} ••••${result.account_number.slice(-4)}) was received on ${associateService.jMY()}. Your payments keep going to your verified account until NexaCrest has checked the new one. If you did not ask for this, contact NexaCrest at once.`);
  for (const uid of await permissionRepository.usersWithPermission('manage_associate_bank')) {
    await notificationRepository.create(uid, null, 'associate_bank_change', null, `Associate ${a.name} asked to change their bank details — two people must verify it.`);
  }
  flash.set(req, 'success', 'Bank change received. It is used once NexaCrest has verified it; until then payments go to your verified account.');
  res.redirect('/associate/profile');
}

async function downloadDocument(req, res) {
  const a = await me(req);
  const doc = await associateDocumentRepository.find(parseInt(req.params.id, 10));
  if (!doc || Number(doc.associate_id) !== Number(a.id)) {
    notFound(res, 'Document not found.');
    return;
  }
  sendFile(res, doc.server_path, doc.original_filename, doc.mime_type || 'application/octet-stream');
}

async function downloadAgreement(req, res) {
  const a = await me(req);
  const file = a.agreement_file_id ? await fileStoreRepository.find(Number(a.agreement_file_id)) : null;
  if (!file) {
    notFound(res, 'No signed agreement is on file yet.');
    return;
  }
  await auditLogRepository.log(null, 'ASSOCIATE_AGREEMENT_DOWNLOADED_BY_ASSOCIATE', 'associates', Number(a.id));
  sendFile(res, file.server_path, file.original_filename, 'application/pdf');
}

async function messages(req, res) {
  const a = await me(req);
  const rm = a.relationship_manager_id ? await userRepository.findById(Number(a.relationship_manager_id)) : null;
  await render(req, res, 'messages', { messages: await associateEventRepository.messages(Number(a.id)), rm });
}

async function sendMessage(req, res) {
  const a = await me(req);
  const body = String((req.body || {}).body || '').trim();
  if (body === '' || len(body) > 4000) {
    flash.set(req, 'error', 'Write your message (up to 4,000 characters).');
  } else {
    await associateEventRepository.addMessage(Number(a.id), true, null, body);
    const to = a.relationship_manager_id ? [Number(a.relationship_manager_id)] : await permissionRepository.usersWithPermission('manage_associates');
    for (const uid of to) {
      await notificationRepository.create(uid, null, 'associate_message', null, `Message from associate ${a.name}.`);
    }
    flash.set(req, 'success', 'Message sent.');
  }
  res.redirect('/associate/messages');
}

module.exports = {
  codeLink, showLogin, login, showCode, verifyCode, logout, showSetPassword, setPassword, home, clients, createInvitation, cancelInvitation, chooseCode,
  orders, order, commission, uploadInvoice, certificate, profile, saveProfile, uploadDocument, requestBank, downloadDocument, downloadAgreement,
  messages, sendMessage,
};
