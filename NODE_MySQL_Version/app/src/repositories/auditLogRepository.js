'use strict';

const db = require('../config/db');

// Port of App\Repositories\AuditLogRepository. Read-only viewer by design —
// no function here ever updates or deletes a row.

async function log(userId, actionType, entityType = null, entityId = null, fieldName = null, oldValue = null, newValue = null, reason = null, ipAddress = null) {
  await db.execute(
    `INSERT INTO audit_log
        (user_id, action_type, entity_type, entity_id, field_name, old_value, new_value, reason, ip_address)
     VALUES
        (:user_id, :action_type, :entity_type, :entity_id, :field_name, :old_value, :new_value, :reason, :ip)`,
    {
      user_id: userId, action_type: actionType, entity_type: entityType, entity_id: entityId,
      field_name: fieldName, old_value: oldValue, new_value: newValue, reason, ip: ipAddress,
    }
  );
}

async function search({ entityType = null, entityId = null, userId = null, actionType = null, dateFrom = null, dateTo = null, limit = 100, offset = 0 } = {}) {
  const where = [];
  const params = {};
  if (entityType) { where.push('al.entity_type = :entity_type'); params.entity_type = entityType; }
  if (entityId) { where.push('al.entity_id = :entity_id'); params.entity_id = entityId; }
  if (userId) { where.push('al.user_id = :user_id'); params.user_id = userId; }
  if (actionType) { where.push('al.action_type = :action_type'); params.action_type = actionType; }
  if (dateFrom) { where.push('DATE(al.created_at) >= :date_from'); params.date_from = dateFrom; }
  if (dateTo) { where.push('DATE(al.created_at) <= :date_to'); params.date_to = dateTo; }

  let sql = `SELECT al.*, u.name AS user_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id`;
  if (where.length) sql += ' WHERE ' + where.join(' AND ');
  sql += ` ORDER BY al.created_at DESC LIMIT ${parseInt(limit, 10)} OFFSET ${parseInt(offset, 10)}`;

  return db.query(sql, params);
}

async function distinctActionTypes() {
  const rows = await db.query('SELECT DISTINCT action_type FROM audit_log ORDER BY action_type');
  return rows.map((r) => r.action_type);
}

module.exports = { log, search, distinctActionTypes };
