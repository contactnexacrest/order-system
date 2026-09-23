'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const userRepository = require('../repositories/userRepository');
const emailService = require('../services/emailService');
const workingDaysCalculator = require('../services/workingDaysCalculator');
const db = require('../config/db');
const logger = require('../helpers/logger');

/**
 * Port of app/cron/check_alerts.php. Spec Section 10 — "AUTO-NOTIFICATIONS"
 * (LUT/RCMC expiry, FDN payment overdue) plus Section 16's dispute response
 * timer. Run once daily (a `node-cron` schedule in src/jobs/scheduler.js
 * for a VPS, or an external OS-level cron/systemd timer calling
 * `node src/jobs/checkAlerts.js` directly — either works, see the
 * deployment guide).
 *
 * Every alert here also goes out by email now (previously this only
 * created the in-app notification — this file's own prior docblock
 * flagged that as a known, deliberately deferred follow-up). A
 * compliance/financial deadline (LUT/RCMC expiry, an overdue freight or
 * dispute deadline) sitting unread in a bell icon nobody happened to
 * check is exactly the kind of risk this alert system exists to prevent
 * — email doesn't depend on someone being logged in that day.
 * emailService.sendPlainText() degrades safely (logs instead of
 * throwing) if SMTP isn't configured, so this never breaks the job
 * itself; existsToday()'s same-day dedup covers the email too, so this
 * never sends more than one email per user per condition per day.
 */

/** UTC-safe signed day difference: positive when `dateStr` is in the future. */
function daysUntil(dateStr, today) {
  const target = new Date(`${String(dateStr).substring(0, 10)}T00:00:00Z`);
  const diffMs = target.getTime() - today.getTime();
  return Math.round(diffMs / 86400000);
}

async function notifyMdAndAdmin(userIds, type, message, relatedOrderId = null, usersById = {}) {
  let count = 0;
  for (const userId of userIds) {
    if (await notificationRepository.existsToday(userId, type, relatedOrderId)) {
      continue; // already alerted this same condition today — don't spam the bell (or the inbox)
    }
    await notificationRepository.create(userId, null, type, relatedOrderId, message);
    const email = usersById[userId] && usersById[userId].email;
    if (email) {
      await emailService.sendPlainText(email, `NexaCrest Alert: ${type.replace(/_/g, ' ')}`, message);
    }
    count++;
  }
  return count;
}

async function run() {
  const today = new Date(`${new Date().toISOString().substring(0, 10)}T00:00:00Z`);
  let created = 0;

  const activeUsers = await userRepository.listActive();
  const usersById = {};
  for (const u of activeUsers) usersById[u.id] = u;
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
      created += await notifyMdAndAdmin(mdAndAdminIds, 'lut_escalation', `ESCALATION: LUT expires in ${daysLeft} day(s) (${lutExpiry}). Renew before 1 April or document generation will be blocked.`, null, usersById);
    } else if (daysLeft <= alertDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'lut_expiry', `LUT expires in ${daysLeft} day(s) (${lutExpiry}).`, null, usersById);
    }
  }

  // --- RCMC expiry ---
  const rcmcExpiry = await companySettingsRepository.get('rcmc_valid_until');
  if (rcmcExpiry) {
    const daysLeft = daysUntil(rcmcExpiry, today);
    const alertDays = parseInt((await companySettingsRepository.get('rcmc_alert_days_a')) || '60', 10);
    const escalationDays = parseInt((await companySettingsRepository.get('rcmc_escalation_days_b')) || '30', 10);
    if (daysLeft <= escalationDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'rcmc_escalation', `ESCALATION: RCMC certificate expires in ${daysLeft} day(s) (${rcmcExpiry}). Renew with CAPEXIL.`, null, usersById);
    } else if (daysLeft <= alertDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'rcmc_expiry', `RCMC certificate expires in ${daysLeft} day(s) (${rcmcExpiry}).`, null, usersById);
    }
  }

  // --- FDN payment overdue (freight generated, not yet cleared, C WORKING
  // days elapsed) --- Real bug this closes: the CFR/CIF Indicative Freight
  // Validity clause (seeded on QT and PI) is a written promise to the buyer
  // that freight "must be received BEFORE shipment booking is confirmed...
  // payable within 3 working days of issue" — but this check used plain
  // calendar-day SQL arithmetic against fdn_overdue_days_c=7, so the alert
  // didn't fire until 7 calendar days after issue. For the 4+ days between
  // the buyer actually breaching their 3-WORKING-day written commitment and
  // this alert finally firing, nothing in the system showed the payment as
  // overdue at all. Aligned fdn_overdue_days_c to 3 (the clause's own
  // figure) and switched the comparison to workingDaysCalculator, matching
  // the same working-days-not-calendar-days fix already applied to the
  // dispute response deadline.
  const fdnOverdueDays = parseInt((await companySettingsRepository.get('fdn_overdue_days_c')) || '3', 10);
  const overdueFreight = await db.query(
    `SELECT o.id AS order_id, o.order_reference, d.generated_at
     FROM order_freight of_
     JOIN documents d ON d.id = of_.fdn_document_id
     JOIN orders o ON o.id = of_.order_id
     LEFT JOIN order_payment_status ps ON ps.order_id = o.id
     WHERE of_.fdn_document_id IS NOT NULL
       AND ps.freight_cleared_at IS NULL`
  );
  const todayStr = today.toISOString().slice(0, 10);
  for (const row of overdueFreight) {
    const dueDate = await workingDaysCalculator.addWorkingDays(String(row.generated_at).slice(0, 10), fdnOverdueDays);
    if (dueDate < todayStr) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'fdn_overdue', `Freight payment overdue on order ${row.order_reference} — FDN issued ${row.generated_at}, still not cleared.`, row.order_id, usersById);
    }
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
    created += await notifyMdAndAdmin([...targets], 'dispute_overdue', `Dispute response overdue on order ${row.order_reference} — was due ${row.response_due_date}.`, row.order_id, usersById);
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
      logger.error('check_alerts', e);
      process.exitCode = 1;
    });
}

module.exports = { run };
