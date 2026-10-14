'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\PortRepository (90-item backlog #59). `ports`
 * already existed and already backed lookupRepository.ports($role), but
 * had no admin CRUD at all — only the single seeded Chennai (loading)
 * row existed. Never hard-deleted — orders.port_of_loading_id/
 * port_of_discharge_id carry a real FK to this table, so an existing
 * order's port must never disappear from under it; deactivate instead
 * (same convention as dropdownOptionRepository/paymentPresetRepository).
 */

async function all(includeInactive = false) {
  let sql = 'SELECT * FROM ports';
  if (!includeInactive) {
    sql += ' WHERE is_active = 1';
  }
  sql += ' ORDER BY sort_order, name';
  return db.query(sql);
}

async function find(id) {
  return db.queryOne('SELECT * FROM ports WHERE id = :id', { id });
}

async function create(name, country, portRole, sortOrder) {
  const result = await db.execute(
    'INSERT INTO ports (name, country, port_role, is_default, is_active, sort_order) VALUES (:name, :country, :role, 0, 1, :sort_order)',
    { name, country, role: portRole, sort_order: sortOrder }
  );
  return result.insertId;
}

async function update(id, name, country, portRole, sortOrder) {
  await db.execute(
    'UPDATE ports SET name = :name, country = :country, port_role = :role, sort_order = :sort_order WHERE id = :id',
    { name, country, role: portRole, sort_order: sortOrder, id }
  );
}

async function toggleActive(id) {
  await db.execute('UPDATE ports SET is_active = 1 - is_active WHERE id = :id', { id });
}

/**
 * Unsets is_default on every other port that would appear alongside this
 * one in lookupRepository.ports() for ANY role this port is eligible for,
 * so the order-creation form's Port of Loading/Discharge dropdowns never
 * end up with two options simultaneously marked `selected`. A 'both'-role
 * port is eligible for both dropdowns, so setting it default clears
 * every other port's flag entirely; a 'loading' or 'discharge' port only
 * needs to clear that one role plus 'both'.
 */
async function setDefault(id) {
  const port = await find(id);
  if (!port) return;
  if (port.port_role === 'both') {
    await db.execute('UPDATE ports SET is_default = 0');
  } else {
    await db.execute("UPDATE ports SET is_default = 0 WHERE port_role = :role OR port_role = 'both'", { role: port.port_role });
  }
  await db.execute('UPDATE ports SET is_default = 1 WHERE id = :id', { id });
}

module.exports = { all, find, create, update, toggleActive, setDefault };
