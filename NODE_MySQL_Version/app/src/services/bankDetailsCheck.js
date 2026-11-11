'use strict';

const db = require('../config/db');
const incentiveRepository = require('../repositories/incentiveRepository');
const notificationRepository = require('../repositories/notificationRepository');

/**
 * Port of BankDetailsCheck.php. Owner decisions 11 Nov 2026, points 10 and 18
 * (D-293 / D-294): the rules for checking new bank details, shared by
 * associates and suppliers / logistics partners.
 *
 * - The account holder confirms first (associate: in the portal with a code;
 *   supplier: through a link e-mailed to the registered e-mail) within 7 days,
 *   else the change is cancelled and the old account stays.
 * - Then ONE person (setting: or two people) other than the person who entered
 *   the details verifies them, ticking at least 2 of: matches the bank proof /
 *   phone call on the registered number / test payment with its reference.
 *   A note is optional. Someone allowed by Admin › Self-approval rules › "Bank
 *   details" (seeded MD, ED) may verify their own entry, with a reason.
 * - The account is used after a wait: first account 0 days, change of account
 *   15 days (both 0–90).
 */

const CONFIRM_DAYS = 7;
const CODE_MINUTES = 10;
const CODE_ATTEMPTS = 5;

const CHECKS = {
  check_bank_proof: 'Matches the bank proof on file (cancelled cheque / bank statement)',
  check_phone: 'Called them on their registered phone number and they confirmed the last 4 digits',
  check_test_payment: 'Test payment of ₹1 / USD 1 received in the right name — bank reference',
};

const ALERT_ROLES = ['Managing Director', 'Finance Director', 'Finance Manager'];

const len = (s) => [...String(s ?? '')].length;

async function people(settings = null) {
  const s = settings || await incentiveRepository.settings();
  return (s.bank_details_check_people || 'one') === 'two' ? 'two' : 'one';
}

async function waitDays(firstAccount, settings = null) {
  const s = settings || await incentiveRepository.settings();
  const v = firstAccount ? (s.associate_bank_wait_first_days ?? '0') : (s.associate_bank_wait_change_days ?? '15');
  return Math.max(0, Math.min(90, parseInt(v, 10) || 0));
}

/** The "how did you check it" ticks: at least 2, and the test payment needs its reference. [checks, error] */
function readChecks(post) {
  const p = post || {};
  const ticks = {};
  for (const k of Object.keys(CHECKS)) ticks[k] = p[k] ? 1 : 0;
  const ref = String(p.test_payment_ref ?? '').trim();
  const note = String(p.verify_note ?? '').trim();
  if (ticks.check_bank_proof + ticks.check_phone + ticks.check_test_payment < 2) {
    return [null, 'Tick at least two of the ways you checked the bank details.'];
  }
  if (ticks.check_test_payment === 1 && (len(ref) < 3 || len(ref) > 60)) {
    return [null, 'Enter the bank reference (UTR / SWIFT) of the test payment.'];
  }
  if (len(note) > 300) return [null, 'The note can be up to 300 characters.'];
  return [{ ...ticks, test_payment_ref: ticks.check_test_payment === 1 ? ref : null, verify_note: note !== '' ? note : null }, null];
}

function checksText(row) {
  const parts = [];
  if (Number(row.check_bank_proof)) parts.push('bank proof');
  if (Number(row.check_phone)) parts.push('phone call on the registered number');
  if (Number(row.check_test_payment)) parts.push(`test payment${row.test_payment_ref ? ` (ref ${row.test_payment_ref})` : ''}`);
  return parts.join(', ');
}

/** Refusal for verifying details one entered oneself (allowed only with the "bank_details" self-approval rule and a reason). */
async function selfCheckError(enteredBy, userId, selfReason) {
  if (enteredBy === null || enteredBy === undefined || Number(enteredBy) !== Number(userId)) return null;
  if (!(await require('./selfApprovalRules').allowed('bank_details', Number(userId)))) return 'Someone else must verify details you entered.';
  if (len(String(selfReason ?? '').trim()) < 10) return 'You entered these details yourself — give the reason you verify them (at least 10 characters).';
  return null;
}

async function maySelfVerify(userId) {
  return require('./selfApprovalRules').allowed('bank_details', Number(userId));
}

function maskEmail(email) {
  const [local, domain] = String(email || '').split('@');
  return `${[...String(local || '')].slice(0, 1).join('')}•••@${domain || ''}`;
}

/**
 * Tells the MD and Finance (in-app and e-mail) — e.g. "This is not me" on a bank change.
 * ACCESS / SECURITY-CRITICAL e-mail (MUST, never switched off): sent through the existing mail send.
 */
async function alertMdAndFinance(subject, message) {
  const rows = await db.query(
    `SELECT DISTINCT u.id, u.email FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name IN (${ALERT_ROLES.map((_, i) => `:r${i}`).join(', ')}) AND u.is_active = 1 ORDER BY u.id`,
    Object.fromEntries(ALERT_ROLES.map((r, i) => [`r${i}`, r]))
  );
  for (const u of rows) {
    await notificationRepository.createInAppOnly(Number(u.id), 'bank_change_refused', null, [...message].slice(0, 500).join(''));
    if (u.email) {
      try {
        await require('./emailService').sendPlainText(String(u.email), subject, `${message}\n\nNexaCrest order system`);
      } catch (e) {
        // The in-app alert stands; a mail failure must not undo the cancellation.
      }
    }
  }
}

module.exports = {
  CONFIRM_DAYS, CODE_MINUTES, CODE_ATTEMPTS, CHECKS, ALERT_ROLES,
  people, waitDays, readChecks, checksText, selfCheckError, maySelfVerify, maskEmail, alertMdAndFinance,
};
