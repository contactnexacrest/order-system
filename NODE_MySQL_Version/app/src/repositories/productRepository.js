'use strict';

const db = require('../config/db');

// Port of App\Repositories\ProductRepository. The Product Interface /
// internal product catalog (see docs/schema.sql Section U). Deliberately
// standalone — no foreign keys to orders/order_products anywhere in this
// repository or the tables it reads.

async function all(activeOnly = true) {
  let sql = 'SELECT * FROM catalog_products';
  if (activeOnly) {
    sql += ' WHERE is_active = 1';
  }
  sql += ' ORDER BY name';
  return db.query(sql);
}

async function search(term) {
  return db.query(
    `SELECT * FROM catalog_products
     WHERE is_active = 1
       AND (name LIKE :term OR hs_code LIKE :term OR specifications LIKE :term)
     ORDER BY name`,
    { term: `%${term}%` }
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM catalog_products WHERE id = :id', { id });
}

async function create(data, userId) {
  const result = await db.execute(
    `INSERT INTO catalog_products
        (name, specifications, hs_code, origin,
         default_factory_cost, default_transportation_cost, default_packing_cost,
         default_loading_cost, default_cha_cost, is_active, created_by, updated_by)
     VALUES
        (:name, :specifications, :hs_code, :origin,
         :default_factory_cost, :default_transportation_cost, :default_packing_cost,
         :default_loading_cost, :default_cha_cost, :is_active, :created_by, :updated_by)`,
    {
      name: data.name,
      specifications: data.specifications,
      hs_code: data.hs_code,
      origin: data.origin ?? null,
      default_factory_cost: data.default_factory_cost ?? null,
      default_transportation_cost: data.default_transportation_cost ?? null,
      default_packing_cost: data.default_packing_cost ?? null,
      default_loading_cost: data.default_loading_cost ?? null,
      default_cha_cost: data.default_cha_cost ?? null,
      is_active: data.is_active !== undefined && data.is_active !== null ? Number(data.is_active) : 1,
      created_by: userId,
      updated_by: userId,
    }
  );
  return result.insertId;
}

async function update(id, data, userId) {
  await db.execute(
    `UPDATE catalog_products SET
        name = :name,
        specifications = :specifications,
        hs_code = :hs_code,
        origin = :origin,
        default_factory_cost = :default_factory_cost,
        default_transportation_cost = :default_transportation_cost,
        default_packing_cost = :default_packing_cost,
        default_loading_cost = :default_loading_cost,
        default_cha_cost = :default_cha_cost,
        is_active = :is_active,
        updated_by = :updated_by
     WHERE id = :id`,
    {
      name: data.name,
      specifications: data.specifications,
      hs_code: data.hs_code,
      origin: data.origin ?? null,
      default_factory_cost: data.default_factory_cost ?? null,
      default_transportation_cost: data.default_transportation_cost ?? null,
      default_packing_cost: data.default_packing_cost ?? null,
      default_loading_cost: data.default_loading_cost ?? null,
      default_cha_cost: data.default_cha_cost ?? null,
      is_active: data.is_active !== undefined && data.is_active !== null ? Number(data.is_active) : 1,
      updated_by: userId,
      id,
    }
  );
}

/**
 * Hard delete. ON DELETE CASCADE on catalog_product_images/
 * catalog_product_suppliers/catalog_product_misc_charges removes their
 * rows for this product too — there is nothing else in the schema that
 * references catalog_products.id (it is deliberately never linked into
 * the order pipeline).
 */
async function remove(id) {
  await db.execute('DELETE FROM catalog_products WHERE id = :id', { id });
}

module.exports = { all, search, find, create, update, remove };
