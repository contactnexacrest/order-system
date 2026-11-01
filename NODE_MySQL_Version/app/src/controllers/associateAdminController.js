'use strict';

const fs = require('fs');
const db = require('../config/db');
const csv = require('../helpers/csv');
const dates = require('../helpers/dates');
const flash = require('../helpers/flash');
const recordCheck = require('../helpers/recordCheck');
const associateBankRepository = require('../repositories/associateBankRepository');
const associateDocumentRepository = require('../repositories/associateDocumentRepository');
const associateEventRepository = require('../repositories/associateEventRepository');
const associateInvitationRepository = require('../repositories/associateInvitationRepository');
const associatePortalRepository = require('../repositories/associatePortalRepository');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientRepository = require('../repositories/clientRepository');
const fileStoreRepository = require('../repositories/fileStoreRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const lookupRepository = require('../repositories/lookupRepository');
const orderRepository = require('../repositories/orderRepository');
const userRepository = require('../repositories/userRepository');
const associateCodeService = require('../services/associateCodeService');
const associatePortalService = require('../services/associatePortalService');
const associateService = require('../services/associateService');
const fileUploadService = require('../services/fileUploadService');
const incentiveService = require('../services/incentiveService');
const uiTemplateService = require('../services/uiTemplateService');

/**
 * Port of AssociateAdminController.php. Owner decision 1 Nov 2026 ("AS all
 * as recommended"): the staff side of the associate system — the register
 * (Standard list and Kanban board, kept parallel), each associate's page,
 * their rates, portal access and personal code, client links (changed only
 * by the MD, with a reason), bank details (two different Accounts people
 * verify a change), documents, messages, payouts on hold and claw-backs, the
 * global ladder and the associates report.
 *
 * view_associates (Sales) sees who introduced which client, never amounts,
 * rates, bank details or agreements. manage_associates (MD, ED, Admin)
 * manages everything but the full bank number. manage_associate_bank
 * (Finance, MD, Admin) sees and verifies bank details.
 */

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const RUNGS = [['rate_1_5', '1–5', 1], ['rate_6_10', '6–10', 6], ['rate_11_20', '11–20', 11], ['rate_21_40', '21–40', 21], ['rate_41_plus', '41+', 41]];
const len = (v) => [...String(v ?? '')].length;
const isNum = (v) => v !== undefined && v !== null && String(v).trim() !== '' && Number.isFinite(Number(v));
const fmt2 = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const trimPct = (v) => `${fmt2(v).replace(/0+$/, '').replace(/\.$/, '')}%`;

function can(req, key) {
  return !!(req.user && req.permissions && req.permissions[key]);
}

function back(res, id, anchor = '') {
  res.redirect(`/associates/${id}${anchor ? `#${anchor}` : ''}`);
}

async function found(req, res) {
  const a = await associateRepository.find(parseInt(req.params.id, 10));
  if (!a) {
    res.status(404).send('Associate not found.');
  }
  return a;
}

/** {label, rate, source, rate_text} per rung — the view shows "clients 1–5: 1.25% (personal)". */
async function ratesFor(a) {
  const ladder = await associateRepository.globalLadder();
  const out = [];
  for (const [, label, n] of RUNGS) {
    const r = await associateRepository.rateWithSource(a, n, ladder);
    out.push({ label, rate: r.rate, source: r.source, rate_text: trimPct(r.rate) });
  }
  return out;
}

function termsText(a) {
  if (Number(a.no_commission) === 1) return 'No commission';
  return [a.rate_1_5, a.rate_6_10, a.rate_11_20, a.rate_21_40, a.rate_41_plus].some((r) => r !== null && Number(r) > 0) ? 'Personal rates' : 'Standard rates';
}

// ------------------------------------------------------------ register

/** The register: Standard list or Kanban board by status, with the same filters, links and actions. */
async function index(req, res) {
  let status = String(req.query.status || 'all');
  if (status !== 'all' && !Object.prototype.hasOwnProperty.call(associateRepository.STATUSES, status)) {
    status = 'all';
  }
  const q = String(req.query.q || '').trim();
  const rows = (await associateRepository.all()).filter((a) => (status === 'all' || a.status === status)
    && (q === '' || `${a.name} ${a.email || ''} ${a.personal_code || ''} ${a.country || ''}`.toLowerCase().includes(q.toLowerCase())));
  for (const r of rows) {
    r.clients = await associateRepository.clients(Number(r.id));
    r.pending_bank = Number((await db.queryOne("SELECT COUNT(*) AS n FROM associate_bank_accounts WHERE status = 'pending' AND associate_id = :a", { a: r.id })).n);
    r.terms = termsText(r);
  }
  const data = {
    rows,
    status,
    q,
    counts: await associateRepository.statusCounts(),
    statuses: associateRepository.STATUSES,
    canManage: can(req, 'manage_associates'),
    canBank: can(req, 'manage_associate_bank'),
    pendingBank: await associateBankRepository.pendingCount(),
    kanbanUser: (await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban',
  };
  if (await uiTemplateService.showsBoard(req)) {
    res.renderView('associates/index_kanban', data, 'layout/base');
    return;
  }
  res.renderView('associates/index', data, 'layout/base');
}

async function form(req, res, a) {
  const ladder = await associateRepository.globalLadder();
  res.renderView('associates/form', {
    editing: a,
    currencies: await lookupRepository.currencies(),
    users: await userRepository.listActive(),
    ladder,
    ladderText: Object.fromEntries(Object.entries(ladder).map(([k, v]) => [k, trimPct(v)])),
  }, 'layout/base');
}

async function create(req, res) {
  await form(req, res, null);
}

async function edit(req, res) {
  const a = await found(req, res);
  if (a) await form(req, res, a);
}

async function save(req, res) {
  const user = req.user;
  const id = req.params.id !== undefined ? parseInt(req.params.id, 10) : null;
  if (id !== null && await recordCheck.missing(req, res, 'associates', id, '/associates', 'associate')) return;
  const existing = id !== null ? await associateRepository.find(id) : null;
  const body = req.body || {};
  const data = {};
  for (const f of associateRepository.FIELDS) {
    const v = typeof body[f] === 'string' ? body[f].trim() : (Array.isArray(body[f]) ? '' : String(body[f] ?? '').trim());
    data[f] = v === '' ? null : v;
  }
  data.no_commission = body.no_commission ? 1 : 0;
  data.pan = data.pan !== null ? data.pan.toUpperCase() : null;
  data.gstin = data.gstin !== null ? data.gstin.toUpperCase() : null;
  // The status changes only through "Change status" (with a reason); a new associate starts as Invited.
  data.status = existing ? existing.status : 'invited';
  const errors = [];
  if (data.name === null || len(data.name) > 150) {
    errors.push('Enter the associate\'s name (up to 150 characters).');
  }
  if (!['india', 'abroad'].includes(data.based_in)) {
    errors.push('Choose whether the associate is in India or abroad.');
  }
  if (!/^[A-Z]{3}$/.test(String(data.currency_code ?? ''))) {
    errors.push('Choose the currency the associate is paid in.');
  }
  if (data.email !== null && (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email) || len(data.email) > 190)) {
    errors.push('Enter a valid email address, or leave it empty.');
  } else if (data.email !== null) {
    const other = await associateRepository.findByEmail(data.email);
    if (other && Number(other.id) !== Number(id)) {
      errors.push('Another associate already uses this e-mail — it is their portal login.');
    }
  }
  for (const d of ['agreement_signed_on', 'agreement_ends_on']) {
    if (data[d] !== null && !DATE_RE.test(data[d])) {
      errors.push('Enter the agreement dates as dates.');
      break;
    }
  }
  if (data.agreement_signed_on !== null && data.agreement_ends_on !== null && data.agreement_ends_on <= data.agreement_signed_on) {
    errors.push('The agreement must end after it was signed.');
  }
  // Owner decision 1 Nov 2026 (AS-17): a personal rate per rung; empty or 0 uses the global ladder.
  for (const [r] of RUNGS) {
    if (data[r] !== null && (!isNum(data[r]) || Number(data[r]) < 0 || Number(data[r]) > 20)) {
      errors.push('Enter each personal rate as a % from 0 to 20, or leave it empty for the standard rate.');
      break;
    }
    if (data[r] !== null && Number(data[r]) === 0) {
      data[r] = null;
    }
  }
  if (data.pan !== null && data.based_in === 'india' && !/^[A-Z]{5}\d{4}[A-Z]$/.test(data.pan)) {
    errors.push('Enter the PAN as on the card (e.g. ABCDE1234F).');
  }
  if (data.gstin !== null && !/^\d{2}[A-Z0-9]{13}$/.test(data.gstin)) {
    errors.push('Enter the 15-character GSTIN, or leave it empty.');
  }
  if (data.relationship_manager_id !== null && (!/^\d+$/.test(data.relationship_manager_id) || !(await userRepository.findById(parseInt(data.relationship_manager_id, 10))))) {
    errors.push('Choose the relationship manager from the list.');
  }
  for (const [f, max] of [['country', 80], ['phone', 40], ['tax_id', 50], ['notes', 500], ['address', 1000]]) {
    if (data[f] !== null && len(data[f]) > max) {
      errors.push(`The ${f} is too long (up to ${max} characters).`);
    }
  }
  // Owner decision 2026-10-30: the signed agreement is kept with its date.
  const hasUpload = Boolean(req.file && req.file.originalname);
  if (data.agreement_signed_on !== null && !hasUpload && !(existing && existing.agreement_file_id)) {
    errors.push('Attach the signed agreement (PDF) with its signing date.');
  }
  if ((hasUpload || (existing && existing.agreement_file_id)) && data.agreement_signed_on === null) {
    errors.push('Enter the date the agreement was signed with its signed copy.');
  }
  const backTo = id ? `/associates/${id}/edit` : '/associates/new';
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(backTo);
    return;
  }
  let fileId = null;
  if (hasUpload) {
    try {
      fileId = await fileUploadService.handleUpload(req, 'agreement_file', 'associate_agreement', 'associates/agreements', null, null, user.id, null, data.name, 'Signed associate agreement');
    } catch (e) {
      flash.setErrorFromException(req, e);
      res.redirect(backTo);
      return;
    }
  }
  let savedId = id;
  if (id === null) {
    savedId = await associateRepository.create(data, user.id);
    await auditLogRepository.log(user.id, 'ASSOCIATE_ADDED', 'associates', savedId, null, null, data.name);
    await associateEventRepository.add(savedId, 'registered', `Associate registered by ${user.name}.`, null, null, null, user.id, false);
    flash.set(req, 'success', `Associate "${data.name}" added. Next: upload their KYC, have Accounts verify their bank, then send portal access and set them Active.`);
  } else {
    await associateRepository.update(id, data);
    await auditLogRepository.log(user.id, 'ASSOCIATE_UPDATED', 'associates', id, null, null, JSON.stringify(data));
    const changed = [...RUNGS.map(([r]) => r), 'no_commission'].filter((r) => {
      const before = existing[r] ?? '';
      const after = data[r] ?? '';
      if (String(before) === String(after)) return false;
      return !(r !== 'no_commission' && Number(before || 0) === Number(after || 0));
    });
    if (changed.length) {
      await associateEventRepository.add(id, 'rates_changed', `Commission terms changed by ${user.name} — applies to orders created from now on; existing orders keep the rate fixed on them.`, null, null, null, user.id, true);
    }
    flash.set(req, 'success', `Associate "${data.name}" saved.`);
  }
  if (fileId !== null) {
    await associateRepository.setAgreementFile(savedId, fileId);
    await auditLogRepository.log(user.id, 'ASSOCIATE_AGREEMENT_UPLOADED', 'associates', savedId, 'agreement_file_id', existing && existing.agreement_file_id ? String(existing.agreement_file_id) : null, String(fileId));
  }
  res.redirect(`/associates/${savedId}`);
}

/** One associate: profile, rates, portal, clients, invitations, bank, documents, commission, history, messages. */
async function show(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const id = Number(a.id);
  const canManage = can(req, 'manage_associates');
  const canBank = can(req, 'manage_associate_bank');
  const data = {
    a,
    canManage,
    canBank,
    canChangeLink: await associateService.canChangeLink(req),
    clients: await associatePortalRepository.clients(id),
    me: req.user,
    statuses: associateRepository.STATUSES,
  };
  if (canManage || canBank) {
    const payouts = canManage ? await associatePortalRepository.payouts(id) : [];
    const invoiceByPayout = {};
    for (const p of payouts) {
      p.month_label = incentiveService.monthLabel(String(p.incentive_month));
      const inv = await associateDocumentRepository.invoiceForPayout(Number(p.id));
      if (inv) invoiceByPayout[p.id] = { id: Number(inv.id), reference: inv.reference };
    }
    const banks = await associateBankRepository.forAssociate(id);
    for (const b of banks) b.masked = associateBankRepository.masked(b);
    const usableBank = await associateBankRepository.usable(id);
    const invitations = await associateInvitationRepository.forAssociate(id);
    for (const inv of invitations) inv.usable = associateInvitationRepository.isUsable(inv);
    Object.assign(data, {
      orders: canManage ? await associatePortalRepository.orders(id) : [],
      payouts,
      invoiceByPayout,
      invitations,
      invitationStatuses: associateInvitationRepository.STATUSES,
      banks,
      usableBank,
      usableBankMasked: usableBank ? associateBankRepository.masked(usableBank) : null,
      documents: await associateDocumentRepository.forAssociate(id),
      docKinds: associateDocumentRepository.KINDS,
      events: await associateEventRepository.forAssociate(id, null, 200),
      messages: await associateEventRepository.messages(id),
      unlinkedClients: await associateRepository.unlinkedClients(),
      allAssociates: (await associateRepository.all()).filter((o) => Number(o.id) !== id),
      ladder: await associateRepository.globalLadder(),
      rates: await ratesFor(a),
      rm: a.relationship_manager_id ? await userRepository.findById(Number(a.relationship_manager_id)) : null,
      codeLink: a.personal_code ? `${associateService.appUrl()}/quotation-details?code=${encodeURIComponent(a.personal_code)}` : null,
      tdsPct: await associateService.tdsPct(),
      tdsPctText: trimPct(await associateService.tdsPct()),
    });
  }
  res.renderView('associates/show', data, 'layout/base');
}

// ------------------------------------------------------------ status, portal, code

async function changeStatus(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const user = req.user;
  const status = String((req.body || {}).status || '');
  const reason = String((req.body || {}).reason || '').trim();
  const STATUSES = associateRepository.STATUSES;
  if (!Object.prototype.hasOwnProperty.call(STATUSES, status) || status === 'inactive') {
    flash.set(req, 'error', 'Choose the new status.');
  } else if (status === a.status) {
    flash.set(req, 'error', 'The associate already has this status.');
  } else if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Give the reason for the change (10 to 300 characters).');
  } else if (status === 'active' && !(await associateBankRepository.usable(Number(a.id), associateService.addDays(associateService.todayIst(), await associateService.bankWaitDays())))) {
    flash.set(req, 'error', 'An associate becomes Active once Accounts have verified their bank details (two people).');
  } else {
    await associateRepository.setFields(Number(a.id), { status, status_reason: reason });
    await auditLogRepository.log(user.id, 'ASSOCIATE_STATUS_CHANGED', 'associates', Number(a.id), 'status', String(a.status), status);
    // AS-3: suspension holds every due payout; reactivation leaves the held ones for the MD to release one by one.
    if (status === 'suspended') {
      await db.execute("UPDATE incentive_payouts SET status = 'held', hold_reason = 'The associate is suspended.' WHERE associate_id = :a AND payee_kind = 'associate' AND status = 'due' AND amount_inr > 0", { a: a.id });
    }
    await associateService.notify(a, 'status_changed', `Your associate status is now: ${STATUSES[status]}. Reason: ${reason}`, null, null, null, user.id);
    flash.set(req, 'success', `Status changed to ${STATUSES[status]}.`);
  }
  back(res, a.id);
}

async function invitePortal(req, res) {
  const a = await found(req, res);
  if (!a) return;
  try {
    const link = await associateService.invitePortal(Number(a.id), req.user.id);
    flash.set(req, 'success', `Portal access e-mailed to ${a.email}.${associatePortalService.showCodeOnScreen() ? ` Development only — link: ${link}` : ''}`);
  } catch (e) {
    flash.set(req, 'error', e.message);
  }
  back(res, a.id, 'portal');
}

async function disablePortal(req, res) {
  const a = await found(req, res);
  if (!a) return;
  await associateRepository.setFields(Number(a.id), { portal_enabled: 0 });
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_PORTAL_DISABLED', 'associates', Number(a.id));
  await associateEventRepository.add(Number(a.id), 'portal_disabled', `Portal access switched off by ${req.user.name}.`, null, null, null, req.user.id, false);
  flash.set(req, 'success', 'Portal access switched off. Their personal code and client links stay as they are.');
  back(res, a.id, 'portal');
}

/** A new personal code (e.g. after a leak). The old code no longer brings new clients; existing links stay. */
async function setCode(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const part = associateCodeService.readablePart(String((req.body || {}).readable || ''));
  if (part.length < 3) {
    flash.set(req, 'error', 'Use 3 to 12 letters or digits for the readable part of the code.');
  } else {
    const code = await associateCodeService.issue(Number(a.id), part);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_CODE_SET', 'associates', Number(a.id), 'personal_code', a.personal_code || null, code);
    await associateService.notify(a, 'code_changed', `Your personal code is now ${code}${a.personal_code ? `; ${a.personal_code} no longer brings new clients (clients already registered stay yours)` : ''}.`, null, null, null, req.user.id);
    flash.set(req, 'success', `Personal code set to ${code}.`);
  }
  back(res, a.id, 'portal');
}

/** Opens the associate's portal exactly as they see it, read-only (every form post is refused). */
async function viewAs(req, res) {
  const a = await found(req, res);
  if (!a) return;
  await associatePortalService.startViewAs(req, req.user.id, Number(a.id));
  res.redirect('/associate');
}

function sendFile(res, filePath, name, mime) {
  if (!filePath || !fs.existsSync(filePath)) {
    res.status(404).send('The file is missing from storage.');
    return;
  }
  // eslint-disable-next-line no-control-regex
  const safe = String(name).replace(/[\x00-\x1F\x7F"/\\]/g, '-') || 'file';
  res.setHeader('Content-Type', mime);
  res.setHeader('Content-Disposition', `attachment; filename="${safe}"`);
  res.setHeader('Content-Length', String(fs.statSync(filePath).size));
  res.setHeader('Cache-Control', 'private, no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.send(fs.readFileSync(filePath));
}

async function downloadAgreement(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const file = a.agreement_file_id ? await fileStoreRepository.find(Number(a.agreement_file_id)) : null;
  if (!file || !fs.existsSync(file.server_path)) {
    res.status(404).send('No signed agreement is on file for this associate.');
    return;
  }
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_AGREEMENT_DOWNLOADED', 'associates', Number(a.id), null, null, file.original_filename);
  sendFile(res, file.server_path, file.original_filename, 'application/pdf');
}

// ------------------------------------------------------------ client links (guarantee 3)

/** A staff link: a client already on file that the associate introduced (manage_associates); the lock starts now. */
async function linkClient(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const body = req.body || {};
  const clientId = parseInt(body.client_id || 0, 10) || 0;
  const registeredOn = String(body.registered_on || '');
  const reason = String(body.reason || '').trim();
  const client = clientId > 0 ? await clientRepository.find(clientId) : null;
  if (!client || !DATE_RE.test(registeredOn) || registeredOn > associateService.todayIst()) {
    flash.set(req, 'error', 'Choose the client and the date the associate introduced them (today or earlier).');
  } else if (client.associate_id) {
    flash.set(req, 'error', 'This client is already linked to an associate — only the MD can change that link.');
  } else if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Say how the associate introduced this client (10 to 300 characters).');
  } else {
    await associateService.linkClient(clientId, Number(a.id), 'staff', null, registeredOn, req.user.id);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_CLIENT_LINKED', 'clients', clientId, 'associate_id', null, String(a.id));
    const until = associateService.addMonths(associateService.todayIst(), await associateService.protectionMonths());
    await associateService.notify(a, 'client_accepted', `${client.company_legal_name} is registered as your client (linked by NexaCrest: ${reason}). Your protection runs until ${dates.short(until)}.`, clientId, null, null, req.user.id);
    flash.set(req, 'success', 'Client linked to the associate.');
  }
  back(res, a.id, 'clients');
}

/** Guarantee 3: only the MD removes or moves a client's associate, with a written reason; the associate is told at once. */
async function unlinkClient(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const clientId = parseInt(req.params.clientId, 10);
  const reason = String((req.body || {}).reason || '').trim();
  const moveTo = parseInt((req.body || {}).move_to || 0, 10) || 0;
  if (await recordCheck.missing(req, res, 'clients', clientId, `/associates/${a.id}`, 'client link', { associate_id: Number(a.id) })) return;
  if (!(await associateService.canChangeLink(req))) {
    flash.set(req, 'error', 'Only the Managing Director can change or remove a client\'s associate.');
    res.status(403);
    back(res, a.id, 'clients');
    return;
  }
  const target = moveTo > 0 ? await associateRepository.find(moveTo) : null;
  if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Write the reason (10 to 300 characters) — the associate is shown it.');
  } else if (moveTo > 0 && (!target || moveTo === Number(a.id))) {
    flash.set(req, 'error', 'Choose the other associate.');
  } else {
    const client = await clientRepository.find(clientId);
    if (target) {
      await associateService.linkClient(clientId, Number(target.id), 'md_change', null, associateService.todayIst(), req.user.id);
      await associateService.notify(target, 'link_changed', `${client.company_legal_name} is now registered as your client (decided by the Managing Director: ${reason}).`, clientId, null, null, req.user.id);
    } else {
      await db.execute('UPDATE clients SET associate_id = NULL, associate_registered_on = NULL, associate_locked_until = NULL, associate_source = NULL, associate_invitation_id = NULL WHERE id = :id', { id: clientId });
    }
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_CLIENT_UNLINKED', 'clients', clientId, 'associate_id', String(a.id), target ? String(target.id) : null);
    await associateService.notify(a, 'link_changed', `${client.company_legal_name} is no longer registered as your client. Decided by the Managing Director. Reason: ${reason}`, clientId, null, null, req.user.id);
    flash.set(req, 'success', target ? `Client moved to ${target.name}; both associates were told.` : 'Client unlinked; the associate was told.');
  }
  back(res, a.id, 'clients');
}

// ------------------------------------------------------------ bank (AS-7)

/** Checks a bank form (associate's request or staff entry). Returns the error string, or the cleaned fields. */
function validateBank(associate, post) {
  const d = {};
  for (const f of ['holder_name', 'account_number', 'account_number_confirm', 'ifsc', 'swift', 'bank_name', 'bank_branch_address', 'intermediary_bank', 'account_currency']) {
    d[f] = typeof post[f] === 'string' ? post[f].trim() : '';
  }
  d.account_number = d.account_number.replace(/\s+/g, '').toUpperCase();
  d.account_number_confirm = d.account_number_confirm.replace(/\s+/g, '').toUpperCase();
  d.ifsc = d.ifsc.toUpperCase();
  d.swift = d.swift.toUpperCase();
  d.account_currency = (d.account_currency || String(associate.currency_code || 'INR')).toUpperCase();
  const india = (associate.based_in || 'india') === 'india';
  const SWIFT = /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/;
  if (len(d.holder_name) < 2 || len(d.holder_name) > 150) return `Enter the account holder's name as the bank has it${india ? ' (as on the PAN)' : ''}.`;
  if (!/^[A-Z0-9]{6,34}$/.test(d.account_number)) return india ? 'Enter the account number (6 to 34 letters or digits).' : 'Enter the IBAN or account number (6 to 34 letters or digits).';
  if (d.account_number !== d.account_number_confirm) return 'The two account numbers do not match — type it again.';
  if (india && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(d.ifsc)) return 'Enter the 11-character IFSC (e.g. HDFC0001234).';
  if (!india && !SWIFT.test(d.swift)) return 'Enter the bank\'s SWIFT / BIC (8 or 11 characters).';
  if (d.swift !== '' && !SWIFT.test(d.swift)) return 'Enter the SWIFT / BIC as 8 or 11 characters, or leave it empty.';
  if (len(d.bank_name) < 2 || len(d.bank_name) > 150) return 'Enter the bank\'s name.';
  if (!india && len(d.bank_branch_address) < 5) return 'Enter the bank\'s address (needed for a foreign transfer).';
  if (len(d.bank_branch_address) > 300 || len(d.intermediary_bank) > 300) return 'The bank address or intermediary bank is too long.';
  if (!/^[A-Z]{3}$/.test(d.account_currency)) return 'Choose the account currency.';
  delete d.account_number_confirm;
  return d;
}

/** Accounts enter the bank details themselves (from the associate's signed form) — still checked by two people. */
async function addBank(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const result = validateBank(a, req.body || {});
  if (typeof result === 'string') {
    flash.set(req, 'error', result);
    back(res, a.id, 'bank');
    return;
  }
  try {
    result.proof_file_id = await fileUploadService.handleUpload(req, 'proof_file', 'associate_document', 'associates/bank', null, null, req.user.id, null, a.name, 'Bank proof');
  } catch (e) {
    flash.set(req, 'error', 'Attach a cancelled cheque or a bank letter (PDF or image).');
    back(res, a.id, 'bank');
    return;
  }
  const id = await associateBankRepository.request(Number(a.id), result, false, req.user.id);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_BANK_CHANGE_REQUESTED', 'associate_bank_accounts', id, null, null, 'by staff');
  await associateService.notify(a, 'bank_changed', `NexaCrest recorded new bank details for you (${result.bank_name} ••••${result.account_number.slice(-4)}) on ${associateService.jMY()}. They are used once checked. If you did not send these details, contact NexaCrest at once.`, null, null, null, req.user.id);
  flash.set(req, 'success', 'Bank details recorded — two people must now check them.');
  back(res, a.id, 'bank');
}

async function firstCheck(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  const method = String((req.body || {}).check_method || '').trim();
  if (!bank || Number(bank.associate_id) !== Number(a.id)) {
    flash.set(req, 'error', 'Bank request not found.');
  } else if (len(method) < 10 || len(method) > 300) {
    flash.set(req, 'error', 'Say how you checked it (e.g. "Called the associate on their known number; test credit of ₹1 confirmed") — 10 to 300 characters.');
  } else if (Number(bank.requested_by_user || 0) === Number(req.user.id)) {
    flash.set(req, 'error', 'You entered these bank details, so two other people must check them.');
  } else if (!(await associateBankRepository.firstCheck(Number(bank.id), req.user.id, method))) {
    flash.set(req, 'error', 'This request was already checked or is no longer pending.');
  } else {
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_BANK_FIRST_CHECK', 'associate_bank_accounts', Number(bank.id), null, null, method);
    flash.set(req, 'success', 'First check recorded. A different person now verifies it.');
  }
  back(res, a.id, 'bank');
}

async function verifyBank(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  const uid = Number(req.user.id);
  if (!bank || Number(bank.associate_id) !== Number(a.id)) {
    flash.set(req, 'error', 'Bank request not found.');
  } else if (!bank.first_checked_by) {
    flash.set(req, 'error', 'Record the first check before verifying.');
  } else if (Number(bank.first_checked_by) === uid || Number(bank.requested_by_user || 0) === uid) {
    flash.set(req, 'error', 'A different person must verify — you already acted on this request.');
  } else if (!(await associateBankRepository.verify(Number(bank.id), uid, await associateService.bankWaitDays()))) {
    flash.set(req, 'error', 'This request is no longer pending.');
  } else {
    const b = await associateBankRepository.find(Number(bank.id));
    await auditLogRepository.log(uid, 'ASSOCIATE_BANK_VERIFIED', 'associate_bank_accounts', Number(bank.id), 'status', 'pending', 'verified');
    await associateService.notify(a, 'bank_changed', `Your bank account ${associateBankRepository.masked(b)} was verified by NexaCrest. Payments go to it from `
      + `${dates.short(String(b.usable_from))}. If you did not ask for this change, contact NexaCrest at once.`, null, null, null, uid);
    flash.set(req, 'success', `Verified. Usable from ${dates.short(String(b.usable_from))}; the associate was e-mailed.`);
  }
  back(res, a.id, 'bank');
}

async function rejectBank(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  const reason = String((req.body || {}).reason || '').trim();
  if (!bank || Number(bank.associate_id) !== Number(a.id)) {
    flash.set(req, 'error', 'Bank request not found.');
  } else if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Give the reason (10 to 300 characters) — the associate is shown it.');
  } else if (!(await associateBankRepository.reject(Number(bank.id), reason))) {
    flash.set(req, 'error', 'This request is no longer pending.');
  } else {
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_BANK_REJECTED', 'associate_bank_accounts', Number(bank.id), 'status', 'pending', 'rejected');
    await associateService.notify(a, 'bank_rejected', `Your new bank details (${associateBankRepository.masked(bank)}) were not accepted: ${reason}. Payments keep going to your verified account.`, null, null, null, req.user.id);
    flash.set(req, 'success', 'Bank request rejected; the associate was told.');
  }
  back(res, a.id, 'bank');
}

/** The full account number — Finance / MD only (manage_associate_bank), every view audit-logged. */
async function bankNumber(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  if (!bank || Number(bank.associate_id) !== Number(a.id)) {
    res.status(404).send('Bank account not found.');
    return;
  }
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_BANK_NUMBER_VIEWED', 'associate_bank_accounts', Number(bank.id));
  res.renderView('associates/bank_number', { a, b: bank, number: await associateBankRepository.fullNumber(Number(bank.id)) }, 'layout/base');
}

// ------------------------------------------------------------ documents, messages

async function uploadDocument(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const kind = String((req.body || {}).kind || '');
  const reference = String((req.body || {}).reference || '').trim();
  const KINDS = associateDocumentRepository.KINDS;
  if (!Object.prototype.hasOwnProperty.call(KINDS, kind)) {
    flash.set(req, 'error', 'Choose what the document is.');
    back(res, a.id, 'documents');
    return;
  }
  if (len(reference) > 100) {
    flash.set(req, 'error', 'The reference is too long (up to 100 characters).');
    back(res, a.id, 'documents');
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'document_file', 'associate_document', 'associates/documents', null, null, req.user.id, null, a.name, KINDS[kind]);
  } catch (e) {
    flash.setErrorFromException(req, e);
    back(res, a.id, 'documents');
    return;
  }
  await associateDocumentRepository.add(Number(a.id), kind, fileId, null, reference || null, false, req.user.id);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', Number(a.id), 'kind', null, kind);
  if (['form_16a', 'form_15ca', 'form_15cb'].includes(kind)) {
    await associateService.notify(a, 'document_added', `${KINDS[kind]}${reference !== '' ? ` (${reference})` : ''} is in your portal.`, null, null, null, req.user.id);
  }
  flash.set(req, 'success', `${KINDS[kind]} saved.`);
  back(res, a.id, 'documents');
}

async function downloadDocument(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const doc = await associateDocumentRepository.find(parseInt(req.params.docId, 10));
  if (!doc || Number(doc.associate_id) !== Number(a.id)) {
    res.status(404).send('Document not found.');
    return;
  }
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_DOCUMENT_DOWNLOADED', 'associate_documents', Number(doc.id));
  sendFile(res, doc.server_path, doc.original_filename, doc.mime_type || 'application/octet-stream');
}

async function reply(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const body = String((req.body || {}).body || '').trim();
  if (body === '' || len(body) > 4000) {
    flash.set(req, 'error', 'Write the message (up to 4,000 characters).');
  } else {
    await associateEventRepository.addMessage(Number(a.id), false, req.user.id, body);
    await associateService.notify(a, 'message', `New message from ${req.user.name} (NexaCrest): ${[...body].slice(0, 300).join('')}`, null, null, null, req.user.id);
    flash.set(req, 'success', 'Message sent.');
  }
  back(res, a.id, 'messages');
}

// ------------------------------------------------------------ payouts: hold, release, claw-back (AS-3, AS-9)

async function associatePayout(req, res) {
  const p = await incentiveRepository.findPayout(parseInt(req.params.payoutId, 10));
  if (!p || p.payee_kind !== 'associate') {
    res.status(404).send('Payout not found.');
    return null;
  }
  return p;
}

async function holdPayout(req, res) {
  const p = await associatePayout(req, res);
  if (!p) return;
  const reason = String((req.body || {}).reason || '').trim();
  if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Give the reason for the hold (10 to 300 characters) — the associate is shown it.');
  } else if (!(await incentiveRepository.holdPayout(Number(p.id), reason))) {
    flash.set(req, 'error', 'Only a payout waiting to be paid can be put on hold.');
  } else {
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_PAYOUT_HELD', 'incentive_payouts', Number(p.id), 'status', 'due', 'held');
    const a = await associateRepository.find(Number(p.associate_id));
    await associateService.notify(a, 'payout_held', `A commission payment was put on hold: ${reason}`, null, Number(p.order_id), null, req.user.id);
    flash.set(req, 'success', 'Payout on hold.');
  }
  back(res, p.associate_id, 'commission');
}

async function releasePayout(req, res) {
  const p = await associatePayout(req, res);
  if (!p) return;
  if (!(await incentiveRepository.releasePayout(Number(p.id)))) {
    flash.set(req, 'error', 'This payout is not on hold.');
  } else {
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_PAYOUT_RELEASED', 'incentive_payouts', Number(p.id), 'status', 'held', 'due');
    const a = await associateRepository.find(Number(p.associate_id));
    await associateService.notify(a, 'payout_released', 'A commission payment on hold was released and will be paid.', null, Number(p.order_id), null, req.user.id);
    flash.set(req, 'success', 'Payout released — it is due again.');
  }
  back(res, p.associate_id, 'commission');
}

/** After a refund to the buyer: the paid commission's share is deducted from the associate's next payout. */
async function clawback(req, res) {
  const p = await associatePayout(req, res);
  if (!p) return;
  const body = req.body || {};
  const amount = isNum(body.amount_inr) ? Number(body.amount_inr) : null;
  const reason = String(body.reason || '').trim();
  const already = Number((await db.queryOne('SELECT COALESCE(-SUM(amount_inr), 0) AS s FROM incentive_payouts WHERE clawback_of_payout_id = :id', { id: p.id })).s);
  if (p.status !== 'paid' || Number(p.amount_inr) <= 0) {
    flash.set(req, 'error', 'Only a paid commission can be clawed back.');
  } else if (amount === null || amount <= 0 || amount > Number(p.paid_inr) - already + 0.005) {
    flash.set(req, 'error', `Enter the amount to claw back, above 0 and at most what is left of the paid commission (₹${fmt2(Number(p.paid_inr) - already)}).`);
  } else if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', 'Say what was refunded to the buyer (10 to 300 characters) — the associate is shown it.');
  } else {
    const month = incentiveService.incentiveMonth(associateService.todayIst(), (await incentiveService.settings()).cutoff);
    const fc = Number(p.amount_inr) > 0 && p.amount_fc !== null ? Math.round(Number(p.amount_fc) * amount / Number(p.amount_inr) * 100) / 100 : null;
    const order = await orderRepository.find(Number(p.order_id));
    const id = await incentiveRepository.addClawback(p, amount, fc, month, `Claw-back of commission paid on order ${order ? order.order_reference : p.order_id} (payout no. ${p.id}, ref ${p.paid_reference || '—'}): ${reason}`);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_CLAWBACK', 'incentive_payouts', id, null, null, String(amount));
    const a = await associateRepository.find(Number(p.associate_id));
    await associateService.notify(a, 'clawback', `INR ${fmt2(amount)} of the commission paid on order ${order ? order.order_reference : ''} will be deducted from your ${incentiveService.monthLabel(month)} payout: ${reason}`, null, Number(p.order_id), null, req.user.id);
    flash.set(req, 'success', 'Claw-back recorded; it is deducted from the associate\'s next payout.');
  }
  back(res, p.associate_id, 'commission');
}

// ------------------------------------------------------------ global ladder (AS-17) and settings

async function ladder(req, res) {
  const l = await associateRepository.globalLadder();
  res.renderView('associates/ladder', {
    ladder: l,
    ladderValues: Object.fromEntries(Object.entries(l).map(([k, v]) => [k, v.toFixed(2)])),
    tds: await associateService.tdsPct(),
    tdsValue: (await associateService.tdsPct()).toFixed(2),
    protection: await associateService.protectionMonths(),
    wait: await associateService.bankWaitDays(),
    settings: await incentiveRepository.settings(),
  }, 'layout/base');
}

async function saveLadder(req, res) {
  const body = req.body || {};
  const values = {};
  for (const col of Object.keys(await associateRepository.globalLadder())) {
    const v = isNum(body[col]) ? Number(body[col]) : null;
    if (v === null || v < 0 || v > 20) {
      flash.set(req, 'error', 'Enter every standard rate as a % from 0 to 20.');
      res.redirect('/associates/ladder');
      return;
    }
    values[`associate_${col}`] = v.toFixed(2);
  }
  const tds = isNum(body.associate_tds_pct) ? Number(body.associate_tds_pct) : null;
  const months = /^-?\d+$/.test(String(body.associate_protection_months ?? '').trim()) ? parseInt(body.associate_protection_months, 10) : null;
  const wait = /^-?\d+$/.test(String(body.associate_bank_wait_days ?? '').trim()) ? parseInt(body.associate_bank_wait_days, 10) : null;
  if (tds === null || tds < 0 || tds > 30 || months === null || months < 1 || months > 60 || wait === null || wait < 0 || wait > 60) {
    flash.set(req, 'error', 'Enter TDS as 0–30 %, the protection as 1–60 months and the bank waiting days as 0–60.');
    res.redirect('/associates/ladder');
    return;
  }
  values.associate_tds_pct = tds.toFixed(2);
  values.associate_protection_months = String(months);
  values.associate_bank_wait_days = String(wait);
  const before = await incentiveRepository.settings();
  for (const [k, v] of Object.entries(values)) {
    if (String(before[k] ?? '') !== v) {
      await incentiveRepository.setSetting(k, v, req.user.id);
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_SETTING_CHANGED', 'incentive_settings', null, k, before[k] ?? null, v);
    }
  }
  flash.set(req, 'success', 'Saved. New rates apply to orders created from now on; existing orders keep the rate fixed on them.');
  res.redirect('/associates/ladder');
}

// ------------------------------------------------------------ report (phase D)

/** Performance per associate, ageing of commission waiting, and the TDS register; CSV of each. */
async function report(req, res) {
  let year = String(req.query.fy || '');
  if (!/^\d{4}$/.test(year)) {
    const today = associateService.todayIst();
    const y = parseInt(today.slice(0, 4), 10);
    year = String(parseInt(today.slice(5, 7), 10) >= 4 ? y : y - 1);
  }
  const from = `${year}-04-01`;
  const to = `${parseInt(year, 10) + 1}-03-31`;
  const perf = [];
  for (const a of await associateRepository.all()) {
    const orders = await associatePortalRepository.orders(Number(a.id));
    const inYear = orders.filter((o) => String(o.created_at).slice(0, 10) >= from && String(o.created_at).slice(0, 10) <= to);
    const m = await db.queryOne(
      `SELECT COALESCE(SUM(CASE WHEN status='paid' THEN paid_inr END),0) AS paid, COALESCE(SUM(CASE WHEN status='paid' THEN tds_inr END),0) AS tds,
              COALESCE(SUM(CASE WHEN status IN ('due','held') THEN amount_inr END),0) AS waiting
         FROM incentive_payouts WHERE payee_kind='associate' AND associate_id=:a AND (status <> 'paid' OR paid_on BETWEEN :f AND :t)`,
      { a: a.id, f: from, t: to }
    );
    perf.push({
      id: Number(a.id), name: a.name, status: a.status, clients: Number(a.client_count), orders: inYear.length,
      fob: Math.round(inYear.reduce((s, o) => s + Number(o.fob_value), 0) * 100) / 100,
      paid: Number(m.paid), tds: Number(m.tds), waiting: Number(m.waiting),
    });
  }
  const ageing = await db.query(
    `SELECT p.id, p.incentive_month, p.amount_inr, p.status, p.hold_reason, a.name, a.id AS associate_id, o.order_reference,
            DATEDIFF(CURDATE(), STR_TO_DATE(CONCAT(p.incentive_month, '-01'), '%Y-%m-%d')) AS age_days
       FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id JOIN orders o ON o.id = p.order_id
      WHERE p.payee_kind = 'associate' AND p.status IN ('due','held') ORDER BY p.incentive_month, a.name`
  );
  const tdsRows = await db.query(
    `SELECT p.id, p.paid_on, p.paid_inr, p.tds_inr, p.paid_reference, a.name, a.pan, a.based_in, a.country, o.order_reference
       FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id JOIN orders o ON o.id = p.order_id
      WHERE p.payee_kind = 'associate' AND p.status = 'paid' AND p.amount_inr > 0 AND p.paid_on BETWEEN :f AND :t ORDER BY p.paid_on, a.name`,
    { f: from, t: to }
  );
  const format = String(req.query.format || '');
  if (format === 'performance') {
    csv.stream(res, `associate-performance-FY${year}.csv`, ['Associate', 'Status', 'Clients', 'Orders in year', 'FOB value of orders', 'Commission paid INR', 'TDS INR', 'Waiting INR'],
      perf.map((r) => [r.name, r.status, r.clients, r.orders, r.fob, r.paid, r.tds, r.waiting]));
    return;
  }
  if (format === 'ageing') {
    csv.stream(res, 'associate-commission-ageing.csv', ['Associate', 'Order', 'Incentive month', 'Amount INR', 'Status', 'Days since month start', 'Hold reason'],
      ageing.map((r) => [r.name, r.order_reference, r.incentive_month, r.amount_inr, r.status, r.age_days, r.hold_reason]));
    return;
  }
  if (format === 'tds') {
    csv.stream(res, `associate-tds-register-FY${year}.csv`, ['Paid on', 'Associate', 'PAN', 'Based in', 'Order', 'Paid INR', 'TDS INR', 'Bank reference'],
      tdsRows.map((r) => [r.paid_on, r.name, r.pan, r.based_in === 'india' ? 'India' : `Abroad ${r.country || ''}`, r.order_reference, r.paid_inr, r.tds_inr, r.paid_reference]));
    return;
  }
  res.renderView('associates/report', { year, yearEndShort: String(parseInt(year, 10) + 1).slice(2), perf, ageing, tds: tdsRows }, 'layout/base');
}

module.exports = {
  can, validateBank, sendFile, index, create, edit, save, show, changeStatus, invitePortal, disablePortal, setCode, viewAs, downloadAgreement,
  linkClient, unlinkClient, addBank, firstCheck, verifyBank, rejectBank, bankNumber, uploadDocument, downloadDocument, reply,
  holdPayout, releasePayout, clawback, ladder, saveLadder, report,
};
