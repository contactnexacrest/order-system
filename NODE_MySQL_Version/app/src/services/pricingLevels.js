'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Port of App\Services\PricingLevels — owner decision D-359 (13 Nov 2026, "go with
 * recommendation"): every pricing value comes from three levels — the PRODUCT's own value
 * if it has one, else its CATEGORY's (Price rules), else the GLOBAL value (Pricing settings).
 * A product marked "follow global" (catalog_products.pricing_follow_global = 1, set by
 * "Reset all to global values") skips its category, so later changes to the global values
 * still reach it.
 *
 *   margin %, other costs, buffer %           product → category (NEW, empty = global) → global
 *   weight t/CBM, container CBM, container
 *   max t, thickness factor K                 product → category → global (NEW physical defaults)
 *   team incentive reserve                    product → global (no category level)
 *
 * Only reading and resetting live here — no maths. pricingService.effectiveFor() and
 * thicknessPrices.rules() use resolve(); the Configure screen (D-361) uses
 * resetPreview() / resetToCategory() / resetToGlobal().
 */

const PHYSICAL = {
  weight_t_per_cbm: 'Weight (t per CBM)',
  container_cbm: 'Container (CBM)',
  container_max_t: 'Container max (t)',
  thick_k_factor: 'Thickness factor (50 mm and above = base per m² × K)',
};

/** The product's own pricing values that "Reset all" clears. */
const PRODUCT_OVERRIDES = [
  'pricing_margin_pct', 'pricing_other_cost_mode', 'pricing_other_cost_value', 'pricing_buffer_pct',
  'weight_t_per_cbm', 'container_cbm', 'container_max_t', 'thick_k_factor',
];

const followsGlobal = (product) => !!product && product.pricing_follow_global !== undefined && product.pricing_follow_global !== null && Number(product.pricing_follow_global) === 1;

/** The category row a product follows (null when it follows global or has none). */
async function category(product) {
  if (followsGlobal(product)) return null;
  const id = await require('./productSpecs').categoryIdFor(product);
  if (id === null || id === undefined) return null;
  return db.queryOne('SELECT * FROM product_categories WHERE id = :id', { id });
}

function num(v, positive = false) {
  if (v === null || v === undefined || v === '') return null;
  const f = Number(v);
  return positive && !(f > 0) ? null : f;
}

/**
 * Every value with where it comes from ('product' | 'category' | 'global'). $cat: the
 * category() row (pass null when the product follows global or has none).
 */
function resolve(product, s, cat) {
  const p = product || {};
  const c = cat || {};
  const pick = (pv, cv, gv) => {
    if (pv !== null) return { value: pv, from: 'product', product: pv, category: cv, global: gv };
    if (cv !== null) return { value: cv, from: 'category', product: null, category: cv, global: gv };
    return { value: gv, from: 'global', product: null, category: null, global: gv };
  };
  const out = {
    margin_pct: pick(num(p.pricing_margin_pct), num(c.margin_pct), Number(s.margin_pct)),
    buffer_pct: pick(num(p.pricing_buffer_pct), num(c.buffer_pct), Number(s.buffer_pct)),
  };
  // Other costs: the product may set a % or a USD amount; the category and global levels are a %.
  const hasOther = p.pricing_other_cost_mode !== null && p.pricing_other_cost_mode !== undefined;
  const pOther = hasOther ? { mode: String(p.pricing_other_cost_mode), value: Number(p.pricing_other_cost_value) } : null;
  const cOther = num(c.other_cost_pct);
  out.other_cost = pick(pOther, cOther !== null ? { mode: 'pct', value: cOther } : null, { mode: 'pct', value: Number(s.other_cost_pct) });
  const hasInc = p.pricing_incentive_mode !== null && p.pricing_incentive_mode !== undefined;
  out.incentive = hasInc
    ? { value: { mode: String(p.pricing_incentive_mode), value: Number(p.pricing_incentive_value) }, from: 'product', product: null, category: null, global: null }
    : { value: { mode: String(s.incentive_mode), value: Number(s.incentive_value) }, from: 'global', product: null, category: null, global: null };
  for (const key of Object.keys(PHYSICAL)) {
    out[key] = pick(num(p[key], true), num(c[key], true), num(s[key], true));
  }
  return out;
}

/** resolve() with the category loaded. */
async function resolveFor(product, s = null) {
  s = s || (await require('./pricingService').settings());
  return resolve(product, s, await category(product));
}

/** "15%", "USD 2.00 per unit", "2.7", "—". */
function text(key, value) {
  const ps = require('./pricingService');
  if (value === null || value === undefined) return '—';
  if (typeof value === 'object') return ps.modeLabel(String(value.mode), Number(value.value));
  return ['margin_pct', 'buffer_pct'].includes(key) ? ps.pct(Number(value)) : require('./thicknessPrices').fmt(Number(value));
}

/**
 * What "Reset all to category values" / "Reset all to global values" would change, for the
 * confirm box: one line per value whose figure in use changes, or that stops being the
 * product's own. $to: 'category' | 'global'.
 */
async function resetPreview(product, to, s = null) {
  s = s || (await require('./pricingService').settings());
  const now = await resolveFor(product, s);
  const after = { ...product };
  for (const col of PRODUCT_OVERRIDES) after[col] = null;
  after.pricing_follow_global = to === 'global' ? 1 : 0;
  const then = await resolveFor(after, s);
  const labels = { margin_pct: 'Margin %', other_cost: 'Other costs', buffer_pct: 'Buffer %', ...PHYSICAL };
  const out = [];
  for (const [key, lbl] of Object.entries(labels)) {
    const a = text(key, now[key].value);
    const b = text(key, then[key].value);
    if (a !== b || now[key].from !== then[key].from) out.push({ key, label: lbl, now: a, after: b, from_now: now[key].from, from_after: then[key].from });
  }
  return out;
}

async function reset(productId, global, userId) {
  const sets = PRODUCT_OVERRIDES.map((c) => `${c} = NULL`).join(', ');
  await db.execute(`UPDATE catalog_products SET ${sets}, pricing_follow_global = :g, updated_by = :u WHERE id = :id`, { g: global ? 1 : 0, u: userId, id: productId });
  await auditLogRepository.log(userId, 'PRODUCT_PRICING_RESET', 'catalog_products', productId, 'pricing_follow_global', null, global ? 'reset to global values' : 'reset to category values');
}

/** "Reset all to category values": clears the product's own values; it follows its category again. */
async function resetToCategory(productId, userId) {
  await reset(productId, false, userId);
}

/** "Reset all to global values": clears the product's own values and makes it skip its category. */
async function resetToGlobal(productId, userId) {
  await reset(productId, true, userId);
}

module.exports = { PHYSICAL, PRODUCT_OVERRIDES, category, resolve, resolveFor, text, resetPreview, resetToCategory, resetToGlobal };
