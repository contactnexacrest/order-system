'use strict';

const db = require('../config/db');

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the records of the Merchant
 * Trade (MTT) division — deals, their lines, the money on both legs, the AD
 * bank's document checklist and the RBI reminders already sent.
 * Port of PHP App\Repositories\MerchantTradeRepository.
 */

const idList = (ids) => (ids.length === 0 ? '0' : ids.map((i) => parseInt(i, 10)).join(','));

async function find(id, executor = db) {
  return executor.queryOne('SELECT * FROM merchant_deals WHERE id = :id', { id });
}

/** @param {number[]|null} divisionIds null = every division */
async function list(divisionIds, stage = null) {
  const where = [];
  const params = {};
  if (divisionIds !== null) where.push(`d.division_id IN (${idList(divisionIds)})`);
  if (stage !== null) {
    where.push('d.stage = :stage');
    params.stage = stage;
  }
  return db.query(
    `SELECT d.*, (SELECT COUNT(*) FROM merchant_deal_lines l WHERE l.deal_id = d.id AND l.is_active = 1) AS line_count
       FROM merchant_deals d${where.length ? ` WHERE ${where.join(' AND ')}` : ''} ORDER BY d.deal_date DESC, d.id DESC`,
    params
  );
}

async function insert(row, userId) {
  const cols = Object.keys(row);
  const r = await db.execute(
    `INSERT INTO merchant_deals (${cols.join(', ')}, created_by, updated_by) VALUES (${cols.map((c) => `:${c}`).join(', ')}, :created_by, :updated_by)`,
    { ...row, created_by: userId, updated_by: userId }
  );
  return r.insertId;
}

/** @param {object} fields column => value (columns fixed by the caller, never user input) */
async function update(id, fields, userId, executor = db) {
  const sets = [];
  const params = { id, u: userId === undefined ? null : userId };
  for (const [col, value] of Object.entries(fields)) {
    sets.push(`${col} = :f_${col}`);
    params[`f_${col}`] = value === undefined ? null : value;
  }
  await executor.execute(`UPDATE merchant_deals SET ${sets.join(', ')}, updated_by = :u WHERE id = :id`, params);
}

/** Moves the stage only from the stage the caller saw (two people clicking at once move it once). */
async function moveStage(id, from, to, userId) {
  const r = await db.execute('UPDATE merchant_deals SET stage = :to, updated_by = :u WHERE id = :id AND stage = :from', { to, u: userId, id, from });
  return r.affectedRows === 1;
}

/** Locks the deal row until the transaction ends (two payments at once are checked one after the other). */
async function lock(id, trx) {
  return trx.queryOne('SELECT * FROM merchant_deals WHERE id = :id FOR UPDATE', { id });
}

async function lines(dealId, executor = db) {
  return executor.query('SELECT * FROM merchant_deal_lines WHERE deal_id = :d AND is_active = 1 ORDER BY line_no, id', { d: dealId });
}

async function addLine(dealId, line, userId) {
  const row = await db.queryOne('SELECT COALESCE(MAX(line_no), 0) + 1 AS n FROM merchant_deal_lines WHERE deal_id = :d', { d: dealId });
  const r = await db.execute(
    `INSERT INTO merchant_deal_lines (deal_id, line_no, description, hsn_code, quantity, unit, buy_price_fc, sell_price_fc, created_by)
     VALUES (:d, :n, :ds, :h, :q, :u, :b, :s, :by)`,
    { d: dealId, n: Number(row.n), ds: line.description, h: line.hsn_code, q: line.quantity, u: line.unit, b: line.buy_price_fc, s: line.sell_price_fc, by: userId }
  );
  return r.insertId;
}

async function removeLine(dealId, lineId) {
  const r = await db.execute('UPDATE merchant_deal_lines SET is_active = 0 WHERE id = :id AND deal_id = :d AND is_active = 1', { id: lineId, d: dealId });
  return r.affectedRows === 1;
}

/** Oldest first. */
async function payments(dealId, executor = db) {
  return executor.query(
    `SELECT p.*, u.name AS recorded_by_name FROM merchant_payments p LEFT JOIN users u ON u.id = p.recorded_by
      WHERE p.deal_id = :d ORDER BY p.moved_on, p.id`,
    { d: dealId }
  );
}

async function addPayment(dealId, row, userId, executor = db) {
  const r = await executor.execute(
    `INSERT INTO merchant_payments (deal_id, leg, moved_on, amount_fc, fx_rate, amount_inr, bank_reference, description, note, recorded_by)
     VALUES (:d, :l, :m, :a, :fx, :inr, :b, :desc, :n, :u)`,
    {
      d: dealId, l: row.leg, m: row.moved_on, a: row.amount_fc, fx: row.fx_rate, inr: row.amount_inr, b: row.bank_reference,
      desc: row.description, n: row.note, u: userId,
    }
  );
  return r.insertId;
}

async function bankReferenceUsed(bankReference, executor = db) {
  return !!(await executor.queryOne('SELECT 1 AS x FROM merchant_payments WHERE bank_reference = :b LIMIT 1', { b: bankReference }));
}

/** @returns {Promise<object>} doc_key => received_on */
async function documents(dealId) {
  const out = {};
  for (const r of await db.query('SELECT doc_key, received_on FROM merchant_deal_documents WHERE deal_id = :d', { d: dealId })) {
    out[String(r.doc_key)] = String(r.received_on).slice(0, 10);
  }
  return out;
}

async function setDocument(dealId, docKey, receivedOn, userId) {
  if (receivedOn === null) {
    await db.execute('DELETE FROM merchant_deal_documents WHERE deal_id = :d AND doc_key = :k', { d: dealId, k: docKey });
    return;
  }
  await db.execute(
    `INSERT INTO merchant_deal_documents (deal_id, doc_key, received_on, recorded_by) VALUES (:d, :k, :r, :u)
     ON DUPLICATE KEY UPDATE received_on = VALUES(received_on), recorded_by = VALUES(recorded_by)`,
    { d: dealId, k: docKey, r: receivedOn, u: userId }
  );
}

/** Records that a reminder was sent; false when it already was (never sent twice). */
async function markAlertSent(dealId, checkKey, level) {
  const r = await db.execute('INSERT IGNORE INTO merchant_alerts_sent (deal_id, check_key, level) VALUES (:d, :c, :l)', { d: dealId, c: checkKey, l: level });
  return r.affectedRows === 1;
}

/** Deals not closed yet, in every division. */
async function openDeals() {
  return db.query("SELECT * FROM merchant_deals WHERE stage <> 'closed' ORDER BY id");
}

/** Active users who may manage purchasing and work in this division or at company level. */
async function alertRecipients(divisionId) {
  const ids = [];
  for (const userId of await require('./permissionRepository').usersWithPermission('manage_purchasing')) {
    const row = await db.queryOne('SELECT division_id FROM users WHERE id = :id AND is_active = 1', { id: Number(userId) });
    if (row && (row.division_id === null || Number(row.division_id) === Number(divisionId))) ids.push(Number(userId));
  }
  return [...new Set(ids)];
}

module.exports = {
  find, list, insert, update, moveStage, lock, lines, addLine, removeLine, payments, addPayment, bankReferenceUsed,
  documents, setDocument, markAlertSent, openDeals, alertRecipients,
};
