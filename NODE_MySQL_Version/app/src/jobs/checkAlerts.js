'use strict';

const env = require('../config/env'); // loads .env as a side effect — must run first
const companySettingsRepository = require('../repositories/companySettingsRepository');
const notificationRepository = require('../repositories/notificationRepository');
const permissionRepository = require('../repositories/permissionRepository');
const db = require('../config/db');
const logger = require('../helpers/logger');
const { todayIst } = require('../helpers/dbDateTime');

/**
 * Port of app/cron/check_alerts.php. Spec Section 10 — "AUTO-NOTIFICATIONS"
 * (LUT/RCMC expiry, FDN payment overdue) plus Section 16's dispute response
 * timer. Run once daily (a `node-cron` schedule in src/jobs/scheduler.js
 * for a VPS, or an external OS-level cron/systemd timer calling
 * `node src/jobs/checkAlerts.js` directly — either works, see the
 * deployment guide).
 *
 * Every alert here also goes out by email — via notificationRepository.
 * create()'s own built-in emailNotification() call, which every in-app
 * notification already triggers. A compliance/financial deadline
 * (LUT/RCMC expiry, an overdue freight or dispute deadline) sitting
 * unread in a bell icon nobody happened to check is exactly the kind of
 * risk this alert system exists to prevent — email doesn't depend on
 * someone being logged in that day. External QA report N-09: this used
 * to ALSO send a second, separately-worded email directly via
 * emailService.sendPlainText() right after create() — every recipient
 * of every alert type here got two emails for the same event, one
 * "NexaCrest — New Notification" and one "NexaCrest Alert: ...". Removed
 * — create()'s own email is enough, and existsToday()'s same-day dedup
 * already covers it, so this never sends more than one email per user
 * per condition per day.
 */

/** UTC-safe signed day difference: positive when `dateStr` is in the future. */
function daysUntil(dateStr, today) {
  const target = new Date(`${String(dateStr).substring(0, 10)}T00:00:00Z`);
  const diffMs = target.getTime() - today.getTime();
  return Math.round(diffMs / 86400000);
}

/**
 * Owner decision 4 Nov 2026 (alert job speed-up): the job used to ask the
 * database once per person and order "already alerted today?" and once per
 * order for its assignees — slow once there are thousands of orders. It
 * now reads today's notifications, the LC reminders already sent and every
 * order's assignees once per run, and keeps those sets up to date as it
 * adds notifications. The alerts sent are exactly the same.
 */
let runCache = { today: null, once: null, assignees: null };

function todayKey(userId, type, orderId) {
  return `${Number(userId)}|${type}|${orderId === null || orderId === undefined ? 0 : Number(orderId)}`;
}

async function todaySeen() {
  if (runCache.today === null) {
    runCache.today = new Set();
    const rows = await db.query('SELECT user_id, type, related_order_id FROM notifications WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY');
    for (const r of rows) runCache.today.add(todayKey(r.user_id, r.type, r.related_order_id));
  }
  return runCache.today;
}

async function onceSent() {
  if (runCache.once === null) {
    runCache.once = new Set();
    const rows = await db.query("SELECT user_id, type, related_order_id, message FROM notifications WHERE type IN ('lc_last_shipment_soon', 'lc_presentation_soon', 'lc_expiry_soon')");
    for (const r of rows) runCache.once.add(`${Number(r.user_id)}|${r.type}|${Number(r.related_order_id)}|${r.message}`);
  }
  return runCache.once;
}

async function notifyMdAndAdmin(userIds, type, message, relatedOrderId = null) {
  let count = 0;
  const seen = await todaySeen();
  for (const userId of userIds) {
    const key = todayKey(userId, type, relatedOrderId);
    if (seen.has(key)) {
      continue; // already alerted this same condition today — don't spam the bell (or the inbox)
    }
    await notificationRepository.create(userId, null, type, relatedOrderId, message);
    seen.add(key);
    count++;
  }
  return count;
}

/**
 * Gap list G-4 (owner, 1 Nov 2026): a reminder that goes out once per
 * person, order and date (not once a day) — sent again only if the date
 * itself changes (e.g. after an LC amendment).
 */
async function notifyOnce(userIds, type, message, relatedOrderId) {
  let count = 0;
  const sent = await onceSent();
  for (const userId of [...new Set(userIds.map(Number))]) {
    const key = `${userId}|${type}|${Number(relatedOrderId)}|${message}`;
    if (sent.has(key)) {
      continue;
    }
    await notificationRepository.create(userId, null, type, relatedOrderId, message);
    sent.add(key);
    count++;
  }
  return count;
}

/** The order's assignees (read once per run for every order). */
async function orderAssignees(orderId) {
  if (runCache.assignees === null) {
    runCache.assignees = new Map();
    const rows = await db.query('SELECT oa.order_id, oa.user_id FROM order_assignees oa JOIN users u ON u.id = oa.user_id ORDER BY oa.order_id, oa.assigned_at');
    for (const r of rows) {
      const id = Number(r.order_id);
      if (!runCache.assignees.has(id)) runCache.assignees.set(id, []);
      runCache.assignees.get(id).push(Number(r.user_id));
    }
  }
  return runCache.assignees.get(Number(orderId)) || [];
}

async function run() {
  runCache = { today: null, once: null, assignees: null };
  // 90-item backlog "timezone": todayIst(), not new Date().toISOString()
  // (always UTC) — this "today" drives every escalation threshold and
  // overdue check below, and was a full calendar day behind the IST date
  // between 00:00 and 05:29 IST, exactly when this cron's own daily run
  // (local-time scheduled, i.e. 02:00 IST) fires.
  const today = new Date(`${todayIst()}T00:00:00Z`);
  let created = 0;

  // Whoever can manage company settings is who these compliance-deadline
  // escalations are for — checked by permission, not a hardcoded role
  // name, since roles can be renamed via /admin/roles.
  const mdAndAdminIds = await permissionRepository.usersWithPermission('manage_company_settings');

  // --- LUT expiry ---
  const lutExpiry = await companySettingsRepository.get('lut_expiry_date');
  if (lutExpiry) {
    const daysLeft = daysUntil(lutExpiry, today);
    const alertDays = parseInt((await companySettingsRepository.get('lut_alert_days_x')) || '30', 10);
    const escalationDays = parseInt((await companySettingsRepository.get('lut_escalation_days_y')) || '15', 10);
    if (daysLeft <= escalationDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'lut_escalation', `ESCALATION: LUT expires in ${daysLeft} day(s) (${lutExpiry}). Renew before 1 April or document generation will be blocked.`, null);
    } else if (daysLeft <= alertDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'lut_expiry', `LUT expires in ${daysLeft} day(s) (${lutExpiry}).`, null);
    }
  }

  // --- Agreements expiring (owner decision 2 Nov 2026, point 4): 30 / 7 days before and on the day, once each ---
  created += await require('../services/agreementAlertService').run(todayIst());
  // --- TDS on associate commission (owner decision 2 Nov 2026, D-235): deposit by the 7th, Form 26Q after each quarter ---
  {
    const payIncentiveIds = await permissionRepository.usersWithPermission('pay_incentives');
    for (const reminder of await require('../services/associateTds').reminders(todayIst())) {
      created += await notifyMdAndAdmin(payIncentiveIds, reminder.type, reminder.message, null);
    }
  }
  // Owner decision (2 Nov 2026, gap-list Q-6): eBRC / ECGC / incentive-claim reminders, each once.
  created += await require('../services/complianceTrackingService').runReminders(todayIst());

  // --- RCMC expiry ---
  const rcmcExpiry = await companySettingsRepository.get('rcmc_valid_until');
  if (rcmcExpiry) {
    const daysLeft = daysUntil(rcmcExpiry, today);
    const alertDays = parseInt((await companySettingsRepository.get('rcmc_alert_days_a')) || '60', 10);
    const escalationDays = parseInt((await companySettingsRepository.get('rcmc_escalation_days_b')) || '30', 10);
    if (daysLeft <= escalationDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'rcmc_escalation', `ESCALATION: RCMC certificate expires in ${daysLeft} day(s) (${rcmcExpiry}). Renew with CAPEXIL.`, null);
    } else if (daysLeft <= alertDays && daysLeft >= 0) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'rcmc_expiry', `RCMC certificate expires in ${daysLeft} day(s) (${rcmcExpiry}).`, null);
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
  // Owner decision (2026-10-28): the clause, the FDN and every other buyer
  // payment deadline now read "Calendar Days", so the overdue check counts
  // calendar days too — the alert fires the day after the date printed on
  // the FDN, not later.
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
    const due = new Date(`${String(row.generated_at).slice(0, 10)}T00:00:00Z`);
    due.setUTCDate(due.getUTCDate() + fdnOverdueDays);
    const dueDate = due.toISOString().slice(0, 10);
    if (dueDate < todayStr) {
      created += await notifyMdAndAdmin(mdAndAdminIds, 'fdn_overdue', `Freight payment overdue on order ${row.order_reference} — FDN issued ${row.generated_at}, still not cleared.`, row.order_id);
    }
  }

  // --- Balance payment overdue (gap list G-2, owner 1 Nov 2026) ---
  // Same daily pattern as the freight alert above, to Admin/MD and the
  // order's assignees, from the day after the balance due date.
  const overdueBalances = await db.query(
    `SELECT o.id AS order_id, o.order_reference, ps.balance_due_date
     FROM order_payment_status ps
     JOIN orders o ON o.id = ps.order_id
     WHERE ps.balance_due_date IS NOT NULL
       AND ps.balance_due_date < CURDATE()
       AND ps.balance_cleared_at IS NULL
       AND o.is_locked = 0
       AND o.status NOT IN ('cancelled', 'lost')`
  );
  for (const row of overdueBalances) {
    const targets = [...new Set([...mdAndAdminIds.map(Number), ...(await orderAssignees(row.order_id))])];
    created += await notifyMdAndAdmin(targets, 'balance_overdue',
      `Balance payment overdue on order ${row.order_reference} — was due ${String(row.balance_due_date).slice(0, 10)}, still not cleared.`, row.order_id);
  }

  // --- Letter of Credit dates (gap list G-4, owner 1 Nov 2026) ---
  // Once each, 7 days before the LC's last shipment date, deadline to
  // present documents and expiry, to Admin/MD and the order's assignees,
  // while the bank has not yet paid under the LC.
  const lcRows = await db.query(
    `SELECT o.id AS order_id, o.order_reference, lc.lc_number, lc.lc_last_shipment_date, lc.lc_presentation_deadline, lc.lc_expiry_date
     FROM order_lc_details lc
     JOIN orders o ON o.id = lc.order_id
     WHERE o.payment_method = 'LC'
       AND o.is_locked = 0
       AND o.status NOT IN ('cancelled', 'lost')
       AND lc.lc_received_at IS NULL`
  );
  const lcDates = [['lc_last_shipment_date', 'lc_last_shipment_soon', 'last shipment date'], ['lc_presentation_deadline', 'lc_presentation_soon', 'deadline to present the documents'], ['lc_expiry_date', 'lc_expiry_soon', 'expiry date']];
  for (const row of lcRows) {
    for (const [col, type, label] of lcDates) {
      if (!row[col]) {
        continue;
      }
      const date = String(row[col]).slice(0, 10);
      const daysLeft = daysUntil(date, today);
      if (daysLeft < 0 || daysLeft > 7) {
        continue;
      }
      const lc = row.lc_number ? `LC ${row.lc_number}` : 'the LC';
      created += await notifyOnce([...mdAndAdminIds.map(Number), ...(await orderAssignees(row.order_id))], type,
        `Order ${row.order_reference}: ${lc} — ${label} is ${date} (${daysLeft} day(s) away).`, row.order_id);
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
    created += await notifyMdAndAdmin([...targets], 'dispute_overdue', `Dispute response overdue on order ${row.order_reference} — was due ${row.response_due_date}.`, row.order_id);
  }

  // --- Quotation/PI validity expired, order still stuck at that stage ---
  // 90-item backlog "expiry alerts": orders.quotation_valid_until and
  // pi_valid_until (quotation_date/pi_date + their company_settings-driven
  // validity span) have existed since the Quotation/PI documents
  // themselves — rendered on every QT/PI as the buyer-facing "valid for N
  // days" disclaimer — but nothing ever alerted STAFF when one actually
  // lapsed with the order still sitting there, unconfirmed. Checked
  // against is_locked = 0 (excludes complete/lost orders) and the order's
  // own progress past the stage where that validity still matters: a
  // quotation stops mattering once a PI is issued (pi_date set); a PI
  // stops mattering once Order Confirmation (Stage 4) is reached.
  const expiredQuotations = await db.query(
    `SELECT o.id AS order_id, o.order_reference, o.quotation_valid_until
     FROM orders o
     WHERE o.is_locked = 0
       AND o.quotation_valid_until IS NOT NULL
       AND o.quotation_valid_until < CURDATE()
       AND o.pi_date IS NULL`
  );
  for (const row of expiredQuotations) {
    created += await notifyMdAndAdmin(mdAndAdminIds, 'quotation_expired', `Quotation validity expired on order ${row.order_reference} — was valid until ${row.quotation_valid_until}, still no PI issued.`, row.order_id);
  }

  const expiredPis = await db.query(
    `SELECT o.id AS order_id, o.order_reference, o.pi_valid_until
     FROM orders o
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.is_locked = 0
       AND o.pi_valid_until IS NOT NULL
       AND o.pi_valid_until < CURDATE()
       AND o.pi_date IS NOT NULL
       AND (sm.stage_number IS NULL OR sm.stage_number < 4)`
  );
  for (const row of expiredPis) {
    created += await notifyMdAndAdmin(mdAndAdminIds, 'pi_expired', `Proforma Invoice validity expired on order ${row.order_reference} — was valid until ${row.pi_valid_until}, Order Confirmation not yet reached.`, row.order_id);
  }

  // --- Export process step 8a: 7-day pre-shipment inspection reminder ---
  // Fires every day the estimated shipment date is within the next 7
  // days (inclusive, not yet passed) for any non-locked order that
  // still has a "Pre-Shipment Inspection" compliance task NOT marked
  // skipped (not applicable) — reusing that existing checklist row as
  // the "does this order even need 3rd-party inspection" signal, rather
  // than a second, duplicate flag. Targets whoever manages orders
  // (production/ops), not just Admin/MD, since this is squarely an
  // operations coordination reminder.
  const orderManagerIds = await permissionRepository.usersWithPermission('manage_orders');
  const dueInspections = await db.query(
    `SELECT o.id AS order_id, o.order_reference, o.est_shipment_date
     FROM orders o
     WHERE o.is_locked = 0
       AND o.est_shipment_date IS NOT NULL
       AND o.est_shipment_date >= CURDATE()
       AND o.est_shipment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       AND EXISTS (
         SELECT 1 FROM compliance_task_types tt
         LEFT JOIN order_compliance_tasks oct ON oct.task_type_id = tt.id AND oct.order_id = o.id
         WHERE tt.name = 'Pre-Shipment Inspection' AND tt.is_active = 1
           AND COALESCE(oct.status, 'not_started') != 'skipped'
       )`
  );
  for (const row of dueInspections) {
    const daysLeft = daysUntil(row.est_shipment_date, today);
    created += await notifyMdAndAdmin(orderManagerIds, 'pre_shipment_inspection_reminder', `Pre-shipment inspection due soon on order ${row.order_reference} — estimated shipment ${row.est_shipment_date} (${daysLeft} day(s) away). Confirm production completion and prepare for 3rd-party inspection.`, row.order_id);
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
