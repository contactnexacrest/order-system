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

/**
 * Owner request (2026-10-26): the checklist is split by when each task is
 * done — with the Supplier PO (Due Diligence, ECGC Cover...) or before
 * shipment (Fumigation, Phytosanitary, Pre-Shipment Inspection...).
 */
const PHASES = {
  order: 'With the Supplier PO (Step 7)',
  shipment: 'Before shipment (Step 10)',
};

async function create(name, createdBy, phase = 'shipment') {
  const result = await db.execute(
    'INSERT INTO compliance_task_types (name, phase, created_by) VALUES (:name, :phase, :created_by)',
    { name, phase, created_by: createdBy }
  );
  return result.insertId;
}

/** `phase` null/undefined keeps the task type's current phase. */
async function update(id, name, phase = null) {
  await db.execute('UPDATE compliance_task_types SET name = :name, phase = COALESCE(:phase, phase) WHERE id = :id', { name, phase, id });
}

async function toggleActive(id) {
  await db.execute('UPDATE compliance_task_types SET is_active = 1 - is_active WHERE id = :id', { id });
}

/** Throws (err.code === 'ER_ROW_IS_REFERENCED_2') if the type is still referenced by order_compliance_tasks — caller should catch and flash a friendly message */
async function remove(id) {
  await db.execute('DELETE FROM compliance_task_types WHERE id = :id', { id });
}

module.exports = { PHASES, all, find, create, update, toggleActive, remove };
