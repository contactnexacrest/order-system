'use strict';

const db = require('../config/db');

/** Port of AssociateEventRepository.php — the associate's history (nobody edits it), notices and messages. */

async function add(associateId, event, message, clientId = null, orderId = null, invitationId = null, userId = null, notice = true) {
  const r = await db.execute(
    `INSERT INTO associate_events (associate_id, client_id, order_id, invitation_id, event, message, user_id, is_notice)
     VALUES (:a, :c, :o, :i, :e, :m, :u, :n)`,
    { a: associateId, c: clientId ?? null, o: orderId ?? null, i: invitationId ?? null, e: event, m: [...String(message)].slice(0, 600).join(''), u: userId ?? null, n: notice ? 1 : 0 }
  );
  return r.insertId;
}

/** Newest first. */
async function forAssociate(associateId, clientId = null, limit = 200) {
  let sql = 'SELECT e.*, c.company_legal_name FROM associate_events e LEFT JOIN clients c ON c.id = e.client_id WHERE e.associate_id = :a';
  const params = { a: associateId };
  if (clientId !== null && clientId !== undefined) {
    sql += ' AND e.client_id = :c';
    params.c = clientId;
  }
  return db.query(`${sql} ORDER BY e.id DESC LIMIT ${Math.max(1, parseInt(limit, 10) || 200)}`, params);
}

async function unreadNotices(associateId) {
  return Number((await db.queryOne('SELECT COUNT(*) AS n FROM associate_events WHERE associate_id = :a AND is_notice = 1 AND read_at IS NULL', { a: associateId })).n);
}

async function markNoticesRead(associateId) {
  await db.execute('UPDATE associate_events SET read_at = NOW() WHERE associate_id = :a AND is_notice = 1 AND read_at IS NULL', { a: associateId });
}

async function hasEvent(associateId, event, clientId, orderId) {
  return (await db.queryOne(
    'SELECT 1 AS yes FROM associate_events WHERE associate_id = :a AND event = :e AND client_id <=> :c AND order_id <=> :o LIMIT 1',
    { a: associateId, e: event, c: clientId ?? null, o: orderId ?? null }
  )) !== null;
}

async function addMessage(associateId, fromAssociate, userId, body) {
  const r = await db.execute('INSERT INTO associate_messages (associate_id, from_associate, user_id, body) VALUES (:a, :f, :u, :b)',
    { a: associateId, f: fromAssociate ? 1 : 0, u: userId ?? null, b: body });
  return r.insertId;
}

async function messages(associateId) {
  return db.query('SELECT m.*, u.name AS user_name FROM associate_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.associate_id = :a ORDER BY m.id', { a: associateId });
}

module.exports = { add, forAssociate, unreadNotices, markNoticesRead, hasEvent, addMessage, messages };
