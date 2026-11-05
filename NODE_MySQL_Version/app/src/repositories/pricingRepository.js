'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\PricingRepository — owner decision D-245 (4 Nov 2026), P1:
 * pricing settings, product pricing overrides, market-price dates, commission
 * levels and the ladder.
 */

async function settings() {
  // D-245 P5: read once per report run (batchCache), fresh otherwise.
  // D-257: a division's own values over the company ones (outside the memo, which is not per division).
  return require('../services/divisionService').overlay('pricing', { ...(await require('../helpers/batchCache').memo('pricing_settings', settingsUncached)) });
}

async function settingsUncached() {
  const out = {};
  for (const row of await db.query('SELECT setting_key, setting_value FROM pricing_settings')) {
    out[String(row.setting_key)] = String(row.setting_value);
  }
  return out;
}

async function setSetting(key, value, userId) {
  await db.execute(
    `INSERT INTO pricing_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)`,
    { k: key, v: value, u: userId }
  );
}

async function saveProductOverrides(productId, f) {
  await db.execute(
    `UPDATE catalog_products SET pricing_margin_pct = :m, pricing_other_cost_mode = :om, pricing_other_cost_value = :ov,
            pricing_incentive_mode = :im, pricing_incentive_value = :iv, pricing_buffer_pct = :b
      WHERE id = :id`,
    {
      m: f.pricing_margin_pct, om: f.pricing_other_cost_mode, ov: f.pricing_other_cost_value,
      im: f.pricing_incentive_mode, iv: f.pricing_incentive_value, b: f.pricing_buffer_pct, id: productId,
    }
  );
}

async function levels(activeOnly = false) {
  return db.query(`SELECT * FROM commission_levels${activeOnly ? ' WHERE is_active = 1' : ''} ORDER BY sort_order, value, id`);
}

async function findLevel(id) {
  return db.queryOne('SELECT * FROM commission_levels WHERE id = :id', { id: Number(id) || 0 });
}

async function levelNameTaken(name, exceptId) {
  return (await db.queryOne('SELECT id FROM commission_levels WHERE name = :n AND id <> :id', { n: name, id: exceptId || 0 })) !== null;
}

async function createLevel(name, kind, value, userId) {
  const sort = Number((await db.queryOne('SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM commission_levels')).s);
  const r = await db.execute(
    'INSERT INTO commission_levels (name, kind, value, is_active, sort_order, created_by, updated_by) VALUES (:n, :k, :v, 1, :s, :u, :u2)',
    { n: name, k: kind, v: value, s: sort, u: userId, u2: userId }
  );
  return r.insertId;
}

async function updateLevel(id, name, kind, value, userId) {
  await db.execute('UPDATE commission_levels SET name = :n, kind = :k, value = :v, updated_by = :u WHERE id = :id', { n: name, k: kind, v: value, u: userId, id });
}

async function setLevelActive(id, active, userId) {
  await db.execute('UPDATE commission_levels SET is_active = :a, updated_by = :u WHERE id = :id', { a: active ? 1 : 0, u: userId, id });
}

async function deleteLevel(id) {
  await db.execute('DELETE FROM commission_levels WHERE id = :id', { id });
}

/** How many associates and ladder steps use the level (a used level can only be deactivated). */
async function levelUse(id) {
  const a = await db.queryOne('SELECT COUNT(*) AS n FROM associates WHERE commission_level_id = :id', { id });
  const s = await db.queryOne('SELECT COUNT(*) AS n FROM commission_ladder_steps WHERE level_id = :id', { id });
  return { associates: Number(a.n), steps: Number(s.n) };
}

async function associatesPerLevel() {
  const out = {};
  for (const r of await db.query('SELECT commission_level_id AS id, COUNT(*) AS n FROM associates WHERE commission_level_id IS NOT NULL GROUP BY commission_level_id')) {
    out[Number(r.id)] = Number(r.n);
  }
  return out;
}

async function ladder() {
  return db.query(
    `SELECT s.id, s.from_order, s.to_order, s.level_id, l.name AS level_name, l.kind, l.value, l.is_active
       FROM commission_ladder_steps s JOIN commission_levels l ON l.id = s.level_id
      ORDER BY s.from_order`
  );
}

async function replaceLadder(steps, userId) {
  await db.transaction(async (t) => {
    await t.execute('DELETE FROM commission_ladder_steps');
    for (const s of steps) {
      await t.execute('INSERT INTO commission_ladder_steps (from_order, to_order, level_id, updated_by) VALUES (:f, :t, :l, :u)', { f: s.from, t: s.to, l: s.level_id, u: userId });
    }
  });
}

async function setAssociateCommission(associateId, mode, levelId) {
  await db.execute('UPDATE associates SET commission_mode = :m, commission_level_id = :l WHERE id = :id', { m: mode, l: levelId, id: associateId });
}

/** D-255: a supplier price per unit (the selling unit) or per CBM (thick stone). */
async function setSupplierBasis(supplierId, basis) {
  await db.execute("UPDATE catalog_product_suppliers SET price_basis = :b, is_primary = IF(:b2 = 'cbm', 0, is_primary) WHERE id = :id",
    { b: basis === 'cbm' ? 'cbm' : 'unit', b2: basis, id: supplierId });
}

/** D-255: marks one per-CBM supplier price "used for pricing" thick stone. */
async function setCbmPrimary(productId, supplierId) {
  await db.execute('UPDATE catalog_product_suppliers SET is_cbm_primary = 0 WHERE product_id = :p', { p: productId });
  await db.execute("UPDATE catalog_product_suppliers SET is_cbm_primary = 1 WHERE id = :id AND price_basis = 'cbm'", { id: supplierId });
}

async function setSupplierDates(supplierId, quotedOn, validUntil, quoteId = null) {
  await db.execute(
    'UPDATE catalog_product_suppliers SET quoted_on = :q, valid_until = :v, purchase_quote_id = COALESCE(:pq, purchase_quote_id) WHERE id = :id',
    { q: quotedOn, v: validUntil, pq: quoteId, id: supplierId }
  );
}

/** Supplier quotes recorded in Procurement for order lines picked from this product. */
async function purchaseQuotesForProduct(productId) {
  return db.query(
    `SELECT q.id, q.unit_price_inr, q.valid_until, q.quote_reference, q.recorded_at, s.supplier_legal_name, r.unit
       FROM purchase_quotes q
       JOIN purchase_requirements r ON r.id = q.requirement_id
       JOIN order_products op ON op.id = r.order_product_id
       JOIN suppliers s ON s.id = q.supplier_id
      WHERE op.catalog_product_id = :p
      ORDER BY q.recorded_at DESC, q.id DESC
      LIMIT 20`,
    { p: productId }
  );
}

async function purchaseQuote(quoteId, productId) {
  return (await purchaseQuotesForProduct(productId)).find((q) => Number(q.id) === Number(quoteId)) || null;
}

// ------------------------------------------------------------ D-256: exact prices for a combination

/** supplier id => { spec id => option id } for these exact-price rows. */
async function comboOptions(supplierIds) {
  const ids = [...new Set(supplierIds.map(Number))];
  if (!ids.length) return {};
  const params = {};
  const marks = ids.map((id, i) => { params[`s${i}`] = id; return `:s${i}`; }).join(',');
  const rows = await db.query(`SELECT supplier_id, spec_id, option_id FROM catalog_product_supplier_options WHERE supplier_id IN (${marks}) ORDER BY supplier_id, spec_id`, params);
  const out = {};
  for (const r of rows) {
    const sid = Number(r.supplier_id);
    if (!out[sid]) out[sid] = {};
    out[sid][Number(r.spec_id)] = Number(r.option_id);
  }
  return out;
}

async function setCombo(supplierId, named, per, inUse) {
  await db.execute(
    "UPDATE catalog_product_suppliers SET price_basis = 'combo', combo_per = :per, combo_in_use = :u, is_primary = 0, is_cbm_primary = 0 WHERE id = :id",
    { per: per === 'cbm' ? 'cbm' : 'unit', u: inUse ? 1 : 0, id: supplierId }
  );
  await db.execute('DELETE FROM catalog_product_supplier_options WHERE supplier_id = :id', { id: supplierId });
  for (const [specId, optionId] of Object.entries(named)) {
    await db.execute('INSERT INTO catalog_product_supplier_options (supplier_id, spec_id, option_id) VALUES (:s, :sp, :o)', { s: supplierId, sp: Number(specId), o: Number(optionId) });
  }
}

async function setComboInUse(supplierId, inUse) {
  await db.execute('UPDATE catalog_product_suppliers SET combo_in_use = :u WHERE id = :id', { u: inUse ? 1 : 0, id: supplierId });
}

module.exports = {
  comboOptions, setCombo, setComboInUse,
  settings, setSetting, saveProductOverrides, levels, findLevel, levelNameTaken, createLevel, updateLevel, setLevelActive, deleteLevel,
  levelUse, associatesPerLevel, ladder, replaceLadder, setAssociateCommission, setSupplierBasis, setCbmPrimary, setSupplierDates, purchaseQuotesForProduct, purchaseQuote,
};
