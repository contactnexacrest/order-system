'use strict';

const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const mailSenderService = require('./mailSenderService');

/**
 * Owner decision 2 Nov 2026 (D-233): when a Zoho Books sync run — scheduled,
 * Sync now, or "Sync this order" — has failures, Finance hears about it once
 * for that run: one e-mail to every active holder of ca_module_manage with an
 * e-mail address (only while business e-mail is on) and an in-app
 * notification to each of them either way. The list carries record type,
 * order and Zoho's error message — never amounts. PHP twin:
 * ZohoSyncFailureNotifier.php.
 */
const state = { sender: null }; // test hook, replaces mailSenderService.send

/** The last sync-log id before a run starts; failures after it belong to the run. */
async function mark() {
  const row = await db.queryOne('SELECT COALESCE(MAX(id), 0) AS id FROM zoho_sync_log');
  return Number(row.id);
}

function label(r) {
  const type = String(r.sync_type).replace(/_/g, ' ');
  const cap = type.charAt(0).toUpperCase() + type.slice(1);
  if (r.order_ref) return `${cap} — order ${r.order_ref}${r.leg ? ` (${r.leg})` : ''}`;
  if (r.entity_type && r.entity_id) return `${cap} — ${String(r.entity_type).replace(/_/g, ' ')} #${r.entity_id}`;
  return cap;
}

function stamp(d = new Date()) {
  const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const p = (n) => String(n).padStart(2, '0');
  return `${p(d.getDate())} ${m[d.getMonth()]} ${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** @return {Promise<{failures:number, notified:number, emailed:number, mail_off:boolean}>} */
async function afterRun(sinceLogId, triggeredBy, orderId = null) {
  const out = { failures: 0, notified: 0, emailed: 0, mail_off: false };
  const rows = await db.query(
    `SELECT l.id, l.sync_type, l.entity_type, l.entity_id, l.leg, l.message, o.buyer_inquiry_ref AS order_ref
       FROM zoho_sync_log l
       LEFT JOIN orders o ON o.id = l.entity_id AND l.entity_type IN ('order', 'order_payment_status')
      WHERE l.id > :since AND l.status = 'error'
      ORDER BY l.id`,
    { since: sinceLogId }
  );
  out.failures = rows.length;
  if (rows.length === 0) return out;
  const how = { scheduled: 'the scheduled (hourly) sync', manual: orderId !== null ? '"Sync this order to Zoho now"' : 'Sync now' }[triggeredBy] || `${triggeredBy} sync`;
  const subject = `Zoho Books sync: ${rows.length} record${rows.length === 1 ? '' : 's'} could not be sent`;
  const body = 'Zoho Books sync failures\n\n'
    + `Run: ${how} at ${stamp()}${orderId !== null ? ' (one order only)' : ''}.\n`
    + `${rows.length} record(s) could not be sent to Zoho Books. They stay waiting and the next sync tries them again.\n\n`
    + rows.map((r) => `- ${label(r)}: ${String(r.message || '').trim()}`).join('\n')
    + '\n\nOpen Zoho Sync in the app to see the Sync Log and retry (Sync now).\n';
  const notice = `${subject} — see Zoho Sync (Sync Log).`;

  const recipients = [...new Set((await permissionRepository.usersWithPermission('ca_module_manage')).map(Number))];
  const mailOn = await require('./emailPolicy').allows('staff_zoho', 'staff'); // D-280: Admin › E-mail settings
  out.mail_off = !mailOn;
  const users = recipients.length
    ? await db.query(`SELECT id, email FROM users WHERE is_active = 1 AND id IN (${recipients.join(',')})`)
    : [];
  for (const u of users) {
    await notificationRepository.createInAppOnly(Number(u.id), 'zoho_sync_failed', orderId, notice);
    out.notified += 1;
    if (mailOn && String(u.email || '').trim() !== '') {
      const ok = state.sender ? await state.sender(String(u.email), subject, body) : await mailSenderService.send(String(u.email), subject, body, [], { kind: 'staff_zoho', audience: 'staff' });
      out.emailed += ok ? 1 : 0;
    }
  }
  return out;
}

module.exports = { mark, afterRun, state };
