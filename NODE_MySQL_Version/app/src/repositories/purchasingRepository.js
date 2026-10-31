'use strict';

const db = require('../config/db');

// Port of App\Repositories\PurchasingRepository. Owner decision D-214
// (2026-10-31), phase 3: purchase requirements (raised by Sales, no prices)
// and the supplier quotes Procurement records against them.

const STATUSES = {
  open: 'Waiting for quotes', quoted: 'Quotes in', awarded: 'Awarded — waiting for approval',
  approved: 'Approved — with the supplier', received: 'Received', cancelled: 'Cancelled',
};

const SELECT = `SELECT pr.*, o.order_reference, q.supplier_id AS awarded_supplier_id, s.supplier_legal_name AS awarded_supplier_name,
                       q.unit_price_inr AS awarded_unit_price_inr, q.gst_percent AS awarded_gst_percent, q.lead_time_days AS awarded_lead_time_days,
                       (SELECT COUNT(*) FROM purchase_quotes x WHERE x.requirement_id = pr.id) AS quote_count
                  FROM purchase_requirements pr
                  JOIN orders o ON o.id = pr.order_id
                  LEFT JOIN purchase_quotes q ON q.id = pr.awarded_quote_id
                  LEFT JOIN suppliers s ON s.id = q.supplier_id`;

async function forOrder(orderId) {
  return db.query(`${SELECT} WHERE pr.order_id = :o ORDER BY pr.id`, { o: orderId });
}

/** The Procurement queue: everything not received or cancelled, earliest need first. */
async function queue(status = null) {
  const where = status !== null ? 'pr.status = :s' : "pr.status NOT IN ('received', 'cancelled')";
  return db.query(`${SELECT} WHERE ${where} ORDER BY pr.needed_by, pr.id`, status !== null ? { s: status } : {});
}

/**
 * The Kanban board: every requirement still in play, plus those received in
 * the last 60 days (older ones stay in the list view), earliest need first;
 * cancelled ones are only counted.
 */
async function board() {
  const rows = await db.query(`${SELECT} WHERE pr.status NOT IN ('received', 'cancelled')
    OR (pr.status = 'received' AND pr.received_on >= CURDATE() - INTERVAL 60 DAY) ORDER BY pr.needed_by, pr.id`);
  const c = await db.queryOne("SELECT COUNT(*) AS n FROM purchase_requirements WHERE status = 'cancelled'");
  return { rows, cancelled: Number(c.n) };
}

async function find(id) {
  return db.queryOne(`${SELECT} WHERE pr.id = :id`, { id });
}

async function create(orderId, orderProductId, description, specification, quantity, unit, neededBy, raisedBy, note) {
  const result = await db.execute(
    `INSERT INTO purchase_requirements (order_id, order_product_id, description, specification, quantity, unit, needed_by, raised_by, note)
     VALUES (:o, :p, :d, :s, :q, :u, :n, :r, :note)`,
    { o: orderId, p: orderProductId, d: description, s: specification, q: quantity, u: unit, n: neededBy, r: raisedBy, note }
  );
  return result.insertId;
}

async function setStatus(id, status) {
  await db.execute('UPDATE purchase_requirements SET status = :s WHERE id = :id', { s: status, id });
}

async function award(id, quoteId, userId, approvalNeeded) {
  await db.execute(
    `UPDATE purchase_requirements SET awarded_quote_id = :q, awarded_by = :u, awarded_at = NOW(), approval_needed = :n,
            status = :s, approved_by = :ab, approved_at = IF(:n2 = 1, NULL, NOW())
      WHERE id = :id`,
    { q: quoteId, u: userId, n: approvalNeeded ? 1 : 0, n2: approvalNeeded ? 1 : 0, s: approvalNeeded ? 'awarded' : 'approved', ab: approvalNeeded ? null : userId, id }
  );
}

async function approve(id, userId) {
  await db.execute("UPDATE purchase_requirements SET status = 'approved', approved_by = :u, approved_at = NOW() WHERE id = :id", { u: userId, id });
}

async function receive(id, quantity, receivedOn, qcResult, qcNote, userId) {
  await db.execute(
    "UPDATE purchase_requirements SET status = 'received', received_quantity = :q, received_on = :d, qc_result = :r, qc_note = :n, received_by = :u WHERE id = :id",
    { q: quantity, d: receivedOn, r: qcResult, n: qcNote, u: userId, id }
  );
}

/** A requirement's quotes, cheapest landed value first. */
async function quotes(requirementId) {
  return db.query(
    `SELECT q.*, s.supplier_legal_name, ROUND(q.unit_price_inr * (1 + q.gst_percent / 100), 2) AS unit_price_with_gst_inr
       FROM purchase_quotes q JOIN suppliers s ON s.id = q.supplier_id
      WHERE q.requirement_id = :r ORDER BY unit_price_with_gst_inr, q.lead_time_days, q.id`,
    { r: requirementId }
  );
}

async function findQuote(id) {
  return db.queryOne('SELECT q.*, s.supplier_legal_name FROM purchase_quotes q JOIN suppliers s ON s.id = q.supplier_id WHERE q.id = :id', { id });
}

async function addQuote(requirementId, supplierId, unitPrice, gstPercent, leadTimeDays, validUntil, reference, note, userId) {
  const result = await db.execute(
    `INSERT INTO purchase_quotes (requirement_id, supplier_id, unit_price_inr, gst_percent, lead_time_days, valid_until, quote_reference, note, recorded_by)
     VALUES (:r, :s, :p, :g, :l, :v, :ref, :n, :u)`,
    { r: requirementId, s: supplierId, p: unitPrice, g: gstPercent, l: leadTimeDays, v: validUntil, ref: reference, n: note, u: userId }
  );
  return result.insertId;
}

/** A supplier's record: quotes given, orders won, received on time and quality checks failed. */
async function supplierPerformance() {
  return db.query(
    `SELECT s.id, s.supplier_legal_name,
            COUNT(DISTINCT q.id) AS quotes_given,
            COUNT(DISTINCT pr.id) AS awards_won,
            COUNT(DISTINCT CASE WHEN pr.status = 'received' THEN pr.id END) AS received,
            COUNT(DISTINCT CASE WHEN pr.status = 'received' AND pr.received_on <= pr.needed_by THEN pr.id END) AS on_time,
            COUNT(DISTINCT CASE WHEN pr.qc_result = 'failed' THEN pr.id END) AS qc_failed
       FROM suppliers s
       JOIN purchase_quotes q ON q.supplier_id = s.id
       LEFT JOIN purchase_requirements pr ON pr.awarded_quote_id = q.id
      GROUP BY s.id, s.supplier_legal_name
      ORDER BY awards_won DESC, s.supplier_legal_name`
  );
}

module.exports = {
  board,
  STATUSES, forOrder, queue, find, create, setStatus, award, approve, receive, quotes, findQuote, addQuote, supplierPerformance,
};
