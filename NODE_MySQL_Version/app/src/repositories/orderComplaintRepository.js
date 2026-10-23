'use strict';

const db = require('../config/db');

/** docs/schema.sql Section BX — a Complaint / Process Issue, distinct from a Dispute. */
async function create(orderId, clientId, description) {
  const result = await db.execute(
    `INSERT INTO order_complaints (order_id, client_id, description, status)
     VALUES (:order_id, :client_id, :description, 'open')`,
    { order_id: orderId, client_id: clientId, description }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne(
    'SELECT c.*, o.order_reference FROM order_complaints c JOIN orders o ON o.id = c.order_id WHERE c.id = :id',
    { id }
  );
}

async function forOrder(orderId) {
  return db.query('SELECT * FROM order_complaints WHERE order_id = :order_id ORDER BY created_at DESC', { order_id: orderId });
}

async function all(statusFilter = null) {
  if (statusFilter) {
    return db.query(
      `SELECT c.*, o.order_reference, o.company_legal_name FROM order_complaints c
       JOIN orders o ON o.id = c.order_id
       WHERE c.status = :status ORDER BY c.created_at DESC`,
      { status: statusFilter }
    );
  }
  return db.query(
    `SELECT c.*, o.order_reference, o.company_legal_name FROM order_complaints c
     JOIN orders o ON o.id = c.order_id
     ORDER BY c.created_at DESC`
  );
}

async function updateStatus(id, status) {
  if (status === 'resolved') {
    await db.execute("UPDATE order_complaints SET status = 'resolved', resolved_at = NOW() WHERE id = :id", { id });
    return;
  }
  await db.execute('UPDATE order_complaints SET status = :status, resolved_at = NULL WHERE id = :id', { status, id });
}

module.exports = { create, find, forOrder, all, updateStatus };
