'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\SpecRepository — owner decision D-255 (5 Nov 2026): the
 * specifications that change the price — per category, and a product's own changes
 * (hidden specifications and options, changed values, options and specifications for
 * that product only).
 */

/** The system category (Natural Stone): a product with no category uses it. */
async function systemCategoryId() {
  const r = await db.queryOne('SELECT id FROM product_categories WHERE is_system = 1 ORDER BY id LIMIT 1');
  return r ? Number(r.id) : null;
}

/** A category's specifications (not the product-only ones), in order. */
async function categorySpecs(categoryId, activeOnly = false) {
  return db.query(`SELECT * FROM spec_definitions WHERE category_id = :c AND product_id IS NULL${activeOnly ? ' AND is_active = 1' : ''} ORDER BY sort_order, id`, { c: categoryId });
}

async function productOnlySpecs(productId) {
  return db.query('SELECT * FROM spec_definitions WHERE product_id = :p ORDER BY sort_order, id', { p: productId });
}

async function findSpec(id) {
  return db.queryOne('SELECT * FROM spec_definitions WHERE id = :id', { id });
}

async function findOption(id) {
  return db.queryOne('SELECT * FROM spec_options WHERE id = :id', { id });
}

/** Options of the given specifications: the category's plus, for productId, that product's own. By spec id. */
async function optionsFor(specIds, productId = null) {
  const out = {};
  if (!specIds.length) return out;
  const inList = specIds.map((x) => parseInt(x, 10) || 0).join(',');
  const rows = await db.query(
    `SELECT * FROM spec_options WHERE spec_id IN (${inList}) AND (product_id IS NULL${productId !== null ? ' OR product_id = :p' : ''})
     ORDER BY sort_order, id`,
    productId !== null ? { p: productId } : {}
  );
  for (const o of rows) (out[Number(o.spec_id)] = out[Number(o.spec_id)] || []).push(o);
  return out;
}

/** Spec ids the product hides: { id: true }. */
async function hiddenSpecs(productId) {
  const out = {};
  for (const r of await db.query('SELECT spec_id FROM product_spec_hidden WHERE product_id = :p', { p: productId })) out[Number(r.spec_id)] = true;
  return out;
}

/** By option id: { is_hidden, value }. */
async function optionOverrides(productId) {
  const out = {};
  for (const r of await db.query('SELECT option_id, is_hidden, value FROM product_option_overrides WHERE product_id = :p', { p: productId })) {
    out[Number(r.option_id)] = { is_hidden: Number(r.is_hidden), value: r.value };
  }
  return out;
}

async function createSpec(d, userId) {
  const r = await db.execute(
    `INSERT INTO spec_definitions (category_id, product_id, name, kind, applies_to, sort_order, is_active, updated_by)
     VALUES (:c, :p, :n, :k, :a, :o, 1, :u)`,
    { c: d.category_id ?? null, p: d.product_id ?? null, n: d.name, k: d.kind, a: d.applies_to, o: d.sort_order, u: userId }
  );
  return Number(r.insertId);
}

async function updateSpec(id, d, userId) {
  await db.execute('UPDATE spec_definitions SET name = :n, kind = :k, applies_to = :a, sort_order = :o, updated_by = :u WHERE id = :id',
    { n: d.name, k: d.kind, a: d.applies_to, o: d.sort_order, u: userId, id });
}

async function setSpecActive(id, active, userId) {
  await db.execute('UPDATE spec_definitions SET is_active = :a, updated_by = :u WHERE id = :id', { a: active ? 1 : 0, u: userId, id });
}

async function deleteSpec(id) {
  await db.execute('DELETE FROM spec_definitions WHERE id = :id', { id });
}

async function createOption(d, userId) {
  const r = await db.execute(
    `INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, updated_by)
     VALUES (:s, :p, :n, :b, :t, :v, :mm, :o, 1, :u)`,
    { s: d.spec_id, p: d.product_id ?? null, n: d.name, b: d.is_base, t: d.change_type, v: d.value ?? null, mm: d.thickness_mm ?? null, o: d.sort_order, u: userId }
  );
  return Number(r.insertId);
}

async function updateOption(id, d, userId) {
  await db.execute('UPDATE spec_options SET name = :n, is_base = :b, change_type = :t, value = :v, thickness_mm = :mm, updated_by = :u WHERE id = :id',
    { n: d.name, b: d.is_base, t: d.change_type, v: d.value ?? null, mm: d.thickness_mm ?? null, u: userId, id });
}

/** Only one base option per specification (per product, for product-only options). */
async function clearBase(specId, productId, exceptId) {
  await db.execute(
    `UPDATE spec_options SET is_base = 0 WHERE spec_id = :s AND id <> :id AND ${productId === null ? 'product_id IS NULL' : 'product_id = :p'}`,
    productId === null ? { s: specId, id: exceptId } : { s: specId, id: exceptId, p: productId }
  );
}

async function setOptionActive(id, active, userId) {
  await db.execute('UPDATE spec_options SET is_active = :a, updated_by = :u WHERE id = :id', { a: active ? 1 : 0, u: userId, id });
}

async function deleteOption(id) {
  await db.execute('DELETE FROM spec_options WHERE id = :id', { id });
}

async function setSpecHidden(productId, specId, hidden) {
  if (hidden) await db.execute('INSERT IGNORE INTO product_spec_hidden (product_id, spec_id) VALUES (:p, :s)', { p: productId, s: specId });
  else await db.execute('DELETE FROM product_spec_hidden WHERE product_id = :p AND spec_id = :s', { p: productId, s: specId });
}

/** Sets or clears a product's change to a category option (hidden, and/or its own value). */
async function setOptionOverride(productId, optionId, hidden, value) {
  if (!hidden && value === null) {
    await db.execute('DELETE FROM product_option_overrides WHERE product_id = :p AND option_id = :o', { p: productId, o: optionId });
    return;
  }
  await db.execute(
    `INSERT INTO product_option_overrides (product_id, option_id, is_hidden, value) VALUES (:p, :o, :h, :v)
     ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden), value = VALUES(value)`,
    { p: productId, o: optionId, h: hidden ? 1 : 0, v: value }
  );
}

/** Clears a product's hidden flag and option overrides for one category specification. */
async function resetSpecForProduct(productId, specId) {
  await db.execute('DELETE FROM product_spec_hidden WHERE product_id = :p AND spec_id = :s', { p: productId, s: specId });
  await db.execute('DELETE ov FROM product_option_overrides ov JOIN spec_options o ON o.id = ov.option_id WHERE ov.product_id = :p AND o.spec_id = :s', { p: productId, s: specId });
}

async function setProductFields(productId, categoryId, forms, cbmFrom) {
  await db.execute('UPDATE catalog_products SET category_id = :c, forms = :f, cbm_from_mm = :m WHERE id = :id', { c: categoryId, f: forms, m: cbmFrom, id: productId });
}

/** Whether an option has been used on an order line or a price request. */
async function optionUsed(optionId) {
  const a = `%":${optionId}}%`;
  const b = `%":${optionId},%`;
  if (await db.queryOne('SELECT 1 AS x FROM price_requests WHERE selection LIKE :a OR selection LIKE :b LIMIT 1', { a, b })) return true;
  return !!(await db.queryOne('SELECT 1 AS x FROM order_products WHERE spec_selection LIKE :a OR spec_selection LIKE :b LIMIT 1', { a, b }));
}

async function specUsed(specId) {
  const a = `%"${specId}":%`;
  for (const sql of ['SELECT 1 AS x FROM price_requests WHERE selection LIKE :a LIMIT 1', 'SELECT 1 AS x FROM order_products WHERE spec_selection LIKE :a LIMIT 1']) {
    if (await db.queryOne(sql, { a })) return true;
  }
  return false;
}

module.exports = {
  systemCategoryId, categorySpecs, productOnlySpecs, findSpec, findOption, optionsFor, hiddenSpecs, optionOverrides,
  createSpec, updateSpec, setSpecActive, deleteSpec, createOption, updateOption, clearBase, setOptionActive, deleteOption,
  setSpecHidden, setOptionOverride, resetSpecForProduct, setProductFields, optionUsed, specUsed,
};
