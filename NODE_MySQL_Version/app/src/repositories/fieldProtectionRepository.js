'use strict';

const db = require('../config/db');
const superAdminService = require('../services/superAdminService');

// Repository for the "protected fields" governance mechanism (Section L,
// schema.sql). One shared table/flag/workflow, reused across every table
// that can carry an is_protected column — currently company_settings,
// tc_clauses, payment_presets. Deliberately generic: a new protectable
// table only needs an is_protected column and an entry in PROTECTABLE_TABLES
// below, nothing else here changes.
//
// Flipping is_protected is NEVER done directly by a single user — it always
// goes through field_protection_requests: one user requests lock/unlock
// with a reason, a second, different privileged user (manage_field_protection)
// approves or rejects. Approval is the only code path that actually writes
// to the is_protected column; see resolve() below.

const PROTECTABLE_TABLES = {
  company_settings: { idCol: 'id', labelExpr: 'setting_key' },
  tc_clauses: { idCol: 'id', labelExpr: "CONCAT(COALESCE(clause_number, '—'), ' — ', clause_title)" },
  payment_presets: { idCol: 'id', labelExpr: 'preset_name' },
};

function assertKnownTable(tableName) {
  if (!Object.prototype.hasOwnProperty.call(PROTECTABLE_TABLES, tableName)) {
    const err = new Error(`Unknown protectable table: ${tableName}`);
    err.statusCode = 400;
    throw err;
  }
}

async function listProtectable() {
  const out = {};
  for (const [tableName, def] of Object.entries(PROTECTABLE_TABLES)) {
    // Table name and label expression both come from the fixed whitelist
    // above, never from user input, so this interpolation is safe.
    out[tableName] = await db.query(
      `SELECT ${def.idCol} AS id, (${def.labelExpr}) AS label, is_protected FROM ${tableName} ORDER BY ${def.idCol}`
    );
  }
  return out;
}

async function pendingRequests() {
  return db.query(
    `SELECT fpr.*, u.name AS requested_by_name
     FROM field_protection_requests fpr
     JOIN users u ON u.id = fpr.requested_by
     WHERE fpr.status = 'pending'
     ORDER BY fpr.created_at ASC`
  );
}

async function recentResolved(limit = 20) {
  return db.query(
    `SELECT fpr.*, u.name AS requested_by_name, r.name AS resolved_by_name
     FROM field_protection_requests fpr
     JOIN users u ON u.id = fpr.requested_by
     LEFT JOIN users r ON r.id = fpr.resolved_by
     WHERE fpr.status IN ('approved','rejected')
     ORDER BY fpr.resolved_at DESC
     LIMIT ${parseInt(limit, 10)}`
  );
}

async function findPendingForRecord(tableName, recordId) {
  assertKnownTable(tableName);
  return db.queryOne(
    `SELECT * FROM field_protection_requests
     WHERE table_name = :table_name AND record_id = :record_id AND status = 'pending' LIMIT 1`,
    { table_name: tableName, record_id: recordId }
  );
}

async function getRequest(requestId) {
  return db.queryOne('SELECT * FROM field_protection_requests WHERE id = :id', { id: requestId });
}

async function createRequest({ tableName, recordId, recordLabel, action, reason, requestedBy }) {
  assertKnownTable(tableName);
  if (!['lock', 'unlock'].includes(action)) {
    const err = new Error(`Invalid requested_action: ${action}`);
    err.statusCode = 400;
    throw err;
  }
  const result = await db.execute(
    `INSERT INTO field_protection_requests
        (table_name, record_id, record_label, requested_action, reason, requested_by)
     VALUES
        (:table_name, :record_id, :record_label, :action, :reason, :requested_by)`,
    { table_name: tableName, record_id: recordId, record_label: recordLabel, action, reason, requested_by: requestedBy }
  );
  return result.insertId;
}

// Approves a pending request: flips the actual is_protected column on the
// target table, then marks the request resolved. The two writes happen in
// one transaction so a request can never end up "approved" while the
// underlying flag failed to flip, or vice versa.
async function approve(requestId, resolvedBy, resolvedReason) {
  const request = await getRequest(requestId);
  if (!request) {
    const err = new Error('Request not found.');
    err.statusCode = 404;
    throw err;
  }
  if (request.status !== 'pending') {
    const err = new Error('This request has already been resolved.');
    err.statusCode = 409;
    throw err;
  }
  if (request.requested_by === resolvedBy && !(await superAdminService.isEffective(resolvedBy))) {
    const err = new Error('You cannot approve your own protection request — a different privileged user must confirm.');
    err.statusCode = 403;
    throw err;
  }
  assertKnownTable(request.table_name);
  const def = PROTECTABLE_TABLES[request.table_name];
  const newFlag = request.requested_action === 'lock' ? 1 : 0;

  await db.transaction(async (conn) => {
    await conn.execute(
      `UPDATE ${request.table_name} SET is_protected = :flag WHERE ${def.idCol} = :id`,
      { flag: newFlag, id: request.record_id }
    );
    await conn.execute(
      `UPDATE field_protection_requests
         SET status = 'approved', resolved_by = :resolved_by, resolved_reason = :resolved_reason, resolved_at = NOW()
       WHERE id = :id`,
      { resolved_by: resolvedBy, resolved_reason: resolvedReason || null, id: requestId }
    );
  });

  return { ...request, newFlag };
}

async function reject(requestId, resolvedBy, resolvedReason) {
  const request = await getRequest(requestId);
  if (!request) {
    const err = new Error('Request not found.');
    err.statusCode = 404;
    throw err;
  }
  if (request.status !== 'pending') {
    const err = new Error('This request has already been resolved.');
    err.statusCode = 409;
    throw err;
  }
  if (request.requested_by === resolvedBy) {
    const err = new Error('You cannot reject your own protection request — a different privileged user must action it.');
    err.statusCode = 403;
    throw err;
  }
  await db.execute(
    `UPDATE field_protection_requests
        SET status = 'rejected', resolved_by = :resolved_by, resolved_reason = :resolved_reason, resolved_at = NOW()
      WHERE id = :id`,
    { resolved_by: resolvedBy, resolved_reason: resolvedReason || null, id: requestId }
  );
  return request;
}

module.exports = {
  PROTECTABLE_TABLES,
  listProtectable,
  pendingRequests,
  recentResolved,
  findPendingForRecord,
  getRequest,
  createRequest,
  approve,
  reject,
};
