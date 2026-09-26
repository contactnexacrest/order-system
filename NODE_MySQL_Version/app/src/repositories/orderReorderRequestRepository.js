'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\OrderReorderRequestRepository — client-
 * initiated "repeat order" requests (schema.sql Section AJ). See the PHP
 * class doc comment for the full rationale.
 */

async function create(clientId, sourceOrderId, notes) {
  const result = await db.execute(
    'INSERT INTO order_reorder_requests (client_id, source_order_id, notes) VALUES (:client_id, :source_order_id, :notes)',
    { client_id: clientId, source_order_id: sourceOrderId, notes }
  );
  return result.insertId;
}

async function addProductLine(requestId, lineNo, description, dimensions, finish, quantity, quantityIsTbc, unit, unitPrice, hsCode) {
  await db.execute(
    `INSERT INTO order_reorder_request_products
        (reorder_request_id, line_no, description, dimensions, finish, quantity, quantity_is_tbc, unit, unit_price, hs_code)
     VALUES
        (:reorder_request_id, :line_no, :description, :dimensions, :finish, :quantity, :quantity_is_tbc, :unit, :unit_price, :hs_code)`,
    {
      reorder_request_id: requestId, line_no: lineNo, description, dimensions, finish,
      quantity: quantity || null, quantity_is_tbc: quantityIsTbc ? 1 : 0, unit,
      unit_price: unitPrice || null, hs_code: hsCode || null,
    }
  );
}

async function find(id) {
  return db.queryOne(
    `SELECT rr.*, c.company_legal_name, o.order_reference AS source_order_reference
     FROM order_reorder_requests rr
     JOIN clients c ON c.id = rr.client_id
     JOIN orders o ON o.id = rr.source_order_id
     WHERE rr.id = :id`,
    { id }
  );
}

async function productLines(requestId) {
  return db.query('SELECT * FROM order_reorder_request_products WHERE reorder_request_id = :id ORDER BY line_no', { id: requestId });
}

async function pendingReview() {
  return db.query(
    `SELECT rr.*, c.company_legal_name, o.order_reference AS source_order_reference
     FROM order_reorder_requests rr
     JOIN clients c ON c.id = rr.client_id
     JOIN orders o ON o.id = rr.source_order_id
     WHERE rr.status = 'pending'
     ORDER BY rr.submitted_at ASC`
  );
}

async function recentResolved(limit = 20) {
  return db.query(
    `SELECT rr.*, c.company_legal_name, o.order_reference AS source_order_reference,
            u.name AS reviewed_by_name, no.order_reference AS new_order_reference
     FROM order_reorder_requests rr
     JOIN clients c ON c.id = rr.client_id
     JOIN orders o ON o.id = rr.source_order_id
     LEFT JOIN users u ON u.id = rr.reviewed_by
     LEFT JOIN orders no ON no.id = rr.new_order_id
     WHERE rr.status != 'pending'
     ORDER BY rr.reviewed_at DESC
     LIMIT ${parseInt(limit, 10)}`
  );
}

async function forClient(clientId) {
  return db.query(
    `SELECT rr.*, o.order_reference AS source_order_reference, no.order_reference AS new_order_reference
     FROM order_reorder_requests rr
     JOIN orders o ON o.id = rr.source_order_id
     LEFT JOIN orders no ON no.id = rr.new_order_id
     WHERE rr.client_id = :client_id
     ORDER BY rr.submitted_at DESC`,
    { client_id: clientId }
  );
}

async function markApproved(id, reviewedBy, newOrderId) {
  await db.execute(
    `UPDATE order_reorder_requests
     SET status = 'approved', reviewed_by = :reviewed_by, reviewed_at = NOW(), new_order_id = :new_order_id
     WHERE id = :id`,
    { reviewed_by: reviewedBy, new_order_id: newOrderId, id }
  );
}

async function markRejected(id, reviewedBy, reason) {
  await db.execute(
    `UPDATE order_reorder_requests
     SET status = 'rejected', reviewed_by = :reviewed_by, reviewed_at = NOW(), rejection_reason = :reason
     WHERE id = :id`,
    { reviewed_by: reviewedBy, reason, id }
  );
}

module.exports = { create, addProductLine, find, productLines, pendingReview, recentResolved, forClient, markApproved, markRejected };
