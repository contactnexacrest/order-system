'use strict';

const db = require('../config/db');

async function create(orderId, noticeDate, fromParty, description, assignedTo, responseDueDate) {
  const result = await db.execute(
    `INSERT INTO disputes (order_id, notice_date, from_party, description, assigned_to, response_due_date, status)
     VALUES (:order_id, :notice_date, :from_party, :description, :assigned_to, :response_due_date, 'Open')`,
    { order_id: orderId, notice_date: noticeDate, from_party: fromParty, description, assigned_to: assignedTo, response_due_date: responseDueDate }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT d.*, o.order_reference FROM disputes d JOIN orders o ON o.id = d.order_id WHERE d.id = :id', { id });
}

async function forOrder(orderId) {
  return db.query('SELECT * FROM disputes WHERE order_id = :order_id ORDER BY created_at DESC', { order_id: orderId });
}

/** Also carries company_legal_name — the card-grid list view (disputes/index.njk) shows it per dispute. */
async function all(statusFilter = null) {
  if (statusFilter) {
    return db.query(
      `SELECT d.*, o.order_reference, c.company_legal_name FROM disputes d
       JOIN orders o ON o.id = d.order_id JOIN clients c ON c.id = o.client_id
       WHERE d.status = :status ORDER BY d.created_at DESC`,
      { status: statusFilter }
    );
  }
  return db.query(
    `SELECT d.*, o.order_reference, c.company_legal_name FROM disputes d
     JOIN orders o ON o.id = d.order_id JOIN clients c ON c.id = o.client_id
     ORDER BY d.created_at DESC`
  );
}

async function updateStatus(id, status) {
  await db.execute('UPDATE disputes SET status = :status WHERE id = :id', { status, id });
}

async function resolve(id, resolutionNotes) {
  await db.execute("UPDATE disputes SET status = 'Resolved', resolved_at = NOW(), resolution_notes = :notes WHERE id = :id", { notes: resolutionNotes, id });
}

module.exports = { create, find, forOrder, all, updateStatus, resolve };
