'use strict';

const db = require('../config/db');

// Port of App\Repositories\ProductRepository. The product catalogue (see
// docs/schema.sql Section U). Products are picked on order lines
// (order_products.catalog_product_id) and priced through associate price
// requests (price_requests.product_id); usage() counts those links so a used
// product is never deleted, only deactivated.

/** The list filter values (owner, 12 Nov 2026): active is the default. */
const STATUSES = ['active', 'inactive', 'all'];

async function all(activeOnly = true) {
  let sql = 'SELECT * FROM catalog_products';
  if (activeOnly) {
    sql += ' WHERE is_active = 1';
  }
  sql += ' ORDER BY name';
  return db.query(sql);
}

/** A status filter value from the query string — anything unknown is "active". */
function statusFilter(value) {
  return STATUSES.includes(value) ? value : 'active';
}

function listingWhere(term) {
  const t = String(term ?? '').trim();
  if (t === '') return { where: [], params: {} };
  return { where: ['(name LIKE :term OR hs_code LIKE :term OR specifications LIKE :term)'], params: { term: `%${t}%` } };
}

/**
 * The Products list: active, inactive or all products, optionally narrowed by a search term
 * (name, HS code or specification).
 */
async function listing(status = 'active', term = null) {
  const { where, params } = listingWhere(term);
  if (status === 'active') where.push('is_active = 1');
  else if (status === 'inactive') where.push('is_active = 0');
  return db.query(`SELECT * FROM catalog_products${where.length ? ` WHERE ${where.join(' AND ')}` : ''} ORDER BY name`, params);
}

/** The numbers on the "Active (n) · Inactive (n) · All (n)" chips — the same rows listing() returns. */
async function statusCounts(term = null) {
  const { where, params } = listingWhere(term);
  const r = (await db.queryOne(
    `SELECT COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive, COUNT(*) AS total
     FROM catalog_products${where.length ? ` WHERE ${where.join(' AND ')}` : ''}`,
    params
  )) || {};
  return { active: Number(r.active || 0), inactive: Number(r.inactive || 0), all: Number(r.total || 0) };
}

/** Products matching the term (active ones unless a status is given). */
async function search(term, status = 'active') {
  return listing(status, term);
}

/**
 * Where a product is used (owner, 12 Nov 2026): the orders whose lines were picked from it
 * (removed lines included — the order history keeps them), the associate price requests for
 * it, and the procurement lines (purchase requirements) bought for those order lines.
 * Its own supplier prices, images, misc charges and specifications are not "use" — they
 * belong to the product and are deleted with it.
 */
async function usage(id) {
  const one = async (sql) => Number(Object.values((await db.queryOne(sql, { id })) || { n: 0 })[0] || 0);
  const u = {
    orders: await one('SELECT COUNT(DISTINCT order_id) AS n FROM order_products WHERE catalog_product_id = :id'),
    order_lines: await one('SELECT COUNT(*) AS n FROM order_products WHERE catalog_product_id = :id'),
    price_requests: await one('SELECT COUNT(*) AS n FROM price_requests WHERE product_id = :id'),
    procurement: await one('SELECT COUNT(*) AS n FROM purchase_requirements pr JOIN order_products op ON op.id = pr.order_product_id WHERE op.catalog_product_id = :id'),
  };
  u.total = u.orders + u.price_requests + u.procurement;
  return u;
}

/** "3 orders · 1 price request" — or "not used anywhere". */
function usageText(u) {
  const parts = [];
  for (const [k, one, many] of [['orders', 'order', 'orders'], ['price_requests', 'price request', 'price requests'], ['procurement', 'procurement line', 'procurement lines']]) {
    const n = Number((u || {})[k] || 0);
    if (n > 0) parts.push(`${n} ${n === 1 ? one : many}`);
  }
  return parts.length ? parts.join(' · ') : 'not used anywhere';
}

/** Why this product may not be deleted, or null when it may (inactive and not used anywhere). */
function deleteRefusal(product, u) {
  if (Number(product.is_active)) {
    return 'This product is active. Deactivate it first — only an inactive product that is not used anywhere can be deleted.';
  }
  if (Number((u || {}).total || 0) > 0) {
    const what = Number(u.orders || 0) > 0 ? 'those orders' : (Number(u.price_requests || 0) > 0 ? 'those price requests' : 'those procurement lines');
    return `Used on ${usageText(u)} — kept so ${what} stay complete.`;
  }
  return null;
}

/** Deactivate or reactivate a product. */
async function setActive(id, active, userId) {
  await db.execute('UPDATE catalog_products SET is_active = :a, updated_by = :u WHERE id = :id', { a: active ? 1 : 0, u: userId, id });
}

// Active products for "Pick from Products" on an order line.
async function forPicker() {
  return db.query('SELECT id, name, hs_code, specifications, reference_unit FROM catalog_products WHERE is_active = 1 ORDER BY name');
}

async function find(id) {
  return db.queryOne('SELECT * FROM catalog_products WHERE id = :id', { id });
}

async function create(data, userId) {
  const result = await db.execute(
    `INSERT INTO catalog_products
        (name, specifications, hs_code, origin,
         default_factory_cost, default_transportation_cost, default_packing_cost,
         default_loading_cost, default_cha_cost, reference_unit,
         is_active, created_by, updated_by)
     VALUES
        (:name, :specifications, :hs_code, :origin,
         :default_factory_cost, :default_transportation_cost, :default_packing_cost,
         :default_loading_cost, :default_cha_cost, :reference_unit,
         :is_active, :created_by, :updated_by)`,
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
      reference_unit: data.reference_unit ?? null,
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
        reference_unit = :reference_unit,
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
      reference_unit: data.reference_unit ?? null,
      is_active: data.is_active !== undefined && data.is_active !== null ? Number(data.is_active) : 1,
      updated_by: userId,
      id,
    }
  );
}

/**
 * Hard delete. ON DELETE CASCADE removes the product's own rows (images,
 * supplier prices and their price history, misc charges, specifications).
 * Callers must check deleteRefusal() first: an active or used product is
 * never deleted (owner, 12 Nov 2026).
 */
async function remove(id) {
  // Owner decision 2 Nov 2026 (D-238): a product already in Zoho Books is marked inactive there at the next sync.
  await require('../services/zohoMasterDataSync').rememberDeletedProduct(await find(id));
  await db.execute('DELETE FROM catalog_products WHERE id = :id', { id });
}

/** D-300 (point 12): the product's CHA + transport default and its own price rules (null = the category's). */
async function setPriceRuleFields(id, f) {
  const cols = Object.keys(f).filter((c) => ['cha_transport_per_container', 'thick_k_factor', 'container_cbm', 'container_max_t'].includes(c));
  if (!cols.length) return;
  const params = { id };
  for (const c of cols) params[c] = f[c];
  await db.execute(`UPDATE catalog_products SET ${cols.map((c) => `${c} = :${c}`).join(', ')} WHERE id = :id`, params);
}

module.exports = {
  STATUSES, all, statusFilter, listing, statusCounts, search, usage, usageText, deleteRefusal, setActive,
  find, create, update, remove, forPicker, setPriceRuleFields,
};
