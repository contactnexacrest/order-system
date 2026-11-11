'use strict';

const db = require('../config/db');

/**
 * Suppliers directory (docs/schema.sql Section BZ). A supplier can hold
 * several types (supplier_type_links), each the text of a
 * dropdown_options('supplier_type') option. Orders never read this table
 * for their own content — order_supplier_po snapshots the supplier's
 * details when the Supplier PO terms are saved.
 */

/** Directory columns written by create()/update(), besides the name. */
const FIELDS = [
  'address', 'city', 'state', 'gstin', 'pan', 'contact_person', 'contact_person_phone',
  'contact_person_whatsapp', 'phone', 'email', 'whatsapp_number', 'notes',
];

function bindData(data) {
  const bound = { supplier_legal_name: data.supplier_legal_name };
  for (const field of FIELDS) {
    bound[field] = data[field] ?? null;
  }
  return bound;
}

/** The same rows, each with `types` (sorted list of type texts). */
async function withTypes(rows) {
  if (rows.length === 0) {
    return [];
  }
  const params = {};
  const placeholders = rows.map((row, i) => {
    params[`id${i}`] = row.id;
    return `:id${i}`;
  });
  const links = await db.query(
    `SELECT supplier_id, supplier_type FROM supplier_type_links WHERE supplier_id IN (${placeholders.join(', ')}) ORDER BY supplier_type`,
    params
  );
  const typesById = {};
  for (const link of links) {
    (typesById[link.supplier_id] = typesById[link.supplier_id] || []).push(link.supplier_type);
  }
  return rows.map((row) => ({ ...row, types: typesById[row.id] || [] }));
}

/**
 * Ordered by name, each row with a `types` list. `type` filters to
 * suppliers holding that type; `search` matches the name.
 */
async function all(includeInactive = false, type = null, search = null) {
  const where = [];
  const params = {};
  if (!includeInactive) {
    where.push('s.is_active = 1');
  }
  if (type) {
    where.push('EXISTS (SELECT 1 FROM supplier_type_links f WHERE f.supplier_id = s.id AND f.supplier_type = :type)');
    params.type = type;
  }
  if (search) {
    where.push('s.supplier_legal_name LIKE :search');
    params.search = `%${search}%`;
  }
  let sql = 'SELECT s.* FROM suppliers s';
  if (where.length) {
    sql += ` WHERE ${where.join(' AND ')}`;
  }
  sql += ' ORDER BY s.supplier_legal_name';
  return withTypes(await db.query(sql, params));
}

async function find(id) {
  const row = await db.queryOne('SELECT * FROM suppliers WHERE id = :id', { id });
  return row ? (await withTypes([row]))[0] : null;
}

async function setTypes(id, types) {
  await db.execute('DELETE FROM supplier_type_links WHERE supplier_id = :id', { id });
  for (const raw of types || []) {
    const type = String(raw).trim();
    if (type !== '') {
      await db.execute('INSERT IGNORE INTO supplier_type_links (supplier_id, supplier_type) VALUES (:id, :type)', { id, type });
    }
  }
}

/** `data` includes `types` (list of type texts). */
async function create(data, createdBy = null) {
  const columns = ['supplier_legal_name', ...FIELDS, 'created_by'];
  const result = await db.execute(
    `INSERT INTO suppliers (${columns.join(', ')}) VALUES (${columns.map((c) => `:${c}`).join(', ')})`,
    { ...bindData(data), created_by: createdBy }
  );
  await setTypes(result.insertId, data.types || []);
  return result.insertId;
}

/** `data` includes `types` (list of type texts). */
async function update(id, data) {
  const sets = ['supplier_legal_name', ...FIELDS].map((c) => `${c} = :${c}`);
  await db.execute(`UPDATE suppliers SET ${sets.join(', ')} WHERE id = :id`, { ...bindData(data), id });
  await setTypes(id, data.types || []);
}

async function toggleActive(id) {
  await db.execute('UPDATE suppliers SET is_active = 1 - is_active WHERE id = :id', { id });
}

/** True once any order has referenced this supplier — such a supplier can only be deactivated, never deleted. */
async function isUsedOnAnOrder(id) {
  const row = await db.queryOne(
    `SELECT (SELECT COUNT(*) FROM order_supplier_po WHERE supplier_id = :a)
          + (SELECT COUNT(*) FROM order_production WHERE supplier_id = :b) AS c`,
    { a: id, b: id }
  );
  return Number(row.c) > 0;
}

/** Ids of every supplier any order has referenced, as a lookup set. */
async function idsUsedOnOrders() {
  const rows = await db.query(
    `SELECT supplier_id FROM order_supplier_po
     UNION SELECT supplier_id FROM order_production WHERE supplier_id IS NOT NULL`
  );
  return new Set(rows.map((row) => Number(row.supplier_id)));
}

/** Deletes a never-used supplier along with its factory addresses (its types go by ON DELETE CASCADE). */
async function remove(id) {
  await db.execute('DELETE FROM supplier_factory_addresses WHERE supplier_id = :id', { id });
  await db.execute('DELETE FROM suppliers WHERE id = :id', { id });
}

/** Phase E follow-up — flags a supplier as Sample Data Playground content (see sampleDataService). */
async function markSample(id) {
  await db.execute('UPDATE suppliers SET is_sample_data = 1 WHERE id = :id', { id });
}

/** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
async function markTest(id) {
  await db.execute('UPDATE suppliers SET is_test_data = 1 WHERE id = :id', { id });
}

/** QA Round 6, R6-N27: the columns the supplier edit form saves (its edit fingerprint). */
const EDIT_FIELDS = [
  'supplier_legal_name', 'address', 'city', 'state', 'gstin', 'pan', 'contact_person', 'contact_person_phone',
  'contact_person_whatsapp', 'phone', 'email', 'whatsapp_number', 'notes', 'bank_account_name', 'bank_name',
  'bank_branch', 'bank_account_number', 'bank_ifsc', 'bank_account_type', 'bank_upi_id',
];

/**
 * D-303 (point 15): "+ Add a new supplier (quick)" from a price screen — name, location, phone and
 * type; marked "Details pending" until GSTIN, bank and contacts are completed.
 */
async function quickAdd(name, location, phone, type, createdBy) {
  const id = await create({ supplier_legal_name: name, address: location, city: location, phone,
    notes: 'Added quickly from a product price — details pending.', types: type !== '' ? [type] : [] }, createdBy);
  await setDetailsPending(id, true);
  return id;
}

async function setDetailsPending(id, pending) {
  await db.execute('UPDATE suppliers SET details_pending = :p WHERE id = :id', { p: pending ? 1 : 0, id });
}

/** D-303: the one active supplier with exactly this name (spaces and capitals ignored), or null when none or several. */
async function findByExactName(name) {
  const rows = await db.query('SELECT * FROM suppliers WHERE is_active = 1 AND LOWER(TRIM(supplier_legal_name)) = LOWER(TRIM(:n)) ORDER BY id LIMIT 2', { n: String(name) });
  return rows.length === 1 ? find(rows[0].id) : null;
}

/** D-303: the supplier pick list — active suppliers with their locations (factory / quarry addresses, else the city). */
async function pickList() {
  const rows = await db.query('SELECT id, supplier_legal_name, city, state, details_pending, bank_verified_at FROM suppliers WHERE is_active = 1 ORDER BY supplier_legal_name');
  const addresses = await require('./supplierFactoryAddressRepository').allGroupedBySupplier();
  return rows.map((r) => {
    const locs = (addresses[Number(r.id)] || []).map((a) => `${a.label ? `${a.label} — ` : ''}${a.address}`.trim());
    const city = [r.city || '', r.state || ''].filter((x) => String(x) !== '').join(', ').trim();
    if (!locs.length && city !== '') locs.push(city);
    const pending = Number(r.details_pending || 0) === 1;
    const verified = !!r.bank_verified_at;
    // The text beside the name in the pick list (the PHP views build the same).
    const hint = [locs[0] || '', pending ? 'Details pending' : '', verified ? 'Verified bank' : ''].filter((x) => x !== '').join(' · ');
    const shortHint = [locs[0] || '', pending ? 'Details pending' : ''].filter((x) => x !== '').join(' · ');
    return { id: Number(r.id), name: String(r.supplier_legal_name), locations: locs, pending, verified, hint, shortHint };
  });
}

module.exports = {
  EDIT_FIELDS,
  all, find, create, update, setTypes, toggleActive, isUsedOnAnOrder, idsUsedOnOrders, remove, markSample, markTest,
  quickAdd, setDetailsPending, findByExactName, pickList,
};
