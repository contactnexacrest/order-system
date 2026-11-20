'use strict';

/**
 * Owner decision D-394 (20 Nov 2026): quick-quote prospects. Port of
 * App\Services\ProspectService — see its header for the whole rule.
 *
 * A client added with "Quick quote" on (only the company name, e-mail and
 * country of destination required) is a prospect with its own expiry date
 * (Settings › Quick-quote prospects, default 60 days, separate from the
 * Quotation's printed validity). N days before (default 7) the order's owner
 * gets ONE reminder; on the date, when Automatic closing is On, the daily job
 * moves each still-active order to "Did not go ahead" (the same service and
 * refusal rules as Mark Lost; never past the quotation stage) and deactivates
 * the client. Nothing is ever deleted.
 */

const db = require('../config/db');
const { short } = require('../helpers/dates');
const { todayIst } = require('../helpers/dbDateTime');
const clientAddress = require('../helpers/clientAddress');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientRepository = require('../repositories/clientRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const orderLostService = require('./orderLostService');

const LOST_CATEGORY = 'quotation_expired';
const NOTIFICATION_TYPE = 'prospect_expiry_reminder';
const DEFAULT_EXPIRY_DAYS = 60;
const DEFAULT_REMINDER_DAYS = 7;
/** The normally required details (D-346) — all filled = no longer a prospect. */
const REQUIRED_DETAILS = ['company_legal_name', 'billing_address_line1', 'billing_city', 'billing_country'];
const REQUIRED_MESSAGE = 'For a prospect, Company Legal Name, Email and Country of Destination are required.';
const QUOTATION_FORM_SUBJECT = 'Your quotation — please send us your company details';

async function expiryDays() {
  const v = parseInt((await companySettingsRepository.get('prospect_expiry_days')) ?? DEFAULT_EXPIRY_DAYS, 10);
  return v >= 7 && v <= 365 ? v : DEFAULT_EXPIRY_DAYS;
}

async function reminderDays() {
  const v = parseInt((await companySettingsRepository.get('prospect_reminder_days')) ?? DEFAULT_REMINDER_DAYS, 10);
  return v >= 1 && v <= 30 ? v : DEFAULT_REMINDER_DAYS;
}

async function autoClose() {
  return ((await companySettingsRepository.get('prospect_auto_close')) ?? '1') === '1';
}

function today() {
  return todayIst();
}

/** 'YYYY-MM-DD' + n calendar days. */
function addDays(date, n) {
  const [y, m, d] = String(date).slice(0, 10).split('-').map(Number);
  const t = new Date(Date.UTC(y, m - 1, d + n));
  return t.toISOString().slice(0, 10);
}

/** Days from today to the date (negative once past). */
function daysLeft(date) {
  const toUtc = (s) => { const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return Date.UTC(y, m - 1, d); };
  return Math.round((toUtc(date) - toUtc(today())) / 86400000);
}

async function freshExpiry() {
  return addDays(today(), await expiryDays());
}

/** The day the owner is reminded: the expiry date less the reminder days. */
function reminderDate(date, remindDays) {
  return addDays(date, -remindDays);
}

/** True when the date is within the reminder days (shown in red). */
function isUrgent(date, remindDays) {
  return !!date && daysLeft(date) <= remindDays;
}

/** "in 60 days" / "today" / "tomorrow" / "3 days ago". */
function relative(date) {
  const d = daysLeft(date);
  if (d === 0) return 'today';
  if (d === 1) return 'tomorrow';
  if (d > 1) return `in ${d} days`;
  if (d === -1) return '1 day ago';
  return `${-d} days ago`;
}

/** Clients list: "Quote expires 19 Jan 2027 (in 60 days)". */
function clientExpiryLine(date) {
  if (!date) return '';
  return `Quote expires ${short(date)} (${relative(date)})`;
}

/** Order card: "No reply yet · expires 19 Jan 2027", "… · expires in 5 days" when urgent. */
function orderExpiryLine(date, remindDays) {
  if (!date) return 'No reply yet';
  const d = daysLeft(date);
  if (d < 0) return `No reply yet · expired ${short(date)}`;
  if (isUrgent(date, remindDays)) return `No reply yet · expires ${d === 0 ? 'today' : (d === 1 ? 'tomorrow' : `in ${d} days`)}`;
  return `No reply yet · expires ${short(date)}`;
}

/** Empty, or only the words "To be confirmed" (any capitals, optional full stop). */
function isPlaceholder(value) {
  const v = String(value ?? '').trim().toLowerCase();
  return v === '' || /^to\s+be\s+confirmed\.?$/.test(v);
}

/**
 * True when every normally required detail is really filled in. A field that reads
 * only "To be confirmed" is the placeholder staff typed before D-394 and counts as
 * still empty (owner, 20 Nov 2026, option A), so saving such a prospect keeps it a prospect.
 */
function hasRequiredDetails(client) {
  return REQUIRED_DETAILS.every((f) => !isPlaceholder(client && client[f]));
}

/** The prospect form's own server check (company name, e-mail, country of destination); null = fine. */
function createError(body) {
  for (const f of ['company_legal_name', 'email', 'country_of_destination']) {
    if (String((body && body[f]) ?? '').trim() === '') return REQUIRED_MESSAGE;
  }
  return null;
}

/** True when the form asked for a prospect (the Quick quote box). */
function requested(body) {
  return !!(body && body.is_prospect);
}

/** The billing address of a prospect whose address is not complete: the parts there are, or "To Be Confirmed" without a Line 1. */
function partialBillingAddress(body) {
  const part = (k) => String((body && body[k]) ?? '').trim();
  if (part('billing_address_line1') === '') return 'To Be Confirmed';
  return clientAddress.compose(part('billing_address_line1'), part('billing_address_line2'), part('billing_city'), part('billing_postcode'), part('billing_country'));
}

async function markNew(clientId, userId) {
  const expires = await freshExpiry();
  await db.execute(
    'UPDATE clients SET is_prospect = 1, prospect_expires_on = :d, prospect_reminded_on = NULL, prospect_expired_on = NULL, prospect_expired_reason = NULL WHERE id = :id',
    { d: expires, id: clientId }
  );
  await auditLogRepository.log(userId, 'CLIENT_PROSPECT_CREATED', 'clients', clientId, 'prospect_expires_on', null, expires, 'Quick quote — the buyer has not sent their details yet (D-394).');
  return expires;
}

/** Every normally required detail is filled in: no longer a prospect (it never expires on its own). */
async function confirm(clientId, userId, why) {
  const client = await clientRepository.find(clientId);
  if (!client || Number(client.is_prospect || 0) !== 1) return;
  await db.execute('UPDATE clients SET is_prospect = 0, prospect_expires_on = NULL, prospect_reminded_on = NULL WHERE id = :id', { id: clientId });
  await auditLogRepository.log(userId, 'CLIENT_PROSPECT_CONFIRMED', 'clients', clientId, 'is_prospect', '1', '0', why);
}

function quotationFormEmailBody(clientName, email, link, company) {
  return `Dear ${clientName},\n\n`
    + `Thank you for your enquiry. So that we can prepare your documents, please fill in your company details on our quotation form:\n\n${link}\n\n`
    + `Please use this e-mail address (${email}) on the form, so that your details are added to your existing enquiry.\n\n`
    + `Kind regards,\n${company || 'NexaCrest'}`;
}

/** "Keep for N more days": from the later of today and the current date. */
async function extend(clientId, userId) {
  const client = await clientRepository.find(clientId);
  if (!client || Number(client.is_prospect || 0) !== 1 || Number(client.is_active) !== 1) return null;
  let from = String(client.prospect_expires_on || '');
  if (from === '' || from < today()) from = today();
  const days = await expiryDays();
  const next = addDays(from, days);
  await db.execute('UPDATE clients SET prospect_expires_on = :d, prospect_reminded_on = NULL WHERE id = :id', { d: next, id: clientId });
  await auditLogRepository.log(userId, 'CLIENT_PROSPECT_EXTENDED', 'clients', clientId, 'prospect_expires_on', client.prospect_expires_on || null, next, `Keep for ${days} more days.`);
  return next;
}

/** Reactivated: a fresh date (today + the setting); the "expired" note goes. */
async function onReactivated(clientId, userId) {
  const client = await clientRepository.find(clientId);
  const next = await freshExpiry();
  await db.execute(
    'UPDATE clients SET prospect_expires_on = :d, prospect_reminded_on = NULL, prospect_expired_on = NULL, prospect_expired_reason = NULL WHERE id = :id',
    { d: next, id: clientId }
  );
  await auditLogRepository.log(userId, 'CLIENT_PROSPECT_EXTENDED', 'clients', clientId, 'prospect_expires_on', (client && client.prospect_expires_on) || null, next, `Reactivated — a fresh ${await expiryDays()} days.`);
  return next;
}

function reactivatedMessage(name, expires) {
  return `${name} is active again. It stays a prospect until its details are filled in; it now expires on ${short(expires)}`
    + ' unless an order goes ahead. To quote again, open the old order (Did not go ahead) and use Duplicate order.';
}

/** Why the daily job must not close this order, or null: Mark Lost's own refusals, and never an order past the quotation stage. */
async function closeRefusal(order) {
  const refusal = await orderLostService.refusal(order);
  if (refusal) return refusal;
  const stage1 = await orderStageRepository.findByOrderAndStageNumber(Number(order.id), 1);
  if (stage1 && ['gate_passed', 'skipped'].includes(String(stage1.status))) return 'The order is past the quotation stage.';
  return null;
}

/** The client's orders, oldest first. */
async function orders(clientId) {
  return db.query('SELECT * FROM orders WHERE client_id = :c ORDER BY created_at, id', { c: clientId });
}

/** The first still-active order (the quotation order the client page names), else the first order. */
async function quotationOrder(clientId) {
  const list = await orders(clientId);
  return list.find((o) => o.status === 'active') || list[0] || null;
}

/** Who is reminded for an order: its assignees, else whoever created it. */
async function ownersOf(order, client) {
  let ids = (await orderAssigneeRepository.forOrder(Number(order.id))).map((a) => Number(a.user_id));
  if (ids.length === 0 && order.created_by) ids = [Number(order.created_by)];
  if (ids.length === 0 && client.created_by) ids = [Number(client.created_by)];
  return [...new Set(ids)];
}

async function reminderMessage(client, order, expires) {
  let text = `Reminder: quotation for ${client.company_legal_name} expires on ${short(expires)}`
    + (order ? ` (order ${order.order_reference})` : '') + '. No reply from the buyer yet.';
  text += (await autoClose())
    ? ` If the buyer does not go ahead, on that date the order moves to "Did not go ahead" and the client to Deactivated. Nothing is deleted, and the client can be activated again. To keep it open, use "Keep for ${await expiryDays()} more days" on the client page.`
    : ' Automatic closing is off, so nothing closes on its own. Nothing is deleted.';
  return text;
}

/** Daily: one reminder per prospect and expiry date, N days before it, to each still-active order's owner(s). Returns how many people were notified. */
async function sendReminders() {
  const days = await reminderDays();
  const auto = await autoClose();
  const clients = await db.query(
    `SELECT * FROM clients
      WHERE is_prospect = 1 AND is_active = 1 AND prospect_expires_on IS NOT NULL AND prospect_reminded_on IS NULL
        AND prospect_expires_on <= DATE_ADD(CURDATE(), INTERVAL :d DAY)`,
    { d: days }
  );
  let sent = 0;
  for (const client of clients) {
    const expires = String(client.prospect_expires_on).slice(0, 10);
    if (expires <= today() && auto) continue; // due today or past: the closing (or its "left as it is") comes instead
    const active = (await orders(client.id)).filter((o) => o.status === 'active');
    if (active.length === 0 && client.created_by) {
      await notificationRepository.create(Number(client.created_by), null, NOTIFICATION_TYPE, null, await reminderMessage(client, null, expires));
      sent++;
    }
    for (const order of active) {
      for (const uid of await ownersOf(order, client)) {
        await notificationRepository.create(uid, null, NOTIFICATION_TYPE, Number(order.id), await reminderMessage(client, order, expires));
        sent++;
      }
    }
    await db.execute('UPDATE clients SET prospect_reminded_on = CURDATE() WHERE id = :id', { id: client.id });
    await auditLogRepository.log(null, 'CLIENT_PROSPECT_REMINDED', 'clients', Number(client.id), 'prospect_reminded_on', null, today(), `Quotation expires on ${short(expires)} (D-394).`);
  }
  return sent;
}

/** "Quote expired 19 Jan 2027 — no reply in 60 days". */
function expiredReason(date, days) {
  return `Quote expired ${short(date)} — no reply in ${days} days`;
}

/**
 * Daily, when Automatic closing is On: every active prospect whose date has come. Each of its
 * active orders is marked lost (if any one may not be, or an order already went ahead, the client
 * is left as it is); then the client is deactivated. Safe to run twice.
 */
async function expireDue() {
  const out = { clients: 0, orders: 0, skipped: [] };
  if (!(await autoClose())) return out;
  const days = await expiryDays();
  const clients = await db.query(
    'SELECT * FROM clients WHERE is_prospect = 1 AND is_active = 1 AND prospect_expires_on IS NOT NULL AND prospect_expires_on <= CURDATE() ORDER BY id'
  );
  for (const client of clients) {
    const toClose = [];
    let blocked = null;
    for (const order of await orders(client.id)) {
      if (['lost', 'cancelled'].includes(order.status)) continue;
      if (order.status !== 'active') { blocked = `order ${order.order_reference} went ahead (${order.status})`; break; }
      const why = await closeRefusal(order);
      if (why) { blocked = `order ${order.order_reference}: ${why}`; break; }
      toClose.push(order);
    }
    if (blocked !== null) {
      out.skipped.push(`${client.company_legal_name} — ${blocked}`);
      continue;
    }
    const reason = `Quotation expired — no reply in ${days} days`;
    for (const order of toClose) {
      await orderLostService.markLost(Number(order.id), reason, null, LOST_CATEGORY);
      out.orders++;
    }
    const why = expiredReason(String(client.prospect_expires_on).slice(0, 10), days);
    await clientRepository.setActive(Number(client.id), false);
    await db.execute('UPDATE clients SET prospect_expired_on = CURDATE(), prospect_expired_reason = :r WHERE id = :id', { r: why, id: client.id });
    await auditLogRepository.log(null, 'CLIENT_DEACTIVATED', 'clients', Number(client.id), 'is_active', '1', '0', `${why} (closed automatically, D-394).`);
    out.clients++;
  }
  return out;
}

/** A prospect (active or deactivated) using this e-mail, case- and space-insensitive; null when none. */
async function matchingProspect(email) {
  const e = String(email ?? '').trim();
  if (e === '') return null;
  return db.queryOne('SELECT * FROM clients WHERE is_prospect = 1 AND LOWER(TRIM(email)) = LOWER(:e) ORDER BY id LIMIT 1', { e });
}

/** The client fields a Quotation request fills in => their label on the "Update this prospect" page. */
const INTAKE_FIELDS = {
  company_legal_name: 'Company Legal Name',
  billing_address_line1: 'Billing Address Line 1',
  billing_address_line2: 'Billing Address Line 2',
  billing_city: 'City / Town',
  billing_postcode: 'Postcode',
  billing_country: 'Billing Country',
  billing_address: 'Billing address (as printed)',
  vat_eori_tax_no: 'VAT / EORI / Tax Reg. No.',
  contact_person: 'Contact Person',
  email: 'Email',
  phone: 'Phone',
  country_of_destination: 'Country of Destination',
  coo_type: 'Certificate of Origin Type',
  consignee_same_as_buyer: 'Consignee same as buyer',
  consignee_name: 'Consignee Company Name',
  consignee_address_line1: 'Consignee Address Line 1',
  consignee_address_line2: 'Consignee Address Line 2',
  consignee_city: 'Consignee City / Town',
  consignee_postcode: 'Consignee Postcode',
  consignee_country: 'Consignee Country',
  consignee_vat_eori_tax_no: 'Consignee VAT / EORI / Tax Reg. No.',
  consignee_contact_person: 'Consignee Contact Person',
  consignee_phone: 'Consignee Phone',
  consignee_email: 'Consignee Email',
};

/** The client values a submission sets (the same mapping as Accept & Create Client). */
function valuesFromIntake(s) {
  const same = Number(s.consignee_same_as_buyer ?? 1) === 1;
  const v = {
    company_legal_name: s.company_legal_name,
    billing_address: s.billing_address,
    billing_address_line1: s.billing_address_line1 ?? null,
    billing_address_line2: s.billing_address_line2 ?? null,
    billing_city: s.billing_city ?? null,
    billing_postcode: s.billing_postcode ?? null,
    billing_country: s.billing_country ?? null,
    vat_eori_tax_no: s.vat_eori_tax_no ?? null,
    contact_person: s.contact_person ?? null,
    email: s.email ?? null,
    phone: s.phone ?? null,
    country_of_destination: s.country_of_destination ?? null,
    coo_type: s.coo_type || 'To Be Confirmed',
    consignee_same_as_buyer: same ? 1 : 0,
  };
  for (const f of ['consignee_name', 'consignee_address_line1', 'consignee_address_line2', 'consignee_city', 'consignee_postcode', 'consignee_country', 'consignee_vat_eori_tax_no', 'consignee_contact_person', 'consignee_phone', 'consignee_email']) {
    v[f] = same ? null : (s[f] ?? null);
  }
  return v;
}

/** What "Update this prospect" changes: [{ field, label, old, new }]. */
function intakeChanges(submission, client) {
  const changes = [];
  for (const [field, next] of Object.entries(valuesFromIntake(submission))) {
    const old = client[field] ?? null;
    if (String(old ?? '') === String(next ?? '')) continue;
    changes.push({ field, label: INTAKE_FIELDS[field] || field, old: String(old ?? ''), new: String(next ?? '') });
  }
  return changes;
}

/** Why "Update this prospect" cannot go ahead, or null. */
async function intakeUpdateRefusal(submission, client) {
  if (!client || Number(client.is_prospect || 0) !== 1) return 'This request no longer matches a prospect.';
  if (String(client.email || '').trim().toLowerCase() !== String(submission.email || '').trim().toLowerCase()) return 'This request no longer matches the prospect\'s e-mail.';
  if (Number(client.is_data_locked || 0) === 1) return 'This client\'s details are locked and cannot be updated from a request.';
  const blocker = await require('./associateIntakeService').acceptBlocker(submission);
  if (blocker) return blocker;
  const other = await clientRepository.findOtherWithEmail(submission.email, Number(client.id));
  if (other) return clientRepository.duplicateEmailMessage(submission.email, other);
  return null;
}

/**
 * "Update this prospect": the request's details go onto the prospect (same id, Buyer Inquiry Ref and
 * orders), the prospect badge goes, a deactivated one is reactivated, and the request is marked
 * accepted and linked to it.
 */
async function applyIntake(submission, client, userId) {
  const clientId = Number(client.id);
  const changes = intakeChanges(submission, client);
  const values = valuesFromIntake(submission);
  const set = Object.keys(values).map((f) => `${f} = :${f}`).join(', ');
  await db.execute(`UPDATE clients SET ${set} WHERE id = :id`, { ...values, id: clientId });
  const why = `From Quotation request no. ${submission.id} (Update this prospect, D-394).`;
  for (const c of changes) {
    await auditLogRepository.log(userId, 'CLIENT_UPDATED', 'clients', clientId, c.field, c.old !== '' ? c.old : null, c.new !== '' ? c.new : null, why);
  }
  await db.execute(
    'UPDATE clients SET is_prospect = 0, prospect_expires_on = NULL, prospect_reminded_on = NULL, prospect_expired_on = NULL, prospect_expired_reason = NULL WHERE id = :id',
    { id: clientId }
  );
  await auditLogRepository.log(userId, 'CLIENT_PROSPECT_CONFIRMED', 'clients', clientId, 'is_prospect', '1', '0', why);
  let reactivated = false;
  if (Number(client.is_active) !== 1) {
    await clientRepository.setActive(clientId, true);
    await auditLogRepository.log(userId, 'CLIENT_REACTIVATED', 'clients', clientId, 'is_active', '0', '1', why);
    reactivated = true;
  }
  await require('../repositories/clientIntakeRepository').markConverted(Number(submission.id), clientId, userId);
  await auditLogRepository.log(userId, 'CLIENT_INTAKE_ACCEPTED', 'client_intake_submissions', Number(submission.id), 'converted_client_id', 'pending', String(clientId), 'Linked to the existing prospect (Update this prospect, D-394).');
  const associate = await require('./associateIntakeService').onAccepted(submission, clientId, userId);
  const refresh = await require('./buyerDetailsRefreshService').afterClientSaved(clientId, userId, 'quotation request');
  return { changes: changes.length, reactivated, associate, refresh };
}

/** What the views need to draw prospect badges, dates and buttons (one call per request). */
async function viewSettings() {
  return { expiryDays: await expiryDays(), reminderDays: await reminderDays(), autoClose: await autoClose() };
}

module.exports = {
  LOST_CATEGORY, NOTIFICATION_TYPE, DEFAULT_EXPIRY_DAYS, DEFAULT_REMINDER_DAYS, REQUIRED_DETAILS, REQUIRED_MESSAGE, QUOTATION_FORM_SUBJECT, INTAKE_FIELDS,
  expiryDays, reminderDays, autoClose, today, addDays, daysLeft, freshExpiry, reminderDate, isUrgent, relative, clientExpiryLine, orderExpiryLine,
  hasRequiredDetails, isPlaceholder, createError, requested, partialBillingAddress, markNew, confirm, quotationFormEmailBody, extend, onReactivated, reactivatedMessage,
  closeRefusal, orders, quotationOrder, reminderMessage, sendReminders, expiredReason, expireDue, matchingProspect, valuesFromIntake, intakeChanges,
  intakeUpdateRefusal, applyIntake, viewSettings,
};
