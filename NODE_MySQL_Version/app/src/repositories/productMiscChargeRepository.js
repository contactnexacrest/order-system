'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\ProductMiscChargeRepository. catalog_product_
 * misc_charges is open-ended free-text/amount reference data (e.g. "Bank
 * charges", "LC charges") — informational only. Nothing in this
 * repository, or anywhere else, sums these into a FOB calculation; see
 * productService.effectiveFobForSupplier(), which never queries this
 * table.
 */

async function forProduct(productId) {
  return db.query('SELECT * FROM catalog_product_misc_charges WHERE product_id = :product_id ORDER BY id', { product_id: productId });
}

async function find(id) {
  return db.queryOne('SELECT * FROM catalog_product_misc_charges WHERE id = :id', { id });
}

async function create(productId, label, amount, notes, userId) {
  const result = await db.execute(
    `INSERT INTO catalog_product_misc_charges (product_id, label, amount, notes, created_by)
     VALUES (:product_id, :label, :amount, :notes, :created_by)`,
    { product_id: productId, label, amount, notes: notes ?? null, created_by: userId ?? null }
  );
  return result.insertId;
}

async function remove(id) {
  await db.execute('DELETE FROM catalog_product_misc_charges WHERE id = :id', { id });
}

module.exports = { forProduct, find, create, remove };
