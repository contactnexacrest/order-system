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

module.exports = {
  all, find, create, update, setTypes, toggleActive, isUsedOnAnOrder, idsUsedOnOrders, remove, markSample, markTest,
};
