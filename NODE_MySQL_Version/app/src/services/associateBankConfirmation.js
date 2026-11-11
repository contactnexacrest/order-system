'use strict';

const crypto = require('crypto');
const { short } = require('../helpers/dates');
const associateBankRepository = require('../repositories/associateBankRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const bankDetailsCheck = require('./bankDetailsCheck');

/**
 * Port of AssociateBankConfirmation.php. Owner decisions 11 Nov 2026, point 18
 * (D-293): the associate confirms new bank details before NexaCrest verifies
 * them — even when they asked for the change themselves.
 *
 * Entered (in the portal, or by staff from the signed form) → a bar on every
 * portal page and an e-mail → on the confirm page they compare the current and
 * the new account, re-type the last 4 digits of the new account, enter a fresh
 * 6-digit code sent to their REGISTERED e-mail and tick "These are my bank
 * details" → Confirm (staff may now verify) or "This is not me — cancel it"
 * (cancelled; the MD and Finance are alerted). No answer in 7 days: cancelled
 * by itself; the old account stays.
 */

const NOT_ME_MESSAGE = 'Cancelled. Your bank details have not changed. We have told our Managing Director and Finance team. If someone contacted you about your bank details, please reply in Messages.';

const associateService = () => require('./associateService');
const day = (v) => String(v || '').slice(0, 10);
const sha256 = (s) => crypto.createHash('sha256').update(String(s)).digest('hex');
const name = (b) => `${associateBankRepository.masked(b)} (${b.account_currency})`;

async function askMessage(b, byAssociate) {
  return `${byAssociate ? 'You asked' : 'NexaCrest recorded, from your signed form,'} to change your bank details to ${name(b)} on ${short(day(b.requested_at))}. `
    + `Please confirm them in your associate portal (Profile › Bank details) by ${short(day(b.confirm_by))} — your commission is paid to them only after you confirm and NexaCrest has checked them. `
    + `${await associateService().bankPaymentsSentence(Number(b.associate_id))} If you did not ask for this, open the portal and click "This is not me — cancel it", or contact NexaCrest at once.`;
}

/** A request was entered: it waits for the associate's confirmation (7 days) and they are e-mailed (the MUST bank-changed alert). */
async function start(bankId, associate, byAssociate, userId) {
  await associateBankRepository.markAwaiting(bankId, Number(associate.id), bankDetailsCheck.CONFIRM_DAYS);
  const b = await associateBankRepository.find(bankId);
  await auditLogRepository.log(userId ?? null, 'ASSOCIATE_BANK_CHANGE_REQUESTED', 'associate_bank_accounts', Number(bankId), 'status', null, 'awaiting_confirmation', byAssociate ? 'by associate' : 'by staff');
  // ACCESS / SECURITY-CRITICAL e-mail (MUST — the bank-changed alert): always sent through the existing mail send.
  await associateService().notify(associate, 'bank_changed', await askMessage(b, byAssociate), null, null, null, userId ?? null);
}

/** The open request for the portal bar and the confirm page (null when nothing waits). */
async function open(associateId) {
  return associateBankRepository.awaiting(Number(associateId));
}

/**
 * A fresh 6-digit code to the associate's REGISTERED e-mail; valid 10 minutes.
 * ACCESS / SECURITY-CRITICAL e-mail (MUST, a one-time code): sent on the security path of the existing mail send.
 */
async function sendCode(associate, b, req = null) {
  if (!associate.email) return false;
  const code = String(crypto.randomInt(0, 1000000)).padStart(6, '0');
  await associateBankRepository.setCode(Number(b.id), sha256(code), bankDetailsCheck.CODE_MINUTES);
  await require('./emailService').sendPlainText(String(associate.email), 'NexaCrest associate portal — your code to confirm new bank details',
    `Hello ${associate.name},\n\nYour code to confirm the new bank details ${name(b)} is:\n\n${code}\n\n`
    + `It is valid for ${bankDetailsCheck.CODE_MINUTES} minutes. Never share it — NexaCrest staff will never ask you for it.\n`
    + 'If you did not ask for new bank details, open your portal and click "This is not me — cancel it".\n\nNexaCrest International Private Limited', { isSecurityEmail: true, kind: 'bank_confirm_code', audience: 'associate' });
  if (req && req.session && require('./associatePortalService').showCodeOnScreen()) {
    req.session.associateBankCodeDev = code; // dev servers only, as with the login code
  }
  return true;
}

/** "You can be paid to them from about … Until then, payments go to your current account." */
async function usableSentence(associateId) {
  const first = !(await associateBankRepository.hasVerified(Number(associateId)));
  const wait = await bankDetailsCheck.waitDays(first);
  const from = associateService().addDays(associateService().todayIst(), wait);
  return `You can be paid to them from about ${short(from)}${wait > 0 ? ` (we always wait ${wait} days after ${first ? 'adding a first account' : 'a change'}, for your safety)` : ''}. `
    + (first ? '' : 'Until then, payments go to your current account.');
}

/** The associate confirms. Returns the error shown beside the form, or null. */
async function confirm(associate, bankId, last4, code, ticked) {
  const b = await associateBankRepository.find(bankId);
  if (!b || Number(b.associate_id) !== Number(associate.id) || b.status !== 'awaiting_confirmation') {
    return 'These bank details are no longer waiting for your confirmation.';
  }
  if (!(await associateBankRepository.awaiting(Number(associate.id)))) {
    return 'The 7 days to confirm are over — the change was cancelled. Send the new details again if you still want them.';
  }
  if (!ticked) return 'Tick "These are my bank details" to confirm.';
  if (String(last4 ?? '').replace(/\D/g, '') !== String(b.account_last4)) {
    return 'The last 4 digits do not match the new account number — check them and type them again.';
  }
  if (!b.confirm_code_hash || !(await associateBankRepository.codeLive(Number(b.id)))) {
    return `Ask for a code first (or a new one — a code is valid for ${bankDetailsCheck.CODE_MINUTES} minutes).`;
  }
  if (Number(b.confirm_code_attempts) >= bankDetailsCheck.CODE_ATTEMPTS) return 'Too many wrong codes — ask for a new code.';
  const given = sha256(String(code ?? '').replace(/\D/g, ''));
  if (!crypto.timingSafeEqual(Buffer.from(String(b.confirm_code_hash)), Buffer.from(given))) {
    await associateBankRepository.addCodeAttempt(Number(b.id));
    return 'The code is not right — check the e-mail we sent you, or ask for a new code.';
  }
  if (!(await associateBankRepository.markConfirmed(Number(b.id)))) return 'These bank details are no longer waiting for your confirmation.';
  await auditLogRepository.log(null, 'ASSOCIATE_BANK_CONFIRMED', 'associate_bank_accounts', Number(b.id), 'status', 'awaiting_confirmation', 'pending', 'Confirmed by the associate (last 4 digits + e-mailed code)');
  await associateService().notify(associate, 'bank_changed', `Thank you — you confirmed your new bank details ${name(b)}. NexaCrest will now check them. ${await usableSentence(associate.id)}`, null, null, null, null, false);
  const notificationRepository = require('../repositories/notificationRepository');
  for (const uid of await require('../repositories/permissionRepository').usersWithPermission('manage_associate_bank')) {
    await notificationRepository.createInAppOnly(Number(uid), 'associate_bank_change', null, `Associate ${associate.name} confirmed new bank details (${name(b)}) — verify them on their page.`);
  }
  return null;
}

/** "This is not me — cancel it": cancelled at once; the MD and Finance are alerted. */
async function notMe(associate, bankId) {
  const b = await associateBankRepository.find(bankId);
  if (!b || Number(b.associate_id) !== Number(associate.id) || !(await associateBankRepository.cancel(Number(bankId), 'not_me'))) return false;
  await auditLogRepository.log(null, 'ASSOCIATE_BANK_NOT_ME', 'associate_bank_accounts', Number(bankId), 'status', String(b.status), 'cancelled', 'The associate said "This is not me" — possible fraud');
  await associateService().notify(associate, 'bank_rejected', NOT_ME_MESSAGE, null, null, null, null, false);
  const enterer = Number(b.requested_by_associate) === 1 ? null : await require('../repositories/userRepository').findById(Number(b.requested_by_user || 0));
  const by = Number(b.requested_by_associate) === 1 ? 'in the portal' : `by ${(enterer && enterer.name) || 'NexaCrest staff'}`;
  await bankDetailsCheck.alertMdAndFinance(`Associate bank change refused — possible fraud: ${associate.name}`,
    `Associate ${associate.name} clicked "This is not me — cancel it" for the new bank details ${name(b)}, entered ${by} on ${short(day(b.requested_at))}.\n`
    + 'The change is cancelled and their bank details have not changed. Please find out who entered them.\n'
    + `Associate page: ${associateService().appUrl()}/associates/${Number(associate.id)}#bank`);
  return true;
}

/** Staff "Remind associate": the confirmation e-mail again. */
async function remind(bankId, userId) {
  const b = await associateBankRepository.find(bankId);
  if (!b || b.status !== 'awaiting_confirmation') return false;
  const a = await require('../repositories/associateRepository').find(Number(b.associate_id));
  if (!a) return false;
  await associateBankRepository.markReminded(Number(bankId));
  await auditLogRepository.log(userId, 'ASSOCIATE_BANK_CONFIRM_REMINDED', 'associate_bank_accounts', Number(bankId));
  await associateService().notify(a, 'bank_changed', `Reminder: ${await askMessage(b, Number(b.requested_by_associate) === 1)}`, null, null, null, userId);
  return true;
}

/** Daily: requests not confirmed in 7 days are cancelled; the old account stays. Returns how many. */
async function expireOverdue() {
  let n = 0;
  for (const b of await associateBankRepository.overdue()) {
    if (!(await associateBankRepository.cancel(Number(b.id), 'expired'))) continue;
    n += 1;
    await auditLogRepository.log(null, 'ASSOCIATE_BANK_CONFIRM_EXPIRED', 'associate_bank_accounts', Number(b.id), 'status', 'awaiting_confirmation', 'cancelled', `Not confirmed within ${bankDetailsCheck.CONFIRM_DAYS} days`);
    const a = await require('../repositories/associateRepository').find(Number(b.associate_id));
    if (a) {
      await associateService().notify(a, 'bank_rejected', `The new bank details ${name(b)} were not confirmed within ${bankDetailsCheck.CONFIRM_DAYS} days, so the change was cancelled. `
        + `${await associateService().bankPaymentsSentence(Number(a.id))} Send the new details again in your portal if you still want them.`);
    }
  }
  return n;
}

function staffStatus(b) {
  switch (String(b.status)) {
    case 'awaiting_confirmation': return `Waiting for the associate — ends ${short(day(b.confirm_by))}`;
    case 'pending': return `Confirmed by the associate${b.confirmed_at ? ` ${short(day(b.confirmed_at))}` : ''} — waiting for NexaCrest to verify`;
    case 'verified': return `Verified${b.usable_from ? ` — used from ${short(day(b.usable_from))}` : ''}`;
    case 'cancelled':
      return {
        not_me: 'Cancelled — the associate said "This is not me" (MD and Finance alerted)',
        expired: `Cancelled — not confirmed within ${bankDetailsCheck.CONFIRM_DAYS} days`,
        replaced: 'Cancelled — replaced by newer details',
      }[String(b.cancelled_how || '')] || 'Cancelled';
    case 'rejected': return 'Rejected';
    case 'superseded': return 'Replaced';
    default: return String(b.status).charAt(0).toUpperCase() + String(b.status).slice(1);
  }
}

function associateStatus(b, today) {
  switch (String(b.status)) {
    case 'awaiting_confirmation': return `Waiting for your confirmation — by ${short(day(b.confirm_by))}`;
    case 'pending': return 'You confirmed — being checked by NexaCrest';
    case 'verified': return `Verified${day(b.usable_from) > day(today) ? ` — used from ${short(day(b.usable_from))}` : ''}`;
    case 'rejected': return `Not accepted: ${b.rejected_reason}`;
    case 'cancelled': return b.cancelled_how === 'expired' ? 'Cancelled — not confirmed in time' : 'Cancelled';
    case 'superseded': return 'Replaced';
    default: return String(b.status);
  }
}

module.exports = { NOT_ME_MESSAGE, askMessage, start, open, sendCode, usableSentence, confirm, notMe, remind, expireOverdue, staffStatus, associateStatus };
