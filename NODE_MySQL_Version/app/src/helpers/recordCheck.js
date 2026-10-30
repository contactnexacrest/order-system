'use strict';

/**
 * QA Round 6, R6-N04: an action on a record that does not exist (a stale
 * page, a typed URL) used to report success and write an audit row for it.
 * Each such action now checks first. Port of App\Helpers\RecordCheck.
 */
const db = require('../config/db');
const flash = require('./flash');

/** @param {Object<string, *>} where extra column => value conditions */
async function exists(table, id, where = {}) {
  let sql = `SELECT 1 AS found FROM ${table} WHERE id = :id`;
  const params = { id };
  for (const [column, value] of Object.entries(where)) {
    sql += ` AND ${column} = :w_${column}`;
    params[`w_${column}`] = value;
  }
  return !!(await db.queryOne(sql, params));
}

/** True when the query (SELECT 1 ... WHERE ...) finds a row. */
async function found(sql, params) {
  return !!(await db.queryOne(sql, params));
}

/** Sets the "no longer exists" message and redirects when the record is missing; true when it was. */
async function missing(req, res, table, id, redirect, what, where = {}) {
  if (await exists(table, id, where)) return false;
  flash.set(req, 'error', `That ${what} was not found — it may have been removed already. Nothing was changed.`);
  res.redirect(redirect);
  return true;
}

module.exports = { exists, found, missing };
