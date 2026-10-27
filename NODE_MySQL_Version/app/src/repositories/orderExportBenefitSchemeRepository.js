'use strict';

const db = require('../config/db');
const { formatForDb } = require('../helpers/dbDateTime');

/**
 * Per-order eligibility decision against the export_benefit_scheme
 * dropdown options (RODTEP, Duty Drawback, RoSCTL, Other) —
 * docs/schema.sql's order_export_benefit_schemes table, added
 * 2026-10-24d. Separate from caExportBenefitRepository.js/
 * ca_export_benefits, which records a real claim (amount/date, written
 * only once staff actually submit one) — this table instead always
 * carries an explicit 'pending'/'eligible'/'not_eligible' row per order
 * per scheme, written at order creation (initializeForOrder(), called
 * from orderRepository.js's insertOrderRow()), so "not eligible" is
 * never just the absence of a claim row — the exact same "never a
 * missing row standing in for not applicable" pattern as
 * orderComplianceTaskRepository.js.
 */
const STATUSES = {
  pending: 'Pending',
  eligible: 'Eligible',
  not_eligible: 'Not Eligible',
};

/**
 * Writes a real 'pending' row for every active scheme option — called
 * once, at order creation. Optional executor lets this join the
 * caller's transaction (see orderRepository.insertOrderRow()), same
 * pattern as orderAssigneeRepository.add().
 */
async function initializeForOrder(orderId, executor = db) {
  const schemes = await executor.query(
    "SELECT option_value FROM dropdown_options WHERE list_key = 'export_benefit_scheme' AND is_active = 1"
  );
  for (const s of schemes) {
    await executor.execute(
      `INSERT INTO order_export_benefit_schemes (order_id, scheme_name, status)
       VALUES (:order_id, :scheme_name, 'pending')
       ON DUPLICATE KEY UPDATE status = status`,
      { order_id: orderId, scheme_name: s.option_value }
    );
  }
}

/** @return one row per active scheme option, LEFT JOINed against this order's own status */
async function forOrder(orderId) {
  return db.query(
    `SELECT
        d.option_value AS scheme_name,
        COALESCE(oebs.status, 'pending') AS status,
        oebs.reason,
        oebs.resolved_at,
        oebs.claim_skipped_at,
        oebs.claim_skip_reason,
        u.name AS resolved_by_name
     FROM dropdown_options d
     LEFT JOIN order_export_benefit_schemes oebs
        ON oebs.scheme_name = d.option_value AND oebs.order_id = :order_id
     LEFT JOIN users u ON u.id = oebs.resolved_by
     WHERE d.list_key = 'export_benefit_scheme' AND d.is_active = 1
     ORDER BY d.sort_order`,
    { order_id: orderId }
  );
}

async function setStatus(orderId, schemeName, status, reason, userId) {
  await db.execute(
    `INSERT INTO order_export_benefit_schemes (order_id, scheme_name, status, reason, resolved_at, resolved_by)
     VALUES (:order_id, :scheme_name, :status, :reason, NOW(), :resolved_by)
     ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        reason = VALUES(reason),
        resolved_at = VALUES(resolved_at),
        resolved_by = VALUES(resolved_by)`,
    { order_id: orderId, scheme_name: schemeName, status, reason, resolved_by: userId }
  );
}

/** @return {total, resolved, pending} resolved = eligible + not_eligible */
async function summaryForOrder(orderId) {
  const rows = await forOrder(orderId);
  const total = rows.length;
  let resolved = 0;
  for (const r of rows) {
    if (r.status === 'eligible' || r.status === 'not_eligible') {
      resolved += 1;
    }
  }
  return { total, resolved, pending: total - resolved };
}

/**
 * Owner decision (2026-10-27): at Step 13g an eligible scheme the company
 * will not claim (or whose money will never come) is skipped with a reason;
 * a null reason undoes the skip.
 */
async function setClaimSkipped(orderId, schemeName, reason, userId) {
  await db.execute(
    `UPDATE order_export_benefit_schemes
        SET claim_skipped_at = :at, claim_skipped_by = :by, claim_skip_reason = :reason
      WHERE order_id = :order_id AND scheme_name = :scheme_name`,
    { at: reason === null ? null : formatForDb(new Date()), by: reason === null ? null : userId, reason, order_id: orderId, scheme_name: schemeName }
  );
}

module.exports = { STATUSES, initializeForOrder, forOrder, setStatus, setClaimSkipped, summaryForOrder };
