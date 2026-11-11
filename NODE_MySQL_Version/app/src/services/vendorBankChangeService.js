'use strict';

const crypto = require('crypto');
const env = require('../config/env');
const { short } = require('../helpers/dates');
const auditLogRepository = require('../repositories/auditLogRepository');
const vendorBankChangeRepository = require('../repositories/vendorBankChangeRepository');
const vendorDetailsRepository = require('../repositories/vendorDetailsRepository');
const bankDetailsCheck = require('./bankDetailsCheck');

/**
 * Port of VendorBankChangeService.php. Owner decisions 11 Nov 2026, point 18
 * (D-294): the same rules as for associates apply to a supplier's or logistics
 * partner's bank details. A change does not replace the account in use: a
 * confirmation link goes to the vendor's REGISTERED e-mail (current vs new,
 * last 4 digits, "These are our bank details" — Confirm, or "This is not us"
 * which cancels and alerts the MD and Finance); 7 days without an answer
 * cancels it. Staff verify only after the confirmation; after the waiting days
 * the new details are copied onto the supplier as verified.
 */

const EDIT = { supplier: (id) => `/suppliers/${id}/edit`, logistics_partner: (id) => `/logistics-partners/${id}/edit` };
const day = (v) => String(v || '').slice(0, 10);
const sha256 = (s) => crypto.createHash('sha256').update(String(s)).digest('hex');
const appUrl = () => String(env.get('APP_URL', '') || '').replace(/\/+$/, '');

function name(c, vendor = null) {
  let last4 = c.account_last4;
  if ((last4 === null || last4 === undefined) && vendor) last4 = String(vendorDetailsRepository.bankDetails(vendor).bank_account_number || '').slice(-4);
  return `${c.bank_name || ''} ••••${last4 || ''}`.trim();
}

/** ACCESS / SECURITY-CRITICAL e-mail (MUST — the bank-changed alert with its confirm link): sent on the security path of the existing mail send. */
async function sendLink(kind, vendor, c, raw, email) {
  const meta = vendorDetailsRepository.KINDS[kind];
  const link = `${appUrl()}/vendor-bank-confirm/${raw}`;
  await require('./emailService').sendPlainText(email, 'NexaCrest — please confirm your new bank details',
    `Hello${vendor ? ` ${vendor[meta.name]}` : ''},\n\n`
    + `New bank details were recorded for you at NexaCrest: ${name(c, vendor)}.\n`
    + `NexaCrest pays into them only after you confirm them here (no login needed), by ${short(day(c.confirm_by))}:\n${link}\n\n`
    + 'If you did not send new bank details, open the link and click "This is not us — cancel it". Until then, payments go to the account you had.\n\n'
    + 'NexaCrest International Private Limited', { isSecurityEmail: true, kind: 'vendor_bank_confirm', audience: 'supplier' });
}

/** A change of bank details is recorded and the confirmation link e-mailed to the registered e-mail (the one on file BEFORE this change). */
async function propose(kind, vendorId, bank, source, userId, registeredEmail) {
  const vendor = await vendorDetailsRepository.find(kind, vendorId);
  const onFile = vendorDetailsRepository.bankDetails(vendor).bank_account_number;
  const last4 = bank.bank_account_number ? String(bank.bank_account_number).slice(-4) : (String(onFile || '').slice(-4) || null);
  const raw = crypto.randomBytes(32).toString('hex');
  const email = String(registeredEmail || '').trim() || null;
  const id = await vendorBankChangeRepository.create(kind, vendorId, bank, source, userId, email, sha256(raw), bankDetailsCheck.CONFIRM_DAYS, last4);
  const meta = vendorDetailsRepository.KINDS[kind];
  await auditLogRepository.log(userId ?? null, 'VENDOR_BANK_CHANGE_REQUESTED', meta.table, Number(vendorId), 'bank_account_number',
    vendorDetailsRepository.maskAccount(onFile), `••••${last4}`,
    `${source === 'vendor' ? 'Entered by them through the details link' : 'Entered by staff'} — waiting for their confirmation (${email || 'no registered e-mail'})`);
  if (email) await sendLink(kind, vendor, (await vendorBankChangeRepository.find(id)) || {}, raw, email);
  return { id, emailedTo: email };
}

async function resend(changeId, userId) {
  const c = await vendorBankChangeRepository.find(changeId);
  if (!c || c.status !== 'awaiting_confirmation') return 'This change is no longer waiting for their confirmation.';
  const vendor = await vendorDetailsRepository.find(c.vendor_kind, Number(c.vendor_id));
  const email = String((vendor && vendor.email) || '').trim();
  if (!email) return 'There is no e-mail on file — add their e-mail first.';
  const raw = crypto.randomBytes(32).toString('hex');
  await vendorBankChangeRepository.newToken(Number(changeId), sha256(raw), bankDetailsCheck.CONFIRM_DAYS, email);
  await auditLogRepository.log(userId, 'VENDOR_BANK_CONFIRM_REMINDED', vendorDetailsRepository.KINDS[c.vendor_kind].table, Number(c.vendor_id), null, null, null, `Confirmation link e-mailed again to ${email}`);
  await sendLink(c.vendor_kind, vendor, (await vendorBankChangeRepository.find(changeId)) || c, raw, email);
  return null;
}

async function confirm(c, last4, ticked) {
  if (!ticked) return 'Tick "These are our bank details" to confirm.';
  if (String(last4 ?? '').replace(/\D/g, '') !== String(c.account_last4)) return 'The last 4 digits do not match the new account number — check them and type them again.';
  if (!(await vendorBankChangeRepository.move(Number(c.id), 'awaiting_confirmation', 'pending', { confirmed_at: 'NOW()', confirm_token_hash: null }))) {
    return 'These bank details are no longer waiting for your confirmation.';
  }
  await auditLogRepository.log(null, 'VENDOR_BANK_CONFIRMED', vendorDetailsRepository.KINDS[c.vendor_kind].table, Number(c.vendor_id), 'bank_account_number', null, `••••${c.account_last4}`, `Confirmed by them through the link e-mailed to ${c.sent_to_email || '—'}`);
  return null;
}

async function notUs(c) {
  if (!(await vendorBankChangeRepository.move(Number(c.id), 'awaiting_confirmation', 'cancelled', { cancelled_at: 'NOW()', cancelled_how: 'not_me', confirm_token_hash: null }))) return false;
  const kind = c.vendor_kind;
  const meta = vendorDetailsRepository.KINDS[kind];
  const vendor = await vendorDetailsRepository.find(kind, Number(c.vendor_id));
  await auditLogRepository.log(null, 'VENDOR_BANK_NOT_US', meta.table, Number(c.vendor_id), 'bank_account_number', null, `••••${c.account_last4}`, 'They said "This is not us" — possible fraud');
  const enterer = c.entered_source === 'vendor' ? null : await require('../repositories/userRepository').findById(Number(c.entered_by || 0));
  const by = c.entered_source === 'vendor' ? 'through the Vendor Details Link' : `by ${(enterer && enterer.name) || 'NexaCrest staff'}`;
  const vname = (vendor && vendor[meta.name]) || '';
  await bankDetailsCheck.alertMdAndFinance(`Bank change refused by the ${meta.label} — possible fraud: ${vname}`,
    `${meta.label.charAt(0).toUpperCase()}${meta.label.slice(1)} ${vname} clicked "This is not us — cancel it" for the new bank details ${name(c, vendor)}, entered ${by} on ${short(day(c.entered_at))}.\n`
    + 'The change is cancelled and their bank details have not changed. Please find out who entered them.\n'
    + `Record: ${appUrl()}${EDIT[kind](Number(c.vendor_id))}`);
  return true;
}

/** Staff verify (or first-check in the two-people setting). Returns the error or null. */
async function verify(changeId, kind, vendorId, userId, post, firstCheck) {
  const c = await vendorBankChangeRepository.find(changeId);
  if (!c || c.vendor_kind !== kind || Number(c.vendor_id) !== Number(vendorId)) return 'Bank change not found.';
  if (c.status === 'awaiting_confirmation') return 'Verify is available once they confirm the new details (link sent to their registered e-mail).';
  if (c.status !== 'pending') return 'This change is no longer waiting to be checked.';
  const selfError = await bankDetailsCheck.selfCheckError(c.entered_by ?? null, userId, (post || {}).self_reason);
  if (selfError) return selfError;
  const two = (await bankDetailsCheck.people()) === 'two';
  if (firstCheck && c.first_checked_by) return 'The first check is already recorded — a different person now verifies it.';
  const selfReason = Number(c.entered_by || 0) === Number(userId) ? String((post || {}).self_reason || '').trim() : null;
  const table = vendorDetailsRepository.KINDS[kind].table;
  let set;
  if (firstCheck || !two || !c.first_checked_by) {
    if (two && !firstCheck) return 'Record the first check before verifying.';
    if (!two && firstCheck) return 'One person verifies bank details — use Verify.';
    const [checks, err] = bankDetailsCheck.readChecks(post);
    if (err) return err;
    set = { ...checks, self_verify_reason: selfReason };
    if (firstCheck) {
      if (!(await vendorBankChangeRepository.move(Number(changeId), 'pending', 'pending', { ...set, first_checked_by: userId, first_checked_at: 'NOW()' }))) {
        return 'This change is no longer waiting to be checked.';
      }
      await auditLogRepository.log(userId, 'VENDOR_BANK_FIRST_CHECK', table, Number(vendorId), null, null, bankDetailsCheck.checksText(checks), selfReason);
      return null;
    }
  } else {
    if (Number(c.first_checked_by) === Number(userId)) return 'A different person must verify — you already acted on this change.';
    set = { self_verify_reason: selfReason ?? c.self_verify_reason ?? null };
  }
  const vendor = await vendorDetailsRepository.find(kind, Number(vendorId));
  const first = !vendorDetailsRepository.bankDetails(vendor).has_bank;
  // The database's date, the same clock applyDue compares with (CURDATE()).
  const usable = String((await require('../config/db').queryOne(`SELECT DATE_ADD(CURDATE(), INTERVAL ${await bankDetailsCheck.waitDays(first)} DAY) AS d`)).d).slice(0, 10);
  if (!(await vendorBankChangeRepository.move(Number(changeId), 'pending', 'verified', { ...set, verified_by: userId, verified_at: 'NOW()', usable_from: usable }))) {
    return 'This change is no longer waiting to be checked.';
  }
  const after = (await vendorBankChangeRepository.find(changeId)) || c;
  await auditLogRepository.log(userId, 'VENDOR_BANK_DETAILS_VERIFIED', table, Number(vendorId), 'bank_account_number', null, `••••${c.account_last4}`,
    `Checked: ${bankDetailsCheck.checksText(after)}. Used from ${usable}.${selfReason ? ` Own entry — reason: ${selfReason}` : ''}`.trim());
  await applyDue(kind, Number(vendorId));
  return null;
}

async function reject(changeId, kind, vendorId, userId, reason) {
  const c = await vendorBankChangeRepository.find(changeId);
  const why = String(reason ?? '').trim();
  if (!c || c.vendor_kind !== kind || Number(c.vendor_id) !== Number(vendorId)) return 'Bank change not found.';
  if ([...why].length < 10 || [...why].length > 300) return 'Give the reason (10 to 300 characters).';
  for (const from of ['awaiting_confirmation', 'pending']) {
    if (await vendorBankChangeRepository.move(Number(changeId), from, 'rejected', { rejected_reason: why, confirm_token_hash: null })) {
      await auditLogRepository.log(userId, 'VENDOR_BANK_CHANGE_REJECTED', vendorDetailsRepository.KINDS[kind].table, Number(vendorId), null, null, null, why);
      return null;
    }
  }
  return 'This change is no longer waiting to be checked.';
}

/** Verified changes whose waiting days are over are copied onto the supplier (verified). */
async function applyDue(kind = null, vendorId = null) {
  let n = 0;
  for (const c of await vendorBankChangeRepository.due(kind, vendorId)) {
    if (!(await vendorBankChangeRepository.move(Number(c.id), 'verified', 'applied', { applied_at: 'NOW()' }))) continue;
    const bank = {};
    for (const f of vendorDetailsRepository.BANK_FIELDS) bank[f] = c[f] ?? null;
    bank.bank_account_number = vendorBankChangeRepository.number(c);
    await vendorDetailsRepository.saveBankDetails(c.vendor_kind, Number(c.vendor_id), bank, c.entered_source, Number(c.verified_by));
    await auditLogRepository.log(Number(c.verified_by), 'VENDOR_BANK_DETAILS_SAVED', vendorDetailsRepository.KINDS[c.vendor_kind].table, Number(c.vendor_id), 'bank_account_number', null, `••••${c.account_last4}`, 'Confirmed by them, verified and its waiting days over — now in use');
    n += 1;
  }
  return n;
}

async function daily() {
  let expired = 0;
  for (const c of await vendorBankChangeRepository.overdue()) {
    if (await vendorBankChangeRepository.move(Number(c.id), 'awaiting_confirmation', 'cancelled', { cancelled_at: 'NOW()', cancelled_how: 'expired', confirm_token_hash: null })) {
      expired += 1;
      await auditLogRepository.log(null, 'VENDOR_BANK_CONFIRM_EXPIRED', vendorDetailsRepository.KINDS[c.vendor_kind].table, Number(c.vendor_id), null, null, null, `Not confirmed within ${bankDetailsCheck.CONFIRM_DAYS} days — cancelled; the old account stays`);
    }
  }
  return { expired, applied: await applyDue() };
}

function statusText(c) {
  switch (String(c.status)) {
    case 'awaiting_confirmation': return `Waiting for their confirmation — ends ${short(day(c.confirm_by))}${c.sent_to_email ? '' : ' (no registered e-mail — add it, then send the link again)'}`;
    case 'pending': return `Confirmed by them ${short(day(c.confirmed_at))} — waiting for NexaCrest to verify`;
    case 'verified': return `Verified — used from ${short(day(c.usable_from))}`;
    case 'applied': return `In use since ${short(day(c.applied_at))}`;
    case 'rejected': return `Rejected — ${c.rejected_reason || ''}`;
    case 'cancelled':
      return {
        not_me: 'Cancelled — they said "This is not us" (MD and Finance alerted)',
        expired: `Cancelled — not confirmed within ${bankDetailsCheck.CONFIRM_DAYS} days`,
        replaced: 'Cancelled — replaced by newer details',
      }[String(c.cancelled_how || '')] || 'Cancelled';
    default: return String(c.status);
  }
}

/** The "Bank details changes" panel on the supplier / logistics partner page (applies due changes first). */
async function panelData(kind, vendorId, userId) {
  await applyDue(kind, Number(vendorId));
  const changes = await vendorBankChangeRepository.forVendor(kind, Number(vendorId));
  for (const c of changes) {
    c.status_text = statusText(c);
    c.checks_text = bankDetailsCheck.checksText(c);
    c.name = name(c);
    c.own = Number(c.entered_by || 0) === Number(userId) && Number(userId) > 0;
    c.entered_on = short(day(c.entered_at));
  }
  return {
    changes,
    two: (await bankDetailsCheck.people()) === 'two',
    maySelf: await bankDetailsCheck.maySelfVerify(userId),
    base: kind === 'supplier' ? `/suppliers/${Number(vendorId)}` : `/logistics-partners/${Number(vendorId)}`,
    userId: Number(userId),
  };
}

module.exports = { EDIT, name, propose, resend, confirm, notUs, verify, reject, applyDue, daily, statusText, panelData };
