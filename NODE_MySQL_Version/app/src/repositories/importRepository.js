'use strict';

const db = require('../config/db');

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the records of the Import &
 * Distribution (IMP) division — imports (shipments), their lines and
 * supplier payments, customs duty rates, and the stock with its movements.
 * Port of PHP App\Repositories\ImportRepository.
 */

const idList = (ids) => (ids.length === 0 ? '0' : ids.map((i) => parseInt(i, 10)).join(','));

// ------------------------------------------------------------ duty rates

async function dutyRates(activeOnly = false) {
  return db.query(`SELECT * FROM import_duty_rates${activeOnly ? ' WHERE is_active = 1' : ''} ORDER BY hsn_prefix, id`);
}

async function addDutyRate(hsnPrefix, bcd, sws, igst, note, userId) {
  const r = await db.execute(
    'INSERT INTO import_duty_rates (hsn_prefix, bcd_pct, sws_pct, igst_pct, note, updated_by) VALUES (:h, :b, :s, :i, :n, :u)',
    { h: hsnPrefix, b: bcd, s: sws, i: igst, n: note, u: userId }
  );
  return r.insertId;
}

async function setDutyRateActive(id, active, userId) {
  const r = await db.execute('UPDATE import_duty_rates SET is_active = :a, updated_by = :u WHERE id = :id', { a: active ? 1 : 0, u: userId, id });
  return r.affectedRows === 1;
}

// ------------------------------------------------------------ shipments

async function find(id) {
  return db.queryOne('SELECT * FROM import_shipments WHERE id = :id', { id });
}

/** @param {number[]|null} divisionIds null = every division */
async function list(divisionIds, stage = null) {
  const where = [];
  const params = {};
  if (divisionIds !== null) where.push(`s.division_id IN (${idList(divisionIds)})`);
  if (stage !== null) {
    where.push('s.stage = :stage');
    params.stage = stage;
  }
  return db.query(
    `SELECT s.id, s.reference, s.stage, s.supplier_name, s.supplier_country, s.currency_code, s.po_date, s.bl_no, s.boe_no,
            s.received_on, s.landed_total,
            (SELECT COUNT(*) FROM import_shipment_lines l WHERE l.shipment_id = s.id AND l.is_active = 1) AS line_count,
            (SELECT COALESCE(SUM(ROUND(l.quantity * l.unit_price_fc, 2)), 0) FROM import_shipment_lines l WHERE l.shipment_id = s.id AND l.is_active = 1) AS po_value_fc
       FROM import_shipments s${where.length ? ` WHERE ${where.join(' AND ')}` : ''} ORDER BY s.po_date DESC, s.id DESC`,
    params
  );
}

async function insert(row, userId) {
  const r = await db.execute(
    `INSERT INTO import_shipments (division_id, reference, supplier_name, supplier_country, supplier_address, currency_code, incoterm, po_date, payment_terms, created_by, updated_by)
     VALUES (:d, :r, :sn, :sc, :sa, :cur, :inc, :pd, :pt, :u, :u2)`,
    {
      d: row.division_id, r: row.reference, sn: row.supplier_name, sc: row.supplier_country, sa: row.supplier_address,
      cur: row.currency_code, inc: row.incoterm, pd: row.po_date, pt: row.payment_terms, u: userId, u2: userId,
    }
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
  await executor.execute(`UPDATE import_shipments SET ${sets.join(', ')}, updated_by = :u WHERE id = :id`, params);
}

/** Moves the stage only from the stage the caller saw (two people clicking at once move it once). */
async function moveStage(id, from, to, userId, executor = db) {
  const r = await executor.execute('UPDATE import_shipments SET stage = :to, updated_by = :u WHERE id = :id AND stage = :from', { to, u: userId, id, from });
  return r.affectedRows === 1;
}

async function lines(shipmentId, executor = db) {
  return executor.query(
    `SELECT l.*, p.product_code, p.name AS product_name FROM import_shipment_lines l JOIN products p ON p.id = l.product_id
      WHERE l.shipment_id = :s AND l.is_active = 1 ORDER BY l.line_no, l.id`,
    { s: shipmentId }
  );
}

async function addLine(shipmentId, line, userId) {
  const row = await db.queryOne('SELECT COALESCE(MAX(line_no), 0) + 1 AS n FROM import_shipment_lines WHERE shipment_id = :s', { s: shipmentId });
  const r = await db.execute(
    `INSERT INTO import_shipment_lines (shipment_id, line_no, product_id, description, hsn_code, quantity, unit, unit_price_fc, bcd_pct, sws_pct, igst_pct, created_by)
     VALUES (:s, :n, :p, :d, :h, :q, :un, :pr, :b, :sw, :i, :u)`,
    {
      s: shipmentId, n: Number(row.n), p: line.product_id, d: line.description, h: line.hsn_code, q: line.quantity, un: line.unit,
      pr: line.unit_price_fc, b: line.bcd_pct, sw: line.sws_pct, i: line.igst_pct, u: userId,
    }
  );
  return r.insertId;
}

async function removeLine(shipmentId, lineId) {
  const r = await db.execute('UPDATE import_shipment_lines SET is_active = 0 WHERE id = :id AND shipment_id = :s AND is_active = 1', { id: lineId, s: shipmentId });
  return r.affectedRows === 1;
}

async function setLineFigures(lineId, f, executor = db) {
  await executor.execute(
    'UPDATE import_shipment_lines SET value_inr = :v, assessable_inr = :a, bcd_inr = :b, sws_inr = :s, igst_inr = :i, landed_inr = :l, landed_unit_inr = :lu WHERE id = :id',
    { v: f.value_inr, a: f.assessable_inr, b: f.bcd_inr, s: f.sws_inr, i: f.igst_inr, l: f.landed_inr, lu: f.landed_unit_inr, id: lineId }
  );
}

// ------------------------------------------------------------ supplier payments

async function payments(shipmentId) {
  return db.query(
    `SELECT p.*, u.name AS recorded_by_name FROM import_payments p LEFT JOIN users u ON u.id = p.recorded_by
      WHERE p.shipment_id = :s ORDER BY p.paid_on, p.id`,
    { s: shipmentId }
  );
}

async function addPayment(shipmentId, row, userId) {
  const r = await db.execute(
    `INSERT INTO import_payments (shipment_id, paid_on, kind, amount_fc, fx_rate, amount_inr, bank_reference, description, note, recorded_by)
     VALUES (:s, :d, :k, :a, :fx, :inr, :b, :desc, :n, :u)`,
    {
      s: shipmentId, d: row.paid_on, k: row.kind, a: row.amount_fc, fx: row.fx_rate, inr: row.amount_inr,
      b: row.bank_reference, desc: row.description, n: row.note, u: userId,
    }
  );
  return r.insertId;
}

async function bankReferenceUsed(bankReference) {
  return !!(await db.queryOne('SELECT 1 AS x FROM import_payments WHERE bank_reference = :b LIMIT 1', { b: bankReference }));
}

// ------------------------------------------------------------ stock

/** The balance row, locked until the transaction (executor) ends. */
async function lockBalance(divisionId, productId, executor) {
  // Make sure the row exists so that FOR UPDATE has something to lock (an unseen product starts at zero).
  await executor.execute('INSERT IGNORE INTO stock_balances (division_id, product_id, qty_on_hand, value_inr) VALUES (:d, :p, 0, 0)', { d: divisionId, p: productId });
  return executor.queryOne('SELECT * FROM stock_balances WHERE division_id = :d AND product_id = :p FOR UPDATE', { d: divisionId, p: productId });
}

async function setBalance(divisionId, productId, qty, value, executor) {
  await executor.execute('UPDATE stock_balances SET qty_on_hand = :q, value_inr = :v WHERE division_id = :d AND product_id = :p', { q: qty, v: value, d: divisionId, p: productId });
}

async function addMovement(m, userId, executor = db) {
  await executor.execute(
    `INSERT INTO stock_movements (division_id, product_id, moved_on, kind, quantity, value_inr, shipment_id, order_id, note, created_by)
     VALUES (:d, :p, :on, :k, :q, :v, :s, :o, :n, :u)`,
    {
      d: m.division_id, p: m.product_id, on: m.moved_on, k: m.kind, q: m.quantity, v: m.value_inr,
      s: m.shipment_id || null, o: m.order_id || null, n: m.note || null, u: userId,
    }
  );
}

async function balance(divisionId, productId) {
  return db.queryOne('SELECT * FROM stock_balances WHERE division_id = :d AND product_id = :p', { d: divisionId, p: productId });
}

/** Stock on hand per product (zero rows left out). */
async function stockOnHand(divisionIds) {
  let where = 'b.qty_on_hand <> 0';
  if (divisionIds !== null) where += ` AND b.division_id IN (${idList(divisionIds)})`;
  return db.query(
    `SELECT b.division_id, d.code AS division_code, b.product_id, p.product_code, p.name AS product_name, b.qty_on_hand, b.value_inr
       FROM stock_balances b JOIN products p ON p.id = b.product_id JOIN divisions d ON d.id = b.division_id
      WHERE ${where} ORDER BY p.name, b.division_id`
  );
}

/** The latest movements, newest first. */
async function movements(divisionIds, limit = 100) {
  const where = divisionIds === null ? '1=1' : `m.division_id IN (${idList(divisionIds)})`;
  const n = Math.max(1, Math.min(500, parseInt(limit, 10) || 100));
  return db.query(
    `SELECT m.*, p.product_code, p.name AS product_name, s.reference AS shipment_reference, o.order_reference
       FROM stock_movements m JOIN products p ON p.id = m.product_id
       LEFT JOIN import_shipments s ON s.id = m.shipment_id LEFT JOIN orders o ON o.id = m.order_id
      WHERE ${where} ORDER BY m.moved_on DESC, m.id DESC LIMIT ${n}`
  );
}

async function products() {
  return db.query('SELECT id, product_code, name, hs_code FROM products WHERE is_active = 1 ORDER BY name');
}

async function product(id) {
  return db.queryOne('SELECT id, product_code, name, hs_code, is_active FROM products WHERE id = :id', { id: parseInt(id, 10) || 0 });
}

module.exports = {
  dutyRates, addDutyRate, setDutyRateActive, find, list, insert, update, moveStage, lines, addLine, removeLine, setLineFigures,
  payments, addPayment, bankReferenceUsed, lockBalance, setBalance, addMovement, balance, stockOnHand, movements, products, product,
};
