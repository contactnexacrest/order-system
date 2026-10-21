'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\PortRouteRepository. 90-item backlog item 15:
 * port-to-port sailing-days reference document. Never hard-deleted — same
 * convention as portRepository itself — deactivate instead so a route
 * already quoted from doesn't disappear from under an order's history.
 * One row per (loading, discharge) pair, enforced by a DB unique
 * constraint (uq_port_routes_pair) — create()/update() let a violation
 * propagate as a plain mysql2 error (code ER_DUP_ENTRY); the controller
 * catches it, same convention as holidayController's date uniqueness.
 */

/** @returns {Promise<Array>} joined with both ports' names for the admin list screen */
async function all(includeInactive = false) {
  let sql = `SELECT r.*, pl.name AS port_of_loading_name, pd.name AS port_of_discharge_name
             FROM port_routes r
             JOIN ports pl ON pl.id = r.port_of_loading_id
             JOIN ports pd ON pd.id = r.port_of_discharge_id`;
  if (!includeInactive) {
    sql += ' WHERE r.is_active = 1';
  }
  sql += ' ORDER BY pl.name, pd.name';
  return db.query(sql);
}

async function find(id) {
  return db.queryOne('SELECT * FROM port_routes WHERE id = :id', { id });
}

/** The lookup the order create/edit form's auto-fill calls — active routes only. */
async function findByPorts(portOfLoadingId, portOfDischargeId) {
  return db.queryOne(
    'SELECT * FROM port_routes WHERE port_of_loading_id = :loading AND port_of_discharge_id = :discharge AND is_active = 1',
    { loading: portOfLoadingId, discharge: portOfDischargeId }
  );
}

async function create(portOfLoadingId, portOfDischargeId, sailingDaysMin, sailingDaysMax, notes) {
  const result = await db.execute(
    `INSERT INTO port_routes (port_of_loading_id, port_of_discharge_id, sailing_days_min, sailing_days_max, notes, is_active)
     VALUES (:loading, :discharge, :min, :max, :notes, 1)`,
    { loading: portOfLoadingId, discharge: portOfDischargeId, min: sailingDaysMin, max: sailingDaysMax, notes }
  );
  return result.insertId;
}

async function update(id, portOfLoadingId, portOfDischargeId, sailingDaysMin, sailingDaysMax, notes) {
  await db.execute(
    `UPDATE port_routes SET port_of_loading_id = :loading, port_of_discharge_id = :discharge,
        sailing_days_min = :min, sailing_days_max = :max, notes = :notes WHERE id = :id`,
    { loading: portOfLoadingId, discharge: portOfDischargeId, min: sailingDaysMin, max: sailingDaysMax, notes, id }
  );
}

async function toggleActive(id) {
  await db.execute('UPDATE port_routes SET is_active = 1 - is_active WHERE id = :id', { id });
}

module.exports = { all, find, findByPorts, create, update, toggleActive };
