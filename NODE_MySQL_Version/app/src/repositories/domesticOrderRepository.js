'use strict';

const db = require('../config/db');

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the records of a Domestic Trade
 * (DOM) order — its domestic row, lines, payments, documents and number
 * series — plus the Indian states and the GST rates. Port of PHP
 * App\Repositories\DomesticOrderRepository.
 */

async function states() {
  return db.query('SELECT * FROM indian_states ORDER BY sort_order, name');
}

async function stateName(code) {
  const s = (await states()).find((r) => r.code === code);
  return s ? String(s.name) : '';
}

async function stateAbbr(code) {
  const s = (await states()).find((r) => r.code === code);
  return s ? String(s.abbr) : '';
}

async function isState(code) {
  return code !== null && code !== undefined && (await stateName(String(code))) !== '';
}

async function gstRates(activeOnly = false) {
  return db.query(
    `SELECT g.*, c.name AS category_name FROM gst_rates g LEFT JOIN product_categories c ON c.id = g.category_id${activeOnly ? ' WHERE g.is_active = 1' : ''} ORDER BY g.hsn_prefix IS NULL, g.hsn_prefix, c.name, g.id`
  );
}

async function addGstRate(hsnPrefix, categoryId, rate, note, userId) {
  const r = await db.execute('INSERT INTO gst_rates (hsn_prefix, category_id, rate_pct, note, updated_by) VALUES (:h, :c, :r, :n, :u)', { h: hsnPrefix, c: categoryId, r: rate, n: note, u: userId });
  return r.insertId;
}

async function setGstRateActive(id, active, userId) {
  await db.execute('UPDATE gst_rates SET is_active = :a, updated_by = :u WHERE id = :id', { a: active ? 1 : 0, u: userId, id });
}

async function find(orderId, executor = db) {
  return executor.queryOne('SELECT * FROM domestic_orders WHERE order_id = :o', { o: orderId });
}

async function insert(orderId, row, userId, executor = db) {
  await executor.execute(
    `INSERT INTO domestic_orders (order_id, buyer_gstin, buyer_state_code, place_of_supply, seller_state_code, delivery_address, payment_terms_days, buyer_po_ref, updated_by)
     VALUES (:o, :g, :bs, :pos, :ss, :addr, :days, :po, :u)`,
    { o: orderId, g: row.buyer_gstin, bs: row.buyer_state_code, pos: row.place_of_supply, ss: row.seller_state_code, addr: row.delivery_address, days: row.payment_terms_days, po: row.buyer_po_ref, u: userId }
  );
}

/** @param {object} fields column => value (columns are fixed by the caller, never user input) */
async function update(orderId, fields, userId, executor = db) {
  const sets = [];
  const params = { o: orderId, u: userId === undefined ? null : userId };
  for (const [col, value] of Object.entries(fields)) {
    sets.push(`${col} = :f_${col}`);
    params[`f_${col}`] = value === undefined ? null : value;
  }
  await executor.execute(`UPDATE domestic_orders SET ${sets.join(', ')}, updated_by = :u WHERE order_id = :o`, params);
}

/** Moves the stage only from the stage the caller saw (two people clicking at once move it once). */
async function moveStage(orderId, from, to, userId, executor = db) {
  const r = await executor.execute(
    "UPDATE domestic_orders SET stage = :to, updated_by = :u, closed_at = IF(:to2 = 'closed', NOW(), closed_at) WHERE order_id = :o AND stage = :from",
    { to, to2: to, u: userId, o: orderId, from }
  );
  return r.affectedRows === 1;
}

async function lines(orderId) {
  return db.query('SELECT * FROM domestic_order_lines WHERE order_id = :o AND is_active = 1 ORDER BY line_no, id', { o: orderId });
}

async function addLine(orderId, line, userId) {
  const n = await db.queryOne('SELECT COALESCE(MAX(line_no), 0) + 1 AS n FROM domestic_order_lines WHERE order_id = :o', { o: orderId });
  const r = await db.execute(
    `INSERT INTO domestic_order_lines (order_id, line_no, product_id, description, hsn_code, quantity, unit, rate_inr, gst_rate_pct, created_by)
     VALUES (:o, :n, :p, :d, :h, :q, :un, :r, :g, :u)`,
    { o: orderId, n: Number(n.n), p: line.product_id, d: line.description, h: line.hsn_code, q: line.quantity, un: line.unit, r: line.rate_inr, g: line.gst_rate_pct, u: userId }
  );
  return r.insertId;
}

async function removeLine(orderId, lineId) {
  const r = await db.execute('UPDATE domestic_order_lines SET is_active = 0 WHERE id = :id AND order_id = :o AND is_active = 1', { id: lineId, o: orderId });
  return r.affectedRows === 1;
}

async function payments(orderId, executor = db) {
  return executor.query(
    `SELECT p.*, u.name AS recorded_by_name FROM domestic_payments p LEFT JOIN users u ON u.id = p.recorded_by
      WHERE p.order_id = :o ORDER BY p.received_on, p.id`,
    { o: orderId }
  );
}

async function addPayment(orderId, receivedOn, amount, bankReference, description, note, userId, executor = db) {
  const r = await executor.execute(
    `INSERT INTO domestic_payments (order_id, received_on, amount_inr, bank_reference, description, note, recorded_by)
     VALUES (:o, :d, :a, :b, :desc, :n, :u)`,
    { o: orderId, d: receivedOn, a: amount, b: bankReference, desc: description, n: note, u: userId }
  );
  return r.insertId;
}

async function bankReferenceUsed(bankReference, executor = db) {
  return !!(await executor.queryOne('SELECT 1 AS x FROM domestic_payments WHERE bank_reference = :b LIMIT 1', { b: bankReference }));
}

async function documents(orderId) {
  return db.query(
    `SELECT d.*, u.name AS generated_by_name FROM domestic_documents d LEFT JOIN users u ON u.id = d.generated_by
      WHERE d.order_id = :o ORDER BY d.generated_at DESC, d.id DESC`,
    { o: orderId }
  );
}

async function latestDocument(orderId, code) {
  return db.queryOne('SELECT * FROM domestic_documents WHERE order_id = :o AND doc_code = :c ORDER BY id DESC LIMIT 1', { o: orderId, c: code });
}

async function addDocument(orderId, code, reference, fileId, userId) {
  const r = await db.execute('INSERT INTO domestic_documents (order_id, doc_code, reference, file_store_id, generated_by) VALUES (:o, :c, :r, :f, :u)', { o: orderId, c: code, r: reference, f: fileId, u: userId });
  return r.insertId;
}

/** The next number of a series in a financial year, race-safe (one statement takes the number on one connection). */
async function nextNumber(series, fy) {
  const conn = await db.pool.getConnection();
  try {
    await conn.execute('INSERT INTO domestic_counters (series, fy, last_no) VALUES (:s, :f, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', { s: series, f: fy });
    const [rows] = await conn.execute('SELECT LAST_INSERT_ID() AS n');
    return Number(rows[0].n);
  } finally {
    conn.release();
  }
}

/** A DOM setting (Admin › Divisions › Domestic Trade, company scope), or null when not set. */
async function divisionSetting(divisionId, key) {
  const row = await db.queryOne("SELECT setting_value FROM division_settings WHERE division_id = :d AND scope = 'company' AND setting_key = :k", { d: divisionId, k: key });
  return row && row.setting_value !== null ? String(row.setting_value) : null;
}

async function client(clientId) {
  return db.queryOne('SELECT * FROM clients WHERE id = :id', { id: clientId });
}

/** Fills a client's GST fields where they are still empty (a client's own values are never overwritten here). */
async function fillClientGst(clientId, gstin, stateCode, placeOfSupply, executor = db) {
  await executor.execute(
    'UPDATE clients SET gstin = COALESCE(gstin, :g), gst_state_code = COALESCE(gst_state_code, :s), place_of_supply_state = COALESCE(place_of_supply_state, :p) WHERE id = :id',
    { g: gstin, s: stateCode, p: placeOfSupply, id: clientId }
  );
}

async function setClientGst(clientId, gstin, stateCode, placeOfSupply) {
  await db.execute('UPDATE clients SET gstin = :g, gst_state_code = :s, place_of_supply_state = :p WHERE id = :id', { g: gstin, s: stateCode, p: placeOfSupply, id: clientId });
}

module.exports = {
  states, stateName, stateAbbr, isState, gstRates, addGstRate, setGstRateActive, find, insert, update, moveStage, lines, addLine, removeLine,
  payments, addPayment, bankReferenceUsed, documents, latestDocument, addDocument, nextNumber, divisionSetting, client, fillClientGst, setClientGst,
};
