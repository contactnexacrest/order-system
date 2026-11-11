'use strict';

const specRepository = require('../repositories/specRepository');

/**
 * Port of App\Services\ProductSpecs — owner decision D-255 (5 Nov 2026): the
 * specifications that change the price.
 *
 * A category has specifications (Grade, Thickness, Finish, Size, Edge, Quantity,
 * extras) and options. Each option changes the COST — a %, a USD amount per m²,
 * per running metre or per container — or, for thickness, switches the price to
 * per CBM (50 mm and above). A product follows its category; it may hide a
 * specification or an option, change an option's value (empty = follow the
 * category, so later category changes flow through), and add options or whole
 * specifications for itself only.
 *
 * How the options change the cost (then margin, incentive, commission and buffer
 * are added as usual — pricingEngine):
 *   % options multiply the cost → fixed USD amounts are added → the quantity
 *   discount comes last. Per-m² amounts on a CBM price are converted:
 *   USD per m² × 1000 ÷ thickness (one finished face). Per-running-metre amounts
 *   go into the unit price only for products sold per running metre; per-container
 *   amounts are never in the unit price — both are shown as separate charges.
 */

const FORMS = { block: 'Blocks', slab: 'Slabs', tile: 'Tiles', kerb: 'Kerbs', step: 'Steps', cobble: 'Cobbles' };

const CHANGE_TYPES = {
  none: 'No change',
  pct: '% of the price',
  usd_m2: 'USD per m²',
  usd_rm: 'USD per running metre',
  usd_container: 'USD per container',
  // D-257 Phase 0B: price options for products sold per piece, per kg or per set.
  usd_piece: 'USD per piece',
  usd_kg: 'USD per kg',
  usd_set: 'USD per set',
  cbm: 'Priced per CBM',
};

const KINDS = { normal: 'Normal', thickness: 'Thickness (m² or CBM)', quantity: 'Quantity (applied last)' };

/** Units that count as "per running metre" for per-rm amounts. */
const RM_UNITS = ['RM', 'RMT', 'LM', 'RUNNING METRE', 'RUNNING METER'];

/**
 * D-257 Phase 0B: the units a per-piece / per-kg / per-set amount belongs to.
 * On a product sold in that unit the amount goes into the unit price;
 * otherwise it is listed as a separate charge, like per-rm amounts.
 */
const UNIT_TYPES = {
  usd_piece: { label: 'piece', units: ['PCS', 'NOS', 'PC', 'PIECE', 'PIECES'] },
  usd_kg: { label: 'kg', units: ['KG', 'KGS'] },
  usd_set: { label: 'set', units: ['SET', 'SETS'] },
};

const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

/** PHP filter_var(FILTER_VALIDATE_INT): the integer, or false. */
function validInt(v) {
  const s = String(v ?? '').trim();
  return /^[+-]?(0|[1-9]\d*)$/.test(s) ? Number(s) : false;
}

/** PHP number_format($v, 2) — comma thousands. */
function money(v) {
  return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** PHP rtrim(rtrim(number_format(abs($v), 2, '.', ''), '0'), '.') */
function trim2(v) {
  return Math.abs(Number(v)).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

async function categoryIdFor(product) {
  return product.category_id ? Number(product.category_id) : specRepository.systemCategoryId();
}

function splitForms(value) {
  if (value === null || value === undefined || String(value).trim() === '') return [];
  return String(value).split(',').map((f) => f.trim()).filter((f) => has(FORMS, f));
}

function productForms(product) {
  return splitForms(product.forms ?? null);
}

function formsLabel(value) {
  const forms = splitForms(value);
  return forms.length ? forms.map((f) => FORMS[f].toLowerCase()).join(', ') : 'all';
}

/** Whether a specification applies to a product with these forms. */
function appliesTo(applies, forms) {
  const specForms = splitForms(applies);
  if (!specForms.length || !forms.length) return true;
  return specForms.some((f) => forms.includes(f));
}

/**
 * The product's specifications, with the source of each value (same shape as PHP):
 * spec { id, name, kind, applies_to, source, hidden, changed, options: [{ id, name, is_base,
 * change_type, value, category_value, thickness_mm, source, hidden }] }.
 */
async function tree(product, includeHidden = false) {
  const productId = Number(product.id);
  const categoryId = await categoryIdFor(product);
  const forms = productForms(product);
  const specs = [];
  for (const s of categoryId !== null ? await specRepository.categorySpecs(categoryId, true) : []) {
    if (appliesTo(s.applies_to, forms)) specs.push({ ...s, source: 'category' });
  }
  for (const s of await specRepository.productOnlySpecs(productId)) {
    if (Number(s.is_active) === 1) specs.push({ ...s, source: 'product' });
  }
  const hiddenSpecs = await specRepository.hiddenSpecs(productId);
  const overrides = await specRepository.optionOverrides(productId);
  const options = await specRepository.optionsFor(specs.map((s) => Number(s.id)), productId);
  const out = [];
  for (const s of specs) {
    const sid = Number(s.id);
    const hidden = !!hiddenSpecs[sid];
    if (hidden && !includeHidden) continue;
    const opts = [];
    let changed = false;
    for (const o of options[sid] || []) {
      if (Number(o.is_active) !== 1) continue;
      const oid = Number(o.id);
      const own = o.product_id !== null;
      const ov = overrides[oid] || null;
      const oHidden = !own && ov !== null && ov.is_hidden === 1;
      const oValue = !own && ov !== null && ov.value !== null ? Number(ov.value) : (o.value !== null ? Number(o.value) : null);
      const source = own ? 'product' : (ov !== null && ov.value !== null ? 'changed' : 'category');
      if (own || oHidden || source === 'changed') changed = true;
      if (oHidden && !includeHidden) continue;
      opts.push({
        id: oid, name: String(o.name), is_base: Number(o.is_base) === 1,
        change_type: String(o.change_type), value: oValue,
        category_value: own ? null : (o.value !== null ? Number(o.value) : null),
        thickness_mm: o.thickness_mm !== null ? Number(o.thickness_mm) : null,
        source, hidden: oHidden,
      });
    }
    out.push({
      id: sid, name: String(s.name), kind: String(s.kind), applies_to: s.applies_to,
      source: String(s.source), hidden, changed: changed || hidden, options: opts,
    });
  }
  return out;
}

/** The specifications a buyer can choose from (no hidden ones, at least one option). */
async function offered(product) {
  return (await tree(product)).filter((s) => s.options.length > 0);
}

/**
 * Checks a choice of options against what the product offers. A specification left
 * out takes its base option (or its first option). Returns [map spec id => option id, errors].
 */
/** Selection value meaning "no thickness chosen — price the supplier's BASE" (Try a price). */
const SUPPLIER_BASE = 'base';

function normalize(offeredSpecs, raw) {
  const map = {};
  const errors = [];
  raw = raw || {};
  for (const s of offeredSpecs) {
    const sid = s.id;
    const chosen = raw[sid] ?? raw[String(sid)] ?? null;
    // "Try a price": the supplier's base price — the thickness is left out, so the
    // supplier row's own BASE is priced (D-335), not the category's base option.
    if (chosen === SUPPLIER_BASE && s.kind === 'thickness') continue;
    if (chosen === null || chosen === '' || chosen === SUPPLIER_BASE) {
      const base = s.options.find((o) => o.is_base);
      map[sid] = base ? base.id : s.options[0].id;
      continue;
    }
    if (!s.options.some((o) => String(o.id) === String(chosen))) {
      errors.push(`${s.name}: that option is not offered for this product.`);
      continue;
    }
    map[sid] = parseInt(chosen, 10);
  }
  return [map, errors];
}

/** The chosen options in words, base options left out, e.g. "Standard · 30 mm · Leather". */
function describe(offeredSpecs, map, includeBase = false) {
  const parts = [];
  for (const s of offeredSpecs) {
    for (const o of s.options) {
      if (Number(map[s.id]) === o.id && map[s.id] !== undefined && (includeBase || !o.is_base)) parts.push(o.name);
    }
  }
  return parts.join(' · ');
}

/** What the chosen options do to the cost (same shape as PHP). */
function adjustments(product, offeredSpecs, map, cbmFrom, exactSpecIds = [], lineThicknessMm = null) {
  const out = {
    basis: 'unit', unit: String(product.reference_unit ?? '') || 'unit', thickness_mm: null,
    pct: [], usd: 0, qty_pct: 0, lines: [], charges: [], manual: null,
  };
  const chosen = [];
  for (const s of offeredSpecs) {
    for (const o of s.options) {
      if (map[s.id] !== undefined && Number(map[s.id]) === o.id) chosen.push([s, o]);
    }
  }
  cbmFrom = (parseInt(product.cbm_from_mm, 10) || 0) > 0 ? parseInt(product.cbm_from_mm, 10) : Number(cbmFrom);
  for (const [s, o] of chosen) {
    if (s.kind !== 'thickness') continue;
    out.thickness_mm = o.thickness_mm;
    if (o.change_type === 'cbm' || (o.thickness_mm !== null && o.thickness_mm >= cbmFrom)) {
      out.basis = 'cbm';
      out.unit = 'CBM';
      // D-270 (point 25): the line's own thickness (e.g. 13 cm) on a "50 mm and above" option
      // is thick stone priced per CBM — no manual price needed.
      if (o.thickness_mm === null && lineThicknessMm !== null && lineThicknessMm !== undefined && lineThicknessMm >= cbmFrom) out.thickness_mm = lineThicknessMm;
      if (out.thickness_mm === null) out.manual = `A thickness of ${cbmFrom} mm or more that is not listed needs a manual price.`;
    }
  }
  const isRm = RM_UNITS.includes(String(product.reference_unit ?? '').trim().toUpperCase());
  const exactIds = exactSpecIds.map(Number);
  for (const [s, o] of chosen) {
    const v = Number(o.value ?? 0);
    const type = o.change_type;
    // D-256: an exact supplier price already covers the options it names.
    if (exactIds.includes(Number(s.id))) {
      if (type !== 'none' || !o.is_base) out.lines.push({ spec: s.name, option: o.name, kind: 'exact', value: 0, usd_effect: null });
      continue;
    }
    // D-256: an option worth 0 is included in the base price.
    if (['pct', 'usd_m2', 'usd_rm', 'usd_container', 'usd_piece', 'usd_kg', 'usd_set'].includes(type) && v === 0) {
      out.lines.push({ spec: s.name, option: o.name, kind: 'included', value: 0, usd_effect: null });
      continue;
    }
    if (type === 'none' || type === 'cbm' || v === 0) continue;
    // On a CBM price, a thickness % (a per-m² rule) no longer applies.
    if (type === 'pct' && s.kind === 'thickness' && out.basis === 'cbm') continue;
    const line = { spec: s.name, option: o.name, kind: type, value: v, usd_effect: null };
    if (type === 'pct') {
      if (s.kind === 'quantity') out.qty_pct += v;
      else out.pct.push(v);
    } else if (type === 'usd_m2') {
      if (out.basis === 'cbm') {
        if (out.thickness_mm === null || out.thickness_mm <= 0) {
          out.manual = out.manual ?? 'A per-m² option on a thickness that is not listed needs a manual price.';
          continue;
        }
        const conv = v * 1000 / out.thickness_mm;
        line.usd_effect = conv;
        line.converted = true;
        out.usd += conv;
      } else {
        line.usd_effect = v;
        out.usd += v;
      }
    } else if (type === 'usd_rm') {
      if (isRm) {
        line.usd_effect = v;
        out.usd += v;
      } else {
        out.charges.push(`${o.name}: USD ${money(v)} per running metre, charged separately`);
        continue;
      }
    } else if (type === 'usd_container') {
      out.charges.push(`${o.name}: USD ${money(v)} per container, charged separately`);
      continue;
    } else if (has(UNIT_TYPES, type)) {
      const unitType = UNIT_TYPES[type];
      if (unitType.units.includes(String(product.reference_unit ?? '').trim().toUpperCase())) {
        line.usd_effect = v;
        out.usd += v;
      } else {
        out.charges.push(`${o.name}: USD ${money(v)} per ${unitType.label}, charged separately`);
        continue;
      }
    }
    out.lines.push(line);
  }
  return out;
}

/** "−8%", "+$4/m²" … for the admin pages. */
function changeLabel(type, value) {
  if (type === 'none') return 'base';
  if (type === 'cbm') return 'priced per CBM';
  const v = Number(value || 0);
  if (v === 0) return 'Included in base price'; // D-256
  const sign = v < 0 ? '−' : '+';
  if (type === 'pct') return `${sign}${trim2(v)}%`;
  const per = { usd_m2: '/m²', usd_rm: '/rm', usd_container: '/container', usd_piece: '/piece', usd_kg: '/kg', usd_set: '/set' }[type] || '';
  return `${sign}$${trim2(v)}${per}`;
}

/** Validates a specification form: [data, errors]. */
function readSpecForm(post) {
  const errors = [];
  const name = String(post.name ?? '').trim();
  if (name === '' || [...name].length > 80) errors.push('Give the specification a name (up to 80 characters).');
  let kind = String(post.kind ?? 'normal');
  if (!has(KINDS, kind)) {
    errors.push('Choose what kind of specification this is.');
    kind = 'normal';
  }
  const raw = post.applies_to === undefined ? [] : (Array.isArray(post.applies_to) ? post.applies_to : (typeof post.applies_to === 'object' ? Object.values(post.applies_to) : [post.applies_to]));
  const forms = raw.map(String).filter((f) => has(FORMS, f));
  let order = validInt(post.sort_order ?? '0');
  if (order === false || order < 0 || order > 999) {
    errors.push('Order: a whole number from 0 to 999.');
    order = 0;
  }
  return [{ name, kind, applies_to: forms.length ? forms.join(',') : null, sort_order: order }, errors];
}

/** Validates an option form: [data, errors]. */
function readOptionForm(post) {
  const errors = [];
  const name = String(post.name ?? '').trim();
  if (name === '' || [...name].length > 80) errors.push('Give the option a name (up to 80 characters).');
  let type = String(post.change_type ?? 'none');
  if (!has(CHANGE_TYPES, type)) {
    errors.push('Choose how the option changes the price.');
    type = 'none';
  }
  let value = null;
  if (!['none', 'cbm'].includes(type)) {
    const v = String(post.value ?? '').trim();
    if (v === '' || !/^-?\d+(\.\d{1,4})?$/.test(v) || Number(v) === 0) {
      errors.push('Enter the change as a number other than 0 (e.g. -8 for −8%, or 4.00 for +USD 4.00), with at most 4 decimals.');
    } else if (type === 'pct' && (Number(v) <= -100 || Number(v) > 500)) {
      errors.push('A % change must be above −100 and at most 500.');
    } else if (type !== 'pct' && Math.abs(Number(v)) > 1000000) {
      errors.push('That amount is too large.');
    } else {
      value = Number(v).toFixed(4);
    }
  }
  let mm = null;
  const t = String(post.thickness_mm ?? '').trim();
  if (t !== '') {
    const mmv = validInt(t);
    if (mmv === false || mmv < 1 || mmv > 2000) errors.push('Thickness: whole millimetres from 1 to 2000, or leave it empty.');
    else mm = mmv;
  }
  const isBase = post.is_base ? 1 : 0;
  if (isBase === 1 && !['none', 'cbm'].includes(type)) errors.push('The base option changes nothing — set its change to "No change".');
  const order = validInt(post.sort_order ?? '0');
  return [{ name, change_type: type, value, thickness_mm: mm, is_base: isBase, sort_order: order === false ? 0 : Math.max(0, Math.min(999, order)) }, errors];
}

module.exports = {
  FORMS, CHANGE_TYPES, SUPPLIER_BASE, KINDS, RM_UNITS, UNIT_TYPES, validInt, categoryIdFor, productForms, splitForms, formsLabel, appliesTo, tree, offered,
  normalize, describe, adjustments, changeLabel, readSpecForm, readOptionForm,
};
