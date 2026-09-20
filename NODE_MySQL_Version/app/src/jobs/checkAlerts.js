'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const userRepository = require('../repositories/userRepository');
const db = require('../config/db');

/**
 * Port of app/cron/check_alerts.php. Spec Section 10 — "AUTO-NOTIFICATIONS"
 * (LUT/RCMC expiry, FDN payment overdue) plus Section 16's dispute response
 * timer. Run once daily (a `node-cron` schedule in src/jobs/scheduler.js
 * for a VPS, or an external OS-level cron/systemd timer calling
 * `node src/jobs/checkAlerts.js` directly — either works, see the
 * deployment guide).
 *
 * Scope note (carried over from the PHP original): this creates in-app
 * notifications (the notifications table — what a logged-in user sees) for
 * every condition the spec names. It does NOT also email those alerts out —
 * the spec's "escalation to MD" language could mean either a notification
 * or an email, and building a second templated-email path for six
 * different alert types, on top of the buyer-facing deferred-send pipeline
 * this phase already delivers, was cut to keep this phase bounded. Every
 * alert an MD would want to see shows up in their notification bell the
 * same day; wiring the same conditions to outbound email is a small,
 * mechanical follow-up against emailService.sendPlainText() whenever
 * that's wanted.
 */

/** UTC-safe signed day difference: positive when `dateStr` is in the future. */
function daysUntil(dateStr, today) {
  const target = new Date(`${String(dateStr).substring(0, 10)}T00:00:00Z`);
  const diffMs = target.getTime() - today.getTime();
  return Math.round(diffMs / 86400000);
}

async function notifyMdAndAdmin(userIds, type, message, relatedOrderId = null) {
  let count = 0;
  for (const userId of userIds) {
    if (await notificationRepository.existsToday(userId, type, relatedOrderId)) {
      continue; // already alerted this same condition today — don't spam the bell
    }
    await notificationRepository.create(userId, null, type, relatedOrderId, message);
    count++;
  }
  return count;
}

async function run() {
  const today = new Date(`${new Date().toISOString().substring(0, 10)}T00:00:00Z`);
  let created = 0;

  const activeUsers = await userRepository.listActive();
  const mdAndAdminIds = activeUsers
    .filter((u) => u.role_name === 'Admin' || u.role_name === 'Managing Director')
    .map((u) => u.id);

  // --- LUT expiry ---
  const lutExpiry = await companySettingsRepository.get('lut_expiry_date');
  if (lutExpiry) {
    const daysLeft = daysUntil(lutExpiry, today);
    const alertDays = parseInt((await companySettingsRepository.get('lut_alert_days_x')) || '30', 10);
    const escalationDays = parseInt((await companySettingsRepository.get('lut_escalation_days_y')) || '15', 10);
    if (daysLeft <= escalationDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'lut_escalation', `ESCALATION: LUT expires in ${daysLeft} day(s) (${lutExpiry}). Renew before 1 April or document generation will be blocked.`);
    } else if (daysLeft <= alertDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'lut_expiry', `LUT expires in ${daysLeft} day(s) (${lutExpiry}).`);
    }
  }

  // --- RCMC expiry ---
  const rcmcExpiry = await companySettingsRepository.get('rcmc_valid_until');
  if (rcmcExpiry) {
    const daysLeft = daysUntil(rcmcExpiry, today);
    const alertDays = parseInt((await companySettingsRepository.get('rcmc_alert_days_a')) || '60', 10);
    const escalationDays = parseInt((await companySettingsRepository.get('rcmc_escalation_days_b')) || '30', 10);
    if (daysLeft <= escalationDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'rcmc_escalation', `ESCALATION: RCMC certificate expires in ${daysLeft} day(s) (${rcmcExpiry}). Renew with CAPEXIL.`);
    } else if (daysLeft <= alertDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'rcmc_expiry', `RCMC certificate expires in ${daysLeft} day(s) (${rcmcExpiry}).`);
    }
  }

  // --- FDN payment overdue (freight generated, not yet cleared, C working days elapsed) ---
  const fdnOverdueDays = parseInt((await companySettingsRepository.get('fdn_overdue_days_c')) || '7', 10);
  const overdueFreight = await db.query(
    `SELECT o.id AS order_id, o.order_reference, d.generated_at
     FROM order_freight of_
     JOIN documents d ON d.id = of_.fdn_document_id
     JOIN orders o ON o.id = of_.order_id
     LEFT JOIN order_payment_status ps ON ps.order_id = o.id
     WHERE of_.fdn_document_id IS NOT NULL
       AND ps.freight_cleared_at IS NULL
       AND DATE(d.generated_at) <= DATE_SUB(CURDATE(), INTERVAL :days DAY)`,
    { days: fdnOverdueDays }
  );
  for (const row of overdueFreight) {
    created += await notifyMdAndAdmin(mdAndAdminIds, 'fdn_overdue', `Freight payment overdue on order ${row.order_reference} — FDN issued ${row.generated_at}, still not cleared.`, row.order_id);
  }

  // --- Dispute response overdue ---
  const overdueDisputes = await db.query(
    `SELECT d.id, d.order_id, d.response_due_date, d.assigned_to, o.order_reference
     FROM disputes d JOIN orders o ON o.id = d.order_id
     WHERE d.status IN ('Open', 'Under Review')
       AND d.response_due_date IS NOT NULL
       AND d.response_due_date < CURDATE()`
  );
  for (const row of overdueDisputes) {
    const targets = new Set(mdAndAdminIds);
    if (row.assigned_to) targets.add(row.assigned_to);
    created += await notifyMdAndAdmin([...targets], 'dispute_overdue', `Dispute response overdue on order ${row.order_reference} — was due ${row.response_due_date}.`, row.order_id);
  }

  return created;
}

// Runnable directly (`node src/jobs/checkAlerts.js`) for an external
// OS-level cron/systemd timer, exactly like the PHP script it replaces.
if (require.main === module) {
  run()
    .then((created) => {
      console.log(`[check_alerts] created ${created} notification(s).`);
      return db.pool.end();
    })
    .catch((e) => {
      console.error('[check_alerts] failed:', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
