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
    pricing_fx_rate: Number(raw.pricing_fx_rate),
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
  return [values, errors];
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

async function pricingSupplier(productId) {
  return (await productSupplierRepository.forProduct(productId)).find((s) => Number(s.is_primary) === 1 && (s.price_basis || 'unit') === 'unit') || null;
}

/** D-255: the per-CBM supplier row used for pricing thick stone (50 mm and above). */
async function cbmSupplier(productId) {
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
async function forProduct(product, levels = null, s = null, program = null, selection = null) {
  s = s || (await settings());
  program = program === null ? await programOn() : program;
  // D-255: the chosen specification options (spec id => option id); null = the base product.
  const productSpecs = require('./productSpecs');
  let spec = null;
  if (selection !== null && selection !== undefined) {
    const offered = await productSpecs.offered(product);
    const [map, specErrors] = productSpecs.normalize(offered, selection);
    const adj = productSpecs.adjustments(product, offered, map, s.cbm_from_mm);
    spec = { map, offered, adj, text: productSpecs.describe(offered, map), errors: specErrors };
  }
  const supplier = spec !== null && spec.adj.basis === 'cbm' ? await cbmSupplier(product.id) : await pricingSupplier(product.id);
  const has = (k) => product[k] !== null && product[k] !== undefined;
  const effective = {
    margin_pct: has('pricing_margin_pct') ? Number(product.pricing_margin_pct) : s.margin_pct,
    other_mode: product.pricing_other_cost_mode || 'pct',
    other_value: has('pricing_other_cost_mode') ? Number(product.pricing_other_cost_value) : s.other_cost_pct,
    incentive_mode: product.pricing_incentive_mode || s.incentive_mode,
    incentive_value: has('pricing_incentive_mode') ? Number(product.pricing_incentive_value) : s.incentive_value,
    buffer_pct: has('pricing_buffer_pct') ? Number(product.pricing_buffer_pct) : s.buffer_pct,
  };
  const overrides = [];
  if (has('pricing_margin_pct')) overrides.push(`margin ${pct(effective.margin_pct)}`);
  if (has('pricing_other_cost_mode')) overrides.push(`other costs ${modeLabel(effective.other_mode, effective.other_value)}`);
  if (has('pricing_incentive_mode')) overrides.push(`incentive ${modeLabel(effective.incentive_mode, effective.incentive_value)}`);
  if (has('pricing_buffer_pct')) overrides.push(`buffer ${pct(effective.buffer_pct)}`);
  const out = {
    supplier, effective, overrides, settings: s, program_on: program,
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
  const inr = productService.effectiveFobForSupplier(supplier, product);
  if (inr === null || !(inr > 0)) {
    out.missing = 'The supplier price used for pricing has no value yet.';
    return out;
  }
  out.supplier_inr = inr;
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
  DEFAULTS, LABELS, settings, settingsRaw, programOn, readSettingsForm, readOverridesForm, pricingSupplier, cbmSupplier, staleness, priceDate,
  forProduct, levelLabel, pct, modeLabel, numberFormat, readLadderForm,
};
