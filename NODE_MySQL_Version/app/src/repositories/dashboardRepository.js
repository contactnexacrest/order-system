'use strict';

const db = require('../config/db');
const companySettingsRepository = require('./companySettingsRepository');

/**
 * Port of App\Repositories\DashboardRepository. Spec Section 16 —
 * "DASHBOARD: Active orders by stage, awaiting review/approval, overdue
 * payments, LUT/RCMC expiry warnings, recent activity, search by
 * client/ref number." One repository, one query per widget — kept
 * separate from orderRepository/auditLogRepository etc. since these are
 * dashboard-shaped aggregate reads, not entity CRUD.
 */

/** @returns {Promise<Array<object>>} stage_name, stage_slug, order_count — active orders only */
async function activeOrdersByStage() {
  return db.query(
    `SELECT sm.stage_name, sm.stage_slug, sm.sequence, COUNT(o.id) AS order_count
     FROM stages_master sm
     LEFT JOIN orders o ON o.current_stage_id = sm.id AND o.status = 'active'
     GROUP BY sm.id, sm.stage_name, sm.stage_slug, sm.sequence
     ORDER BY sm.sequence`
  );
}

async function activeOrderCount() {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM orders WHERE status = 'active'");
  return parseInt(row.c, 10);
}

/**
 * "Awaiting review/approval" — three separate queues the spec covers
 * under Sections 9/10/8 (document review, email-send approval,
 * amendment MD-approval) rolled into one dashboard count each, since
 * they're different workflows with different approvers, not one list.
 */
async function pendingReviewCount(reviewerId = null) {
  let sql = "SELECT COUNT(*) AS c FROM document_reviews WHERE status = 'pending'";
  const params = {};
  if (reviewerId !== null) {
    sql += ' AND reviewer_id = :reviewer_id';
    params.reviewer_id = reviewerId;
  }
  const row = await db.queryOne(sql, params);
  return parseInt(row.c, 10);
}

async function pendingEmailApprovalCount() {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM email_log WHERE status = 'pending_approval'");
  return parseInt(row.c, 10);
}

async function pendingAmendmentApprovalCount() {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM amendments WHERE status = 'pending'");
  return parseInt(row.c, 10);
}

/**
 * Overdue payments — balance (per order_payment_status.balance_due_date,
 * the actual computed due date the app already tracks) and freight
 * (same "FDN issued, still not cleared, past the configured overdue
 * window" definition check_alerts' cron already uses — duplicated here
 * as a read-only dashboard view of the same condition, not a second
 * source of truth for what "overdue" means).
 *
 * @returns {Promise<Array<object>>}
 */
async function overdueBalancePayments() {
  return db.query(
    `SELECT o.id AS order_id, o.order_reference, c.company_legal_name,
            ops.balance_due_date, ops.balance_amount
     FROM order_payment_status ops
     JOIN orders o ON o.id = ops.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE ops.balance_due_date IS NOT NULL
       AND ops.balance_cleared_at IS NULL
       AND ops.balance_due_date < CURDATE()
       AND o.status = 'active'
     ORDER BY ops.balance_due_date ASC`
  );
}

/** @returns {Promise<Array<object>>} */
async function overdueFreightPayments() {
  const overdueDays = parseInt((await companySettingsRepository.get('fdn_overdue_days_c')) ?? '7', 10);
  return db.query(
    `SELECT o.id AS order_id, o.order_reference, c.company_legal_name, d.generated_at AS fdn_generated_at
     FROM order_freight of_
     JOIN documents d ON d.id = of_.fdn_document_id
     JOIN orders o ON o.id = of_.order_id
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN order_payment_status ps ON ps.order_id = o.id
     WHERE of_.fdn_document_id IS NOT NULL
       AND ps.freight_cleared_at IS NULL
       AND DATE(d.generated_at) <= DATE_SUB(CURDATE(), INTERVAL :days DAY)
       AND o.status = 'active'
     ORDER BY d.generated_at ASC`,
    { days: overdueDays }
  );
}

function todayYmd() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Signed whole-day difference between today and an expiry date (YYYY-MM-DD), UTC-safe — positive means the expiry is still ahead. */
function daysLeftUntil(expiryYmd) {
  const today = new Date(`${todayYmd()}T00:00:00Z`);
  const expiry = new Date(`${expiryYmd}T00:00:00Z`);
  return Math.round((expiry.getTime() - today.getTime()) / 86400000);
}

/**
 * LUT/RCMC expiry warnings — mirrors check_alerts' own threshold logic
 * (company_settings) so the dashboard shows the same "how many days
 * left" the cron alerts on, without waiting for the next cron tick to
 * see it.
 *
 * @returns {Promise<{lut: object|null, rcmc: object|null}>}
 */
async function complianceExpiryWarnings() {
  const result = { lut: null, rcmc: null };

  const lutExpiry = await companySettingsRepository.get('lut_expiry_date');
  if (lutExpiry) {
    const daysLeft = daysLeftUntil(lutExpiry);
    const alertDays = parseInt((await companySettingsRepository.get('lut_alert_days_x')) ?? '30', 10);
    if (daysLeft <= alertDays) {
      result.lut = { expiry_date: lutExpiry, days_left: daysLeft };
    }
  }

  const rcmcExpiry = await companySettingsRepository.get('rcmc_valid_until');
  if (rcmcExpiry) {
    const daysLeft = daysLeftUntil(rcmcExpiry);
    const alertDays = parseInt((await companySettingsRepository.get('rcmc_alert_days_a')) ?? '60', 10);
    if (daysLeft <= alertDays) {
      result.rcmc = { expiry_date: rcmcExpiry, days_left: daysLeft };
    }
  }

  return result;
}

/**
 * Search by client/ref number — spec's own phrase from Section 16.
 * Matches client company name, client unique number, or order
 * reference, case-insensitively, partial match.
 *
 * @returns {Promise<Array<object>>}
 */
async function search(term) {
  const like = `%${term}%`;
  return db.query(
    `SELECT o.id AS order_id, o.order_reference, o.status, c.company_legal_name, c.client_unique_number,
            sm.stage_name AS current_stage_name
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.order_reference LIKE :term1
        OR c.client_unique_number LIKE :term2
        OR c.company_legal_name LIKE :term3
     ORDER BY o.created_at DESC
     LIMIT 50`,
    { term1: like, term2: like, term3: like }
  );
}

module.exports = {
  activeOrdersByStage,
  activeOrderCount,
  pendingReviewCount,
  pendingEmailApprovalCount,
  pendingAmendmentApprovalCount,
  overdueBalancePayments,
  overdueFreightPayments,
  complianceExpiryWarnings,
  search,
};
