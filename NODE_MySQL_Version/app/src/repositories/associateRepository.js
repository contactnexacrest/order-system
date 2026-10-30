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

/** The agreement's ladder: [upper bound of the associate's order count, rate column]. */
const TIERS = [[5, 'rate_1_5'], [10, 'rate_6_10'], [20, 'rate_11_20'], [40, 'rate_21_40'], [Infinity, 'rate_41_plus']];

const FIELDS = ['name', 'based_in', 'country', 'currency_code', 'email', 'phone', 'tax_id', 'agreement_signed_on',
  'rate_1_5', 'rate_6_10', 'rate_11_20', 'rate_21_40', 'rate_41_plus', 'status', 'notes'];

function pick(data) {
  const out = {};
  for (const f of FIELDS) {
    out[f] = data[f] === undefined ? null : data[f];
  }
  return out;
}

/** With the number of clients each introduced. */
async function all() {
  return db.query(
    `SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.associate_id = a.id) AS client_count, f.original_filename AS agreement_file_name
     FROM associates a LEFT JOIN file_store f ON f.id = a.agreement_file_id ORDER BY a.status, a.name`
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

/** The rate (%) for the associate's Nth commission order. */
function rateFor(associate, orderNumber) {
  for (const [upTo, column] of TIERS) {
    if (orderNumber <= upTo) {
      return Number(associate[column]);
    }
  }
  return Number(associate.rate_41_plus);
}

/** The clients the associate introduced. */
async function clients(associateId) {
  return db.query(
    'SELECT id, company_legal_name, client_unique_number, associate_registered_on FROM clients WHERE associate_id = :id ORDER BY associate_registered_on, company_legal_name',
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

/**
 * The associate's commission order number for this client: the same
 * number for every order of the client's first 10 days (one "order" under
 * the agreement), otherwise one more than the clients already counted for
 * this associate.
 */
async function orderNumberForClient(associateId, clientId) {
  const existing = await db.queryOne(
    `SELECT oi.associate_order_number FROM order_incentives oi JOIN orders o ON o.id = oi.order_id
     WHERE oi.associate_id = :a AND o.client_id = :c AND oi.associate_order_number IS NOT NULL LIMIT 1`,
    { a: associateId, c: clientId }
  );
  if (existing) {
    return Number(existing.associate_order_number);
  }
  const row = await db.queryOne(
    `SELECT COUNT(DISTINCT o.client_id) AS n FROM order_incentives oi JOIN orders o ON o.id = oi.order_id
     WHERE oi.associate_id = :a AND oi.associate_order_number IS NOT NULL`,
    { a: associateId }
  );
  return Number(row.n) + 1;
}

/** The signed agreement (owner decision 2026-10-30): a file_store row, replaced by a newer upload. */
async function setAgreementFile(id, fileId) {
  await db.execute('UPDATE associates SET agreement_file_id = :f WHERE id = :id', { f: fileId, id });
}

/** Whether the file is an associate's signed agreement (manage_incentives only, wherever it is served). */
async function isAgreementFile(fileId) {
  return (await db.queryOne('SELECT 1 AS yes FROM associates WHERE agreement_file_id = :f LIMIT 1', { f: fileId })) !== null;
}

module.exports = { TIERS, FIELDS, all, find, create, update, setAgreementFile, isAgreementFile, rateFor, clients, linkClient, unlinkedClients, orderNumberForClient };
