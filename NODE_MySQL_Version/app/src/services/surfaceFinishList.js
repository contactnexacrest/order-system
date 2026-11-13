'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Owner decision D-357 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved):
 * ONE list of surface-finish names.
 *
 *  - Settings › Lists › surface_finish (dropdown_options, list_key 'surface_finish') is the
 *    master for the NAMES: name, sort order, default, active.
 *  - Pricing › Specifications › Surface finish keeps the PRICE only (base, change type,
 *    value). The specification is marked spec_definitions.list_key = 'surface_finish' and
 *    each of its options carries spec_options.dropdown_option_id, the Settings row it is.
 *  - A finish added in Settings appears in every linked specification at no price change;
 *    one switched off in Settings is switched off there (gone from Pick from Products).
 *  - A rename in Settings renames the linked options and the finish of order lines on
 *    orders whose first Quotation is not approved yet (orders.prices_fixed_at IS NULL,
 *    the D-342 rule). Orders past that point and issued documents never change.
 *  - A line picked from Products takes its finish from the chosen Surface finish option.
 *
 * Only the Surface finish specification is linked; no other specification or list.
 * PHP twin: App\Services\SurfaceFinishList.
 */

const LIST_KEY = 'surface_finish';
/** The longest name an option can carry (spec_options.name). */
const MAX_NAME = 80;

function isLinkedSpec(spec) {
  return !!spec && (spec.list_key ?? null) === LIST_KEY;
}

/** The Settings list, every row (active or not), in order. */
async function settingsRows() {
  return db.query('SELECT * FROM dropdown_options WHERE list_key = :k ORDER BY sort_order, id', { k: LIST_KEY });
}

/** The linked category specifications, with their category's name. */
async function linkedSpecs() {
  return db.query(
    `SELECT s.*, c.name AS category_name FROM spec_definitions s LEFT JOIN product_categories c ON c.id = s.category_id
      WHERE s.list_key = :k AND s.product_id IS NULL ORDER BY c.is_system DESC, c.sort_order, s.id`, { k: LIST_KEY });
}

/**
 * Brings every linked specification in line with the Settings list: a Settings row with no
 * option yet gets one at no price change; names and sort order follow Settings; options of
 * the rows in activeChanged (dropdown ids switched on or off just now) follow their state.
 */
async function sync(activeChanged = [], userId = null) {
  const rows = await settingsRows();
  for (const s of await linkedSpecs()) {
    const linked = (await db.query('SELECT dropdown_option_id FROM spec_options WHERE spec_id = :s AND product_id IS NULL AND dropdown_option_id IS NOT NULL', { s: Number(s.id) }))
      .map((r) => Number(r.dropdown_option_id));
    for (const r of rows) {
      if (linked.includes(Number(r.id))) continue;
      await db.execute(
        `INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, updated_by, dropdown_option_id)
         VALUES (:s, NULL, :n, 0, 'none', NULL, NULL, :o, :a, :u, :d)`,
        { s: Number(s.id), n: [...String(r.option_value)].slice(0, MAX_NAME).join(''), o: Number(r.sort_order), a: Number(r.is_active), u: userId ?? null, d: Number(r.id) }
      );
    }
  }
  await db.execute(
    `UPDATE spec_options o JOIN dropdown_options d ON d.id = o.dropdown_option_id
        SET o.name = LEFT(d.option_value, ${MAX_NAME}), o.sort_order = d.sort_order
      WHERE BINARY o.name <> BINARY LEFT(d.option_value, ${MAX_NAME}) OR o.sort_order <> d.sort_order`
  );
  const ids = (activeChanged || []).map((i) => parseInt(i, 10)).filter((i) => i > 0);
  if (ids.length) {
    await db.execute(
      `UPDATE spec_options o JOIN dropdown_options d ON d.id = o.dropdown_option_id SET o.is_active = d.is_active
        WHERE o.dropdown_option_id IN (${ids.join(',')})`
    );
  }
}

/**
 * A finish renamed in Settings: the finish of every line on an order whose first Quotation
 * is not approved yet (D-342: prices_fixed_at IS NULL) that reads exactly `old` becomes `neu`,
 * each line audit-logged. Orders past that point, and documents already issued, keep `old`.
 *
 * @returns {Promise<{lines: number, orders: number, kept_orders: number}>}
 */
async function renameOnDraftOrders(old, neu, userId) {
  const rows = await db.query(
    `SELECT op.id, op.order_id FROM order_products op JOIN orders o ON o.id = op.order_id
      WHERE o.prices_fixed_at IS NULL AND BINARY op.finish = BINARY :old ORDER BY op.order_id, op.id`, { old });
  const orders = new Set();
  let lines = 0;
  for (const l of rows) {
    await db.execute('UPDATE order_products SET finish = :neu WHERE id = :id', { neu, id: Number(l.id) });
    await auditLogRepository.log(userId ?? null, 'ORDER_LINE_FINISH_RENAMED', 'order_products', Number(l.id), 'finish', old, neu,
      `D-357: surface finish renamed in Settings › Lists (order #${Number(l.order_id)}, quotation not yet approved)`);
    orders.add(Number(l.order_id));
    lines++;
  }
  const kept = await db.queryOne(
    `SELECT COUNT(DISTINCT op.order_id) AS n FROM order_products op JOIN orders o ON o.id = op.order_id
      WHERE o.prices_fixed_at IS NOT NULL AND BINARY op.finish = BINARY :old`, { old });
  return { lines, orders: orders.size, kept_orders: Number(kept ? kept.n : 0) };
}

/** The two lines of the Settings page's success box after a rename (mockup section 1). */
function renameMessage(old, neu, r) {
  const lines = r.lines === 1 ? '1 line' : `${r.lines} lines`;
  const orders = r.orders === 1 ? '1 draft order' : `${r.orders} draft orders`;
  const kept = r.kept_orders === 1 ? '1 order' : `${r.kept_orders} orders`;
  return {
    saved: `"${old}" is now "${neu}" everywhere: Pick from Products, Pricing › Specifications, and ${lines} on ${orders} (quotation not yet approved).`,
    kept: `Not changed: ${kept} with an approved quotation or later, and every document already issued — they keep "${old}".`,
  };
}

/** The finish name of the Surface finish option chosen in selection ({spec id: option id}), or null. */
async function finishForSelection(catalogProductId, selection) {
  if (!catalogProductId || Number(catalogProductId) <= 0 || !selection || !Object.keys(selection).length) return null;
  const optionIds = Object.values(selection).map((v) => parseInt(v, 10)).filter((v) => v > 0);
  if (!optionIds.length) return null;
  const rows = await db.query(
    `SELECT o.id, o.spec_id, o.name FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id
      WHERE s.list_key = '${LIST_KEY}' AND o.id IN (${optionIds.join(',')})`);
  for (const o of rows) {
    if (parseInt(selection[Number(o.spec_id)], 10) === Number(o.id)) return String(o.name);
  }
  return null;
}

/** A line picked from Products: its finish is the chosen Surface finish option's name (when one is chosen). */
async function applyToLine(lineId) {
  const line = await db.queryOne('SELECT catalog_product_id, spec_selection FROM order_products WHERE id = :id', { id: lineId });
  if (!line) return;
  const olp = require('./orderLinePricing');
  const name = await finishForSelection(line.catalog_product_id !== null ? Number(line.catalog_product_id) : null, olp.lineSelection(line));
  if (name !== null) {
    await db.execute('UPDATE order_products SET finish = :n WHERE id = :id AND (finish IS NULL OR BINARY finish <> BINARY :n2)', { n: name, n2: name, id: lineId });
  }
}

/** "base price" / "+1.00 USD / m²" / "no price change yet" — [text, notSet]. */
function priceText(o) {
  if (Number(o.is_base) === 1) return ['base price', false];
  const type = String(o.change_type);
  if (type === 'cbm') return ['priced per CBM', false];
  const v = o.value !== null && o.value !== undefined ? Number(o.value) : 0;
  if (type === 'none' || v === 0) return ['no price change yet', true];
  const sign = v < 0 ? '−' : '+';
  const n = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  if (type === 'pct') return [`${sign}${n} %`, false];
  const per = { usd_m2: 'USD / m²', usd_rm: 'USD / running m', usd_container: 'USD / container', usd_piece: 'USD / piece', usd_kg: 'USD / kg', usd_set: 'USD / set' }[type] || 'USD';
  return [`${sign}${n} ${per}`, false];
}

/**
 * The Settings page's "Price change (from Pricing › Specifications)" column: per Settings row,
 * one entry per linked category option.
 */
async function priceColumn() {
  const specs = await linkedSpecs();
  const out = {};
  if (!specs.length) return out;
  const byId = {};
  for (const s of specs) byId[Number(s.id)] = s;
  const rows = await db.query(
    `SELECT id, spec_id, dropdown_option_id, is_base, change_type, value FROM spec_options
      WHERE product_id IS NULL AND dropdown_option_id IS NOT NULL AND spec_id IN (${Object.keys(byId).join(',')}) ORDER BY spec_id, sort_order, id`);
  for (const o of rows) {
    const s = byId[Number(o.spec_id)];
    const [text, unset] = priceText(o);
    const key = Number(o.dropdown_option_id);
    (out[key] = out[key] || []).push({
      category: specs.length > 1 ? String(s.category_name ?? '') : '',
      text, unset,
      link: `/products/categories/${Number(s.category_id)}?tab=options&edit=${Number(s.id)}#spec-${Number(s.id)}`,
    });
  }
  return out;
}

/** The active Settings row with this name (any letter case), or null. */
async function settingsRowByName(name) {
  const want = String(name).trim().toLowerCase();
  for (const r of await settingsRows()) {
    if (Number(r.is_active) === 1 && String(r.option_value).trim().toLowerCase() === want) return r;
  }
  return null;
}

module.exports = {
  LIST_KEY, MAX_NAME, isLinkedSpec, settingsRows, linkedSpecs, sync, renameOnDraftOrders, renameMessage,
  finishForSelection, applyToLine, priceText, priceColumn, settingsRowByName,
};
