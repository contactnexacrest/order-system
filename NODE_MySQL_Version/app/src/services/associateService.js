'use strict';

const db = require('../config/db');
const env = require('../config/env');
const dates = require('../helpers/dates');
const associateEventRepository = require('../repositories/associateEventRepository');
const associateInvitationRepository = require('../repositories/associateInvitationRepository');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const incentiveRepository = require('../repositories/incentiveRepository');
const associateCodeService = require('./associateCodeService');
const emailService = require('./emailService');

/**
 * Port of AssociateService.php. Owner decision 1 Nov 2026 ("AS all as
 * recommended"): the associate's portal access, the link between a client
 * and the associate who brought it (with its 12-month lock and the buyer's
 * own confirmation), and the notices and e-mails that keep the associate
 * informed.
 */

const MONTHS3 = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function todayIst() {
  return new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);
}

/** PHP strtotime("+N months") on a Y-m-d date (day overflow rolls forward the same way). */
function addMonths(ymd, n) {
  const d = new Date(`${String(ymd).slice(0, 10)}T00:00:00Z`);
  d.setUTCMonth(d.getUTCMonth() + n);
  return d.toISOString().slice(0, 10);
}

function addDays(ymd, n) {
  const d = new Date(`${String(ymd).slice(0, 10)}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

/** PHP date('j M Y') — "1 Nov 2026". */
function jMY(ymd = null) {
  const [y, m, d] = String(ymd || todayIst()).slice(0, 10).split('-').map(Number);
  return `${d} ${MONTHS3[m - 1]} ${y}`;
}

function appUrl() {
  return String(env.get('APP_URL', '') || '').replace(/\/+$/, '');
}

async function protectionMonths() {
  const v = parseInt((await incentiveRepository.settings()).associate_protection_months ?? 12, 10);
  return v > 0 ? v : 12;
}

async function bankWaitDays() {
  const v = (await incentiveRepository.settings()).associate_bank_wait_days ?? '7';
  return Math.max(0, parseInt(v, 10) || 0);
}

async function tdsPct() {
  return Number((await incentiveRepository.settings()).associate_tds_pct ?? 2.0);
}

const SUBJECTS = {
  client_accepted: 'your client is registered', client_not_accepted: 'a registration was not accepted',
  direct_contact: 'your client contacted us', order_created: 'new order from your client',
  commission_earned: 'commission earned', payout_paid: 'commission paid', payout_held: 'commission on hold',
  clawback: 'commission adjustment', bank_changed: 'your bank details changed', link_changed: 'a client link changed',
  lock_ending: 'protection ending soon', agreement_ending: 'agreement ending soon', portal_invite: 'your portal access',
};

/** A notice in the associate's portal and, where useful, an e-mail. */
async function notify(associate, event, message, clientId = null, orderId = null, invitationId = null, userId = null, email = true) {
  await associateEventRepository.add(Number(associate.id), event, message, clientId, orderId, invitationId, userId, true);
  if (email && associate.email) {
    try {
      await emailService.sendPlainText(String(associate.email), `NexaCrest associate portal — ${SUBJECTS[event] || 'update'}`,
        `Hello ${associate.name},\n\n${message}\n\nSee the details in your associate portal: ${appUrl()}/associate\n\nNexaCrest International Private Limited`);
    } catch (e) {
      // The notice is in the portal either way; a mail failure must not undo the action.
    }
  }
}

/** Sends (or resends) the set-password link and switches the portal on; makes the personal code if there is none. */
async function invitePortal(associateId, userId) {
  const a = await associateRepository.find(associateId);
  if (!a || !a.email) {
    throw new Error('Enter the associate\'s e-mail first — it is their portal login.');
  }
  await associateRepository.setFields(associateId, { portal_enabled: 1 });
  if (!a.personal_code) {
    await associateCodeService.issue(associateId, String(a.name));
  }
  const associatePortalService = require('./associatePortalService');
  const raw = await associatePortalService.issueToken(associateId, 'set_password', 72 * 60);
  const link = `${appUrl()}/associate/set-password/${raw}`;
  try {
    await emailService.sendPlainText(String(a.email), 'Your NexaCrest associate portal',
      `Hello ${a.name},\n\nNexaCrest has opened your associate portal. There you can see the clients you introduced, their orders and stages, your commission and payments, and invite new clients.\n\n`
      + `Set your password within 72 hours:\n${link}\n\nYour login e-mail: ${a.email}\n\nNexaCrest International Private Limited`);
  } catch (e) {
    // shown on screen in development; staff can resend
  }
  await auditLogRepository.log(userId, 'ASSOCIATE_PORTAL_INVITED', 'associates', associateId, null, null, String(a.email));
  await associateEventRepository.add(associateId, 'portal_invite', `Portal access sent to ${a.email}.`, null, null, null, userId, false);
  return link;
}

/**
 * Links a client to the associate who brought it: the buyer's own
 * registration (through a link or the personal code) or a staff link.
 * The 12-month lock starts now.
 */
async function linkClient(clientId, associateId, source, invitationId, registeredOn, userId) {
  const lockUntil = addMonths(todayIst(), await protectionMonths());
  await db.execute(
    'UPDATE clients SET associate_id = :a, associate_registered_on = :d, associate_locked_until = :l, associate_source = :s, associate_invitation_id = :i WHERE id = :id',
    { a: associateId, d: registeredOn || todayIst(), l: lockUntil, s: source, i: invitationId ?? null, id: clientId }
  );
}

/**
 * Owner decision 1 Nov 2026 (guarantee 3): only the MD changes or removes
 * a client's associate, with a written reason; the associate is told at once.
 * Takes the request (the user's own roles include department roles).
 */
async function canChangeLink(req) {
  const superAdminService = require('./superAdminService');
  if (await superAdminService.isEffective(req.user.id)) {
    return true;
  }
  const grantGuard = require('./grantGuard');
  const roleRepository = require('../repositories/roleRepository');
  for (const roleId of await grantGuard.ownRoleIds(req)) {
    const role = await roleRepository.find(roleId);
    if (role && role.name === 'Managing Director') {
      return true;
    }
  }
  return false;
}

/** Called when an order is created: fixes the associate's rate on it (AS-17) and tells the associate. */
async function onOrderCreated(orderId, executor = db) {
  const o = await executor.queryOne(
    `SELECT o.id, o.client_id, o.order_reference, o.status, o.is_test_data, o.is_sample_data, c.associate_id, c.company_legal_name
       FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.id = :id`,
    { id: orderId }
  );
  if (!o || !o.associate_id) return;
  const a = await associateRepository.find(Number(o.associate_id));
  if (!a) return;
  const number = await associateRepository.orderNumberForClient(Number(a.id), Number(o.client_id));
  const rate = await associateRepository.rateWithSource(a, number);
  await executor.execute(
    'UPDATE orders SET associate_id = :a, associate_rate_pct = :r, associate_rate_source = :s, associate_client_number = :n WHERE id = :id',
    { a: a.id, r: rate.rate, s: rate.source, n: number, id: orderId }
  );
  if (Number(o.is_test_data) === 1 || Number(o.is_sample_data) === 1) return;
  await notify(a, 'order_created', `New order ${o.order_reference} from your client ${o.company_legal_name}. Commission rate fixed on it: `
    + `${rate.rate.toFixed(2)}% (${rate.source === 'personal' ? 'personal' : (rate.source === 'none' ? 'no commission' : 'standard')}).`, Number(o.client_id), orderId);
}

/**
 * After an associate payout is paid: the claw-back lines due for the same
 * associate (up to this month) are settled by deduction, and the associate
 * is told the amount, TDS and bank reference (owner rule D-212).
 */
async function onPayoutPaid(payoutId, userId) {
  const p = await incentiveRepository.findPayout(payoutId);
  if (!p || p.payee_kind !== 'associate') return;
  await db.execute(
    `UPDATE incentive_payouts SET status = 'paid', paid_on = :d, paid_inr = amount_inr, paid_by = :u, paid_via = :via, paid_reference = :ref
      WHERE associate_id = :a AND payee_kind = 'associate' AND clawback_of_payout_id IS NOT NULL AND status = 'due' AND incentive_month <= :m`,
    { d: p.paid_on, u: userId, via: p.paid_via ?? null, ref: `Deducted from payout no. ${payoutId}`, a: p.associate_id, m: p.incentive_month }
  );
  const a = await associateRepository.find(Number(p.associate_id));
  const orderRepository = require('../repositories/orderRepository');
  const order = await orderRepository.find(Number(p.order_id));
  if (a) {
    const fmt = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    await notify(a, 'payout_paid', `Commission paid for order ${order ? order.order_reference : p.order_id}: INR ${fmt(p.paid_inr)}`
      + (Number(p.tds_inr || 0) > 0 ? ` (TDS deducted INR ${fmt(p.tds_inr)})` : '')
      + ` on ${dates.short(String(p.paid_on))}${p.paid_reference ? `, bank reference ${p.paid_reference}` : ''}.`, null, Number(p.order_id), null, userId);
  }
}

/** Daily: protection and agreement reminders (30 and 7 days), expired links. */
async function dailyReminders(today = null) {
  const day0 = today || todayIst();
  await associateInvitationRepository.expireOld();
  let sent = 0;
  for (const days of [30, 7]) {
    const day = addDays(day0, days);
    const clientRows = await db.query(
      `SELECT c.id, c.company_legal_name, c.associate_id, c.associate_locked_until FROM clients c
        WHERE c.associate_id IS NOT NULL AND c.associate_locked_until = :d
          AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.client_id = c.id AND o.status NOT IN ('cancelled', 'lost'))`,
      { d: day }
    );
    for (const c of clientRows) {
      const a = await associateRepository.find(Number(c.associate_id));
      if (a && !(await associateEventRepository.hasEvent(Number(a.id), `lock_ending_${days}`, Number(c.id), null))) {
        await notify(a, 'lock_ending', `Your protection for ${c.company_legal_name} ends on ${dates.short(String(c.associate_locked_until))} (${days} days) and the client has not ordered yet. Ask your relationship manager if you need an extension.`, Number(c.id));
        await associateEventRepository.add(Number(a.id), `lock_ending_${days}`, `Reminder sent (${days} days).`, Number(c.id), null, null, null, false);
        sent++;
      }
    }
    for (const a of await db.query("SELECT * FROM associates WHERE agreement_ends_on = :d AND status = 'active'", { d: day })) {
      if (!(await associateEventRepository.hasEvent(Number(a.id), `agreement_ending_${days}`, null, null))) {
        await notify(a, 'agreement_ending', `Your associate agreement ends on ${dates.short(day)} (${days} days). Your relationship manager will contact you about renewal.`);
        await associateEventRepository.add(Number(a.id), `agreement_ending_${days}`, `Reminder sent (${days} days).`, null, null, null, null, false);
        sent++;
      }
    }
  }
  return sent;
}

module.exports = {
  todayIst, addMonths, addDays, jMY, appUrl, protectionMonths, bankWaitDays, tdsPct, notify, invitePortal, linkClient, canChangeLink,
  onOrderCreated, onPayoutPaid, dailyReminders,
};
