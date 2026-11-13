'use strict';

const db = require('../config/db');

// Port of App\Repositories\ProductRepository. The product catalogue (see
// docs/schema.sql Section U). Products are picked on order lines
// (order_products.catalog_product_id) and priced through associate price
// requests (price_requests.product_id); usage() counts those links so a used
// product is never deleted, only deactivated.

/**
 * The list filter values (owner, 12 Nov 2026): active is the default. D-361 (13 Nov 2026): also by
 * where the product stands — not configured, no active supplier, no supplier yet (these three
 * across active and inactive products).
 */
const STATUSES = ['active', 'inactive', 'all', 'not_configured', 'no_active_supplier', 'no_supplier_yet'];

const offersSql = () => require('../services/supplierOffers');

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

/**
 * D-362 (13 Nov 2026, owner request): the tab to open. A status tab (or filter chip) with no
 * products opens the Active tab instead — or All when Active is empty too. A tab that has
 * products (and All, the last resort) is kept.
 */
function openTab(status, counts) {
  if (Number((counts || {})[status] || 0) > 0 || status === 'all') return status;
  return Number((counts || {}).active || 0) > 0 ? 'active' : 'all';
}

/** D-361: the SQL condition (alias p) for one status filter. */
function statusSql(status) {
  const o = offersSql();
  const usable = `EXISTS (SELECT 1 FROM catalog_product_suppliers cps WHERE cps.product_id = p.id AND ${o.USABLE_SQL})`;
  switch (status) {
    case 'active': return 'p.is_active = 1';
    case 'inactive': return 'p.is_active = 0';
    case 'not_configured': return `NOT ${o.CONFIGURED_SQL} AND NOT ${o.NO_SUPPLIER_YET_SQL}`;
    case 'no_active_supplier': return `${o.CONFIGURED_SQL} AND NOT ${o.NO_SUPPLIER_YET_SQL} AND NOT ${usable}`;
    case 'no_supplier_yet': return o.NO_SUPPLIER_YET_SQL;
    default: return null;
  }
}

function listingWhere(term) {
  const t = String(term ?? '').trim();
  if (t === '') return { where: [], params: {} };
  // D-361: a supplier's name (typed, or the directory supplier's legal name) matches too.
  return {
    where: [`(p.name LIKE :term OR p.hs_code LIKE :term OR p.specifications LIKE :term OR EXISTS (SELECT 1 FROM catalog_product_suppliers cs
      LEFT JOIN suppliers sx ON sx.id = cs.supplier_id WHERE cs.product_id = p.id AND (cs.supplier_name LIKE :term OR sx.supplier_legal_name LIKE :term)))`],
    params: { term: `%${t}%` },
  };
}

/**
 * The Products list: active, inactive or all products (D-361: or not configured / no active
 * supplier / no supplier yet), optionally narrowed by a search term (name, HS code,
 * specification or — D-361 — a supplier's name).
 */
async function listing(status = 'active', term = null) {
  const { where, params } = listingWhere(term);
  const cond = statusSql(status);
  if (cond !== null) where.push(cond);
  return db.query(`SELECT p.* FROM catalog_products p${where.length ? ` WHERE ${where.join(' AND ')}` : ''} ORDER BY p.name`, params);
}

/** The numbers on the filter chips — the same rows listing() returns. */
async function statusCounts(term = null) {
  const { where, params } = listingWhere(term);
  const sums = STATUSES.map((st) => {
    const cond = statusSql(st);
    return cond === null ? 'COUNT(*) AS `all`' : `COALESCE(SUM(${cond}), 0) AS \`${st}\``;
  });
  const r = (await db.queryOne(`SELECT ${sums.join(', ')} FROM catalog_products p${where.length ? ` WHERE ${where.join(' AND ')}` : ''}`, params)) || {};
  const out = {};
  for (const st of STATUSES) out[st] = Number(r[st] || 0);
  return out;
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

/**
 * Active products for "Pick from Products" on an order line. D-361 (plan rule 4): only QUOTABLE
 * ones — configured with at least one active supplier offer, or a product from before migration
 * 13b that has no supplier yet.
 */
async function forPicker() {
  return db.query(`SELECT p.id, p.name, p.hs_code, p.specifications, p.reference_unit FROM catalog_products p WHERE p.is_active = 1 AND ${offersSql().quotableSql()} ORDER BY p.name`);
}

/**
 * D-361: products staff may look for but cannot pick yet — not configured, or no active supplier —
 * with the reason shown in the picker. A product deliberately deactivated (and otherwise ready) is
 * left out, as before.
 */
async function notQuotable() {
  const o = offersSql();
  const rows = await db.query(`SELECT p.id, p.name, ${o.CONFIGURED_SQL} AS configured FROM catalog_products p WHERE NOT ${o.quotableSql()} ORDER BY p.name`);
  return rows.map((r) => ({ id: Number(r.id), name: String(r.name), reason: Number(r.configured) === 1 ? 'no active supplier — ask Procurement' : 'not configured yet — ask the product manager' }));
}

/** D-361: whether the product may be quoted now (active and quotable). */
async function isQuotable(id) {
  const r = await db.queryOne(`SELECT COUNT(*) AS n FROM catalog_products p WHERE p.id = :id AND p.is_active = 1 AND ${offersSql().quotableSql()}`, { id });
  return Number((r || {}).n || 0) > 0;
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
  notQuotable, isQuotable,
  STATUSES, all, statusFilter, openTab, listing, statusCounts, search, usage, usageText, deleteRefusal, setActive,
  find, create, update, remove, forPicker, setPriceRuleFields,
};
