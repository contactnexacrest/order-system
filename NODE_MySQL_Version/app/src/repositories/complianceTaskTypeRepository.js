'use strict';

const db = require('../config/db');

/**
 * Admin-editable list of compliance/pre-closure task names (docs/schema.sql
 * Section AR) — ECGC Cover, Pre-Shipment Inspection, etc. Gated on
 * manage_compliance_task_types; the per-order checklist itself (which
 * actually uses this list) is gated on the existing close_orders permission
 * — see orderComplianceTaskRepository.
 */

async function all(includeInactive = false) {
  let sql = 'SELECT * FROM compliance_task_types';
  if (!includeInactive) {
    sql += ' WHERE is_active = 1';
  }
  sql += ' ORDER BY name';
  return db.query(sql);
}

async function find(id) {
  return db.queryOne('SELECT * FROM compliance_task_types WHERE id = :id', { id });
}

async function create(name, createdBy) {
  const result = await db.execute(
    'INSERT INTO compliance_task_types (name, created_by) VALUES (:name, :created_by)',
    { name, created_by: createdBy }
  );
  return result.insertId;
}

async function update(id, name) {
  await db.execute('UPDATE compliance_task_types SET name = :name WHERE id = :id', { name, id });
}

async function toggleActive(id) {
  await db.execute('UPDATE compliance_task_types SET is_active = 1 - is_active WHERE id = :id', { id });
}

/** Throws (err.code === 'ER_ROW_IS_REFERENCED_2') if the type is still referenced by order_compliance_tasks — caller should catch and flash a friendly message */
async function remove(id) {
  await db.execute('DELETE FROM compliance_task_types WHERE id = :id', { id });
}

module.exports = { all, find, create, update, toggleActive, remove };
