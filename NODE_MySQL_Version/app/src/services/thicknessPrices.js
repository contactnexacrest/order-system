'use strict';

const db = require('../config/db');
const lineUnits = require('./lineUnits');
const pricingEngine = require('./pricingEngine');

/**
 * Port of App\Services\ThicknessPrices — owner decision D-300 (point 12, 11 Nov 2026):
 * one supplier price holds every thickness — 20, 30, 40 mm … per m² and "50 mm and
 * above" per CBM. Staff type any of them; the others are CALCULATED from the
 * category's price rules:
 *
 *   other thickness per m² = base (20 mm) per m² × (1 + its % / 100)   e.g. 30 mm +35 %, 40 mm +70 %
 *   50 mm and above per CBM = base (20 mm) per m² × K                  K = 40 by default
 *
 * It works in every direction. CHA + transport is a price per container, turned into
 * a per-CBM cost from what one container really carries (8 CBM, at most 27 t).
 *
 * Owner decision D-335 (12 Nov 2026) — THE ONE RULE:
 *   1. a price the user TYPED always wins — no rule ever changes it;
 *   2. each supplier price row has a BASE thickness the user chose
 *      (catalog_product_suppliers.base_thickness_mm; 0 = 50 mm and above per CBM,
 *      NULL = the category's base thickness): the product-level price and order
 *      lines with no thickness use it;
 *   3. the rules are only a CALCULATOR: a thickness the user ticks while adding a
 *      price is STORED as CALCULATED ("from 20 mm") and can be typed over; nothing
 *      is recalculated in the background.
 * Rows saved before D-335 keep auto_fill = 1: a thickness without a stored price is
 * still worked out on the fly from the typed ones (so no existing price changes).
 * With auto_fill = 0 only stored prices exist; a thickness without one has "no
 * price" (an order line for it goes to the MD).
 */

const CBM = 0;
const DEFAULT_K = 40;
const DEFAULT_CONTAINER_CBM = 8;
const DEFAULT_CONTAINER_MAX_T = 27;

const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));

/** "35", "2.5", "−5" — PHP rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') */
function fmt(v) {
  return Number(v).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
}

/** "20 mm" or "50 mm and above". */
function label(mm, cbmFrom = 50) {
  return Number(mm) === CBM ? `${cbmFrom} mm and above` : `${mm} mm`;
}

/** The price rules a product follows: its category's, with the product's own values where it has them. */
async function rules(product, settings = null) {
  const pricingService = require('./pricingService');
  const productSpecs = require('./productSpecs');
  const orderLinePricing = require('./orderLinePricing');
  const s = settings || (await pricingService.settings());
  const categoryId = await productSpecs.categoryIdFor(product);
  const category = categoryId !== null && categoryId !== undefined
    ? await db.queryOne('SELECT * FROM product_categories WHERE id = :id', { id: categoryId }) : null;
  const cbmFrom = (parseInt(product.cbm_from_mm, 10) || 0) > 0 ? parseInt(product.cbm_from_mm, 10) : Number(s.cbm_from_mm);
  const own = [];
  let k = num(product.thick_k_factor);
  if (k !== null) own.push(`× ${fmt(k)}`);
  if (k === null) k = num(category ? category.thick_k_factor : null);
  let cbm = num(product.container_cbm);
  let maxT = num(product.container_max_t);
  if (cbm !== null || maxT !== null) own.push('container');
  if (cbm === null) cbm = num(category ? category.container_cbm : null) ?? DEFAULT_CONTAINER_CBM;
  if (maxT === null) maxT = num(category ? category.container_max_t : null) ?? DEFAULT_CONTAINER_MAX_T;
  const byMm = new Map();
  let baseMm = null;
  for (const sp of await productSpecs.offered(product)) {
    if (sp.kind !== 'thickness') continue;
    for (const o of sp.options) {
      if (o.thickness_mm === null || o.thickness_mm === undefined || Number(o.thickness_mm) >= cbmFrom || !['none', 'pct'].includes(o.change_type)) continue;
      if ((o.source || 'category') !== 'category') own.push(o.name);
      byMm.set(Number(o.thickness_mm), { mm: Number(o.thickness_mm), pct: o.change_type === 'pct' ? Number(o.value) : 0, option: String(o.name) });
      if (o.is_base) baseMm = Number(o.thickness_mm);
    }
  }
  const rows = [...byMm.values()].sort((a, b) => a.mm - b.mm);
  if (baseMm === null) {
    const r = rows.find((x) => Math.abs(x.pct) < 1e-9);
    if (r) baseMm = r.mm;
  }
  const unit = String(product.reference_unit || '').trim().toUpperCase();
  const unitOk = unit === '' || ['CBM', 'M2', 'SQFT', 'TON'].includes(lineUnits.canonical(unit));
  return {
    grid: k !== null && k > 0 && baseMm !== null && unitOk,
    base_mm: baseMm ?? 20, rows, k, cbm_from: cbmFrom,
    container_cbm: cbm, container_max_t: maxT, t_per_cbm: await orderLinePricing.weightPerCbm(product),
    own: [...new Set(own)], category_id: categoryId ?? null, category_name: category ? category.name : null, unit,
  };
}

/**
 * Fills in every thickness from the typed ones: { ok, error, base, values: { mm: { price, how, calc, rule } } }.
 * @param {Object<number, number>} typed thickness_mm (0 = per CBM) => typed price
 */
function complete(r, typed) {
  const t = {};
  for (const [mm, v] of Object.entries(typed || {})) if (v !== null && v !== undefined && Number(v) > 0) t[Number(mm)] = Number(v);
  const pct = new Map(r.rows.map((x) => [x.mm, Number(x.pct)]));
  const k = r.k !== null && r.k !== undefined ? Number(r.k) : null;
  const baseMm = Number(r.base_mm);
  let base = null;
  if (t[baseMm] !== undefined) {
    base = t[baseMm];
  } else {
    for (const [mm, p] of pct) {
      if (mm !== CBM && t[mm] !== undefined) {
        base = t[mm] / (1 + p / 100);
        break;
      }
    }
    if (base === null && t[CBM] !== undefined && k !== null && k > 0) base = t[CBM] / k;
  }
  if (base === null || !(base > 0)) return { ok: false, error: 'Enter at least one price.', base: null, values: {} };
  const values = {};
  for (const [mm, p] of pct) {
    const calc = pricingEngine.round2(base * (1 + p / 100));
    const rule = mm === baseMm ? 'base' : `${baseMm} mm ${p < 0 ? '− ' : '+ '}${fmt(Math.abs(p))}%`;
    values[mm] = t[mm] !== undefined ? { price: pricingEngine.round2(t[mm]), how: 'typed', calc, rule } : { price: calc, how: 'calculated', calc, rule };
  }
  if (k !== null && k > 0) {
    const calc = pricingEngine.round2(base * k);
    const rule = `${baseMm} mm price × ${fmt(k)}`;
    values[CBM] = t[CBM] !== undefined ? { price: pricingEngine.round2(t[CBM]), how: 'typed', calc, rule } : { price: calc, how: 'calculated', calc, rule };
  }
  return { ok: true, error: null, base, values };
}

/** The values of complete() in display order: the thicknesses, then 50 mm and above. */
function ordered(done) {
  return Object.keys(done.values || {}).map(Number).sort((a, b) => (a === CBM) - (b === CBM) || a - b).map((mm) => ({ mm, ...done.values[mm] }));
}

/** Reads the grid from a form (price[mm], how[mm]): [typed prices, error]. Same rules as PHP. */
function readForm(body, r) {
  const prices = body.price && typeof body.price === 'object' ? body.price : {};
  const how = body.how && typeof body.how === 'object' ? body.how : {};
  const pick = (obj, mm) => (obj[mm] ?? body[`price[${mm}]`] ?? undefined);
  const allowed = r.rows.map((x) => x.mm);
  if (r.k !== null && r.k !== undefined) allowed.push(CBM);
  const raw = {};
  for (const mm of allowed) {
    const v = String(pick(prices, mm) ?? '').replace(/,/g, '').trim();
    if (v === '' || (!Number.isNaN(Number(v)) && Number(v) === 0)) continue;
    if (!/^\d{1,12}(\.\d{1,2})?$/.test(v)) {
      return [{}, `${mm === CBM ? '50 mm and above' : `${mm} mm`}: enter the price in ₹ (at most 2 decimals), or leave it empty to calculate it.`];
    }
    raw[mm] = { v: Number(v), how: String(how[mm] ?? body[`how[${mm}]`] ?? '') };
  }
  const typed = {};
  for (const [mm, x] of Object.entries(raw)) if (x.how !== 'calculated') typed[mm] = x.v;
  if (!Object.keys(typed).length) {
    // Without the browser script every filled box is a typed price.
    for (const [mm, x] of Object.entries(raw)) typed[mm] = x.v;
  } else {
    // A box sent as "calculated" whose value differs from the calculation was typed over.
    const done = complete(r, typed);
    for (const [mm, x] of Object.entries(raw)) {
      if (x.how === 'calculated' && done.ok && Math.abs(((done.values[mm] || {}).calc ?? -1) - x.v) > 0.005) typed[mm] = x.v;
    }
  }
  if (!Object.keys(typed).length) return [{}, 'Enter at least one price.'];
  const out = {};
  for (const mm of Object.keys(typed).map(Number).sort((a, b) => a - b)) out[mm] = typed[mm];
  return [out, null];
}

/** What one container really carries and the CHA + transport per CBM. */
function chaPerCbm(perContainer, containerCbm, maxT, tPerCbm) {
  let fits = Number(containerCbm);
  let byWeight = false;
  if (maxT !== null && maxT > 0 && tPerCbm !== null && tPerCbm > 0 && containerCbm * tPerCbm > maxT) {
    fits = maxT / tPerCbm;
    byWeight = true;
  }
  return {
    per_cbm: perContainer !== null && perContainer > 0 && fits > 0 ? perContainer / fits : null,
    fits_cbm: fits, by_weight: byWeight, weight_t: tPerCbm !== null && tPerCbm > 0 ? containerCbm * tPerCbm : null,
  };
}

function m2ToCbm(perM2, mm) {
  return perM2 / (mm / 1000);
}

function cbmPerUnit(unit, mm, tPerCbm) {
  if (String(unit || '').trim() === '') return null;
  return lineUnits.cbmPerUnit(unit, null, null, mm / 10, tPerCbm);
}

/** The row's CHA + transport per container, else the product's default (₹). */
function chaPerContainer(row, product) {
  let v = row.cha_transport_per_container;
  if (v === null || v === undefined || v === '') v = product.cha_transport_per_container;
  return v === null || v === undefined || v === '' ? null : Number(v);
}

/** The supplier's cost per the product's unit (or per CBM) for one thickness, from a row; null when unusable. */
function cost(r, typed, row, product, basis, mm) {
  const auto = autoFill(row);
  const done = values(r, typed, auto);
  if (!done.ok) return null;
  const cha = chaPerCbm(chaPerContainer(row, product), Number(r.container_cbm), r.container_max_t, r.t_per_cbm);
  const packing = Number(row.includes_packing_loading ?? 1) === 0 ? Number(product.default_packing_cost || 0) + Number(product.default_loading_cost || 0) : 0;
  // D-335: the row's BASE (the user's choice) is what the product-level price uses.
  const baseMm = rowBase(row);
  if (basis === 'cbm' || ((mm === null || mm === undefined) && baseMm === CBM)) {
    const v = done.values[CBM];
    if (!v) return null;
    const chaU = cha.per_cbm ?? 0;
    if (basis === 'cbm') return { supplier: v.price, cha: chaU, packing, total: v.price + chaU + packing, unit: 'CBM', mm, how: v.how, note: null };
    // The base is the per-CBM price ("50 mm and above"): in the product's unit.
    const unit = r.unit !== '' ? r.unit : 'CBM';
    const per = cbmPerUnit(unit, Number(r.cbm_from), r.t_per_cbm);
    if (per === null) return null;
    const supplier = Number(v.price) * per;
    return { supplier, cha: chaU * per, packing, total: supplier + chaU * per + packing, unit, mm: null, how: v.how, note: null, base: CBM };
  }
  const m = mm ?? Number(baseMm ?? r.base_mm);
  let v = done.values[Math.round(m)];
  if (!v) {
    // D-335: no stored price for this thickness — "no price" (the MD prices the line).
    if (!auto) return null;
    // A thickness that is not in the rules (e.g. 25 mm): the base price, no %.
    v = { price: pricingEngine.round2(done.base), how: 'calculated' };
  }
  const unit = r.unit !== '' ? r.unit : 'SQM';
  const perCbm = m2ToCbm(Number(v.price), m);
  const per = cbmPerUnit(unit, m, r.t_per_cbm);
  if (per === null) return null;
  const supplier = perCbm * per;
  const chaU = (cha.per_cbm ?? 0) * per;
  return { supplier, cha: chaU, packing, total: supplier + chaU + packing, unit, mm: m, how: v.how, note: null };
}

// ------------------------------------------------------------- D-335: typed wins, a base, a calculator

/** Rows saved before D-335 (auto_fill = 1, or no such field) still complete missing thicknesses on the fly. */
function autoFill(row) {
  return !row || row.auto_fill === undefined || row.auto_fill === null || Number(row.auto_fill) === 1;
}

/** The row's BASE thickness the user chose (0 = 50 mm and above per CBM); null = the category's base. */
function rowBase(row) {
  const v = row ? row.base_thickness_mm : null;
  return v === null || v === undefined || v === '' ? null : Number(v);
}

/** The base thickness in effect for a row (its own, else the category's). */
function baseOf(row, r) {
  return rowBase(row) ?? Number(r.base_mm);
}

const ymd = (v) => {
  if (!v) return null;
  if (v instanceof Date) {
    const p = (n) => String(n).padStart(2, '0');
    return `${v.getFullYear()}-${p(v.getMonth() + 1)}-${p(v.getDate())}`;
  }
  return String(v).slice(0, 10);
};

/** Stored prices in one shape: { mm: { price, how, calc_from_mm, quoted_on, since } }; a plain number counts as TYPED. */
function normalise(stored) {
  const out = {};
  for (const [mm, s] of Object.entries(stored || {})) {
    if (s !== null && typeof s === 'object') {
      if (!(Number(s.price) > 0)) continue;
      out[Number(mm)] = {
        price: Number(s.price), how: s.how === 'calculated' ? 'calculated' : 'typed',
        calc_from_mm: s.calc_from_mm !== null && s.calc_from_mm !== undefined && s.calc_from_mm !== '' ? Number(s.calc_from_mm) : null,
        quoted_on: s.quoted_on ? ymd(s.quoted_on) : null, since: s.since ? ymd(s.since) : null,
      };
    } else if (s !== null && s !== undefined && Number(s) > 0) {
      out[Number(mm)] = { price: Number(s), how: 'typed', calc_from_mm: null, quoted_on: null, since: null };
    }
  }
  return out;
}

/** Every thickness of a row as it is used: with auto_fill the old completion (stored prices keep how they were set); without, only the stored ones. */
function values(r, stored, auto) {
  const norm = normalise(stored);
  if (auto) {
    const typed = {};
    for (const [mm, s] of Object.entries(norm)) typed[mm] = s.price;
    const done = complete(r, typed);
    for (const mm of Object.keys(done.values)) {
      const v = { calc_from_mm: null, quoted_on: null, since: null, auto: norm[mm] === undefined, ...done.values[mm] };
      done.values[mm] = norm[mm] !== undefined ? { ...v, ...norm[mm] } : v;
    }
    return done;
  }
  const out = {};
  for (const [mm, s] of Object.entries(norm)) {
    out[mm] = {
      ...s, price: pricingEngine.round2(s.price), how: s.how, calc: pricingEngine.round2(s.price),
      rule: s.how === 'calculated' && s.calc_from_mm !== null ? `from ${label(s.calc_from_mm, r.cbm_from)}` : '', auto: false,
    };
  }
  const ok = Object.keys(out).length > 0;
  return { ok, error: ok ? null : 'Enter at least one price.', base: null, values: out };
}

/** "4,600" or "4,600.50" — a short amount for the "how" texts. */
function amount(v) {
  const x = pricingEngine.round2(Number(v));
  return Math.abs(x - Math.round(x)) < 0.005
    ? Math.round(x).toLocaleString('en-US')
    : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Every thickness the rules know, in display order (the thicknesses, then 50 mm and above). */
function thicknesses(r) {
  const out = r.rows.map((x) => Number(x.mm));
  if (r.k !== null && r.k !== undefined && Number(r.k) > 0) out.push(CBM);
  return out;
}

/**
 * D-335: the calculator — every OTHER thickness worked out from one entered price, with how it
 * was worked out ("= 4,600 − 20 %") and the rule ("−20 %"). Same maths and texts as PHP calcFrom().
 */
function calcFrom(r, fromMm, price) {
  const pct = new Map(r.rows.map((x) => [Number(x.mm), Number(x.pct)]));
  const k = r.k !== null && r.k !== undefined ? Number(r.k) : null;
  const baseMm = Number(r.base_mm);
  fromMm = Number(fromMm);
  price = Number(price);
  if (!(price > 0)) return { ok: false, error: 'Enter the price (above 0).', base: null, values: {} };
  let base;
  let back;
  if (fromMm === CBM) {
    if (k === null || !(k > 0)) return { ok: false, error: 'This product has no "50 mm and above" rule.', base: null, values: {} };
    base = price / k;
    back = `${amount(price)} per CBM ÷ ${fmt(k)}`;
  } else if (pct.has(fromMm)) {
    const f = 1 + pct.get(fromMm) / 100;
    if (!(f > 0)) return { ok: false, error: 'The rule for this thickness cannot be worked back.', base: null, values: {} };
    base = price / f;
    back = fromMm === baseMm ? '' : `${amount(price)} ÷ ${fmt(f)}`;
  } else {
    return { ok: false, error: 'Choose a thickness from the list.', base: null, values: {} };
  }
  base = pricingEngine.round2(base);
  const root = fromMm === baseMm ? amount(price) : `${baseMm} mm price ₹${amount(base)} (= ${back})`;
  const out = {};
  for (const [mm, p] of pct) {
    if (mm === fromMm) continue;
    const change = mm === baseMm ? '' : `${p < 0 ? '−' : '+'}${fmt(Math.abs(p))} %`;
    out[mm] = {
      price: pricingEngine.round2(base * (1 + p / 100)), calc_from_mm: fromMm,
      how: `= ${root}${change !== '' ? ` ${p < 0 ? '− ' : '+ '}${fmt(Math.abs(p))} %` : ''}`, change,
    };
  }
  if (k !== null && k > 0 && fromMm !== CBM) {
    out[CBM] = {
      price: pricingEngine.round2(base * k), calc_from_mm: fromMm,
      how: `= ${baseMm} mm price ₹${amount(base)} per m² × ${fmt(k)}${fromMm !== baseMm ? ` (${baseMm} mm = ${back})` : ''}`,
      change: `× ${fmt(k)}`,
    };
  }
  return { ok: true, error: null, base, values: out };
}

const priceText = (p) => Number(p).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const what = (mm, cbmFrom) => `${label(mm, cbmFrom)}${Number(mm) === CBM ? ' per CBM' : ' per m²'}`;

/**
 * D-335: "Add / update a price" — saves the TYPED thickness, the TICKED ones as CALCULATED (from
 * the typed one), leaves the unticked ones as they are, sets the base when asked and switches the
 * row to stored prices only (auto_fill 0). A row saved before D-335 first keeps what it showed for
 * every thickness (stored as CALCULATED). Every change goes into the price history. Returns an error or null.
 */
async function addPrice(row, r, fromMm, price, quotedOn, ticked, makeBase, userId) {
  const id = Number(row.id);
  fromMm = Number(fromMm);
  const calc = calcFrom(r, fromMm, price);
  if (!calc.ok) return calc.error;
  const stored = await storedFor(id);
  const before = values(r, stored, autoFill(row));
  const put = (conn, mm, p, how, from, q) => conn.execute(
    `INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_by)
     VALUES (:id, :mm, :p, :how, :cf, :q, :u)
     ON DUPLICATE KEY UPDATE price_inr = VALUES(price_inr), how = VALUES(how), calc_from_mm = VALUES(calc_from_mm), quoted_on = VALUES(quoted_on), updated_by = VALUES(updated_by)`,
    { id, mm: Number(mm), p: Number(p).toFixed(2), how, cf: from, q, u: userId },
  );
  await db.transaction(async (conn) => {
    if (autoFill(row) && before.ok) {
      const norm = normalise(stored);
      const typedMms = Object.keys(norm).map(Number).filter((mm) => norm[mm].how === 'typed').sort((a, b) => a - b);
      const from = typedMms.includes(Number(r.base_mm)) ? Number(r.base_mm) : (typedMms.length ? typedMms[0] : null);
      for (const mm of Object.keys(before.values).map(Number)) {
        if (stored[mm] === undefined) await put(conn, mm, before.values[mm].price, 'calculated', from, null);
      }
    }
    await put(conn, fromMm, price, 'typed', null, quotedOn);
    for (const mm of ticked) {
      if (calc.values[mm]) await put(conn, mm, calc.values[mm].price, 'calculated', fromMm, null);
    }
    const oldBase = rowBase(row);
    await conn.execute(`UPDATE catalog_product_suppliers SET auto_fill = 0${makeBase ? ', base_thickness_mm = :b' : ''},
      quoted_on = CASE WHEN quoted_on IS NULL OR quoted_on < :q THEN :q2 ELSE quoted_on END WHERE id = :id`,
    { ...(makeBase ? { b: fromMm } : {}), q: quotedOn, q2: quotedOn, id });
    const after = values(r, await storedFor(id, conn), false);
    const cbmFrom = Number(r.cbm_from);
    const keys = Object.keys(after.values).map(Number).sort((a, b) => (a === CBM) - (b === CBM) || a - b);
    for (const mm of keys) {
      const v = after.values[mm];
      const old = before.values[mm];
      const changed = !old || Math.abs(Number(old.price) - Number(v.price)) > 0.005 || old.how !== v.how || (v.how === 'typed' && mm === fromMm);
      if (changed) {
        await history(id, what(mm, cbmFrom), old ? `${priceText(old.price)} ${old.how}` : '—',
          `${priceText(v.price)} ${v.how}${v.how === 'calculated' && v.calc_from_mm !== null ? ` from ${label(v.calc_from_mm, cbmFrom)}` : ''}`, userId, conn);
      }
    }
    if (makeBase && oldBase !== fromMm) {
      await history(id, 'Base', oldBase === null ? `${label(Number(r.base_mm), cbmFrom)} (category)` : label(oldBase, cbmFrom), label(fromMm, cbmFrom), userId, conn);
    }
  });
  return null;
}

/** D-335: make one thickness (that has a price) the row's BASE for orders. Returns an error or null. */
async function setBase(row, r, mm, userId) {
  mm = Number(mm);
  const done = values(r, await storedFor(Number(row.id)), autoFill(row));
  if (!done.values[mm]) return 'That thickness has no price yet — add one first.';
  const old = rowBase(row);
  if (old === mm) return null;
  await db.execute('UPDATE catalog_product_suppliers SET base_thickness_mm = :b WHERE id = :id', { b: mm, id: Number(row.id) });
  const cbmFrom = Number(r.cbm_from);
  await history(Number(row.id), 'Base', old === null ? `${label(Number(r.base_mm), cbmFrom)} (category)` : label(old, cbmFrom), label(mm, cbmFrom), userId);
  return null;
}

// ------------------------------------------------------------- storage

async function typedFor(productSupplierId) {
  const rows = await db.query("SELECT thickness_mm, price_inr FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id AND how = 'typed' ORDER BY thickness_mm", { id: productSupplierId });
  const out = {};
  for (const r of rows) out[Number(r.thickness_mm)] = Number(r.price_inr);
  return out;
}

/** D-335: every stored price of a row (TYPED and CALCULATED) with how it was set. */
async function storedFor(productSupplierId, conn = null) {
  const rows = await (conn || db).query('SELECT thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_at FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id ORDER BY thickness_mm', { id: productSupplierId });
  const out = {};
  for (const r of rows) {
    out[Number(r.thickness_mm)] = {
      price: Number(r.price_inr), how: String(r.how), calc_from_mm: r.calc_from_mm !== null ? Number(r.calc_from_mm) : null,
      quoted_on: r.quoted_on ? ymd(r.quoted_on) : null, since: ymd(r.updated_at),
    };
  }
  return out;
}

async function history(productSupplierId, what, oldV, newV, userId, conn = null) {
  await (conn || db).execute('INSERT INTO catalog_supplier_price_history (product_supplier_id, what, old_value, new_value, changed_by) VALUES (:id, :w, :o, :n, :u)', {
    id: productSupplierId, w: [...String(what)].slice(0, 80).join(''),
    o: oldV !== null && oldV !== undefined ? [...String(oldV)].slice(0, 80).join('') : null,
    n: newV !== null && newV !== undefined ? [...String(newV)].slice(0, 80).join('') : null, u: userId,
  });
}

/** Saves the typed prices of a row (replacing the old ones) and records every change in the price history. */
async function saveTyped(productSupplierId, typed, r, userId) {
  const before = complete(r, await typedFor(productSupplierId));
  const after = complete(r, typed);
  await db.execute('DELETE FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id', { id: productSupplierId });
  for (const [mm, price] of Object.entries(typed)) {
    await db.execute('INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr, updated_by) VALUES (:id, :mm, :p, :u)',
      { id: productSupplierId, mm: Number(mm), p: Number(price).toFixed(2), u: userId });
  }
  for (const x of ordered(after)) {
    const old = before.values[x.mm];
    const oldText = old ? `${old.price.toFixed(2)} ${old.how}` : null;
    const newText = `${x.price.toFixed(2)} ${x.how}`;
    if (oldText !== newText) await history(productSupplierId, `${label(x.mm, r.cbm_from)}${x.mm === CBM ? ' per CBM' : ' per m²'}`, oldText, newText, userId);
  }
}

async function historyFor(productSupplierId) {
  return db.query(`SELECT h.*, u.name AS changed_by_name FROM catalog_supplier_price_history h LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.product_supplier_id = :id ORDER BY h.changed_at DESC, h.id DESC`, { id: productSupplierId });
}

module.exports = {
  CBM, DEFAULT_K, DEFAULT_CONTAINER_CBM, DEFAULT_CONTAINER_MAX_T,
  fmt, label, rules, complete, ordered, readForm, chaPerCbm, m2ToCbm, cbmPerUnit, chaPerContainer, cost,
  typedFor, saveTyped, history, historyFor,
  autoFill, rowBase, baseOf, normalise, values, amount, thicknesses, calcFrom, storedFor, addPrice, setBase,
};
