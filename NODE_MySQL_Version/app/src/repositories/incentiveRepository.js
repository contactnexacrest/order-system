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

/** Cache of incentive_roles by key (kept for the synchronous roleLabel used by the views). */
let roleCache = null;

/**
 * Owner decision D-245 P4: the incentive roles are an editable list
 * (incentive_roles). Every role, active and not, by key, in screen order.
 */
async function allRoles() {
  if (roleCache === null) {
    const out = {};
    for (const r of await db.query('SELECT * FROM incentive_roles ORDER BY sort_order, id')) {
      out[String(r.role_key)] = { id: Number(r.id), role_key: String(r.role_key), label: String(r.label), share_pct: Number(r.share_pct),
        is_active: Number(r.is_active) === 1, sort_order: Number(r.sort_order) };
    }
    roleCache = out;
  }
  return roleCache;
}

/** Active roles: key => label, in screen order. */
async function roles() {
  const out = {};
  for (const [k, r] of Object.entries(await allRoles())) {
    if (r.is_active) out[k] = r.label;
  }
  return out;
}

/** The label of a role key (also of a deactivated role, as payouts keep their key). Uses the loaded list. */
function roleLabel(key) {
  if (roleCache && roleCache[key]) return roleCache[key].label;
  return key === 'associate' ? 'Associate (agreement)' : key;
}

function forgetRoles() {
  roleCache = null;
}

/** Whether a role key is used anywhere (people, payouts, an order's ticks) — then it can only be deactivated. */
async function roleInUse(key) {
  for (const [table, col] of [['user_incentive_roles', 'role'], ['order_incentive_people', 'role'], ['incentive_payouts', 'role'], ['order_incentive_roles', 'role_key']]) {
    if (await db.queryOne(`SELECT 1 AS x FROM ${table} WHERE ${col} = :k LIMIT 1`, { k: key })) return true;
  }
  return false;
}

async function saveRoles(rows, userId) {
  const existing = await allRoles();
  for (const r of rows) {
    const params = { k: r.role_key, l: r.label, p: r.share_pct, a: r.is_active ? 1 : 0, o: r.sort_order, u: userId };
    if (existing[r.role_key]) {
      await db.execute('UPDATE incentive_roles SET label = :l, share_pct = :p, is_active = :a, sort_order = :o, updated_by = :u WHERE role_key = :k', params);
    } else {
      await db.execute('INSERT INTO incentive_roles (role_key, label, share_pct, is_active, sort_order, updated_by) VALUES (:k, :l, :p, :a, :o, :u)', params);
    }
  }
  forgetRoles();
}

async function deleteRole(key) {
  await db.execute('DELETE FROM incentive_roles WHERE role_key = :k', { k: key });
  forgetRoles();
}

/** The order's ticks and shares by role key. */
async function orderRoles(orderId) {
  const out = {};
  for (const r of await db.query('SELECT role_key, gets_it, share_pct FROM order_incentive_roles WHERE order_id = :id', { id: orderId })) {
    out[String(r.role_key)] = { gets_it: Number(r.gets_it) === 1, share_pct: r.share_pct !== null ? Number(r.share_pct) : null };
  }
  return out;
}

async function replaceOrderRoles(orderId, roleMap) {
  await db.execute('DELETE FROM order_incentive_roles WHERE order_id = :id', { id: orderId });
  for (const [key, r] of Object.entries(roleMap)) {
    await db.execute('INSERT INTO order_incentive_roles (order_id, role_key, gets_it, share_pct) VALUES (:o, :k, :g, :s)',
      { o: orderId, k: key, g: r.gets_it ? 1 : 0, s: r.share_pct });
  }
}

/** Owner decision D-245 P4: every change to an order's incentive is kept. */
async function addHistory(orderId, action, before, after, reason, userId, c = db) {
  await c.execute('INSERT INTO order_incentive_history (order_id, action, before_value, after_value, reason, changed_by) VALUES (:o, :a, :b, :v, :r, :u)',
    { o: orderId, a: action, b: before ?? null, v: after ?? null, r: reason ?? null, u: userId });
}

/** Newest first, with the name of who changed it. */
async function history(orderId) {
  return db.query(
    `SELECT h.*, u.name AS changed_by_name FROM order_incentive_history h LEFT JOIN users u ON u.id = h.changed_by
      WHERE h.order_id = :id ORDER BY h.changed_at DESC, h.id DESC`, { id: orderId }
  );
}

/** @returns {Promise<Object<string,string>>} setting_key => setting_value */
async function settings() {
  // D-245 P5: read once per report run (batchCache), fresh otherwise.
  // D-257: a division's own values over the company ones (outside the memo, which is not per division).
  return require('../services/divisionService').overlay('incentive', { ...(await require('../helpers/batchCache').memo('incentive_settings', settingsUncached)) });
}

async function settingsUncached() {
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
  // D-245 P5: read once per report run (batchCache scope only).
  const all = await require('../helpers/batchCache').memo('user_incentive_roles', userRolesUncached);
  return Object.fromEntries(Object.entries(all).map(([k, v]) => [k, [...v]]));
}

async function userRolesUncached() {
  const out = {};
  for (const row of await db.query('SELECT user_id, role FROM user_incentive_roles ORDER BY user_id, role')) {
    (out[Number(row.user_id)] = out[Number(row.user_id)] || []).push(String(row.role));
  }
  return out;
}

/** @param {Object<number, string[]>} rolesByUser */
async function replaceUserRoles(rolesByUser) {
  await db.execute('DELETE FROM user_incentive_roles');
  const active = await roles();
  for (const [userId, list] of Object.entries(rolesByUser)) {
    for (const role of [...new Set(list)]) {
      if (active[role]) {
        await db.execute('INSERT INTO user_incentive_roles (user_id, role) VALUES (:u, :r)', { u: Number(userId), r: role });
      }
    }
  }
}

async function forOrder(orderId, c = db) {
  // D-245 P5: read once per report run (batchCache scope only; never inside a transaction).
  if (c !== db) return c.queryOne('SELECT * FROM order_incentives WHERE order_id = :id', { id: orderId });
  return require('../helpers/batchCache').memoRead(`oi:${orderId}`, () => db.queryOne('SELECT * FROM order_incentives WHERE order_id = :id', { id: orderId }));
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
    `INSERT INTO incentive_payouts (order_id, payee_kind, user_id, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, payout_run_on, locked_on)
     VALUES (:order_id, :payee_kind, :user_id, :associate_id, :role, :amount_inr, :amount_fc, :currency_code, :incentive_month, :payout_run_on, :locked_on)`,
    {
      payout_run_on: row.payout_run_on ?? null,
      locked_on: row.locked_on ?? null,
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

/** Owner decision 2 Nov 2026 (D-235): the TDS rate, section and reason used on an associate payout. */
async function setPayoutTds(id, rate, section, reason, overrideReason) {
  await db.execute('UPDATE incentive_payouts SET tds_rate = :r, tds_section = :s, tds_reason = :why, tds_override_reason = :o WHERE id = :id', {
    r: rate, s: section, why: reason !== null && reason !== undefined ? String(reason).slice(0, 255) : null,
    o: overrideReason !== null && overrideReason !== undefined ? String(overrideReason).slice(0, 255) : null, id,
  });
}

/** Owner decision 2 Nov 2026 (D-235): the challan Accounts deposited the TDS with. */
async function setPayoutChallan(id, challanNo, challanDate) {
  await db.execute("UPDATE incentive_payouts SET tds_challan_no = :n, tds_challan_date = :d WHERE id = :id AND payee_kind = 'associate' AND status = 'paid' AND tds_inr > 0",
    { n: challanNo, d: challanDate, id });
}

/** Owner decision 1 Nov 2026 (AS-3): a due payout put on hold, with the reason. */
async function holdPayout(id, reason, c = db) {
  const r = await c.execute("UPDATE incentive_payouts SET status = 'held', hold_reason = :r WHERE id = :id AND status = 'due'", { r: reason, id });
  return r.affectedRows === 1;
}

async function releasePayout(id) {
  const r = await db.execute("UPDATE incentive_payouts SET status = 'due', hold_reason = NULL WHERE id = :id AND status = 'held'", { id });
  return r.affectedRows === 1;
}

/**
 * AS-9: a claw-back after a refund — a negative line in the associate's
 * next payout month, tied to the paid payout it reverses.
 */
async function addClawback(paid, amountInr, amountFc, month, note, c = db) {
  const r = await c.execute(
    `INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, clawback_of_payout_id, note)
     VALUES (:o, 'associate', :a, 'associate', :inr, :fc, :cur, :m, :of, :n)`,
    { o: paid.order_id, a: paid.associate_id, inr: -Math.abs(amountInr), fc: amountFc !== null && amountFc !== undefined ? -Math.abs(amountFc) : null,
      cur: paid.currency_code ?? null, m: month, of: paid.id, n: note }
  );
  return r.insertId;
}

/**
 * QA Round 8, R8-N12: a claw-back is saved only while it fits what is left
 * of the paid commission — checked with the paid payout row locked, so
 * simultaneous claw-backs (a double click, two people) cannot take back
 * more than was paid.
 * @returns {Promise<{id: number|null, left: number}>} id null when it no longer fits
 */
async function addClawbackWithinPaid(payoutId, amountInr, month, note) {
  return db.transaction(async (conn) => {
    const paid = await conn.queryOne('SELECT * FROM incentive_payouts WHERE id = :id FOR UPDATE', { id: payoutId });
    if (!paid || paid.status !== 'paid' || Number(paid.amount_inr) <= 0) return { id: null, left: 0 };
    const sum = await conn.queryOne('SELECT COALESCE(-SUM(amount_inr), 0) AS s FROM incentive_payouts WHERE clawback_of_payout_id = :p', { p: payoutId });
    const left = Math.round((Number(paid.paid_inr) - Number(sum.s)) * 100) / 100;
    if (!(amountInr > 0) || amountInr > left + 0.005) return { id: null, left };
    const fc = paid.amount_fc !== null && paid.amount_fc !== undefined ? Math.round(Number(paid.amount_fc) * amountInr / Number(paid.amount_inr) * 100) / 100 : null;
    const id = await addClawback(paid, amountInr, fc, month, note, conn);
    return { id, left };
  });
}

async function deleteOrderPayouts(orderId, c = db) {
  await c.execute('DELETE FROM incentive_payouts WHERE order_id = :id', { id: orderId });
}

/** Incentive cost entries of the order (team / associate), keyed by kind. */
async function costEntries(orderId, c = db) {
  // D-245 P5: read once per report run (batchCache scope only; never inside a transaction).
  if (c === db) {
    const all = await require('../helpers/batchCache').memo(`oce:${orderId}`, () => costEntriesUncached(orderId, db));
    return Object.fromEntries(Object.entries(all).map(([k, v]) => [k, { ...v }]));
  }
  return costEntriesUncached(orderId, c);
}

async function costEntriesUncached(orderId, c) {
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

/** Order ids whose buyer may have fully paid and whose incentive is not locked yet (candidates for "ready to lock"). */
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
  setPayoutTds,
  setPayoutChallan,
  addClawbackWithinPaid,
  allRoles,
  roles,
  roleLabel,
  forgetRoles,
  roleInUse,
  saveRoles,
  deleteRole,
  orderRoles,
  replaceOrderRoles,
  addHistory,
  history,
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
  holdPayout,
  releasePayout,
  addClawback,
  deleteOrderPayouts,
  costEntries,
  addCostEntry,
  settleCostEntry,
  unlockedOrderIdsWithPayments,
};
