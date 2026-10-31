'use strict';

const db = require('../config/db');

/**
 * Port of IncentiveRepository.php. Team incentive program (owner decision
 * 2026-10-30, mockups approved) — the settings, each person's incentive
 * role(s), each order's incentive (the amount set on it, exclusion, and the
 * figures fixed when the buyer has fully paid), the people on each order
 * and the payouts. The calculation lives in incentiveService. Only holders
 * of manage_incentives see the profit basis and the settings. Functions
 * used while fixing an order take an optional `c` (a transaction scope).
 */

/** Incentive roles, in the order the screens list them. */
const ROLES = {
  sales: 'Sales',
  export: 'Export Executive',
  marketing: 'Marketing',
  logistics: 'Logistics',
  accounts: 'Accounts',
};

/** @returns {Promise<Object<string,string>>} setting_key => setting_value */
async function settings() {
  const out = {};
  for (const row of await db.query('SELECT setting_key, setting_value FROM incentive_settings')) {
    out[String(row.setting_key)] = row.setting_value === null ? '' : String(row.setting_value);
  }
  return out;
}

async function setSetting(key, value, userId) {
  await db.execute(
    `INSERT INTO incentive_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)`,
    { k: key, v: value, u: userId }
  );
}

/** @returns {Promise<Object<number, string[]>>} user_id => roles */
async function userRoles() {
  const out = {};
  for (const row of await db.query('SELECT user_id, role FROM user_incentive_roles ORDER BY user_id, role')) {
    (out[Number(row.user_id)] = out[Number(row.user_id)] || []).push(String(row.role));
  }
  return out;
}

/** @param {Object<number, string[]>} rolesByUser */
async function replaceUserRoles(rolesByUser) {
  await db.execute('DELETE FROM user_incentive_roles');
  for (const [userId, roles] of Object.entries(rolesByUser)) {
    for (const role of [...new Set(roles)]) {
      if (ROLES[role]) {
        await db.execute('INSERT INTO user_incentive_roles (user_id, role) VALUES (:u, :r)', { u: Number(userId), r: role });
      }
    }
  }
}

async function forOrder(orderId, c = db) {
  return c.queryOne('SELECT * FROM order_incentives WHERE order_id = :id', { id: orderId });
}

/** Creates the order's incentive row when missing, then updates the given columns. */
async function saveOrder(orderId, fields, userId, c = db) {
  await c.execute('INSERT IGNORE INTO order_incentives (order_id, updated_by) VALUES (:id, :u)', { id: orderId, u: userId });
  const keys = Object.keys(fields);
  if (keys.length === 0) {
    return;
  }
  const params = { id: orderId, u: userId };
  const sets = keys.map((column) => {
    params[column] = fields[column] === undefined ? null : fields[column];
    return `${column} = :${column}`;
  });
  await c.execute(`UPDATE order_incentives SET ${sets.join(', ')}, updated_by = :u WHERE order_id = :id`, params);
}

/** @returns {Promise<Object<string, number[]>>} role => user ids set on the order */
async function orderPeople(orderId) {
  const out = {};
  for (const row of await db.query('SELECT role, user_id FROM order_incentive_people WHERE order_id = :id ORDER BY user_id', { id: orderId })) {
    (out[String(row.role)] = out[String(row.role)] || []).push(Number(row.user_id));
  }
  return out;
}

async function replaceOrderPeople(orderId, people) {
  await db.execute('DELETE FROM order_incentive_people WHERE order_id = :id', { id: orderId });
  for (const [role, userIds] of Object.entries(people)) {
    for (const userId of [...new Set(userIds)]) {
      await db.execute('INSERT INTO order_incentive_people (order_id, role, user_id) VALUES (:o, :r, :u)', { o: orderId, r: role, u: userId });
    }
  }
}

async function addPayout(row, c = db) {
  const result = await c.execute(
    `INSERT INTO incentive_payouts (order_id, payee_kind, user_id, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month)
     VALUES (:order_id, :payee_kind, :user_id, :associate_id, :role, :amount_inr, :amount_fc, :currency_code, :incentive_month)`,
    {
      order_id: row.order_id,
      payee_kind: row.payee_kind,
      user_id: row.user_id ?? null,
      associate_id: row.associate_id ?? null,
      role: row.role,
      amount_inr: row.amount_inr,
      amount_fc: row.amount_fc ?? null,
      currency_code: row.currency_code ?? null,
      incentive_month: row.incentive_month,
    }
  );
  return result.insertId;
}

/** With the person's or associate's name. */
async function payoutsForOrder(orderId, c = db) {
  return c.query(
    `SELECT p.*, u.name AS user_name, a.name AS associate_name
     FROM incentive_payouts p
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN associates a ON a.id = p.associate_id
     WHERE p.order_id = :id ORDER BY p.payee_kind DESC, p.id`,
    { id: orderId }
  );
}

/** One incentive month's payouts, for Accounts. */
async function payoutsForMonth(month) {
  return db.query(
    `SELECT p.*, u.name AS user_name, a.name AS associate_name, a.based_in, a.country, a.currency_code AS associate_currency,
            o.buyer_inquiry_ref, o.order_reference
     FROM incentive_payouts p
     JOIN orders o ON o.id = p.order_id
     LEFT JOIN users u ON u.id = p.user_id
     LEFT JOIN associates a ON a.id = p.associate_id
     WHERE p.incentive_month = :m
     ORDER BY p.payee_kind DESC, COALESCE(u.name, a.name), p.id`,
    { m: month }
  );
}

/** Incentive months that have payouts, newest first. */
async function payoutMonths() {
  return (await db.query('SELECT DISTINCT incentive_month FROM incentive_payouts ORDER BY incentive_month DESC')).map((r) => String(r.incentive_month));
}

/** One person's payouts, newest first. */
async function payoutsForUser(userId) {
  return db.query(
    `SELECT p.*, o.buyer_inquiry_ref, o.order_reference, o.company_legal_name
     FROM incentive_payouts p JOIN orders o ON o.id = p.order_id
     WHERE p.user_id = :u AND p.payee_kind = :k
     ORDER BY p.incentive_month DESC, p.id DESC`,
    { u: userId, k: 'staff' }
  );
}

async function findPayout(id) {
  return db.queryOne('SELECT * FROM incentive_payouts WHERE id = :id', { id });
}

/** @returns {Promise<boolean>} whether this call marked it paid (false: it already was — QA Round 6, R6-N17) */
async function markPayoutPaid(id, paidOn, paidInr, tdsInr, userId, paidVia = null, paidReference = null) {
  const result = await db.execute(
    "UPDATE incentive_payouts SET status = 'paid', paid_on = :d, paid_inr = :p, tds_inr = :t, paid_by = :u, paid_via = :via, paid_reference = :ref WHERE id = :id AND status = 'due'",
    { d: paidOn, p: paidInr, t: tdsInr, u: userId, via: paidVia, ref: paidReference !== null ? String(paidReference).trim() : null, id }
  );
  return result.affectedRows === 1;
}

async function deleteOrderPayouts(orderId, c = db) {
  await c.execute('DELETE FROM incentive_payouts WHERE order_id = :id', { id: orderId });
}

/** Incentive cost entries of the order (team / associate), keyed by kind. */
async function costEntries(orderId, c = db) {
  const out = {};
  for (const row of await c.query('SELECT * FROM order_cost_entries WHERE order_id = :id AND incentive_kind IS NOT NULL', { id: orderId })) {
    out[String(row.incentive_kind)] = row;
  }
  return out;
}

async function addCostEntry(orderId, kind, description, amountInr, incurredAt, userId, c = db) {
  const result = await c.execute(
    `INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by, incentive_kind)
     VALUES (:o, 'commission', :d, :a, :i, :u, :k)`,
    { o: orderId, d: description, a: amountInr, i: incurredAt, u: userId, k: kind }
  );
  return result.insertId;
}

/** All of the kind paid: the cost entry takes the amounts actually paid and goes to Zoho Books (paid from the company account). */
async function settleCostEntry(entryId, amountInr, paidOn, paidNote = null) {
  // Owner rule D-212: the cost line (and so the Zoho expense) says how and with what reference it was paid.
  await db.execute(
    `UPDATE order_cost_entries SET amount_inr = :a, incurred_at = :d, paid_from = 'company_account',
            description = CASE WHEN :note IS NULL OR LOCATE(' · paid ', COALESCE(description, '')) > 0 THEN description ELSE CONCAT(COALESCE(description, ''), ' · paid ', :note2) END
      WHERE id = :id`,
    { a: amountInr, d: paidOn, note: paidNote, note2: paidNote, id: entryId }
  );
}

/** Owner rule D-212: how a payout was paid. */
const PAID_VIA = { salary: 'with the salary (payroll)', bank_transfer: 'by bank transfer', cheque: 'by cheque' };

/** Order ids whose buyer may have fully paid and whose incentive is not fixed yet. */
async function unlockedOrderIdsWithPayments() {
  return (await db.query(
    `SELECT o.id FROM orders o
     JOIN order_payment_status ps ON ps.order_id = o.id
     LEFT JOIN order_incentives oi ON oi.order_id = o.id
     WHERE (oi.locked_at IS NULL) AND o.is_test_data = 0 AND o.is_sample_data = 0
       AND o.status NOT IN ('cancelled','lost')
       AND (ps.balance_cleared_at IS NOT NULL OR ps.advance_cleared_at IS NOT NULL OR ps.freight_cleared_at IS NOT NULL)`
  )).map((r) => Number(r.id));
}

module.exports = {
  ROLES,
  PAID_VIA,
  settings,
  setSetting,
  userRoles,
  replaceUserRoles,
  forOrder,
  saveOrder,
  orderPeople,
  replaceOrderPeople,
  addPayout,
  payoutsForOrder,
  payoutsForMonth,
  payoutMonths,
  payoutsForUser,
  findPayout,
  markPayoutPaid,
  deleteOrderPayouts,
  costEntries,
  addCostEntry,
  settleCostEntry,
  unlockedOrderIdsWithPayments,
};
