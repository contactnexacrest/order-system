'use strict';

const db = require('../config/db');
const specRepository = require('../repositories/specRepository');
const thicknessPrices = require('./thicknessPrices');

/**
 * Port of App\Services\PriceRules — owner decision D-300 (point 12, 11 Nov 2026): one
 * "Price rules" page per category holds every rule used to work out a supplier's prices:
 * the thickness %s (the category's Thickness options), × K (50 mm and above per CBM =
 * base per m² × K), "+ Add a thickness", what one container holds (CBM, maximum tonnes)
 * and the stone weight. Only the MD (manage_pricing) changes them, with a reason; every
 * change is in the Audit Log.
 */

const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));

async function forCategory(categoryId) {
  const category = await db.queryOne('SELECT * FROM product_categories WHERE id = :id', { id: categoryId });
  const cbmFrom = Number((await require('./pricingService').settings()).cbm_from_mm);
  let spec = null;
  for (const s of await specRepository.categorySpecs(categoryId, true)) {
    if (s.kind === 'thickness') {
      spec = s;
      break;
    }
  }
  const rows = [];
  let baseMm = 20;
  if (spec !== null) {
    for (const o of (await specRepository.optionsFor([Number(spec.id)]))[Number(spec.id)] || []) {
      if (o.product_id !== null || Number(o.is_active) !== 1 || o.thickness_mm === null || Number(o.thickness_mm) >= cbmFrom || !['none', 'pct'].includes(o.change_type)) continue;
      rows.push({ id: Number(o.id), name: String(o.name), mm: Number(o.thickness_mm), pct: o.change_type === 'pct' ? Number(o.value) : 0, base: Number(o.is_base) === 1 });
      if (Number(o.is_base) === 1) baseMm = Number(o.thickness_mm);
    }
  }
  rows.sort((a, b) => a.mm - b.mm);
  return {
    category: category || {}, spec, thickness: rows, cbm_from: cbmFrom, base_mm: baseMm,
    k: num(category ? category.thick_k_factor : null),
    container_cbm: num(category ? category.container_cbm : null),
    container_max_t: num(category ? category.container_max_t : null),
    weight: num(category ? category.weight_t_per_cbm : null),
  };
}

/** Saves the page with a reason, each change in the Audit Log: [number of changes, error]. Same messages as PHP. */
async function save(categoryId, post, userId) {
  const r = await forCategory(categoryId);
  if (!r.category || !r.category.id) return [0, 'That category no longer exists.'];
  const reason = String(post.reason ?? '').trim();
  const reasonError = require('../helpers/reasonValidator').check(reason);
  if (reasonError) return [0, `Reason for the change: ${reasonError}`];
  const pctIn = post.pct && typeof post.pct === 'object' ? post.pct : {};
  const pctChanges = [];
  for (const t of r.thickness) {
    const raw = pctIn[t.id] ?? post[`pct[${t.id}]`];
    if (t.base || raw === undefined) continue;
    const v = String(raw).trim();
    if (!/^-?\d{1,3}(\.\d{1,2})?$/.test(v) || Number(v) <= -100 || Number(v) > 500) {
      return [0, `${t.name}: enter the % compared with ${r.base_mm} mm (e.g. 35 or -5), above −100 and at most 500, with at most 2 decimals.`];
    }
    if (Math.abs(Number(v) - t.pct) > 1e-9) pctChanges.push([t, Number(v)]);
  }
  const numIn = (key, min, max, dec, lbl) => {
    const v = String(post[key] ?? '').trim();
    if (v === '') return [null, null];
    if (!new RegExp(`^\\d{1,6}(\\.\\d{1,${dec}})?$`).test(v) || Number(v) < min || Number(v) > max) {
      return [null, `${lbl}: enter a number from ${min} to ${max} with at most ${dec} decimals, or leave it empty.`];
    }
    return [Number(v), null];
  };
  const [k, e1] = numIn('thick_k_factor', 1, 1000, 3, '50 mm and above: 20 mm price per m² ×');
  const [cbm, e2] = numIn('container_cbm', 0.5, 100, 2, 'One container holds (CBM)');
  const [maxT, e3] = numIn('container_max_t', 1, 100, 2, 'Container maximum (tonnes)');
  const [weight, e4] = numIn('weight_t_per_cbm', 0.1, 20, 3, 'Stone weight (tonnes per CBM)');
  for (const e of [e1, e2, e3, e4]) if (e) return [0, e];
  const newMm = String(post.new_mm ?? '').trim();
  const newPct = String(post.new_change ?? '').trim();
  let add = null;
  if (newMm !== '' || newPct !== '') {
    if (r.spec === null) return [0, 'This category has no Thickness specification yet — add one under "Options and their price effect" first.'];
    const mm = /^-?\d+$/.test(newMm) ? Number(newMm) : NaN;
    if (Number.isNaN(mm) || mm < 1 || mm >= r.cbm_from) return [0, `New thickness: whole millimetres from 1 to ${r.cbm_from - 1} (from ${r.cbm_from} mm the price is per CBM).`];
    if (r.thickness.some((t) => t.mm === mm)) return [0, `${mm} mm is already in the list — change its % there.`];
    if (!/^-?\d{1,3}(\.\d{1,2})?$/.test(newPct) || Number(newPct) <= -100 || Number(newPct) > 500 || Number(newPct) === 0) {
      return [0, `New thickness: enter its % compared with ${r.base_mm} mm (e.g. -5 or 20), not 0.`];
    }
    add = { mm, pct: Number(newPct) };
  }
  const audit = require('../repositories/auditLogRepository');
  let changes = 0;
  await db.transaction(async (conn) => {
    for (const [t, v] of pctChanges) {
      await conn.execute("UPDATE spec_options SET change_type = 'pct', value = :v, updated_by = :u WHERE id = :id", { v: v.toFixed(4), u: userId, id: t.id });
      changes += 1;
    }
    const fields = { thick_k_factor: [r.k, k], container_cbm: [r.container_cbm, cbm], container_max_t: [r.container_max_t, maxT], weight_t_per_cbm: [r.weight, weight] };
    for (const [col, [oldV, newV]] of Object.entries(fields)) {
      if ((oldV === null) !== (newV === null) || (oldV !== null && Math.abs(oldV - newV) > 1e-9)) {
        await conn.execute(`UPDATE product_categories SET ${col} = :v WHERE id = :id`, { v: newV, id: categoryId });
        changes += 1;
      }
    }
    if (add !== null) {
      const res = await conn.execute(`INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, updated_by)
        VALUES (:s, NULL, :n, 0, 'pct', :v, :mm, :o, 1, :u)`, { s: Number(r.spec.id), n: `${add.mm} mm`, v: add.pct.toFixed(4), mm: add.mm, o: add.mm, u: userId });
      add.id = Number(res.insertId);
      changes += 1;
    }
  });
  // Audit after the commit (the audit log has its own connection).
  for (const [t, v] of pctChanges) await audit.log(userId, 'PRICE_RULE_CHANGED', 'spec_options', t.id, `${t.name} %`, `${thicknessPrices.fmt(t.pct)}%`, `${thicknessPrices.fmt(v)}%`, reason);
  const fields = { thick_k_factor: [r.k, k], container_cbm: [r.container_cbm, cbm], container_max_t: [r.container_max_t, maxT], weight_t_per_cbm: [r.weight, weight] };
  for (const [col, [oldV, newV]] of Object.entries(fields)) {
    if ((oldV === null) !== (newV === null) || (oldV !== null && Math.abs(oldV - newV) > 1e-9)) {
      await audit.log(userId, 'PRICE_RULE_CHANGED', 'product_categories', categoryId, col, oldV === null ? '—' : thicknessPrices.fmt(oldV), newV === null ? '—' : thicknessPrices.fmt(newV), reason);
    }
  }
  if (add !== null) await audit.log(userId, 'PRICE_RULE_ADDED', 'spec_options', add.id, 'thickness', null, `${add.mm} mm ${thicknessPrices.fmt(add.pct)}%`, reason);
  return [changes, null];
}

/** "30 mm = 20 mm + 35 % · … · container 8 CBM / 27 t" */
function summary(rules) {
  const parts = [];
  for (const row of rules.rows) {
    if (row.mm === rules.base_mm) continue;
    parts.push(`${row.mm} mm = ${rules.base_mm} mm ${row.pct < 0 ? '− ' : '+ '}${thicknessPrices.fmt(Math.abs(row.pct))} %`);
  }
  if (rules.k !== null && rules.k !== undefined) parts.push(`${rules.cbm_from} mm+ per CBM = ${rules.base_mm} mm × ${thicknessPrices.fmt(rules.k)}`);
  parts.push(`container ${thicknessPrices.fmt(rules.container_cbm)} CBM / ${thicknessPrices.fmt(rules.container_max_t)} t`);
  return parts.join(' · ');
}

module.exports = { forCategory, save, summary };
