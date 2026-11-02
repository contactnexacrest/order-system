'use strict';

const db = require('../config/db');
const { todayIst } = require('../helpers/dbDateTime');

/**
 * Port of AgreementRepository.php. Agreements register (owner decision 2 Nov 2026, point 4): every
 * agreement signed with a client, supplier, logistics partner, associate or staff member, with its
 * signed copy, dates and status.
 *
 * Rows of the `agreements` table are managed here. Two older records are listed alongside them,
 * read-only, so the register is complete without moving data the rest of the app depends on: the
 * client commercial agreement (clients.agreement_* — its footer prints on documents) and the associate
 * agreement (associates.agreement_* — the associate's own reminders read it).
 */

const PARTY_TYPES = {
  client: 'Client',
  supplier: 'Supplier',
  logistics_partner: 'Logistics partner',
  associate: 'Associate',
  staff: 'Staff member',
};

const STATUSES = {
  expiring: 'Expiring soon',
  active: 'Active',
  no_expiry: 'No expiry',
  expired: 'Expired',
  terminated: 'Terminated',
  renewed: 'Renewed (replaced)',
};

const EXPIRING_DAYS = 30;
const ALERT_DAYS = [30, 7, 0];

function todayStr() {
  return todayIst();
}

function addDays(ymd, days) {
  const d = new Date(`${ymd}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

function daysBetween(from, to) {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);
}

function statusOf(row, today) {
  if (row.terminated_on) return 'terminated';
  if (row.renewed && Number(row.renewed) > 0) return 'renewed';
  const expires = row.expires_on || null;
  if (!expires) return 'no_expiry';
  if (expires < today) return 'expired';
  return expires <= addDays(today, EXPIRING_DAYS) ? 'expiring' : 'active';
}

async function find(id) {
  return db.queryOne('SELECT * FROM agreements WHERE id = :id AND is_active = 1', { id });
}

async function create(data, userId) {
  const r = await db.execute(
    `INSERT INTO agreements (party_type, party_id, agreement_type, title, signed_on, starts_on, expires_on, file_id, renews_agreement_id, notes, created_by)
     VALUES (:party_type, :party_id, :agreement_type, :title, :signed_on, :starts_on, :expires_on, :file_id, :renews, :notes, :created_by)`,
    {
      party_type: data.party_type, party_id: Number(data.party_id), agreement_type: data.agreement_type,
      title: data.title ?? null, signed_on: data.signed_on, starts_on: data.starts_on ?? null,
      expires_on: data.expires_on ?? null, file_id: data.file_id ?? null, renews: data.renews_agreement_id ?? null,
      notes: data.notes ?? null, created_by: userId,
    }
  );
  return r.insertId;
}

async function update(id, data) {
  let sets = 'agreement_type = :agreement_type, title = :title, signed_on = :signed_on, starts_on = :starts_on, expires_on = :expires_on, notes = :notes';
  const params = {
    id, agreement_type: data.agreement_type, title: data.title ?? null, signed_on: data.signed_on,
    starts_on: data.starts_on ?? null, expires_on: data.expires_on ?? null, notes: data.notes ?? null,
  };
  if (data.file_id) {
    sets += ', file_id = :file_id';
    params.file_id = Number(data.file_id);
  }
  await db.execute(`UPDATE agreements SET ${sets} WHERE id = :id`, params);
}

async function terminate(id, on, reason) {
  await db.execute('UPDATE agreements SET terminated_on = :on, terminated_reason = :reason WHERE id = :id', { id, on, reason });
}

const PARTY_NAME_SQL = {
  client: 'SELECT company_legal_name AS n FROM clients WHERE id = :id',
  supplier: 'SELECT supplier_legal_name AS n FROM suppliers WHERE id = :id',
  logistics_partner: 'SELECT partner_name AS n FROM logistics_partners WHERE id = :id',
  associate: 'SELECT name AS n FROM associates WHERE id = :id',
  staff: 'SELECT name AS n FROM users WHERE id = :id',
};

async function partyName(type, id) {
  const sql = PARTY_NAME_SQL[type];
  if (!sql) return null;
  const row = await db.queryOne(sql, { id: Number(id) || 0 });
  return row ? String(row.n) : null;
}

function partyUrl(type, id) {
  return {
    client: `/clients/${id}`,
    supplier: `/suppliers/${id}/edit`,
    logistics_partner: `/logistics-partners/${id}/edit`,
    associate: `/associates/${id}`,
    staff: `/users/${id}/edit`,
  }[type] || '#';
}

async function partyOptions() {
  const pick = async (sql) => (await db.query(sql)).map((r) => ({ id: Number(r.id), name: String(r.n) }));
  return {
    client: await pick('SELECT id, company_legal_name AS n FROM clients WHERE is_active = 1 ORDER BY company_legal_name'),
    supplier: await pick('SELECT id, supplier_legal_name AS n FROM suppliers WHERE is_active = 1 ORDER BY supplier_legal_name'),
    logistics_partner: await pick('SELECT id, partner_name AS n FROM logistics_partners WHERE is_active = 1 ORDER BY partner_name'),
    associate: await pick('SELECT id, name AS n FROM associates ORDER BY name'),
    staff: await pick('SELECT id, name AS n FROM users WHERE is_active = 1 ORDER BY name'),
  };
}

/** Every agreement on record — the register's own rows plus the client and associate agreements kept on their own pages. */
async function all(today = null) {
  const t = today || todayStr();
  const rows = [];
  const own = await db.query(
    `SELECT a.*, (SELECT COUNT(*) FROM agreements n WHERE n.renews_agreement_id = a.id AND n.is_active = 1) AS renewed, f.original_filename
       FROM agreements a LEFT JOIN file_store f ON f.id = a.file_id
      WHERE a.is_active = 1 ORDER BY a.id`
  );
  for (const a of own) {
    a.key = `agreement:${a.id}`;
    a.source = 'register';
    a.party_name = (await partyName(a.party_type, a.party_id)) ?? '(removed)';
    rows.push(a);
  }
  const clients = await db.query(
    `SELECT id, company_legal_name, agreement_file_original_name, agreement_uploaded_at, agreement_expiry_date, agreement_force_expired
       FROM clients WHERE agreement_file_path IS NOT NULL AND agreement_file_path <> '' ORDER BY id`
  );
  for (const c of clients) {
    const forced = Number(c.agreement_force_expired) === 1;
    rows.push({
      key: `client:${c.id}`, source: 'client', id: null,
      party_type: 'client', party_id: Number(c.id), party_name: String(c.company_legal_name),
      agreement_type: 'Client commercial agreement', title: 'Footer agreement on this client\'s documents',
      signed_on: c.agreement_uploaded_at ? String(c.agreement_uploaded_at).slice(0, 10) : null,
      starts_on: null, expires_on: c.agreement_expiry_date || null,
      terminated_on: forced ? (c.agreement_expiry_date || t) : null,
      terminated_reason: forced ? 'Force-expired on the client page' : null,
      renewed: 0, original_filename: c.agreement_file_original_name, notes: null,
    });
  }
  const associates = await db.query(
    `SELECT a.id, a.name, a.status, a.agreement_signed_on, a.agreement_ends_on, f.original_filename
       FROM associates a LEFT JOIN file_store f ON f.id = a.agreement_file_id
      WHERE a.agreement_file_id IS NOT NULL ORDER BY a.id`
  );
  for (const s of associates) {
    const ended = s.status === 'ended';
    rows.push({
      key: `associate:${s.id}`, source: 'associate', id: null,
      party_type: 'associate', party_id: Number(s.id), party_name: String(s.name),
      agreement_type: 'Associate Agreement', title: 'Commission Structure and Associate Agreement',
      signed_on: s.agreement_signed_on || null, starts_on: null, expires_on: s.agreement_ends_on || null,
      terminated_on: ended ? (s.agreement_ends_on || t) : null,
      terminated_reason: ended ? 'Associate ended' : null,
      renewed: 0, original_filename: s.original_filename, notes: null,
    });
  }
  for (const r of rows) {
    r.status = statusOf(r, t);
    r.days_left = r.expires_on ? daysBetween(t, r.expires_on) : null;
  }
  return rows;
}

function visibleTo(rows, canManage) {
  return rows.filter((r) => canManage || r.party_type !== 'staff');
}

async function forParty(type, id, today = null) {
  return (await all(today)).filter((r) => r.party_type === type && Number(r.party_id) === Number(id));
}

async function alertAlreadySent(key, expiresOn, threshold) {
  return !!(await db.queryOne('SELECT 1 AS x FROM agreement_alerts_sent WHERE agreement_key = :k AND expires_on = :e AND threshold_days = :t', { k: key, e: expiresOn, t: threshold }));
}

async function markAlertSent(key, expiresOn, threshold) {
  await db.execute('INSERT IGNORE INTO agreement_alerts_sent (agreement_key, expires_on, threshold_days) VALUES (:k, :e, :t)', { k: key, e: expiresOn, t: threshold });
}

module.exports = {
  PARTY_TYPES, STATUSES, EXPIRING_DAYS, ALERT_DAYS,
  statusOf, find, create, update, terminate, partyName, partyUrl, partyOptions, all, visibleTo, forParty, alertAlreadySent, markAlertSent, todayStr,
};
