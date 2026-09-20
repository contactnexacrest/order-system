'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\ReportDefinitionRepository. Spec Section 16 /
 * ARCHITECTURE.md's own addition — "Saved report definitions ... so
 * recurring reports (overdue payments, stage-wise pipeline, buyer
 * history) are configure-once, run-repeatedly rather than rebuilt from
 * scratch each time." report_type drives which reportRepository query
 * runs; filters_json/columns_json are opaque to this repository
 * (the controller interprets them).
 */

async function create(name, reportType, ownerUserId, visibility, filters, columns) {
  const result = await db.execute(
    `INSERT INTO report_definitions (name, report_type, owner_user_id, visibility, filters_json, columns_json)
     VALUES (:name, :report_type, :owner_user_id, :visibility, :filters_json, :columns_json)`,
    {
      name,
      report_type: reportType,
      owner_user_id: ownerUserId,
      visibility: visibility === 'shared' ? 'shared' : 'private',
      filters_json: JSON.stringify(filters ?? {}),
      columns_json: JSON.stringify(columns ?? {}),
    }
  );
  return result.insertId;
}

async function find(id) {
  const row = await db.queryOne('SELECT * FROM report_definitions WHERE id = :id', { id });
  if (row) {
    try { row.filters_json = JSON.parse(row.filters_json) || {}; } catch { row.filters_json = {}; }
    try { row.columns_json = JSON.parse(row.columns_json) || {}; } catch { row.columns_json = {}; }
  }
  return row;
}

/** Visible to this user: their own (private or shared) plus everyone else's shared ones. */
async function visibleTo(userId) {
  return db.query(
    `SELECT rd.*, u.name AS owner_name FROM report_definitions rd
     JOIN users u ON u.id = rd.owner_user_id
     WHERE rd.owner_user_id = :user_id OR rd.visibility = 'shared'
     ORDER BY rd.name`,
    { user_id: userId }
  );
}

async function markRun(id) {
  await db.execute('UPDATE report_definitions SET last_run_at = NOW() WHERE id = :id', { id });
}

async function deleteDefinition(id) {
  await db.execute('DELETE FROM report_definitions WHERE id = :id', { id });
}

module.exports = { create, find, visibleTo, markRun, delete: deleteDefinition };
