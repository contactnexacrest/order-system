'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\ProductSupplierRepository.
 *
 * "Only one primary supplier per product" is NOT enforced here or by a DB
 * constraint — clearPrimaryForProduct() exists so productService can clear
 * the others and set the new primary inside a single transaction (see
 * productService.setPrimarySupplier). This repository is otherwise a plain
 * CRUD layer with no business logic of its own.
 */

async function forProduct(productId) {
  return db.query(
    'SELECT * FROM catalog_product_suppliers WHERE product_id = :product_id ORDER BY is_primary DESC, supplier_name',
    { product_id: productId }
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM catalog_product_suppliers WHERE id = :id', { id });
}

async function create(data, userId) {
  const params = bindParams(data);
  params.product_id = data.product_id;
  params.created_by = userId;
  params.updated_by = userId;
  const result = await db.execute(
    `INSERT INTO catalog_product_suppliers
        (product_id, supplier_name, location, contact_person, contact_phone, contact_email,
         fob_source, fob_value, factory_cost, transportation_cost, packing_cost, loading_cost, cha_cost,
         is_primary, notes, created_by, updated_by)
     VALUES
        (:product_id, :supplier_name, :location, :contact_person, :contact_phone, :contact_email,
         :fob_source, :fob_value, :factory_cost, :transportation_cost, :packing_cost, :loading_cost, :cha_cost,
         :is_primary, :notes, :created_by, :updated_by)`,
    params
  );
  return result.insertId;
}

async function update(id, data, userId) {
  const params = bindParams(data);
  params.updated_by = userId;
  params.id = id;
  await db.execute(
    `UPDATE catalog_product_suppliers SET
        supplier_name = :supplier_name,
        location = :location,
        contact_person = :contact_person,
        contact_phone = :contact_phone,
        contact_email = :contact_email,
        fob_source = :fob_source,
        fob_value = :fob_value,
        factory_cost = :factory_cost,
        transportation_cost = :transportation_cost,
        packing_cost = :packing_cost,
        loading_cost = :loading_cost,
        cha_cost = :cha_cost,
        is_primary = :is_primary,
        notes = :notes,
        updated_by = :updated_by
     WHERE id = :id`,
    params
  );
}

async function remove(id) {
  await db.execute('DELETE FROM catalog_product_suppliers WHERE id = :id', { id });
}

/** Clears is_primary on every supplier row for this product — call before setting a new primary. */
async function clearPrimaryForProduct(productId) {
  await db.execute('UPDATE catalog_product_suppliers SET is_primary = 0 WHERE product_id = :product_id', { product_id: productId });
}

async function setPrimary(id) {
  await db.execute('UPDATE catalog_product_suppliers SET is_primary = 1 WHERE id = :id', { id });
}

function bindParams(data) {
  const fobSource = data.fob_source ?? 'direct';
  return {
    supplier_name: data.supplier_name,
    location: data.location ?? null,
    contact_person: data.contact_person ?? null,
    contact_phone: data.contact_phone ?? null,
    contact_email: data.contact_email ?? null,
    fob_source: fobSource,
    // fob_value is only meaningful for fob_source='direct' — for 'computed'
    // it is forced NULL here so a stale computed value can never sit in
    // this column (see schema.sql Section U).
    fob_value: fobSource === 'direct' ? (data.fob_value ?? null) : null,
    factory_cost: data.factory_cost ?? null,
    transportation_cost: data.transportation_cost ?? null,
    packing_cost: data.packing_cost ?? null,
    loading_cost: data.loading_cost ?? null,
    cha_cost: data.cha_cost ?? null,
    is_primary: data.is_primary ? 1 : 0,
    notes: data.notes ?? null,
  };
}

/**
 * D-300 / D-303 (points 12 and 15): the directory supplier a price comes from, whether the price
 * includes packing and loading, and CHA + transport per container (₹, null = the product's default).
 */
async function setPriceDetails(id, supplierId, includesPackingLoading, chaPerContainer) {
  await db.execute('UPDATE catalog_product_suppliers SET supplier_id = :s, includes_packing_loading = :i, cha_transport_per_container = :c WHERE id = :id', {
    s: supplierId, i: includesPackingLoading ? 1 : 0, c: chaPerContainer !== null && chaPerContainer !== undefined ? Number(chaPerContainer).toFixed(2) : null, id,
  });
}

/** D-303: links a price to a directory supplier (and takes its name). */
async function linkSupplier(id, supplierId, name) {
  await db.execute('UPDATE catalog_product_suppliers SET supplier_id = :s, supplier_name = :n WHERE id = :id', { s: supplierId, n: name, id });
}

/** D-303: the prices a directory supplier has given for products (newest first). */
async function forDirectorySupplier(supplierId) {
  return db.query(`SELECT s.*, p.name AS product_name, p.reference_unit FROM catalog_product_suppliers s JOIN catalog_products p ON p.id = s.product_id
     WHERE s.supplier_id = :id ORDER BY COALESCE(s.quoted_on, DATE(s.updated_at)) DESC, s.id DESC`, { id: supplierId });
}

/** D-303: typed supplier names not yet linked to the directory, with how many prices use each. */
async function unlinkedNames() {
  return db.query(`SELECT supplier_name, COUNT(*) AS prices, COUNT(DISTINCT product_id) AS products FROM catalog_product_suppliers
     WHERE supplier_id IS NULL GROUP BY supplier_name ORDER BY supplier_name`);
}

/** D-303: links every unlinked price with this typed name. Returns how many were linked. */
async function linkName(typedName, supplierId, directoryName) {
  const r = await db.execute('UPDATE catalog_product_suppliers SET supplier_id = :s, supplier_name = :n WHERE supplier_id IS NULL AND supplier_name = :t',
    { s: supplierId, n: directoryName, t: typedName });
  return r.affectedRows;
}

module.exports = { forProduct, find, create, update, remove, clearPrimaryForProduct, setPrimary, setPriceDetails, linkSupplier, forDirectorySupplier, unlinkedNames, linkName };
