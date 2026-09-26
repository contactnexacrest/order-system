'use strict';

const db = require('../config/db');

// CA / Accounting module (Phase 3) — the Zoho Books sync's own
// audit/error log, deliberately independent of auditLogRepository (see
// schema.sql comment on zoho_sync_log). Read-only from the outside, same
// as auditLogRepository: nothing here ever updates or deletes a row.

async function log(syncType, entityType, entityId, leg, status, zohoReference, message, triggeredBy, triggeredByUserId) {
  await db.execute(
    `INSERT INTO zoho_sync_log
        (sync_type, entity_type, entity_id, leg, status, zoho_reference, message, triggered_by, triggered_by_user_id)
     VALUES
        (:sync_type, :entity_type, :entity_id, :leg, :status, :zoho_reference, :message, :triggered_by, :triggered_by_user_id)`,
    {
      sync_type: syncType, entity_type: entityType, entity_id: entityId, leg, status,
      zoho_reference: zohoReference, message, triggered_by: triggeredBy, triggered_by_user_id: triggeredByUserId,
    }
  );
}

/** @return newest first */
async function recent(limit = 100) {
  return db.query(
    `SELECT zsl.*, u.name AS triggered_by_name
     FROM zoho_sync_log zsl
     LEFT JOIN users u ON u.id = zsl.triggered_by_user_id
     ORDER BY zsl.created_at DESC
     LIMIT ${parseInt(limit, 10)}`
  );
}

module.exports = { log, recent };
