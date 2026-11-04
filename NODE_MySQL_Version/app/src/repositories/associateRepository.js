'use strict';

const db = require('../config/db');

/**
 * Port of AssociateRepository.php. Associates (owner decision
 * 2026-10-30): outside people, in India or abroad, who introduce new
 * clients under a signed agreement and earn its rate ladder on those
 * clients' first orders. A client is linked to the associate who
 * introduced it (clients.associate_id), with the date the lead was
 * registered.
 */

// D-245 P3 (4 Nov 2026): the per-rung rates are gone — commission levels and the ladder (Admin → Pricing) replace them.
const FIELDS = ['name', 'based_in', 'country', 'currency_code', 'email', 'phone', 'tax_id', 'agreement_signed_on',
  'status', 'notes',
  'no_commission', 'agreement_ends_on', 'relationship_manager_id', 'pan', 'gstin', 'address'];

/** Owner decision 1 Nov 2026 (AS-1 … AS-17): the associate's life cycle. 'inactive' is kept for older rows. */
const STATUSES = { invited: 'Invited', active: 'Active', suspended: 'Suspended', ended: 'Agreement ended', inactive: 'Inactive' };

/** Every field, missing ones empty (no_commission off, status invited). */
function pick(data) {
  const out = {};
  for (const f of FIELDS) {
    const v = data[f];
    out[f] = v === undefined || v === null ? (f === 'no_commission' ? 0 : (f === 'status' ? 'invited' : null)) : v;
  }
  return out;
}

/** With the number of clients each introduced. */
async function all() {
  return db.query(
    `SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.associate_id = a.id) AS client_count, f.original_filename AS agreement_file_name,
            cl.name AS commission_level_name
     FROM associates a LEFT JOIN file_store f ON f.id = a.agreement_file_id LEFT JOIN commission_levels cl ON cl.id = a.commission_level_id ORDER BY a.status, a.name`
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM associates WHERE id = :id', { id });
}

async function create(data, userId) {
  const cols = FIELDS.join(', ');
  const marks = FIELDS.map((f) => `:${f}`).join(', ');
  const result = await db.execute(`INSERT INTO associates (${cols}, created_by) VALUES (${marks}, :created_by)`, { ...pick(data), created_by: userId });
  return result.insertId;
}

async function update(id, data) {
  const sets = FIELDS.map((f) => `${f} = :${f}`).join(', ');
  await db.execute(`UPDATE associates SET ${sets} WHERE id = :id`, { ...pick(data), id });
}

async function findByEmail(email) {
  return db.queryOne('SELECT * FROM associates WHERE LOWER(email) = LOWER(:e)', { e: String(email || '').trim() });
}

async function findByCode(code) {
  return db.queryOne('SELECT * FROM associates WHERE personal_code = :c', { c: String(code || '').trim().toUpperCase() });
}

async function setFields(id, fields) {
  const keys = Object.keys(fields);
  if (!keys.length) return;
  const sets = keys.map((f) => `${f} = :${f}`).join(', ');
  const params = { id };
  for (const k of keys) params[k] = fields[k] === undefined ? null : fields[k];
  await db.execute(`UPDATE associates SET ${sets} WHERE id = :id`, params);
}

/** Associates per status, for the board and the filter row. */
async function statusCounts() {
  const out = {};
  for (const k of Object.keys(STATUSES)) out[k] = 0;
  for (const r of await db.query('SELECT status, COUNT(*) AS n FROM associates GROUP BY status')) {
    out[String(r.status)] = Number(r.n);
  }
  return out;
}

/** The clients the associate introduced. */
async function clients(associateId) {
  return db.query(
    'SELECT id, company_legal_name, client_unique_number, country_of_destination, associate_registered_on, associate_locked_until, associate_source FROM clients WHERE associate_id = :id ORDER BY associate_registered_on, company_legal_name',
    { id: associateId }
  );
}

async function linkClient(clientId, associateId, registeredOn) {
  await db.execute('UPDATE clients SET associate_id = :a, associate_registered_on = :d WHERE id = :id', {
    a: associateId, d: associateId === null ? null : registeredOn, id: clientId,
  });
}

/** Clients not yet linked to any associate, for the link picker. */
async function unlinkedClients() {
  return db.query('SELECT id, company_legal_name, client_unique_number FROM clients WHERE associate_id IS NULL AND is_test_data = 0 ORDER BY company_legal_name');
}

/** The signed agreement (owner decision 2026-10-30): a file_store row, replaced by a newer upload. */
async function setAgreementFile(id, fileId) {
  await db.execute('UPDATE associates SET agreement_file_id = :f WHERE id = :id', { f: fileId, id });
}

/**
 * Whether the file belongs to an associate — their signed agreement, a
 * KYC / tax / invoice document, or a bank proof (owner decision 1 Nov
 * 2026). The general file link refuses it; it is served only on the
 * associate's own pages.
 */
async function isAgreementFile(fileId) {
  return (await db.queryOne(
    `SELECT 1 AS yes FROM associates WHERE agreement_file_id = :f
     UNION SELECT 1 FROM associate_documents WHERE file_id = :f2
     UNION SELECT 1 FROM associate_bank_accounts WHERE proof_file_id = :f3 LIMIT 1`,
    { f: fileId, f2: fileId, f3: fileId }
  )) !== null;
}

/**
 * Owner decision 1 Nov 2026 (guarantee 2): staff see first that a client is
 * an associate's — on the client page and the order page. Only for those who
 * may see associates; no amounts.
 */
async function lineForClient(clientId, permissions) {
  if (!permissions || !(permissions.view_associates || permissions.manage_associates)) return null;
  return db.queryOne(
    'SELECT a.id, a.name, c.associate_registered_on, c.associate_locked_until FROM clients c JOIN associates a ON a.id = c.associate_id WHERE c.id = :c',
    { c: clientId }
  );
}

/** A6: the associate who used to have this code (a retired code — not their current one), or null. */
async function findByRetiredCode(code) {
  return db.queryOne(
    'SELECT a.* FROM associate_codes c JOIN associates a ON a.id = c.associate_id WHERE c.code = :c AND (a.personal_code IS NULL OR a.personal_code <> c.code) LIMIT 1',
    { c: String(code || '').trim().toUpperCase() }
  );
}

module.exports = {
  lineForClient, findByRetiredCode,
  FIELDS, STATUSES, all, find, create, update, setAgreementFile, isAgreementFile,
  findByEmail, findByCode, setFields, statusCounts, clients, linkClient, unlinkedClients,
};
