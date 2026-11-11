'use strict';

const incentiveRepository = require('../repositories/incentiveRepository');
const pricingRepository = require('../repositories/pricingRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const productService = require('./productService');
const pricingEngine = require('./pricingEngine');

/**
 * Port of App\Services\PricingService — owner decision D-245 (4 Nov 2026), P1:
 * reads the pricing settings and a product's overrides, picks the supplier
 * price "used for pricing", and runs pricingEngine. Who sees what:
 *  - view_price_buildup (MD, ED): cost, margin, minimum, reserve, every floor;
 *  - anyone who may see selling prices: only the quote price and their lowest price;
 *  - record_market_prices (Procurement): supplier prices only, never selling prices.
 */

const DEFAULTS = {
  margin_pct: '20.00',
  other_cost_pct: '2.50',
  pricing_fx_rate: '82.0000',
  buffer_pct: '3.00',
  incentive_mode: 'pct',
  incentive_value: '15.0000',
  stale_days: '60',
  require_product_pick: '1',
  // D-255 (5 Nov 2026)
  cbm_from_mm: '50',
  price_request_daily_limit: '10',
  price_request_valid_days: '7',
  // D-301 (11 Nov 2026): the exchange rate is fetched daily; pricing rate = market × (1 − cushion %).
  fx_mode: 'auto',
  fx_cushion_pct: '2.0',
};

const LABELS = {
  margin_pct: 'Minimum margin (% of price)',
  other_cost_pct: 'Other costs to FOB (% of supplier price)',
  pricing_fx_rate: 'Pricing exchange rate (₹ per USD)',
  buffer_pct: 'Negotiating buffer (%)',
  incentive_mode: 'Team incentive reserve type',
  incentive_value: 'Team incentive reserve value',
  stale_days: 'Supplier price is stale after (days)',
  require_product_pick: 'Goods lines must be picked from Products',
  cbm_from_mm: 'Price per CBM from (mm, this thickness and above)',
  price_request_daily_limit: 'Associate price requests per day',
  price_request_valid_days: "An associate's price stays valid for (days)",
  fx_mode: 'Exchange rate: Automatic (fetched daily) or Manual',
  fx_cushion_pct: 'Cushion below the market rate (%)',
};

/** PHP number_format($v, $d) — comma thousands, fixed decimals. */
function numberFormat(v, d = 2) {
  return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

/** PHP rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') . '%' */
function pct(v) {
  return `${Number(v).toFixed(4).replace(/0+$/, '').replace(/\.$/, '')}%`;
}

function modeLabel(mode, value) {
  return mode === 'pct' ? pct(value) : `USD ${numberFormat(value, 2)} per unit`;
}

function levelLabel(level) {
  return level.kind === 'pct' ? pct(Number(level.value)) : `USD ${numberFormat(level.value, 2)} per unit`;
}

async function settingsRaw() {
  return { ...DEFAULTS, ...(await pricingRepository.settings()) };
}

async function settings() {
  const raw = await settingsRaw();
  return {
    margin_pct: Number(raw.margin_pct),
    other_cost_pct: Number(raw.other_cost_pct),
    // D-301: the rate in use — the fetched market rate less the cushion (Automatic), or the typed one (Manual).
    pricing_fx_rate: await require('./fxRateService').rate(raw),
    pricing_fx_rate_manual: Number(raw.pricing_fx_rate),
    buffer_pct: Number(raw.buffer_pct),
    incentive_mode: raw.incentive_mode === 'usd' ? 'usd' : 'pct',
    incentive_value: Number(raw.incentive_value),
    stale_days: Math.max(1, parseInt(raw.stale_days, 10) || 0),
    require_product_pick: String(raw.require_product_pick) === '1',
    cbm_from_mm: Math.max(1, parseInt(raw.cbm_from_mm, 10) || 0),
    price_request_daily_limit: Math.max(0, parseInt(raw.price_request_daily_limit, 10) || 0),
    price_request_valid_days: Math.max(1, parseInt(raw.price_request_valid_days, 10) || 0),
  };
}

async function programOn() {
  return ((await incentiveRepository.settings()).program_enabled || '0') === '1';
}

const trimNum = (x) => String(x).replace(/\.?0+$/, '');

/** Validates the settings form: [values as stored strings, errors] (same messages as PHP). */
function readSettingsForm(post) {
  const errors = [];
  const num = (key, min, max, minExclusive, maxExclusive, decimals) => {
    const v = String(post[key] ?? '').trim();
    const ok = v !== '' && !Number.isNaN(Number(v)) && new RegExp(`^\\d+(\\.\\d{1,${decimals}})?$`).test(v);
    const f = ok ? Number(v) : 0;
    if (!ok || (minExclusive ? f <= min : f < min) || (maxExclusive ? f >= max : f > max)) {
      errors.push(`${LABELS[key]}: enter a number ${minExclusive ? 'above ' : 'from '}${trimNum(min.toFixed(2))}${maxExclusive ? ' and below ' : ' to '}${trimNum(max.toFixed(2))} with at most ${decimals} decimals.`);
      return '';
    }
    return f.toFixed(decimals);
  };
  const values = {
    margin_pct: num('margin_pct', 0, 90, false, false, 2),
    other_cost_pct: num('other_cost_pct', 0, 100, false, false, 2),
    pricing_fx_rate: num('pricing_fx_rate', 0, 100000, true, false, 4),
    buffer_pct: num('buffer_pct', 0, 100, false, false, 2),
  };
  let mode = String(post.incentive_mode ?? '');
  if (!['pct', 'usd'].includes(mode)) {
    errors.push('Choose whether the team incentive reserve is a % of profit or a USD amount per unit.');
    mode = 'pct';
  }
  values.incentive_mode = mode;
  values.incentive_value = mode === 'pct' ? num('incentive_value', 0, 100, false, false, 4) : num('incentive_value', 0, 1000000, false, false, 4);
  const daysRaw = String(post.stale_days ?? '').trim();
  const days = /^-?\d+$/.test(daysRaw) ? Number(daysRaw) : NaN;
  if (Number.isNaN(days) || days < 1 || days > 3650) {
    errors.push(`${LABELS.stale_days}: enter whole days from 1 to 3650.`);
    values.stale_days = '';
  } else {
    values.stale_days = String(days);
  }
  // D-245 P2: only when the form carries it (a form without the field keeps the stored value).
  if (Object.prototype.hasOwnProperty.call(post, 'require_product_pick')) {
    values.require_product_pick = String(post.require_product_pick) === '1' ? '1' : '0';
  }
  // D-255: only when the form carries them (an older form keeps the stored values).
  for (const [key, [min, max]] of Object.entries({ cbm_from_mm: [1, 2000], price_request_daily_limit: [0, 1000], price_request_valid_days: [1, 365] })) {
    if (!Object.prototype.hasOwnProperty.call(post, key)) continue;
    const v = String(post[key] ?? '').trim();
    const n = /^[+-]?\d+$/.test(v) ? Number(v) : NaN;
    if (Number.isNaN(n) || n < min || n > max) {
      errors.push(`${LABELS[key]}: enter a whole number from ${min} to ${max}.`);
      values[key] = '';
    } else {
      values[key] = String(n);
    }
  }
  // D-301: the exchange-rate mode and cushion (only when the form carries them).
  const [fx, fxErrors] = require('./fxRateService').readForm(post);
  return [{ ...values, ...fx }, [...errors, ...fxErrors]];
}

/** Validates a product's overrides form (empty = use the global value). */
function readOverridesForm(post) {
  const errors = [];
  const opt = (key, label, max) => {
    const v = String(post[key] ?? '').trim();
    if (v === '') return null;
    if (Number.isNaN(Number(v)) || !/^\d+(\.\d{1,4})?$/.test(v) || Number(v) > max) {
      errors.push(`${label}: enter a number from 0 to ${max} with at most 4 decimals, or leave it empty for the global value.`);
      return null;
    }
    return Number(v).toFixed(4);
  };
  const fields = {
    pricing_margin_pct: opt('pricing_margin_pct', 'Margin %', 90),
    pricing_buffer_pct: opt('pricing_buffer_pct', 'Buffer %', 100),
  };
  for (const [k, label] of [['other_cost', 'Other costs to FOB'], ['incentive', 'Team incentive reserve']]) {
    let mode = String(post[`pricing_${k}_mode`] ?? '');
    const value = opt(`pricing_${k}_value`, label, mode === 'pct' ? 100 : 1000000);
    if (value === null) {
      fields[`pricing_${k}_mode`] = null;
      fields[`pricing_${k}_value`] = null;
      continue;
    }
    if (!['pct', 'usd'].includes(mode)) {
      errors.push(`${label}: choose % or USD per unit.`);
      mode = null;
    }
    fields[`pricing_${k}_mode`] = mode;
    fields[`pricing_${k}_value`] = mode === null ? null : value;
  }
  if (fields.pricing_margin_pct !== null && Number(fields.pricing_margin_pct) >= 90) errors.push('Margin %: enter a number below 90.');
  return [fields, errors];
}

/** The supplier row used for pricing: a base price, or D-300 a price for every thickness. */
async function pricingSupplier(productId) {
  return (await productSupplierRepository.forProduct(productId)).find((s) => Number(s.is_primary) === 1 && ['unit', 'grid'].includes(s.price_basis || 'unit')) || null;
}

/**
 * D-255: the per-CBM supplier row used for pricing thick stone (50 mm and above).
 * D-300: a price for every thickness used for pricing also holds the per-CBM price.
 */
async function cbmSupplier(productId) {
  const primary = await pricingSupplier(productId);
  if (primary !== null && (primary.price_basis || '') === 'grid') return primary;
  return (await productSupplierRepository.forProduct(productId)).find((s) => (s.price_basis || 'unit') === 'cbm' && Number(s.is_cbm_primary) === 1) || null;
}

function ymd(d) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

function priceDate(supplier) {
  if (supplier.quoted_on) return String(supplier.quoted_on).slice(0, 10);
  return supplier.updated_at ? String(supplier.updated_at).slice(0, 10) : null;
}

/** 'stale' | 'expired' | null for a supplier price (quoted on, else last changed). */
function staleness(supplier, staleDays, today = null) {
  const t = today || ymd(new Date());
  if (supplier.valid_until && String(supplier.valid_until).slice(0, 10) < t) return 'expired';
  const date = priceDate(supplier);
  if (date === null) return 'stale';
  const cut = new Date(`${t}T00:00:00`);
  cut.setDate(cut.getDate() - staleDays);
  return date < ymd(cut) ? 'stale' : null;
}

/** The full build-up for a product, or a reason it cannot be worked out. */
async function forProduct(product, levels = null, s = null, program = null, selection = null, lineThicknessMm = null) {
  s = s || (await settings());
  program = program === null ? await programOn() : program;
  // D-255: the chosen specification options (spec id => option id); null = the base product.
  const productSpecs = require('./productSpecs');
  let spec = null;
  if (selection !== null && selection !== undefined) {
    const offered = await productSpecs.offered(product);
    const [map, specErrors] = productSpecs.normalize(offered, selection);
    const adj = productSpecs.adjustments(product, offered, map, s.cbm_from_mm, [], lineThicknessMm);
    spec = { map, offered, adj, text: productSpecs.describe(offered, map), errors: specErrors };
  }
  // D-256: an exact supplier price for the chosen combination is the cost for the options it names.
  let exact = null;
  if (spec !== null && !spec.errors.length && spec.adj.manual === null) {
    exact = await exactPrice(product, spec.map, spec.adj.basis, s, spec.offered);
    if (exact !== null) {
      spec.adj = productSpecs.adjustments(product, spec.offered, spec.map, s.cbm_from_mm, Object.keys(exact.named).map(Number), lineThicknessMm);
      spec.exact = exact.text;
    }
  }
  const supplier = exact !== null ? exact.supplier
    : (spec !== null && spec.adj.basis === 'cbm' ? await cbmSupplier(product.id) : await pricingSupplier(product.id));
  // D-300 (point 12): a price for every thickness already holds the Thickness option's % —
  // the chosen thickness is priced from the grid, so the % is not applied again.
  let gridRules = null;
  if (supplier !== null && (supplier.price_basis || '') === 'grid') {
    gridRules = await require('./thicknessPrices').rules(product, s);
    if (spec !== null && !spec.errors.length && spec.adj.manual === null && spec.adj.basis === 'unit') {
      let thicknessSpec = null;
      for (const sp of spec.offered) if (sp.kind === 'thickness' && spec.map[sp.id] !== undefined) thicknessSpec = Number(sp.id);
      if (thicknessSpec !== null) {
        spec.adj = productSpecs.adjustments(product, spec.offered, spec.map, s.cbm_from_mm, [thicknessSpec], lineThicknessMm);
        for (const l of spec.adj.lines) if (l.kind === 'exact') l.kind = 'included';
      }
    }
  }
  const has = (k) => product[k] !== null && product[k] !== undefined;
  const effective = effectiveFor(product, s);
  const overrides = [];
  if (has('pricing_margin_pct')) overrides.push(`margin ${pct(effective.margin_pct)}`);
  if (has('pricing_other_cost_mode')) overrides.push(`other costs ${modeLabel(effective.other_mode, effective.other_value)}`);
  if (has('pricing_incentive_mode')) overrides.push(`incentive ${modeLabel(effective.incentive_mode, effective.incentive_value)}`);
  if (has('pricing_buffer_pct')) overrides.push(`buffer ${pct(effective.buffer_pct)}`);
  const out = {
    supplier, effective, overrides, settings: s, program_on: program,
    exact: exact !== null ? exact.text : null,
    build: null, levels: [], lowest: null, staleness: null, price_date: null, missing: null,
    spec, manual: false, unit: spec !== null ? spec.adj.unit : (String(product.reference_unit || '') || null),
  };
  if (spec !== null && spec.errors.length) {
    out.missing = spec.errors.join(' ');
    return out;
  }
  if (spec !== null && spec.adj.manual !== null) {
    out.missing = spec.adj.manual;
    out.manual = true;
    return out;
  }
  if (supplier === null) {
    if (spec !== null && spec.adj.basis === 'cbm') {
      out.missing = 'No per-CBM supplier price is marked "used for pricing" for thick stone yet — needs a manual price.';
      out.manual = true;
      return out;
    }
    out.missing = 'No supplier price is marked "used for pricing" yet.';
    return out;
  }
  // D-300: FOB cost (₹) = supplier price + CHA and transport per unit (+ packing and loading
  // when the supplier's price does not include them).
  const parts = await fobParts(product, supplier, spec !== null ? String(spec.adj.basis) : 'unit',
    spec !== null && spec.adj.thickness_mm !== null && spec.adj.thickness_mm !== undefined ? Number(spec.adj.thickness_mm) : null, s, gridRules);
  const inr = parts ? parts.total : null;
  if (inr === null || !(inr > 0)) {
    out.missing = 'The supplier price used for pricing has no value yet.';
    return out;
  }
  out.supplier_inr = inr;
  out.inr_parts = parts;
  out.staleness = staleness(supplier, s.stale_days);
  out.price_date = priceDate(supplier);
  const build = pricingEngine.build({
    supplier_inr: inr, fx_rate: s.pricing_fx_rate,
    other_mode: effective.other_mode, other_value: effective.other_value,
    margin_pct: effective.margin_pct,
    incentive_mode: effective.incentive_mode, incentive_value: effective.incentive_value, program_on: program,
    buffer_pct: effective.buffer_pct,
    adjust: spec !== null ? { pct: spec.adj.pct, usd: spec.adj.usd, qty_pct: spec.adj.qty_pct } : null,
  });
  out.build = build;
  for (const l of levels || (await pricingRepository.levels(true))) {
    const a = pricingEngine.associate(build.raw, String(l.kind), Number(l.value));
    const row = { id: Number(l.id), name: String(l.name), label: levelLabel(l), ...a };
    out.levels.push(row);
    if (out.lowest === null || a.floor < out.lowest.floor) out.lowest = row;
  }
  return out;
}

/** The margin, other costs, incentive and buffer that apply to a product: its own, else the pricing settings. */
function effectiveFor(product, s) {
  const has = (k) => product[k] !== null && product[k] !== undefined;
  return {
    margin_pct: has('pricing_margin_pct') ? Number(product.pricing_margin_pct) : s.margin_pct,
    other_mode: product.pricing_other_cost_mode || 'pct',
    other_value: has('pricing_other_cost_mode') ? Number(product.pricing_other_cost_value) : s.other_cost_pct,
    incentive_mode: product.pricing_incentive_mode || s.incentive_mode,
    incentive_value: has('pricing_incentive_mode') ? Number(product.pricing_incentive_value) : s.incentive_value,
    buffer_pct: has('pricing_buffer_pct') ? Number(product.pricing_buffer_pct) : s.buffer_pct,
  };
}

/**
 * D-335: the price build-up from one supplier-price row's FOB cost (₹ per the product's unit) —
 * supplier USD, cost, minimum and quote — for the "Product price (from the base)" line on Market
 * prices. Null when the FOB cost is not above 0.
 */
async function buildFromFob(product, fobInr, s = null, program = null) {
  s = s || (await settings());
  if (!(Number(fobInr) > 0)) return null;
  const e = effectiveFor(product, s);
  const b = pricingEngine.build({
    supplier_inr: Number(fobInr), fx_rate: s.pricing_fx_rate,
    other_mode: e.other_mode, other_value: e.other_value, margin_pct: e.margin_pct,
    incentive_mode: e.incentive_mode, incentive_value: e.incentive_value, program_on: program === null ? await programOn() : program,
    buffer_pct: e.buffer_pct,
  });
  return { ...b, margin_pct: e.margin_pct, buffer_pct: e.buffer_pct, fx_rate: Number(s.pricing_fx_rate) };
}

/**
 * D-300 (point 12): the FOB cost in ₹ per the product's unit (per CBM for 50 mm and above) from one
 * supplier-price row — supplier price + CHA and transport per unit, + packing and loading when the
 * price does not include them. Null when the row has no usable price.
 */
async function fobParts(product, supplier, basis, mm, s = null, gridRules = null) {
  const thicknessPrices = require('./thicknessPrices');
  s = s || (await settings());
  if ((supplier.price_basis || '') === 'grid') {
    const r = gridRules || (await thicknessPrices.rules(product, s));
    return thicknessPrices.cost(r, await thicknessPrices.storedFor(Number(supplier.id)), supplier, product, basis, mm);
  }
  const base = productService.effectiveFobForSupplier(supplier, product);
  if (base === null || base === undefined) return null;
  const parts = { supplier: base, cha: 0, packing: 0, total: base, unit: basis === 'cbm' ? 'CBM' : String(product.reference_unit || ''), mm, how: 'typed', note: null };
  const perContainer = thicknessPrices.chaPerContainer(supplier, product);
  if (perContainer !== null) {
    const r = gridRules || (await thicknessPrices.rules(product, s));
    const cha = thicknessPrices.chaPerCbm(perContainer, Number(r.container_cbm), r.container_max_t, r.t_per_cbm);
    const cbmPerUnit = basis === 'cbm' ? 1 : thicknessPrices.cbmPerUnit(String(product.reference_unit || ''), mm ?? Number(r.base_mm), r.t_per_cbm);
    if (cha.per_cbm !== null && cbmPerUnit !== null) parts.cha = cha.per_cbm * cbmPerUnit;
    else if (cha.per_cbm !== null) parts.note = "CHA + transport is not added: the product's unit cannot be turned into CBM.";
  }
  if (supplier.includes_packing_loading !== undefined && Number(supplier.includes_packing_loading) === 0) {
    parts.packing = Number(product.default_packing_cost || 0) + Number(product.default_loading_cost || 0);
  }
  parts.total = parts.supplier + parts.cha + parts.packing;
  return parts;
}

/**
 * D-256: the exact supplier price that matches a choice of options — the most specific one
 * (most named options), then the most recently quoted. Stale or expired ones are ignored.
 */
async function exactPrice(product, map, basis, s = null, offeredSpecs = null) {
  s = s || (await settings());
  const rows = (await productSupplierRepository.forProduct(product.id))
    .filter((r) => (r.price_basis || '') === 'combo' && Number(r.combo_in_use || 0) === 1 && String(r.combo_per || 'unit') === basis);
  if (!rows.length) return null;
  const named = await pricingRepository.comboOptions(rows.map((r) => Number(r.id)));
  let best = null;
  const cmp = (a, b) => {
    for (let i = 0; i < 3; i++) {
      if (a[i] < b[i]) return -1;
      if (a[i] > b[i]) return 1;
    }
    return 0;
  };
  for (const r of rows) {
    const n = named[Number(r.id)] || {};
    const ids = Object.keys(n);
    if (!ids.length || staleness(r, s.stale_days) !== null) continue;
    if (!ids.every((specId) => map[specId] !== undefined && Number(map[specId]) === n[specId])) continue;
    const key = [ids.length, String(priceDate(r) || ''), Number(r.id)];
    if (best === null || cmp(key, best.key) > 0) best = { supplier: r, named: n, key };
  }
  if (best === null) return null;
  const productSpecs = require('./productSpecs');
  offeredSpecs = offeredSpecs || (await productSpecs.offered(product));
  return { supplier: best.supplier, named: best.named, text: await comboText(offeredSpecs, best.named) };
}

/** "30 mm · Leather" for named options (in the product's specification order). */
async function comboText(offeredSpecs, named) {
  const parts = [];
  for (const sp of offeredSpecs) {
    for (const o of sp.options) {
      if (named[sp.id] !== undefined && Number(named[sp.id]) === o.id) parts.push(o.name);
    }
  }
  if (parts.length < Object.keys(named).length) {
    // An option no longer offered for this product: name it from the option table.
    const specRepository = require('../repositories/specRepository');
    for (const optionId of Object.values(named)) {
      const opt = await specRepository.findOption(optionId);
      if (opt && !parts.includes(String(opt.name))) parts.push(String(opt.name));
    }
  }
  return parts.join(' · ');
}

const shortDay = (d) => {
  const dt = new Date(`${String(d).slice(0, 10)}T00:00:00`);
  return `${String(dt.getDate()).padStart(2, '0')} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][dt.getMonth()]}`;
};

/**
 * D-256: "Pricing ready?" — what a product still needs before it can be priced.
 * Each item: key, ok, label, detail, link, link_text, supplier (a supplier-price line Procurement may see).
 */
async function readiness(product, s = null) {
  s = s || (await settings());
  const db = require('../config/db');
  const productSpecs = require('./productSpecs');
  const id = Number(product.id);
  const market = `/products/${id}/market-prices`;
  const items = [];
  const unit = await pricingSupplier(id);
  const unitInr = unit !== null ? ((await fobParts(product, unit, 'unit', null, s)) || {}).total ?? null : null; // D-300: grid prices too
  items.push({
    key: 'unit', ok: unit !== null && unitInr !== null && unitInr > 0, supplier: true,
    label: `Supplier price per ${String(product.reference_unit || '') || 'unit'} entered and "used for pricing"`,
    detail: unit !== null && unitInr !== null ? `₹${numberFormat(unitInr)}${priceDate(unit) !== null ? ` · ${shortDay(priceDate(unit))}` : ''}` : 'none yet',
    link: market, link_text: unit === null ? 'Add in Market prices' : 'Market prices',
  });
  const offeredSpecs = await productSpecs.offered(product);
  const cbmFrom = (parseInt(product.cbm_from_mm, 10) || 0) > 0 ? parseInt(product.cbm_from_mm, 10) : s.cbm_from_mm;
  let needsCbm = false;
  for (const sp of offeredSpecs) {
    if (sp.kind !== 'thickness') continue;
    for (const o of sp.options) {
      if (o.change_type === 'cbm' || (o.thickness_mm !== null && o.thickness_mm >= cbmFrom)) needsCbm = true;
    }
  }
  const cbm = await cbmSupplier(id);
  if (needsCbm) {
    items.push({
      key: 'cbm', ok: cbm !== null, supplier: true,
      label: `Supplier price per CBM (needed: sold at ${cbmFrom} mm and above)`,
      detail: cbm !== null ? `₹${numberFormat(((await fobParts(product, cbm, 'cbm', null, s)) || {}).total || 0)} per CBM` : 'none yet',
      link: market, link_text: cbm === null ? 'Add in Market prices' : 'Market prices',
    });
  }
  const count = async (sql) => Number((await db.queryOne(sql, { p: id })).n);
  const changes = await count('SELECT COUNT(*) AS n FROM product_spec_hidden WHERE product_id = :p')
    + await count('SELECT COUNT(*) AS n FROM product_option_overrides WHERE product_id = :p')
    + await count('SELECT COUNT(*) AS n FROM spec_definitions WHERE product_id = :p')
    + await count('SELECT COUNT(*) AS n FROM spec_options WHERE product_id = :p');
  const categoryId = await productSpecs.categoryIdFor(product);
  let categoryName = null;
  if (categoryId !== null) {
    const c = await db.queryOne('SELECT name FROM product_categories WHERE id = :c', { c: categoryId });
    categoryName = c ? c.name : null;
  }
  items.push({
    key: 'specs', ok: offeredSpecs.length > 0, supplier: false,
    label: offeredSpecs.length ? `Specifications checked (follows ${categoryName || 'its category'}, ${changes} change${changes === 1 ? '' : 's'})` : 'Specifications: none offered for this product',
    detail: `${offeredSpecs.length} specification${offeredSpecs.length === 1 ? '' : 's'}`,
    link: `/products/${id}/specifications`, link_text: 'View',
  });
  const stale = [];
  for (const [label, sup] of [['m²/unit', unit], ['CBM', needsCbm ? cbm : null]]) {
    if (sup === null) continue;
    const st = staleness(sup, s.stale_days);
    if (st !== null) stale.push(`${label} price ${st}`);
  }
  items.push({
    key: 'current', ok: unit !== null && !stale.length, supplier: true,
    label: `Supplier price is current (under ${s.stale_days} days old)`,
    detail: unit === null ? 'no price yet' : (stale.length ? stale.join(', ') : (unit.valid_until ? `valid until ${shortDay(unit.valid_until instanceof Date ? ymd(unit.valid_until) : unit.valid_until)}` : 'no valid-until date')),
    link: market, link_text: 'Market prices',
  });
  const combos = (await productSupplierRepository.forProduct(id)).filter((r) => (r.price_basis || '') === 'combo' && Number(r.combo_in_use || 0) === 1);
  if (combos.length) {
    const ignored = combos.filter((r) => staleness(r, s.stale_days) !== null).length;
    items.push({
      key: 'exact', ok: ignored === 0, supplier: true,
      label: 'Exact supplier prices for specific combinations are current',
      detail: `${combos.length} in use${ignored > 0 ? `, ${ignored} stale or expired — ignored until renewed` : ''}`,
      link: market, link_text: 'Market prices',
    });
  }
  const forms = productSpecs.productForms(product);
  const open = new Set();
  if (categoryId !== null) {
    const rows = await db.query('SELECT apa.forms FROM associate_price_access apa JOIN associates a ON a.id = apa.associate_id WHERE apa.category_id = :c AND a.status = \'active\'', { c: categoryId });
    for (const r of rows) {
      const allowed = productSpecs.splitForms(r.forms);
      const hit = !allowed.length ? (!forms.length ? ['all forms'] : forms) : (!forms.length ? allowed : forms.filter((f) => allowed.includes(f)));
      for (const h of hit) open.add(h);
    }
  }
  const openList = [...open].map((f) => f.charAt(0).toUpperCase() + f.slice(1));
  items.push({
    key: 'associates', ok: open.size > 0, supplier: false,
    label: `Product open to associates${open.size ? ` (${openList.join(', ')})` : ''}`,
    detail: open.size ? '' : 'no associate may price it yet',
    link: '/associates', link_text: 'Change',
  });
  return { items, to_fix: items.filter((i) => !i.ok).length };
}

/**
 * Validates the ladder form rows (blank rows and rows ticked "remove" are skipped):
 * steps start at order 1, run on without gaps or overlaps, only the last is open-ended.
 */
function readLadderForm(post, levelsById) {
  const asMap = (v) => {
    if (Array.isArray(v)) {
      const o = {};
      v.forEach((x, i) => { o[i] = x; }); // forEach skips the holes of a sparse list
      return o;
    }
    return v && typeof v === 'object' ? v : {};
  };
  const from = asMap(post.from);
  const to = asMap(post.to);
  const level = asMap(post.level_id);
  const remove = asMap(post.remove);
  let errors = [];
  let steps = [];
  for (const i of Object.keys(from)) {
    const f = String(from[i] ?? '').trim();
    const t = String(to[i] ?? '').trim();
    const l = String(level[i] ?? '').trim();
    if (remove[i] || (f === '' && t === '' && l === '')) continue;
    const isInt = (x) => /^-?\d+$/.test(x);
    const fi = isInt(f) ? Number(f) : NaN;
    const ti = t === '' ? null : (isInt(t) ? Number(t) : NaN);
    const li = isInt(l) ? Number(l) : NaN;
    if (Number.isNaN(fi) || fi < 1 || Number.isNaN(ti) || (ti !== null && ti < fi) || Number.isNaN(li) || !levelsById[li]) {
      errors.push('Every step needs a "from" order number (1 or more), a "to" number not below it (or empty for "onward") and a commission level.');
      continue;
    }
    steps.push({ from: fi, to: ti, level_id: li });
  }
  if (errors.length) return [[], [...new Set(errors)]];
  if (steps.length === 0) return [[], ['Keep at least one step in the ladder.']];
  steps = steps.sort((a, b) => a.from - b.from);
  let expect = 1;
  errors = [];
  for (let i = 0; i < steps.length; i += 1) {
    const s = steps[i];
    if (s.from !== expect) {
      errors.push(s.from > expect
        ? `The ladder has a gap: no step covers order ${expect}${s.from - 1 > expect ? `–${s.from - 1}` : ''}.`
        : `Steps overlap at order ${s.from}.`);
      break;
    }
    const last = i === steps.length - 1;
    if (s.to === null && !last) { errors.push('Only the last step may run "onward" (empty "to").'); break; }
    if (last && s.to !== null) { errors.push('Leave the last step\'s "to" empty so it runs onward.'); break; }
    expect = (s.to || 0) + 1;
  }
  return [errors.length ? [] : steps, errors];
}

module.exports = {
  exactPrice, comboText, readiness,
  DEFAULTS, LABELS, settings, settingsRaw, programOn, readSettingsForm, readOverridesForm, pricingSupplier, cbmSupplier, staleness, priceDate,
  forProduct, levelLabel, pct, modeLabel, numberFormat, readLadderForm, fobParts, effectiveFor, buildFromFob,
};
