'use strict';

const db = require('../config/db');
const dates = require('../helpers/dates');
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
const associateKyc = require('../services/associateKyc');
const associatePortalService = require('../services/associatePortalService');
const twoFactorService = require('../services/twoFactorService');
const associateService = require('../services/associateService');
const fileUploadService = require('../services/fileUploadService');
const incentiveService = require('../services/incentiveService');
const { validateBank, sendFile } = require('./associateAdminController');
const associateAccess = require('../services/associateAccess');

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
    access: a && a.id ? await associateAccess.features(a) : null, // D-261: what the access level shows
    // D-293: new bank details waiting for the associate's confirmation — a bar on every page.
    bankWaiting: a && a.id ? await require('../services/associateBankConfirmation').open(Number(a.id)) : null,
  };
}

/**
 * D-292 (owner 11 Nov 2026): the associate's current % only — "5%" + "of the FOB value of each order from the
 * clients you introduced" — and only with "Show commission %" (D-261). Null otherwise.
 */
async function commissionHeadline(a) {
  if (Number(a.no_commission || 0) === 1 || !(await associateAccess.allows(a, 'commission_pct'))) return null;
  const associateCommission = require('../services/associateCommission');
  const [level] = await associateCommission.levelForAssociate(a, null);
  if (!level) return null;
  return { rate: associateCommission.rateLabel(level),
    text: (level.kind || 'pct') === 'pct' ? 'of the FOB value of each order from the clients you introduced' : 'on each order from the clients you introduced' };
}

/** D-292: the data of the "My commission" box (headline + How it works). */
async function myCommissionData(a) {
  const associateCommission = require('../services/associateCommission');
  const set = await associateCommission.settings();
  // D-264: India is always paid in rupees, abroad in the payout currency — the same rule as the "How we set your commission" box.
  const payCur = require('../services/associatePayoutService').payCurrency(a);
  return {
    headline: await commissionHeadline(a),
    commissionSettings: set,
    payDayText: associateCommission.payDayText(set),
    currencyName: { INR: 'Indian rupees', USD: 'US dollars', EUR: 'euros', GBP: 'pounds sterling' }[payCur] || payCur,
  };
}

/** D-261: orders with what the associate's access level does not show taken out on the server (FOB value, commission %). */
async function visibleOrders(a, orders) {
  const access = await associateAccess.features(a);
  const payoutService = require('../services/associatePayoutService');
  const india = payoutService.isIndia(a);
  return orders.map((o) => {
    const v = associateAccess.filterOrder(access, o);
    // D-264: an associate in India sees the fixed rupee amount once it is fixed; abroad, never rupees.
    const cm = v.commission;
    if (india && cm && cm.fixed_inr && cm.fixed_rate && cm.state !== 'paid') {
      cm.fixed_line = payoutService.fixedRupeeLine(cm.fixed_inr, cm.fixed_rate, cm.currency, cm.fixed_on);
    }
    if (cm && cm.detail && cm.detail.fixed && india && cm.detail.state !== 'unpaid') {
      cm.detail.fixed_line = payoutService.fixedRupeeLine(cm.detail.amount_inr, cm.detail.fixed_rate, cm.detail.currency, cm.detail.fixed_at);
    }
    return v;
  });
}

/** D-264: the portal totals as text in the order currency ("USD 1,250.00"). */
function totalsText(totals) {
  const { fcText } = associatePortalRepository;
  return { earned: fcText(totals.fc.earned), waiting: fcText(totals.fc.waiting), paid: fcText(totals.fc.paid), held: fcText(totals.fc.held) };
}

async function render(req, res, view, data = {}) {
  const a = await me(req);
  res.renderView(`associate_portal/${view}`, { ...(await layoutData(req, a)), ...data }, LAYOUT);
}

/** Q-R8-2: refuses a change by a suspended associate; true when refused (the redirect is sent). */
function refuseWhenSuspended(req, res, a, back) {
  if (!associatePortalService.isSuspended(a)) {
    return false;
  }
  flash.set(req, 'error', associatePortalService.SUSPENDED_REFUSED);
  res.redirect(back);
  return true;
}

/** A missing record (or another associate's) inside the portal layout: 404, same wording, a way back home. */
async function notFoundPage(req, res, message) {
  res.status(404);
  await render(req, res, 'not_found', { message });
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
    flash.set(req, 'error', { locked: 'Too many wrong attempts — try again in 15 minutes.', too_many_codes: twoFactorService.TOO_MANY_CODES_MESSAGE /* HNT-L04 */, disabled: 'Your portal access is not active. Please contact your NexaCrest relationship manager.' }[r.status] || 'E-mail or password is not correct.');
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
  // QA ACC-15: signing out changes the session, so only the posted form (with
  // its CSRF token) does it; a plain link or <img src> to the address does nothing.
  if (req.method !== 'POST') {
    res.redirect(associatePortalService.currentId(req) !== null ? '/associate' : '/associate/login');
    return;
  }
  if (associatePortalService.viewAsStaffId(req) !== null) {
    const id = await associatePortalService.endViewAs(req);
    res.redirect(`/associates/${Number(id)}`);
    return;
  }
  await associatePortalService.logout(req);
  res.redirect('/associate/login');
}

/** D-282: "Forgot password?" for associates. */
async function showForgotPassword(req, res) {
  res.renderView('associate_portal/forgot_password', { ...(await layoutData(req, null)) }, LAYOUT);
}

async function forgotPassword(req, res) {
  const email = String((req.body || {}).email || '').trim().toLowerCase();
  if (email === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    flash.set(req, 'error', email === '' ? 'Enter your e-mail address.' : 'Enter a valid e-mail address.');
    res.redirect('/associate/forgot-password');
    return;
  }
  const link = await associatePortalService.sendResetLink(req, email);
  flash.set(req, 'success', `If ${email} belongs to an associate account, we have sent a link to set a new password. The link is valid for 72 hours and works once.`);
  if (link && associatePortalService.showCodeOnScreen()) {
    flash.set(req, 'info', `Development only — link: ${link}`);
  }
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
    flash.set(req, 'error', 'This link has expired or was already used. Use "Forgot password?" on the login page to get a new one.');
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
    ...(await (async () => { const totals = await associatePortalRepository.totals(id); return { totals, totalsText: totalsText(totals) }; })()),
    clients: await associatePortalRepository.clients(id),
    orders: await visibleOrders(await me(req), (await associatePortalRepository.orders(id)).slice(0, 5)),
    events: await associateEventRepository.forAssociate(id, null, 10),
    invitations,
    openInvitations: invitations.filter((i) => ['created', 'opened', 'registered'].includes(i.status)).length,
    // D-252 / D-253: after the first login the associate completes the profile and the KYC documents.
    kycMissing: await associateKyc.missing(a),
    addressGrace: await associateKyc.addressGraceNote(a), // D-290
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
    // D-254: the code change "like a captcha refresh" and every code the associate has had.
    proposal: req.session.associate_code_proposal && Number(req.session.associate_code_proposal.associate_id) === id ? req.session.associate_code_proposal : null,
    codes: await associateCodeService.codes(id),
    codeChangesPerYear: await associateCodeService.changesPerYear(),
    codeChangesUsed: await associateCodeService.changesInLastYear(id),
  });
}

async function createInvitation(req, res) {
  const a = await me(req);
  if (refuseWhenSuspended(req, res, a, '/associate/clients')) {
    return;
  }
  const company = String((req.body || {}).company_name || '').trim();
  const country = String((req.body || {}).country || '').trim();
  if (a.status !== 'active') {
    flash.set(req, 'error', 'You can invite clients once your account is active.');
  } else if (len(company) < 2 || len(company) > 255 || country === '' || len(country) > 100) {
    flash.set(req, 'error', 'Enter the company name and its country.');
  } else {
    const inv = await associateInvitationRepository.createLink(Number(a.id), company, country);
    req.session._associate_new_link = `${associateService.appUrl()}/invite/${inv.token}`;
    await associateEventRepository.add(Number(a.id), 'link_created', `Buyer registration link created for ${company} (${country}).`, null, null, inv.id, null, false);
    flash.set(req, 'success', `Buyer registration link for ${company} is ready — copy it below and send it to your buyer.`);
  }
  res.redirect('/associate/clients');
}

async function cancelInvitation(req, res) {
  const a = await me(req);
  if (refuseWhenSuspended(req, res, a, '/associate/clients')) {
    return;
  }
  const inv = await associateInvitationRepository.find(parseInt(req.params.id, 10));
  if (!inv || Number(inv.associate_id) !== Number(a.id)) {
    await notFoundPage(req, res, 'Invitation not found.');
    return;
  }
  if (!associateInvitationRepository.isUsable(inv)) {
    flash.set(req, 'error', 'This invitation can no longer be cancelled.');
  } else {
    await associateInvitationRepository.setStatus(Number(inv.id), 'cancelled');
    await associateEventRepository.add(Number(a.id), 'link_cancelled', `Buyer registration link for ${inv.company_name} cancelled.`, null, null, Number(inv.id), null, false);
    flash.set(req, 'success', 'Invitation cancelled.');
  }
  res.redirect('/associate/clients');
}

/** The associate may choose the readable part of their code once (part 4.4-A2). */
/**
 * Owner decision D-254 (A5): the associate changes their personal code like a captcha refresh —
 * "New ending" proposes a code (nothing saved), "Use this code" switches to it. Old codes keep
 * bringing buyers to them. At most N changes a year (setting, default 3).
 */
async function proposeCode(req, res) {
  const a = await me(req);
  if (refuseWhenSuspended(req, res, a, '/associate/clients')) {
    return;
  }
  const part = associateCodeService.readablePart(String((req.body || {}).readable || ''));
  if (part.length < 3) {
    flash.set(req, 'error', 'Use 3 to 12 letters or digits.');
  } else {
    req.session.associate_code_proposal = { associate_id: Number(a.id), code: await associateCodeService.propose(part), readable: part };
  }
  res.redirect('/associate/clients#code');
}

async function chooseCode(req, res) {
  const a = await me(req);
  if (refuseWhenSuspended(req, res, a, '/associate/clients')) {
    return;
  }
  const proposal = req.session.associate_code_proposal;
  const proposed = proposal && Number(proposal.associate_id) === Number(a.id) ? String(proposal.code) : '';
  const limit = await associateCodeService.changesPerYear();
  if (proposed === '' || String((req.body || {}).code || '') !== proposed) {
    flash.set(req, 'error', 'Press "Show another ending" to get a code first, then "Use this code".');
  } else if (a.personal_code && await associateCodeService.changesInLastYear(Number(a.id)) >= limit) {
    flash.set(req, 'error', `You can change your code ${limit} times a year — you have used them. Ask your relationship manager if it must change.`);
  } else {
    let code;
    try {
      code = await associateCodeService.issue(Number(a.id), proposal.readable, false, null, associateCodeService.isValid(proposed) ? proposed : null);
    } catch (e) {
      // Someone took this code in the meantime (unique): propose again.
      delete req.session.associate_code_proposal;
      flash.set(req, 'error', 'That code was just taken — press "Show another ending" for another one.');
      res.redirect('/associate/clients#code');
      return;
    }
    delete req.session.associate_code_proposal;
    await associateEventRepository.add(Number(a.id), 'code_changed', `Personal code set to ${code}${a.personal_code ? ` (was ${a.personal_code}; the old code still brings buyers to you)` : ''}.`, null, null, null, null, false);
    await auditLogRepository.log(null, 'ASSOCIATE_CODE_CHOSEN', 'associates', Number(a.id), 'personal_code', a.personal_code || null, code);
    flash.set(req, 'success', `Your personal code is now ${code}.${a.personal_code ? ' Your old code keeps working.' : ''}`);
  }
  res.redirect('/associate/clients#code');
}

async function orders(req, res) {
  const a = await me(req);
  await render(req, res, 'orders', { orders: await visibleOrders(a, await associatePortalRepository.orders(Number(a.id))) });
}

async function order(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  const rows = await associatePortalRepository.orders(id, parseInt(req.params.id, 10));
  if (!rows.length) {
    await notFoundPage(req, res, 'Order not found.');
    return;
  }
  const events = (await associateEventRepository.forAssociate(id)).filter((e) => Number(e.order_id) === Number(rows[0].id));
  await render(req, res, 'order', { o: (await visibleOrders(await me(req), [rows[0]]))[0], events });
}

async function commission(req, res) {
  const a = await me(req);
  const id = Number(a.id);
  // D-263: the portal shows only the last N months of commission (plus anything still unpaid).
  const months = require('../services/associateCommission').portalMonths(await require('../repositories/incentiveRepository').settings());
  const payouts = await associatePortalRepository.portalPayouts(id, months);
  const payoutService = require('../services/associatePayoutService');
  const india = payoutService.isIndia(a);
  if (req.query.format === 'csv') {
    // D-264: the order currency for every associate; rupees (fixed amount, TDS, paid) only for an associate in India.
    if (india) {
      csv.stream(res, 'commission-statement.csv', ['Incentive month', 'Order', 'Client', 'Commission (order currency)', 'Currency', 'You are paid (INR, fixed)', 'Fixed at (INR per unit)', 'Fixed on', 'Status', 'Paid on', 'Paid INR', 'TDS INR', 'Reference', 'Note'],
        payouts.map((p) => [p.incentive_month, p.order_reference, p.company_legal_name, p.amount_fc, p.currency_code, p.amount_inr, p.fixed_rate, p.fixed_on, p.status, p.paid_on, p.paid_inr, p.tds_inr, p.paid_reference || '', p.note || p.hold_reason || '']));
      return;
    }
    csv.stream(res, 'commission-statement.csv', ['Incentive month', 'Order', 'Client', 'Commission', 'Currency', 'Status', 'Paid on', 'Reference', 'Note'],
      payouts.map((p) => [p.incentive_month, p.order_reference, p.company_legal_name, p.amount_fc, p.currency_code, p.status, p.paid_on, p.paid_reference || '', p.note || p.hold_reason || '']));
    return;
  }
  const invoiceByPayout = {};
  for (const p of payouts) {
    p.month_label = incentiveService.monthLabel(String(p.incentive_month));
    // Owner decision 2 Nov 2026 (D-235): gross / TDS (section, rate) / net.
    p.gross_inr = Number(p.paid_inr || 0) + Number(p.tds_inr || 0);
    p.tdsRateLabel = Number(p.tds_rate || 0) > 0 ? require('../services/associateTds').pct(Number(p.tds_rate)) : '';
    p.status_label = Number(p.amount_inr) < 0 && p.status === 'due' ? 'We will take this off your next payment' : associatePortalRepository.payoutLabel(p, false, String(p.incentive_month));
    p.rate_text = associatePortalRepository.orderRateText(p); // D-292: the % fixed on the order
    if (india && p.fixed_rate !== null && p.fixed_rate !== undefined && p.status !== 'paid' && Number(p.amount_inr) > 0) {
      p.fixed_line = payoutService.fixedRupeeLine(p.amount_inr, p.fixed_rate, p.currency_code, p.fixed_on);
    }
    const inv = await associateDocumentRepository.invoiceForPayout(Number(p.id));
    if (inv) invoiceByPayout[p.id] = { id: Number(inv.id), reference: inv.reference };
  }
  await render(req, res, 'commission', {
    payouts,
    ...(await (async () => { const totals = await associatePortalRepository.totals(id, months); return { totals, totalsText: totalsText(totals) }; })()),
    isIndia: india,
    howFixed: await payoutService.howFixedBox(a),
    print: req.query.print === '1',
    invoiceByPayout,
    documents: await associateDocumentRepository.forAssociate(id),
    docKinds: associateDocumentRepository.KINDS,
    // D-292: the current % and "How it works" at the top; the % fixed on each order in the list.
    ...(await myCommissionData(a)),
  });
}

async function uploadInvoice(req, res) {
  const a = await me(req);
  const payout = await incentiveRepository.findPayout(parseInt(req.params.id, 10));
  if (!payout || payout.payee_kind !== 'associate' || Number(payout.associate_id) !== Number(a.id)) {
    await notFoundPage(req, res, 'Payout not found.');
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
    await notFoundPage(req, res, 'Client not found.');
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
  const associateCommission = require('../services/associateCommission');
  const [level] = await associateCommission.levelForAssociate(a, null);
  const commissionSettings = await associateCommission.settings();
  // D-263: the rate only (no level name, no ladder wording), and only with "Show commission %".
  const commissionNow = (await associateAccess.allows(a, 'commission_pct'))
    ? `${(a.commission_mode || 'ladder') === 'fixed' ? 'Your commission: ' : 'Your commission on your next order: '}${associateCommission.rateLabel(level)}`
    : 'Your commission is as set out in your agreement.';
  const today = associateService.todayIst();
  const banks = await associateBankRepository.forAssociate(id);
  for (const b of banks) {
    b.masked = associateBankRepository.masked(b);
    b.usable_later = !!b.usable_from && String(b.usable_from).slice(0, 10) > today;
    b.status_text = require('../services/associateBankConfirmation').associateStatus(b, today); // D-293
  }
  const usable = await associateBankRepository.usable(id);
  const docs = await associateDocumentRepository.forAssociate(id);
  await render(req, res, 'profile', {
    commissionNow,
    commissionSettings,
    runLabel: associateCommission.runLabel(commissionSettings),
    banks,
    usableBankMasked: usable ? associateBankRepository.masked(usable) : null,
    documents: docs,
    docKinds: associateDocumentRepository.KINDS,
    associateKinds: associateDocumentRepository.ASSOCIATE_KINDS.filter((k) => k !== 'invoice'),
    kyc: await associateKyc.checklist(a, docs),
    kycLabels: associateKyc.STATUS_LABELS,
    docRules: associateKyc.REFERENCE_RULES, docOtherRule: associateKyc.OTHER_RULE, idDocTypes: associateKyc.ID_DOC_TYPES,
    bankWaitDays: await associateService.bankWaitDays(),
    // D-293: confirm within 7 days; waits for a first / changed account.
    bankConfirmDays: require('../services/bankDetailsCheck').CONFIRM_DAYS,
    bankWaitFirst: await require('../services/bankDetailsCheck').waitDays(true),
    addressGrace: await associateKyc.addressGraceNote(a), // D-290
    ...(await myCommissionData(a)),
  });
}

async function saveProfile(req, res) {
  const a = await me(req);
  if (refuseWhenSuspended(req, res, a, '/associate/profile')) {
    return;
  }
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
  if (refuseWhenSuspended(req, res, a, '/associate/profile')) {
    return;
  }
  const kind = String((req.body || {}).kind || '');
  const KINDS = associateDocumentRepository.KINDS;
  if (!associateDocumentRepository.ASSOCIATE_KINDS.includes(kind) || kind === 'invoice') {
    flash.set(req, 'error', 'Choose what the document is.');
    res.redirect('/associate/profile');
    return;
  }
  // Owner decision D-251 (A4): the document's number / reference, checked per kind (Aadhaar: last 4 digits only).
  // D-290: an ID document carries its type and number; ID and address proofs get app-set file names.
  const [reference, idType, refError] = associateKyc.checkUpload(kind, (req.body || {}).reference, (req.body || {}).id_doc_type);
  if (refError) {
    flash.set(req, 'error', refError);
    res.redirect('/associate/profile');
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'document_file', 'associate_document', 'associates/documents', null, null, null,
      associateKyc.appFileName(kind, a.name, req.file ? req.file.originalname : ''), a.name, KINDS[kind]);
  } catch (e) {
    flash.setErrorFromException(req, e);
    res.redirect('/associate/profile');
    return;
  }
  await associateDocumentRepository.add(Number(a.id), kind, fileId, null, reference, true, null, idType);
  await auditLogRepository.log(null, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', Number(a.id), 'kind', null, kind);
  await associateKyc.notifyStaffOfUpload(a, kind);
  flash.set(req, 'success', `${KINDS[kind]} received — NexaCrest will check it.`);
  res.redirect('/associate/profile');
}

/** Bank details: a request; the verified account keeps being used until Accounts verify the new one (AS-7). */
async function requestBank(req, res) {
  const a = await me(req);
  if (refuseWhenSuspended(req, res, a, '/associate/profile')) {
    return;
  }
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
  // D-293: the associate confirms it first (code to their registered e-mail), then NexaCrest verifies it.
  await require('../services/associateBankConfirmation').start(id, a, true, null);
  // D-281: the relationship manager and Accounts & Finance's verifiers.
  await notificationRepository.notifyRouted(await permissionRepository.usersWithPermission('manage_associate_bank'), await require('../services/staffRouting').forAssociate(a, true),
    'associate_bank_change', null, `Associate ${a.name} asked to change their bank details — they confirm them first, then one person verifies them.`);
  flash.set(req, 'success', `New bank details received. Please confirm them now with the code we e-mail you. ${await associateService.bankPaymentsSentence(Number(a.id))}`);
  res.redirect('/associate/bank/confirm');
}

// ---------------------------------------------------------------- D-293: the associate confirms new bank details

/** The confirm page: current vs new, last 4 digits, the e-mailed code, "These are my bank details". */
async function showBankConfirm(req, res) {
  const a = await me(req);
  const confirmation = require('../services/associateBankConfirmation');
  const b = await confirmation.open(Number(a.id));
  if (!b) {
    flash.set(req, 'info', 'No new bank details are waiting for your confirmation.');
    res.redirect('/associate/profile');
    return;
  }
  const devCode = req.session ? req.session.associateBankCodeDev : null;
  if (req.session) delete req.session.associateBankCodeDev;
  const bankDetailsCheck = require('../services/bankDetailsCheck');
  await render(req, res, 'bank_confirm', {
    b, current: await associateBankRepository.usable(Number(a.id)),
    byName: Number(b.requested_by_associate) === 1 ? 'you' : 'NexaCrest (from your signed form)', maskedEmail: bankDetailsCheck.maskEmail(a.email || ''),
    codeMinutes: bankDetailsCheck.CODE_MINUTES, devCode: associatePortalService.showCodeOnScreen() ? devCode : null,
  });
}

async function sendBankCode(req, res) {
  const a = await me(req);
  const confirmation = require('../services/associateBankConfirmation');
  const bankDetailsCheck = require('../services/bankDetailsCheck');
  const b = await confirmation.open(Number(a.id));
  if (!b) {
    flash.set(req, 'error', 'No new bank details are waiting for your confirmation.');
    res.redirect('/associate/profile');
    return;
  }
  if (await confirmation.sendCode(a, b, req)) {
    flash.set(req, 'success', `We e-mailed a 6-digit code to ${bankDetailsCheck.maskEmail(a.email)}. It is valid for ${bankDetailsCheck.CODE_MINUTES} minutes.`);
  } else {
    flash.set(req, 'error', 'There is no e-mail address on your account — contact NexaCrest.');
  }
  res.redirect('/associate/bank/confirm');
}

async function confirmBank(req, res) {
  const a = await me(req);
  const confirmation = require('../services/associateBankConfirmation');
  const b = await confirmation.open(Number(a.id));
  const body = req.body || {};
  const error = !b ? 'No new bank details are waiting for your confirmation.' : await confirmation.confirm(a, Number(b.id), body.last4, body.code, !!body.mine);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(b ? '/associate/bank/confirm' : '/associate/profile');
    return;
  }
  flash.set(req, 'success', `Thank you — confirmed. NexaCrest will now check the new details. ${await confirmation.usableSentence(Number(a.id))}`);
  res.redirect('/associate/profile');
}

async function bankNotMe(req, res) {
  const a = await me(req);
  const confirmation = require('../services/associateBankConfirmation');
  const b = await confirmation.open(Number(a.id));
  if (!b || !(await confirmation.notMe(a, Number(b.id)))) {
    flash.set(req, 'error', 'No new bank details are waiting for your confirmation.');
    res.redirect('/associate/profile');
    return;
  }
  flash.set(req, 'error', confirmation.NOT_ME_MESSAGE);
  res.redirect('/associate/profile');
}

async function downloadDocument(req, res) {
  const a = await me(req);
  const doc = await associateDocumentRepository.find(parseInt(req.params.id, 10));
  if (!doc || Number(doc.associate_id) !== Number(a.id)) {
    notFound(res, 'Document not found.');
    return;
  }
  // D-261: TDS paperwork (Form 16A / 15CA / 15CB) only with "TDS certificates".
  if (associateAccess.TDS_KINDS.includes(String(doc.kind)) && !(await associateAccess.allows(a, 'tds_certificates'))) {
    await require('../middleware/associateAuth').notAvailable(req, res, a);
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
    // D-281: the relationship manager (without one: the associate team, else the MD).
    const to = await require('../services/staffRouting').forAssociate(a);
    for (const uid of to) {
      await notificationRepository.create(uid, null, 'associate_message', null, `Message from associate ${a.name}.`);
    }
    flash.set(req, 'success', 'Message sent.');
  }
  res.redirect('/associate/messages');
}

function prices(req, res) {
  res.redirect('/associate/catalogue');
}

/**
 * Owner decision D-255 (5 Nov 2026): the catalogue — products the associate may
 * price, with no prices, supplier names or quarry locations; "Get price" on each.
 */
async function catalogue(req, res) {
  const a = await me(req);
  const priceRequestService = require('../services/priceRequestService');
  await render(req, res, 'catalogue', {
    products: await priceRequestService.catalogue(a),
    left: await priceRequestService.leftToday(a),
    limit: await priceRequestService.dailyLimit(a),
    requests: (await priceRequestService.forAssociate(Number(a.id), 20)).map(({ figures, ...r }) => ({ ...r, display_status: priceRequestService.displayStatus(r) })), // never the full figures (cost) — D-255
  });
}

async function getPriceForm(req, res) {
  const a = await me(req);
  const priceRequestService = require('../services/priceRequestService');
  const product = await require('../repositories/productRepository').find(parseInt(req.params.id, 10) || 0);
  if (!product || !(await priceRequestService.mayPrice(a, product))) {
    flash.set(req, 'error', 'That product is not in your catalogue.');
    res.redirect('/associate/catalogue');
    return;
  }
  await render(req, res, 'get_price', {
    product,
    specs: await require('../services/productSpecs').offered(product),
    left: await priceRequestService.leftToday(a),
    old: flash.pullOld(req),
  });
}

async function requestPrice(req, res) {
  const a = await me(req);
  const productId = parseInt(req.params.id, 10) || 0;
  if (refuseWhenSuspended(req, res, a, `/associate/catalogue/${productId}`)) return;
  const product = await require('../repositories/productRepository').find(productId);
  if (!product) {
    flash.set(req, 'error', 'That product is not in your catalogue.');
    res.redirect('/associate/catalogue');
    return;
  }
  const body = req.body || {};
  const selection = {};
  const raw = body.spec && typeof body.spec === 'object' ? body.spec : {};
  for (const [specId, optionId] of Object.entries(raw)) {
    if (/^\d+$/.test(String(specId)) && /^\d+$/.test(String(optionId))) selection[Number(specId)] = Number(optionId);
  }
  const [id, error] = await require('../services/priceRequestService').create(a, product, selection, !!body.other, body.note !== undefined ? String(body.note) : null);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, body);
    res.redirect(`/associate/catalogue/${productId}`);
    return;
  }
  res.redirect(`/associate/price-requests/${id}`);
}

async function priceRequest(req, res) {
  const a = await me(req);
  const priceRequestService = require('../services/priceRequestService');
  const r = await priceRequestService.find(parseInt(req.params.id, 10) || 0);
  if (!r || Number(r.associate_id) !== Number(a.id)) {
    flash.set(req, 'error', 'That price request was not found.');
    res.status(404);
    res.redirect('/associate/catalogue');
    return;
  }
  let breakdown = {};
  if (r.breakdown) { try { breakdown = JSON.parse(r.breakdown) || {}; } catch (e) { breakdown = {}; } }
  const { figures, ...shown } = r; // never the full figures (cost) — D-255
  const product = await require('../repositories/productRepository').find(Number(r.product_id));
  // Owner decision D-339: the associate's own two prices (their level only) per CBM / SQM / SQFT
  // at the requested thickness — the same prices, nothing new is priced.
  const unitPrices = require('../services/unitPrices');
  const mm = product ? await unitPrices.selectionThicknessMm(product, String(r.selection ?? '')) : null;
  const otherUnits = r.quote !== null && r.quote !== undefined ? {
    quote: unitPrices.convert(Number(r.quote), String(r.unit ?? ''), mm),
    lowest: unitPrices.convert(Number(r.lowest), String(r.unit ?? ''), mm),
    mm,
  } : null;
  await render(req, res, 'price_request', {
    r: shown,
    otherUnits,
    product,
    status: priceRequestService.displayStatus(r),
    breakdown,
    left: await priceRequestService.leftToday(a),
  });
}

module.exports = {
  proposeCode,
  codeLink, showLogin, login, showCode, verifyCode, logout, showForgotPassword, forgotPassword, showSetPassword, setPassword, home, clients, createInvitation, cancelInvitation, chooseCode,
  orders, order, commission, uploadInvoice, certificate, profile, saveProfile, uploadDocument, requestBank, downloadDocument, downloadAgreement,
  showBankConfirm, sendBankCode, confirmBank, bankNotMe, commissionHeadline,
  messages, sendMessage, prices, catalogue, getPriceForm, requestPrice, priceRequest,
};
