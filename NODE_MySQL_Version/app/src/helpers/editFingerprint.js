'use strict';

/**
 * QA Round 6, R6-N27: two people with the same edit form open — the second
 * save silently put back what the first had just changed. The form now
 * carries a fingerprint of the values it was opened with (only the fields
 * that form edits, so unrelated activity on the record never blocks it); a
 * save is refused when the record no longer matches. Port of
 * App\Helpers\EditFingerprint.
 */
const crypto = require('crypto');
const db = require('../config/db');
const dates = require('./dates');

const FIELD = 'edit_fingerprint';

function plain(value) {
  if (value === null || value === undefined) return null;
  if (value instanceof Date) return value.toISOString();
  return String(value);
}

function of(row, fields) {
  const values = {};
  for (const field of fields) values[field] = plain(row ? row[field] : null);
  return crypto.createHash('sha1').update(JSON.stringify(values)).digest('hex');
}

/**
 * The "changed since you opened it" message, or null when the record still
 * matches the form (or the form sent no fingerprint).
 */
async function conflict(body, current, fields, entityType, entityId, what) {
  const posted = String((body || {})[FIELD] || '').trim();
  if (posted === '' || posted === of(current, fields)) return null;
  const last = await db.queryOne(
    `SELECT u.name, a.created_at FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     WHERE a.entity_type = :type AND a.entity_id = :id ORDER BY a.id DESC LIMIT 1`,
    { type: entityType, id: entityId }
  );
  const by = last ? ` by ${last.name || 'someone else'} at ${dates.human(last.created_at)}` : ' by someone else';
  return `This ${what} was changed${by} after you opened this page. Reload the page to see the change, then make yours again. Nothing was saved.`;
}

module.exports = { FIELD, of, conflict };
