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
const associateKyc = require('../services/associateKyc');
const associatePortalService = require('../services/associatePortalService');
const associateService = require('../services/associateService');
const fileUploadService = require('../services/fileUploadService');
const incentiveService = require('../services/incentiveService');
const uiTemplateService = require('../services/uiTemplateService');
const pricingRepository = require('../repositories/pricingRepository');
const pricingService = require('../services/pricingService');

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

/** D-245 P3: "Fixed: Level 2" or "Ladder" (or no commission) — the register's terms column. */
function termsText(a) {
  if (Number(a.no_commission) === 1) return 'No commission';
  if ((a.commission_mode || 'ladder') === 'fixed') return `Fixed %: ${a.commission_level_name || '?'}`;
  return (a.commission_mode || 'ladder') === 'own_ladder' ? 'Own ladder' : 'Standard ladder'; // D-292
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
    // D-293: new details still waiting for the associate's confirmation.
    r.awaiting_bank = Number((await db.queryOne("SELECT COUNT(*) AS n FROM associate_bank_accounts WHERE status = 'awaiting_confirmation' AND associate_id = :a", { a: r.id })).n);
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
    canRules: can(req, 'view_associate_commission_rules'), // D-292
    pendingBank: await associateBankRepository.pendingCount(),
    pendingRegistrations: await require('../repositories/associateRegistrationRepository').pendingCount(),
    pendingPriceRequests: await require('../services/priceRequestService').pendingCount(),
    kanbanUser: (await uiTemplateService.resolve(req.user.ui_template_preference_id || null)) === 'kanban',
  };
  if (await uiTemplateService.showsBoard(req)) {
    res.renderView('associates/index_kanban', data, 'layout/base');
    return;
  }
  res.renderView('associates/index', data, 'layout/base');
}

async function form(req, res, a) {
  res.renderView('associates/form', {
    editing: a,
    currencies: await lookupRepository.currencies(),
    users: await userRepository.listActive(),
    // Owner decision D-245 (4 Nov 2026): commission level (fixed) or the ladder — MD only.
    commissionLevels: (await pricingRepository.levels()).map((l) => ({ ...l, label: pricingService.levelLabel(l) })),
    canPricing: can(req, 'manage_pricing'),
  }, 'layout/base');
}

/** Owner decision D-245: the associate's commission mode and level, read only for people with manage_pricing. */
async function readCommissionLevel(req, existing) {
  // D-291 (11 Nov 2026): an existing associate's rule is changed on their page (Commission rule), with a reason.
  if (!can(req, 'manage_pricing') || existing) return [null, null];
  const body = req.body || {};
  const mode = String(body.commission_mode ?? '');
  if (!['fixed', 'ladder'].includes(mode)) return [null, 'Choose whether this associate has a fixed commission level or follows the ladder.'];
  let level = null;
  if (mode === 'fixed') {
    const l = await pricingRepository.findLevel(parseInt(body.commission_level_id, 10) || 0);
    const keeping = l && existing && Number(existing.commission_level_id || 0) === Number(l.id);
    if (!l || (Number(l.is_active) !== 1 && !keeping)) return [null, 'Choose an active commission level for a fixed level.'];
    level = Number(l.id);
  }
  return [{ mode, level }, null];
}

async function describeCommission(mode, levelId) {
  if (mode !== 'fixed') return 'Ladder';
  const l = await pricingRepository.findLevel(levelId);
  return `Fixed: ${l ? `${l.name} — ${pricingService.levelLabel(l)}` : '?'}`;
}

async function create(req, res) {
  await form(req, res, null);
}

async function edit(req, res) {
  const a = await found(req, res);
  if (a) await form(req, res, a);
}

/** D-264: the currencies an associate abroad can be paid in — the active currencies other than INR. */
async function abroadCurrencyCodes() {
  return (await lookupRepository.currencies()).map((c) => String(c.code || '')).filter((c) => c !== '' && c !== 'INR');
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
  // D-264: an associate in India is always paid in rupees; one abroad in an active trading currency (USD, EUR, GBP …).
  // Without the browser script both "Paid in" selects are sent: the last one (abroad) is the one meant.
  if (Array.isArray(body.currency_code)) data.currency_code = String(body.currency_code[body.currency_code.length - 1] || '').trim() || null;
  const abroadCurrencies = await abroadCurrencyCodes();
  if (data.based_in === 'india') {
    data.currency_code = 'INR';
  } else if (!abroadCurrencies.includes(String(data.currency_code || ''))) {
    errors.push(`Choose the currency the associate abroad is paid in (${abroadCurrencies.join(', ')}).`);
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
  if (data.pan !== null && data.based_in === 'india' && !/^[A-Z]{5}\d{4}[A-Z]$/.test(data.pan)) {
    errors.push('Enter the PAN as on the card (e.g. ABCDE1234F).');
  }
  if (data.gstin !== null && !/^\d{2}[A-Z0-9]{13}$/.test(data.gstin)) {
    errors.push('Enter the 15-character GSTIN, or leave it empty.');
  }
  if (data.relationship_manager_id !== null && (!/^\d+$/.test(data.relationship_manager_id) || !(await userRepository.findById(parseInt(data.relationship_manager_id, 10))))) {
    errors.push('Choose who the associate reports to from the list.');
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
  const [commission, commissionError] = await readCommissionLevel(req, existing);
  if (commissionError) errors.push(commissionError);
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
    await associateRepository.setFields(savedId, { access_level: 'starter' }); // D-261: a new associate starts on Starter
    await auditLogRepository.log(user.id, 'ASSOCIATE_ADDED', 'associates', savedId, null, null, data.name);
    await associateEventRepository.add(savedId, 'registered', `Associate registered by ${user.name}.`, null, null, null, user.id, false);
    flash.set(req, 'success', `Associate "${data.name}" added. Next: upload their KYC, have Accounts verify their bank, then send portal access and set them Active.`);
  } else {
    await associateRepository.update(id, data);
    await auditLogRepository.log(user.id, 'ASSOCIATE_UPDATED', 'associates', id, null, null, JSON.stringify(data));
    if (Number(existing.no_commission || 0) !== Number(data.no_commission || 0)) {
      await associateEventRepository.add(id, 'rates_changed', `Commission terms changed by ${user.name} — applies to new quotes; orders with a Proforma Invoice keep the level fixed on them.`, null, null, null, user.id, true);
    }
    flash.set(req, 'success', `Associate "${data.name}" saved.`);
  }
  if (commission !== null) {
    const before = `${(existing && existing.commission_mode) || 'ladder'}:${(existing && existing.commission_level_id) || ''}`;
    const after = `${commission.mode}:${commission.level || ''}`;
    await pricingRepository.setAssociateCommission(savedId, commission.mode, commission.level);
    if (!existing || before !== after) {
      const oldText = existing ? await describeCommission((existing.commission_mode) || 'ladder', existing.commission_level_id) : null;
      const newText = await describeCommission(commission.mode, commission.level);
      await auditLogRepository.log(user.id, 'ASSOCIATE_COMMISSION_LEVEL_CHANGED', 'associates', savedId, 'commission_level', oldText, newText);
      if (existing) {
        await associateEventRepository.add(savedId, 'rates_changed', `Commission level changed by ${user.name} to ${newText} — applies to new quotes only.`, null, null, null, user.id, false);
      }
    }
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
  // Owner decision D-245: the fixed commission level, when the associate has one.
  let commissionLevel = null;
  if ((a.commission_mode || 'ladder') === 'fixed' && a.commission_level_id) {
    const l = await pricingRepository.findLevel(a.commission_level_id);
    if (l) commissionLevel = { ...l, label: pricingService.levelLabel(l) };
  }
  const docs = await associateDocumentRepository.forAssociate(id);
  const data = {
    a,
    commissionLevel,
    canManage,
    canBank,
    // D-292: the rule, level and progress only with "See associate commission rules"; others see "Set by the MD".
    rule: can(req, 'view_associate_commission_rules') ? await require('../services/associateCommission').ruleProgress(a) : null,
    canPricing: can(req, 'manage_pricing'),
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
      // D-245 P3: why a due commission cannot be paid yet (due date, buyer claim).
      p.block = p.status === 'due' ? await require('../services/associateCommission').payBlockReason(p, associateService.todayIst()) : null;
      const inv = await associateDocumentRepository.invoiceForPayout(Number(p.id));
      if (inv) invoiceByPayout[p.id] = { id: Number(inv.id), reference: inv.reference };
    }
    const banks = await associateBankRepository.forAssociate(id);
    // D-293: the confirmation / verification state of each request, and what this user may do.
    const bankConfirmation = require('../services/associateBankConfirmation');
    const bankDetailsCheck = require('../services/bankDetailsCheck');
    for (const b of banks) {
      b.masked = associateBankRepository.masked(b);
      b.status_text = bankConfirmation.staffStatus(b);
      b.checks_text = bankDetailsCheck.checksText(b);
      b.own = Number(b.requested_by_user || 0) === Number(req.user.id);
    }
    data.bankTwo = (await bankDetailsCheck.people()) === 'two';
    data.maySelfVerify = await bankDetailsCheck.maySelfVerify(req.user.id);
    data.bankWaitFirst = await bankDetailsCheck.waitDays(true);
    data.bankWaitChange = await bankDetailsCheck.waitDays(false);
    data.bankConfirmDays = bankDetailsCheck.CONFIRM_DAYS;
    data.myId = Number(req.user.id);
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
      documents: docs,
      docKinds: associateDocumentRepository.KINDS,
      kyc: await associateKyc.checklist(a, docs),
      addressGrace: await associateKyc.addressGraceNote(a), // D-290
      kycLabels: associateKyc.STATUS_LABELS,
      codes: await associateCodeService.codes(id),
      docRules: associateKyc.REFERENCE_RULES, docOtherRule: associateKyc.OTHER_RULE, allDocKinds: Object.keys(associateDocumentRepository.KINDS), idDocTypes: associateKyc.ID_DOC_TYPES,
      events: await associateEventRepository.forAssociate(id, null, 200),
      messages: await associateEventRepository.messages(id),
      unlinkedClients: await associateRepository.unlinkedClients(),
      allAssociates: (await associateRepository.all()).filter((o) => Number(o.id) !== id),
      // D-245 P3: commission level or ladder, counting orders so far, when commission is paid.
      commissionNow: await (async () => {
        const associateCommission = require('../services/associateCommission');
        const [lvl, count] = await associateCommission.levelForAssociate(a, null);
        const set = await associateCommission.settings();
        return { label: associateCommission.levelLabel(lvl), count: count || 0, set, runLabel: associateCommission.runLabel(set),
          gstText: String(set.gst_pct.toFixed(2)).replace(/0+$/, '').replace(/\.$/, ''), threshold: set.threshold_inr.toLocaleString('en-US', { maximumFractionDigits: 0 }) };
      })(),
      rm: a.relationship_manager_id ? await userRepository.findById(Number(a.relationship_manager_id)) : null,
      codeLink: a.personal_code ? `${associateService.appUrl()}/quotation-details?code=${encodeURIComponent(a.personal_code)}` : null,
      associateLoginUrl: associateService.loginUrl(), // D-282: the permanent login page, with Copy
      tdsPct: await associateService.tdsPct(),
      tdsPctText: trimPct(await associateService.tdsPct()),
      // D-261: portal access level, track record and the "Ready for Quoting?" suggestion.
      portalAccess: await (async () => {
        const access = require('../services/associateAccess');
        const track = await access.trackRecord(id);
        const feats = await access.features(a);
        return {
          track, history: await access.history(id, 20), isMd: await incentiveService.isMd(req.user), upgradeAfter: await access.upgradeAfter(),
          suggest: await access.suggestUpgrade(a, track.completed_paid), level: access.level(a), describe: await access.describe(a),
          levels: access.ALL_LEVELS, features: Object.entries(access.FEATURES).filter(([k]) => k !== 'profile').map(([k, [label, help]]) => ({ key: k, label, help, on: !!feats[k] })),
          valueText: Object.entries(track.value).map(([c, v]) => `${c} ${Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`).join(' · '),
          old: require('../helpers/flash').pullOld(req),
        };
      })(),
    });
  }
  data.agreementCard = await require('./agreementController').partyCard(req, 'associate', a.id);
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
  } else if (status === 'active' && !(await associateBankRepository.hasVerified(Number(a.id)))) {
    flash.set(req, 'error', 'An associate becomes Active once they have confirmed their bank details and NexaCrest has verified them.');
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
    const out = {};
    const link = await associateService.invitePortal(Number(a.id), req.user.id, out);
    // D-280: "e-mailed" only when it was; the link is always shown to copy.
    flash.set(req, 'success', associateService.inviteOutcome(String(a.email), out.sent), link);
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
  await associateEventRepository.add(Number(a.id), 'portal_disabled', `Portal switched off by ${req.user.name}.`, null, null, null, req.user.id, false);
  flash.set(req, 'success', 'Portal switched off. Their personal code, quotation link and clients stay as they are.');
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
    // Owner rule D-254: the old code keeps working unless staff block it (e.g. after a leak), with a reason.
    const body = req.body || {};
    const block = String(body.block_old || '') === '1';
    const reason = String(body.block_reason || '').trim();
    if (block && [...reason].length < 10) {
      flash.set(req, 'error', 'Say why the old code is blocked (at least 10 characters).');
      back(res, a.id, 'portal');
      return;
    }
    const code = await associateCodeService.issue(Number(a.id), part, block, block ? reason : null);
    await auditLogRepository.log(req.user.id, 'ASSOCIATE_CODE_SET', 'associates', Number(a.id), 'personal_code', a.personal_code || null, code + (block ? ` (old code blocked: ${reason})` : ''));
    await associateService.notify(a, 'code_changed', `Your personal code is now ${code}.${a.personal_code
      ? `${block ? ` Your old code ${a.personal_code} no longer brings new clients.` : ` Your old code ${a.personal_code} still brings buyers to you — links you already shared keep working.`} Clients already registered stay yours.` : ''}`, null, null, null, req.user.id);
    flash.set(req, 'success', `Personal code set to ${code}.${block ? ' The old code is blocked.' : ' The old code keeps working.'}`);
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
    await associateService.notify(a, 'client_accepted', `${client.company_legal_name} is now registered as your client (NexaCrest linked them to you: ${reason}). It stays your client until ${dates.short(until)}.`, clientId, null, null, req.user.id);
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

/** Accounts enter the bank details themselves (from the associate's signed form) — the holder confirms them, then one person (or two, by the setting) verifies them. */
/**
 * Accounts enter the bank details themselves (from the associate's signed form). D-293: the associate
 * confirms them first (portal + e-mailed code), then one person other than the enterer verifies them.
 */
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
  await require('../services/associateBankConfirmation').start(id, a, false, req.user.id);
  const bankDetailsCheck = require('../services/bankDetailsCheck');
  flash.set(req, 'success', `Bank details recorded. The associate must now confirm them in their portal (they were e-mailed, ${bankDetailsCheck.CONFIRM_DAYS} days to answer); then `
    + ((await bankDetailsCheck.people()) === 'two' ? 'two people other than you check them.' : 'one person other than you verifies them.'));
  back(res, a.id, 'bank');
}

/** D-293: the checks every verifier must pass — confirmed by the associate, not the enterer (unless the self-approval rule allows, with a reason). */
async function bankCheckRefusal(bank, uid, body) {
  if (bank.status === 'awaiting_confirmation') return 'Verify is available once the associate confirms the new details.';
  if (bank.status !== 'pending') return 'This request is no longer waiting to be checked.';
  return require('../services/bankDetailsCheck').selfCheckError(bank.requested_by_user ?? null, uid, (body || {}).self_reason);
}

/** Two-people setting only: the first of the two checks (with the "how I checked" ticks). */
async function firstCheck(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bankDetailsCheck = require('../services/bankDetailsCheck');
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  const uid = Number(req.user.id);
  const body = req.body || {};
  const [checks, checkError] = bankDetailsCheck.readChecks(body);
  let refusal = null;
  if (!bank || Number(bank.associate_id) !== Number(a.id)) {
    flash.set(req, 'error', 'Bank request not found.');
  } else if ((await bankDetailsCheck.people()) !== 'two') {
    flash.set(req, 'error', 'One person verifies bank details — use Verify.');
  } else if ((refusal = await bankCheckRefusal(bank, uid, body)) !== null) {
    flash.set(req, 'error', refusal);
  } else if (checkError) {
    flash.set(req, 'error', checkError);
  } else if (!(await associateBankRepository.firstCheck(Number(bank.id), uid, bankDetailsCheck.checksText(checks)))) {
    flash.set(req, 'error', 'This request was already checked or is no longer pending.');
  } else {
    const selfReason = Number(bank.requested_by_user || 0) === uid ? String(body.self_reason || '').trim() : null;
    await associateBankRepository.saveChecks(Number(bank.id), checks, selfReason);
    await auditLogRepository.log(uid, 'ASSOCIATE_BANK_FIRST_CHECK', 'associate_bank_accounts', Number(bank.id), null, null, bankDetailsCheck.checksText(checks), selfReason);
    flash.set(req, 'success', 'First check recorded. A different person now verifies it.');
  }
  back(res, a.id, 'bank');
}

/**
 * D-293: Verify — one person (default), ticking at least 2 of the checks; with the "Two people" setting, the
 * second person after the first check. Usable after the waiting days (first account / change of account).
 * The associate is always e-mailed (the bank-changed alert, MUST).
 */
async function verifyBank(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bankDetailsCheck = require('../services/bankDetailsCheck');
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  const uid = Number(req.user.id);
  const body = req.body || {};
  const two = (await bankDetailsCheck.people()) === 'two';
  const [checks, checkError] = two ? [null, null] : bankDetailsCheck.readChecks(body);
  const wait = bank ? await bankDetailsCheck.waitDays(!(await associateBankRepository.hasVerified(Number(bank.associate_id), Number(bank.id)))) : 0;
  let refusal = null;
  if (!bank || Number(bank.associate_id) !== Number(a.id)) {
    flash.set(req, 'error', 'Bank request not found.');
  } else if ((refusal = await bankCheckRefusal(bank, uid, body)) !== null) {
    flash.set(req, 'error', refusal);
  } else if (two && !bank.first_checked_by) {
    flash.set(req, 'error', 'Record the first check before verifying.');
  } else if (two && Number(bank.first_checked_by) === uid) {
    flash.set(req, 'error', 'A different person must verify — you already acted on this request.');
  } else if (checkError) {
    flash.set(req, 'error', checkError);
  } else if (!(two ? await associateBankRepository.verify(Number(bank.id), uid, wait) : await associateBankRepository.verifyOne(Number(bank.id), uid, wait))) {
    flash.set(req, 'error', 'This request is no longer pending.');
  } else {
    const selfReason = Number(bank.requested_by_user || 0) === uid ? String(body.self_reason || '').trim() : null;
    if (checks) await associateBankRepository.saveChecks(Number(bank.id), checks, selfReason);
    const b = await associateBankRepository.find(Number(bank.id));
    await auditLogRepository.log(uid, 'ASSOCIATE_BANK_VERIFIED', 'associate_bank_accounts', Number(bank.id), 'status', 'pending', 'verified',
      `${checks ? `Checked: ${bankDetailsCheck.checksText(checks)}. ` : ''}${selfReason !== null ? `Own entry — reason: ${selfReason}` : ''}`.trim() || null);
    // ACCESS / SECURITY-CRITICAL e-mail (MUST — the bank-changed alert, kept): always sent through the existing mail send.
    const mailed = await associateService.notify(a, 'bank_changed', `We have checked your bank account ${associateBankRepository.masked(b)}. We will pay into it from `
      + `${dates.short(String(b.usable_from))}. If you did not ask for this change, contact NexaCrest at once.`, null, null, null, uid);
    // D-280: "e-mailed" only when it was.
    flash.set(req, 'success', `Verified. Usable from ${dates.short(String(b.usable_from))}${mailed ? '; the associate was e-mailed.' : '; the associate sees it in their portal (the e-mail could not be sent).'}`);
  }
  back(res, a.id, 'bank');
}

/** D-293: "Remind associate" — the confirmation e-mail again while they have not answered. */
async function remindBank(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const bank = await associateBankRepository.find(parseInt(req.params.bankId, 10));
  if (!bank || Number(bank.associate_id) !== Number(a.id) || !(await require('../services/associateBankConfirmation').remind(Number(bank.id), req.user.id))) {
    flash.set(req, 'error', 'These bank details are no longer waiting for the associate.');
  } else {
    flash.set(req, 'success', 'Reminder e-mailed to the associate.');
  }
  back(res, a.id, 'bank');
}

// ------------------------------------------------------------ D-291: commission rule (the MD)

/** The rule page: Standard ladder / Own ladder (a copy of the standard ladder to change) / Fixed %. */
async function commissionRule(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const associateCommission = require('../services/associateCommission');
  let chosen = String(req.query.rule || a.commission_mode || 'ladder');
  if (!associateCommission.RULE_LABELS[chosen]) chosen = 'ladder';
  const own = await pricingRepository.ownLadder(Number(a.id));
  const standard = await pricingRepository.ladder();
  const rows = (own.length ? own : standard).map((s) => ({ from_order: s.from_order, to_order: s.to_order, level_id: s.level_id }));
  const blank = { from_order: '', to_order: '', level_id: '' };
  const levels = (await pricingRepository.levels()).map((l) => ({ ...l, label: pricingService.levelLabel(l) }));
  res.renderView('associates/commission_rule', {
    a, chosen, rows: [...rows, blank, { ...blank }], levels, ruleLabels: associateCommission.RULE_LABELS,
    currentRule: associateCommission.RULE_LABELS[a.commission_mode] || 'Standard ladder', standardSummary: associateCommission.ladderSummary(standard),
  }, 'layout/base');
}

async function saveCommissionRule(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const associateCommission = require('../services/associateCommission');
  const body = req.body || {};
  const mode = String(body.rule || '');
  const reason = String(body.reason || '').trim();
  const fail = (msg) => {
    flash.set(req, 'error', msg);
    res.redirect(`/associates/${Number(a.id)}/commission-rule?rule=${encodeURIComponent(mode)}`);
  };
  if (!associateCommission.RULE_LABELS[mode]) return fail('Choose the rule: Standard ladder, Own ladder or Fixed %.');
  if (len(reason) < 10 || len(reason) > 300) return fail('Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.');
  const levelsById = {};
  for (const l of await pricingRepository.levels()) levelsById[Number(l.id)] = l;
  let levelId = null;
  let steps = [];
  if (mode === 'fixed') {
    const l = levelsById[parseInt(body.commission_level_id, 10) || 0];
    if (!l || (Number(l.is_active) !== 1 && Number(a.commission_level_id || 0) !== Number(l.id))) return fail('Choose an active commission level for the fixed %.');
    levelId = Number(l.id);
  } else if (mode === 'own_ladder') {
    const [parsed, errors] = pricingService.readLadderForm(body, levelsById);
    if (errors.length) return fail(`Own ladder: ${errors.join(' ')}`);
    steps = parsed;
  }
  const describe = (m, lid, rows) => {
    if (m === 'fixed') {
      const l = levelsById[Number(lid)];
      return `Fixed %: ${l ? `${l.name} — ${pricingService.levelLabel(l)}` : '?'}`;
    }
    if (m === 'own_ladder') {
      return `Own ladder: ${rows.map((s) => `${s.from ?? s.from_order}–${(s.to ?? s.to_order ?? null) === null ? 'onward' : (s.to ?? s.to_order)}: ${(levelsById[Number(s.level_id)] || {}).name || '?'}`).join('; ')}`;
    }
    return 'Standard ladder';
  };
  const oldMode = String(a.commission_mode || 'ladder');
  const before = describe(oldMode, a.commission_level_id, oldMode === 'own_ladder' ? await pricingRepository.ownLadder(Number(a.id)) : []);
  const after = describe(mode, levelId, steps);
  if (before === after) {
    flash.set(req, 'success', 'Nothing changed.');
    res.redirect(`/associates/${Number(a.id)}#commission-rule`);
    return undefined;
  }
  const rateBefore = associateCommission.rateLabel((await associateCommission.levelForAssociate(a, null))[0]);
  await pricingRepository.setAssociateRule(Number(a.id), mode, levelId, steps, reason, req.user.id);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_COMMISSION_RULE_CHANGED', 'associates', Number(a.id), 'commission_rule', before, after, reason);
  const fresh = (await associateRepository.find(Number(a.id))) || a;
  const rateAfter = associateCommission.rateLabel((await associateCommission.levelForAssociate(fresh, null))[0]);
  if (rateAfter !== rateBefore) {
    // D-292: the associate sees only their current % — never the rule, levels or ladder.
    await associateEventRepository.add(Number(a.id), 'rates_changed', `Your commission for new orders is now ${rateAfter} of the FOB value of each order from the clients you introduced. Orders whose Proforma Invoice was already sent keep their %.`, null, null, null, req.user.id, true);
  }
  flash.set(req, 'success', `Commission rule saved: ${after}. It applies to new orders only.`);
  res.redirect(`/associates/${Number(a.id)}#commission-rule`);
  return undefined;
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
    // QA Round 8 (R8-N01): one full stop after the reason; the verified account named only when there is one.
    await associateService.notify(a, 'bank_rejected', `Your new bank details (${associateBankRepository.masked(bank)}) were not accepted: ${associateService.cleanReason(reason)}. ${await associateService.bankPaymentsSentence(Number(a.id))}`, null, null, null, req.user.id);
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
  // Owner decision D-251 (A4): checked per kind; an Aadhaar card keeps only its last 4 digits.
  // D-290: an ID document carries its type and number; ID and address proofs get app-set file names.
  const [refValue, idType, refError] = associateKyc.checkUpload(kind, reference, (req.body || {}).id_doc_type);
  if (refError) {
    flash.set(req, 'error', refError);
    back(res, a.id, 'documents');
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'document_file', 'associate_document', 'associates/documents', null, null, req.user.id,
      associateKyc.appFileName(kind, a.name, req.file ? req.file.originalname : ''), a.name, KINDS[kind]);
  } catch (e) {
    flash.setErrorFromException(req, e);
    back(res, a.id, 'documents');
    return;
  }
  await associateDocumentRepository.add(Number(a.id), kind, fileId, null, refValue, false, req.user.id, idType);
  await auditLogRepository.log(req.user.id, 'ASSOCIATE_DOCUMENT_UPLOADED', 'associates', Number(a.id), 'kind', null, kind);
  if (['form_16a', 'form_15ca', 'form_15cb'].includes(kind)) {
    await associateService.notify(a, 'document_added', `${KINDS[kind]}${refValue ? ` (${refValue})` : ''} is in your portal.`, null, null, null, req.user.id);
  }
  flash.set(req, 'success', `${KINDS[kind]} saved.`);
  back(res, a.id, 'documents');
}

/** Owner decision D-252 (A3): accept or reject one KYC document (reason required to reject). */
async function reviewDocument(req, res) {
  const a = await found(req, res);
  if (!a) return;
  const body = req.body || {};
  const error = await associateKyc.review(a, parseInt(req.params.docId, 10) || 0, String(body.decision || ''), String(body.reason || ''), req.user.id);
  flash.set(req, error ? 'error' : 'success', error || (body.decision === 'accept' ? 'Document accepted.' : 'Document rejected — the associate is asked to upload it again.'));
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

/**
 * Owner decision D-245 P3: a paid commission is taken back only for the associate's
 * fraud or breach of the agreement — never for a later buyer claim or refund — with
 * a written reason. The amount is deducted from the associate's next payout.
 */
async function clawback(req, res) {
  const p = await associatePayout(req, res);
  if (!p) return;
  const body = req.body || {};
  const amount = isNum(body.amount_inr) ? Number(body.amount_inr) : null;
  const reason = String(body.reason || '').trim();
  const kind = String(body.takeback_kind || '');
  if (!['fraud', 'breach'].includes(kind)) {
    flash.set(req, 'error', 'A paid commission is taken back only for the associate\'s fraud or breach of the agreement — choose which, and say what happened. A later buyer claim or refund does not take it back.');
    back(res, p.associate_id, 'commission');
    return;
  }
  const already = Number((await db.queryOne('SELECT COALESCE(-SUM(amount_inr), 0) AS s FROM incentive_payouts WHERE clawback_of_payout_id = :id', { id: p.id })).s);
  if (p.status !== 'paid' || Number(p.amount_inr) <= 0) {
    flash.set(req, 'error', 'Only a paid commission can be clawed back.');
  } else if (amount === null || amount <= 0 || amount > Number(p.paid_inr) - already + 0.005) {
    flash.set(req, 'error', `Enter the amount to claw back, above 0 and at most what is left of the paid commission (₹${fmt2(Number(p.paid_inr) - already)}).`);
  } else if (len(reason) < 10 || len(reason) > 300) {
    flash.set(req, 'error', `Say what the ${kind} was (10 to 300 characters) — the associate is shown it.`);
  } else {
    const month = await incentiveService.incentiveMonth(associateService.todayIst());
    const order = await orderRepository.find(Number(p.order_id));
    // QA Round 8, R8-N12: re-checked with the paid payout locked, so simultaneous claw-backs cannot exceed what was paid.
    const saved = await incentiveRepository.addClawbackWithinPaid(Number(p.id), amount, month, `Taken back for ${kind}: commission paid on order ${order ? order.order_reference : p.order_id} (payout no. ${p.id}, ref ${p.paid_reference || '—'}): ${reason}`);
    if (saved.id === null) {
      flash.set(req, 'error', `Enter the amount to claw back, above 0 and at most what is left of the paid commission (₹${fmt2(Math.max(0, saved.left))}).`);
    } else {
      const id = saved.id;
      await db.execute('UPDATE incentive_payouts SET takeback_kind = :k WHERE id = :id', { k: kind, id });
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_CLAWBACK', 'incentive_payouts', id, 'takeback_kind', null, String(amount), `${kind}: ${reason}`);
      const a = await associateRepository.find(Number(p.associate_id));
      await associateService.notify(a, 'clawback', `We are taking back INR ${fmt2(amount)} of the commission we paid you on order ${order ? order.order_reference : ''}, because of ${kind === 'fraud' ? 'fraud' : 'a breach of the agreement'}. We will take it off your ${incentiveService.monthLabel(month)} payment. Reason: ${reason}`, null, Number(p.order_id), null, req.user.id);
      flash.set(req, 'success', `Take-back recorded (${kind}); it is deducted from the associate's next payout.`);
    }
  }
  back(res, p.associate_id, 'commission');
}

// ------------------------------------------------------------ commission settings (D-245 P3)

/**
 * Owner decision D-245 P3: when an associate's commission is paid, the counting-order
 * threshold, buyer registration, GST — the MD changes these (manage_pricing); TDS and
 * the bank waiting days as before. Commission levels and the ladder: Admin → Pricing.
 */
async function ladder(req, res) {
  const associateCommission = require('../services/associateCommission');
  const commission = await associateCommission.settings();
  res.renderView('associates/ladder', {
    tdsValue: (await associateService.tdsPct()).toFixed(2),
    // D-293: two waits (first account / change of account) and one or two people check.
    waitFirst: await require('../services/bankDetailsCheck').waitDays(true),
    waitChange: await require('../services/bankDetailsCheck').waitDays(false),
    bankPeople: await require('../services/bankDetailsCheck').people(),
    codeChanges: await associateCodeService.changesPerYear(),
    upgradeAfter: await require('../services/associateAccess').upgradeAfter(),
    weekdays: associateCommission.WEEKDAYS,
    monthDays: Array.from({ length: 28 }, (_, i) => ({ value: i + 1, label: `${associateCommission.ordinal(i + 1)} of the month` })),
    commission,
    thresholdValue: commission.threshold_inr.toFixed(0),
    gstValue: commission.gst_pct.toFixed(2),
    canPricing: can(req, 'manage_pricing'),
  }, 'layout/base');
}

async function saveLadder(req, res) {
  const associateCommission = require('../services/associateCommission');
  const body = req.body || {};
  const values = {};
  const int = (v) => (/^-?\d+$/.test(String(v ?? '').trim()) ? parseInt(v, 10) : null);
  const tds = isNum(body.associate_tds_pct) ? Number(body.associate_tds_pct) : null;
  // D-293: two waits (0–90) and one or two people; left as they are when not on the form.
  const bankDetailsCheck = require('../services/bankDetailsCheck');
  const waitFirst = int(body.associate_bank_wait_first_days ?? await bankDetailsCheck.waitDays(true));
  const waitChange = int(body.associate_bank_wait_change_days ?? await bankDetailsCheck.waitDays(false));
  const people = String(body.bank_details_check_people ?? await bankDetailsCheck.people());
  if (tds === null || tds < 0 || tds > 30 || waitFirst === null || waitFirst < 0 || waitFirst > 90 || waitChange === null || waitChange < 0 || waitChange > 90) {
    flash.set(req, 'error', 'Enter TDS as 0–30 % and each bank waiting time as 0–90 days.');
    res.redirect('/associates/ladder');
    return;
  }
  if (!['one', 'two'].includes(people)) {
    flash.set(req, 'error', 'Choose who checks new bank details: one person or two people.');
    res.redirect('/associates/ladder');
    return;
  }
  values.associate_tds_pct = tds.toFixed(2);
  values.associate_bank_wait_first_days = String(waitFirst);
  values.associate_bank_wait_change_days = String(waitChange);
  values.bank_details_check_people = people;
  // The commission rules: the MD only (fields posted by others are ignored).
  if (can(req, 'manage_pricing')) {
    const days = int(body.associate_commission_due_days);
    const run = String(body.associate_payout_run || '');
    // D-292: monthly 1–28 or 'last'; weekly 1–7 (the weekday field, or the old single field).
    const rawDay = String((run === 'weekly' ? (body.associate_payout_weekday ?? body.associate_payout_day) : body.associate_payout_day) ?? '').trim();
    const day = run === 'monthly' && rawDay === 'last' ? 'last' : int(rawDay);
    const holidayRule = String(body.associate_payout_holiday_rule ?? (await associateCommission.settings()).holiday_rule);
    const codeChanges = int(body.associate_code_changes_per_year ?? await associateCodeService.changesPerYear());
    const upgradeAfter = int(body.associate_upgrade_after_orders ?? await require('../services/associateAccess').upgradeAfter());
    const threshold = isNum(body.associate_counting_threshold_inr) ? Number(body.associate_counting_threshold_inr) : null;
    const months = int(body.associate_protection_months);
    const gst = isNum(body.associate_gst_pct) ? Number(body.associate_gst_pct) : null;
    const portalMonths = int(body.associate_portal_commission_months);
    // D-264: left as they are when not on the form.
    const current = await associateCommission.settings();
    const abroadCharges = String(body.associate_abroad_charges ?? current.abroad_charges);
    const abroadMinRaw = body.associate_abroad_min_payout_usd ?? current.abroad_min_usd;
    const abroadMin = isNum(abroadMinRaw) ? Number(abroadMinRaw) : null;
    let error = null;
    if (days === null || days < 0 || days > 365) error = 'Enter the days after full payment as 0 to 365.';
    else if (!['monthly', 'weekly'].includes(run)) error = 'Choose a monthly or a weekly payout run.';
    else if (day === null || (day !== 'last' && ((run === 'monthly' && (day < 1 || day > 28)) || (run === 'weekly' && (day < 1 || day > 7))))) error = 'Choose the payout day: the 1st to the 28th or the last day of the month for a monthly run, a weekday for a weekly run.';
    else if (!['before', 'after'].includes(holidayRule)) error = 'Choose what happens when the payment day is a Sunday or holiday: the working day before or after.';
    else if (codeChanges === null || codeChanges < 0 || codeChanges > 12) error = 'Enter the personal code changes allowed per year as 0 to 12.';
    else if (upgradeAfter === null || upgradeAfter < 1 || upgradeAfter > 50) error = 'Enter after how many paid orders "Quoting" is suggested, 1 to 50.';
    else if (threshold === null || threshold < 0 || threshold > 1000000000) error = 'Enter the counting-order threshold in rupees (0 or more).';
    else if (months === null || months < 1 || months > 60) error = 'Enter the buyer registration period as 1 to 60 months.';
    else if (gst === null || gst < 0 || gst > 28) error = 'Enter the GST on commission as 0 to 28 %.';
    else if (!associateCommission.PORTAL_MONTH_CHOICES.includes(portalMonths)) error = 'Choose the commission period shown to associates: 3, 6, 9, 12, 15, 18 or 24 months.';
    else if (!['OUR', 'SHA'].includes(abroadCharges)) error = 'Choose who pays the bank charges of a transfer abroad (OUR or SHA).';
    else if (abroadMin === null || abroadMin < 0 || abroadMin > 100000) error = 'Enter the minimum payout abroad in US dollars (0 to 100,000).';
    if (error) {
      flash.set(req, 'error', error);
      res.redirect('/associates/ladder');
      return;
    }
    values.associate_commission_due_days = String(days);
    values.associate_claim_hold = body.associate_claim_hold ? '1' : '0';
    values.associate_payout_run = run;
    values.associate_payout_day = String(day);
    values.associate_payout_holiday_rule = holidayRule;
    values.associate_code_changes_per_year = String(codeChanges);
    values.associate_upgrade_after_orders = String(upgradeAfter);
    // D-292: a change of the payment run (run, day, Sunday/holiday rule) needs a reason, kept in the Audit Log.
    const now = await incentiveRepository.settings();
    const runChanged = String(now.associate_payout_run ?? 'monthly') !== run || String(now.associate_payout_day ?? 'last') !== String(day)
      || String(now.associate_payout_holiday_rule ?? 'before') !== holidayRule;
    const payoutReason = String(body.payout_reason || '').trim();
    if (runChanged && (len(payoutReason) < 10 || len(payoutReason) > 300)) {
      flash.set(req, 'error', 'Give the reason for changing the payment run (10 to 300 characters) — it is kept in the Audit Log.');
      res.redirect('/associates/ladder');
      return;
    }
    values.associate_counting_threshold_inr = threshold.toFixed(2);
    values.associate_protection_months = String(months);
    values.associate_gst_pct = gst.toFixed(2);
    values.associate_portal_commission_months = String(portalMonths);
    values.associate_abroad_charges = abroadCharges;
    values.associate_abroad_min_payout_usd = abroadMin.toFixed(2);
    // D-256: the Zoho Books GST tax booked on an associate's commission bill when GST is paid on top.
    const gstTax = String(body.zoho_gst_commission_tax_id ?? '').trim();
    if (gstTax !== '' && !/^[0-9A-Za-z_-]{1,40}$/.test(gstTax)) {
      flash.set(req, 'error', 'Zoho GST tax for commission: enter the tax id from Zoho Books (letters, digits, - and _ only), or leave it empty.');
      res.redirect('/associates/ladder');
      return;
    }
    values.zoho_gst_commission_tax_id = gstTax;
    // D-264: the Zoho Books account of the associate payout costs (the exchange difference and transfer charges).
    const costsAccount = String(body.zoho_payout_costs_account_id ?? '').trim();
    if (costsAccount !== '' && !/^[0-9A-Za-z_-]{1,40}$/.test(costsAccount)) {
      flash.set(req, 'error', 'Zoho account for associate payout costs: enter the account id from Zoho Books (letters, digits, - and _ only), or leave it empty.');
      res.redirect('/associates/ladder');
      return;
    }
    values.zoho_payout_costs_account_id = costsAccount;
  }
  const before = await incentiveRepository.settings();
  for (const [k, v] of Object.entries(values)) {
    if (String(before[k] ?? '') !== v) {
      await incentiveRepository.setSetting(k, v, req.user.id);
      await auditLogRepository.log(req.user.id, 'ASSOCIATE_SETTING_CHANGED', 'incentive_settings', null, k, before[k] ?? null, v,
        ['associate_payout_run', 'associate_payout_day', 'associate_payout_holiday_rule'].includes(k) ? (String(body.payout_reason || '').trim() || null) : null);
    }
  }
  flash.set(req, 'success', 'Saved. The commission rules apply to orders whose buyer pays in full from now on; payouts already worked out keep their dates.');
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
  abroadCurrencyCodes,
  reviewDocument,
  can, validateBank, sendFile, index, create, edit, save, show, changeStatus, invitePortal, disablePortal, setCode, viewAs, downloadAgreement,
  linkClient, unlinkClient, addBank, firstCheck, verifyBank, rejectBank, remindBank, commissionRule, saveCommissionRule, bankNumber, uploadDocument, downloadDocument, reply,
  holdPayout, releasePayout, clawback, ladder, saveLadder, report,
};
