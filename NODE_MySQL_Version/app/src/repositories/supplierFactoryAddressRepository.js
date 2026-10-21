'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\SupplierFactoryAddressRepository. User-reported
 * batch item 12 — docs/schema.sql Section BL. A supplier's own registered
 * address (suppliers.address) is a single field; this is a separate
 * one-to-many list of factory/delivery addresses the same supplier may
 * have, offered as the Supplier PO Terms form's Delivery Location
 * dropdown once a supplier is selected.
 */

async function forSupplier(supplierId) {
  return db.query('SELECT * FROM supplier_factory_addresses WHERE supplier_id = :supplier_id ORDER BY id', {
    supplier_id: supplierId,
  });
}

/**
 * Every factory address for every supplier, grouped by supplier_id — used
 * to embed the full set once on the order page so the Delivery Location
 * dropdown can repopulate client-side as staff change the selected
 * supplier, without a round trip per selection.
 */
async function allGroupedBySupplier() {
  const rows = await db.query('SELECT * FROM supplier_factory_addresses ORDER BY id');
  const grouped = {};
  for (const row of rows) {
    const key = row.supplier_id;
    if (!grouped[key]) grouped[key] = [];
    grouped[key].push(row);
  }
  return grouped;
}

async function create(supplierId, address, label) {
  const result = await db.execute(
    'INSERT INTO supplier_factory_addresses (supplier_id, label, address) VALUES (:supplier_id, :label, :address)',
    { supplier_id: supplierId, label: label ?? null, address }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM supplier_factory_addresses WHERE id = :id', { id });
}

async function remove(id) {
  await db.execute('DELETE FROM supplier_factory_addresses WHERE id = :id', { id });
}

module.exports = { forSupplier, allGroupedBySupplier, create, find, remove };
