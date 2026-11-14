'use strict';

/**
 * Owner decision D-245, phase P2 (4 Nov 2026): prices on order lines.
 *
 * A goods line picked from Products is checked against the floor of whoever
 * sells it: the staff floor, or — when the order's client has an associate
 * (and the order is one the associate earns on) — that associate's floor at
 * their commission level. Below the floor the line needs the MD's approval,
 * which may go down to the minimum price and never below it; until then the
 * Proforma Invoice cannot be issued. When the PI is issued the figures are
 * fixed on the line (price_snapshot), so later changes to the settings or the
 * supplier price do not move that order's check. An approval holds only while
 * the line keeps the approved price.
 *
 * Lines typed by hand (not picked from Products), service and charge lines,
 * lines without a price yet (To Be Confirmed) and orders not in USD are not
 * checked — prices are built in USD per unit (FOB).
 *
 * PHP twin: src/Services/OrderLinePricing.php.
 */
const db = require('../config/db');
const pricingService = require('./pricingService');
const pricingEngine = require('./pricingEngine');
const pricingRepository = require('../repositories/pricingRepository');
const lineUnits = require('./lineUnits');

const OK = 'ok';
const BELOW = 'below';
const BELOW_MINIMUM = 'below_minimum';
const NO_PRICE = 'no_price';
const APPROVED = 'approved';
const UNCHECKED = 'unchecked';
/** D-270 (point 25): the line's unit needs a piece size to be compared — asked for, never "too low". */
const NEEDS_SIZE = 'needs_size';
/**
 * Owner decision D-338 (2b): the price is otherwise fine, but it comes from a per-CBM
 * (50 mm and above) price only CALCULATED from the supplier's thinner base (e.g. 20 mm)
 * — no 50 mm+ price was quoted — so the MD approves it, like a line below the floor.
 */
const CBM_CALCULATED = 'cbm_calculated';
const BLOCKING = [BELOW, BELOW_MINIMUM, NO_PRICE, NEEDS_SIZE, CBM_CALCULATED];
/** Statuses the MD can approve (D-245 P2; D-338: also a CBM price worked out from a thinner base). */
const APPROVABLE = [BELOW, NO_PRICE, CBM_CALCULATED];

/**
 * Owner decision D-338 (1c): a line "As per annexure" has no piece size, so a price that
 * needs the size to be converted cannot be checked — the one wording everywhere (saving
 * the line, the Quotation / PI gate, the price check, the MD's approval page).
 */
function annexureSizeMessage(lineNo, unit) {
  const word = String(unit ?? '').trim() === '' ? 'CBM' : lineUnits.label(unit);
  return `Line ${lineNo} says "As per annexure", so its size is not known — type the price per ${word} for this line.`;
}

/** D-338 (1c): whether a conversion is missing a piece size (not the weight per CBM). */
function missingPieceSize(conv) {
  return conv.factor === null && !conv.unconvertible && conv.missing && conv.missing.length > 0
    && Object.prototype.hasOwnProperty.call(lineUnits.SIZE_FIELDS, String(conv.missing[0]));
}

/**
 * D-338 (2b): the MD-approval reason when the figures' per-CBM price was only worked out
 * from the supplier's thinner base, else null.
 */
function cbmCalculatedReason(figures) {
  if (!figures || !figures.cbm_calculated) return null;
  const from = figures.cbm_calc_from_mm !== null && figures.cbm_calc_from_mm !== undefined ? Number(figures.cbm_calc_from_mm) : 20;
  const cbmFrom = figures.cbm_from_mm !== null && figures.cbm_from_mm !== undefined ? Number(figures.cbm_from_mm) : 50;
  return `CBM price worked out from the supplier's ${from} mm price — no ${cbmFrom} mm+ price quoted`;
}

const n2 = (v) => round2(Number(v)).toFixed(2); // HNT-L01: PHP round()
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const auditLog = (...a) => require('../repositories/auditLogRepository').log(...a);
const { round2 } = require('../helpers/money'); // HNT-L01: PHP round()

/** The order is one the client's associate earns on: forced "yes", or within the first-order window and not forced "no". */
async function associateEarnsOn(order) {
  let mode = 'auto';
  if (order.id) {
    const row = await require('../repositories/incentiveRepository').forOrder(Number(order.id));
    mode = String((row && row.associate_mode) || 'auto');
  }
  if (mode === 'no') return false;
  if (mode === 'yes' || !order.id) return true;
  return require('./incentiveService').withinFirstTenDays(order);
}

/**
 * The associate's commission level: the level fixed on the order when its PI was
 * issued, else their fixed level, or the ladder step for their next counting order
 * (D-245 P3: counting orders replace the P2 stand-in). Returns [level, count].
 */
async function levelForAssociate(associate, excludeOrderId) {
  const associateCommission = require('./associateCommission');
  if (excludeOrderId) {
    const order = await require('../repositories/orderRepository').find(excludeOrderId);
    const fixed = order && Number(order.associate_id || 0) === Number(associate.id) ? associateCommission.fixedLevel(order) : null;
    if (fixed) {
      const level = fixed.id !== null ? await pricingRepository.findLevel(fixed.id) : null;
      // The level as fixed (its value then), even if the level was changed or deactivated since.
      return [{ ...(level || {}), id: fixed.id, name: fixed.name, kind: fixed.kind, value: fixed.value }, null];
    }
  }
  return associateCommission.levelForAssociate(associate, excludeOrderId);
}

/** Who sells this order: the client's associate at their level (when the order is one they earn on), else staff. */
async function context(order) {
  const ctx = { associate: null, level: null, key: 'staff', label: 'Staff', ladder_count: null,
    usd: String((order && order.currency_code) || '').toUpperCase() === 'USD',
    // D-342: once the Quotation is approved, prices are worked out at the rate fixed then — never today's.
    fixed_fx: require('./priceLock').fixedRate(order || {}) };
  const client = order && order.client_id ? await require('../repositories/clientRepository').find(Number(order.client_id)) : null;
  if (!client || !client.associate_id) return ctx;
  const associate = await require('../repositories/associateRepository').find(Number(client.associate_id));
  if (!associate || !(await associateEarnsOn(order))) return ctx;
  const [level, count] = await levelForAssociate(associate, order.id ? Number(order.id) : null);
  ctx.associate = associate;
  ctx.ladder_count = count;
  if (level) {
    ctx.level = level;
    ctx.key = `L${Number(level.id)}`;
    ctx.label = `${associate.name} (${pricingService.levelLabel(level)})`;
  }
  return ctx;
}

/** Live figures for a product in this order's context, or { missing }. */
async function liveFigures(product, ctx, selection = null, lineThicknessMm = null) {
  // D-255: with the line's chosen specification options (null = the base product).
  // D-270: and the line's own thickness (a "50 mm and above" option priced per CBM).
  // D-342: an order whose Quotation is approved works its prices out at the rate fixed then.
  let settings = null;
  if (ctx.fixed_fx !== null && ctx.fixed_fx !== undefined) {
    settings = { ...(await pricingService.settings()), pricing_fx_rate: Number(ctx.fixed_fx) };
  }
  const p = await pricingService.forProduct(product, [], settings, null, selection, lineThicknessMm);
  if (!p.build) return { missing: String(p.missing), manual: !!p.manual };
  const b = p.build;
  let floor = b.staff_floor;
  let quote = b.staff_quote;
  if (ctx.level) {
    const a = pricingEngine.associate(b.raw, String(ctx.level.kind), Number(ctx.level.value));
    floor = a.floor;
    quote = a.quote;
  }
  return {
    cost: b.cost, minimum: b.minimum, reserve: b.reserve,
    staff_floor: b.staff_floor, staff_quote: b.staff_quote,
    floor, quote,
    sold_by: ctx.key, sold_by_label: ctx.label,
    associate_id: ctx.associate ? Number(ctx.associate.id) : null,
    level_id: ctx.level ? Number(ctx.level.id) : null,
    level_name: ctx.level ? String(ctx.level.name) : null,
    level_kind: ctx.level ? String(ctx.level.kind) : null,
    level_value: ctx.level ? Number(ctx.level.value) : null,
    margin_pct: p.effective.margin_pct, buffer_pct: p.effective.buffer_pct,
    other_mode: p.effective.other_mode, other_value: p.effective.other_value,
    incentive_mode: p.effective.incentive_mode, incentive_value: p.effective.incentive_value,
    program_on: p.program_on, fx_rate: p.settings.pricing_fx_rate,
    supplier_inr: p.supplier_inr ?? null,
    supplier_id: p.supplier && p.supplier.id ? Number(p.supplier.id) : null,
    unit: p.unit, spec_text: p.spec ? p.spec.text : null, spec_map: p.spec ? p.spec.map : null,
    // D-270: what the conversion of a line in another unit needs.
    thickness_mm: p.spec && p.spec.adj ? (p.spec.adj.thickness_mm ?? null) : null, weight_t_per_cbm: await weightPerCbm(product),
    // D-338 (2b): a per-CBM (50 mm+) price only CALCULATED from a thinner supplier base.
    cbm_calculated: !!p.inr_parts && p.inr_parts.unit === 'CBM' && (p.inr_parts.how || 'typed') === 'calculated',
    cbm_calc_from_mm: p.inr_parts && p.inr_parts.calc_from_mm !== undefined && p.inr_parts.calc_from_mm !== null ? Number(p.inr_parts.calc_from_mm) : null,
    cbm_from_mm: p.inr_parts && p.inr_parts.cbm_from !== undefined && p.inr_parts.cbm_from !== null ? Number(p.inr_parts.cbm_from) : null,
  };
}

/** D-270 (point 25): tonnes per CBM — the product's own figure, else its category's (stone 2.7); null when neither is set. */
async function weightPerCbm(product) {
  if (product.weight_t_per_cbm !== null && product.weight_t_per_cbm !== undefined && Number(product.weight_t_per_cbm) > 0) return Number(product.weight_t_per_cbm);
  const categoryId = await require('./productSpecs').categoryIdFor(product);
  if (categoryId === null || categoryId === undefined) return null;
  const row = await db.queryOne('SELECT weight_t_per_cbm FROM product_categories WHERE id = :id', { id: categoryId });
  const v = row ? row.weight_t_per_cbm : null;
  return v !== null && v !== undefined && Number(v) > 0 ? Number(v) : null;
}

/** D-270: the line's piece size in cm, [length, width, thickness] (null = not given). */
function lineSize(line) {
  return [lineUnits.size(line.piece_length_cm), lineUnits.size(line.piece_width_cm), lineUnits.size(line.piece_thickness_cm)];
}

/** D-270: the thickness option chosen on a line, in mm (null = none, or one without a thickness). */
async function optionThicknessMm(product, selection) {
  if (!selection) return null;
  const productSpecs = require('./productSpecs');
  const offered = await productSpecs.offered(product);
  const [map] = productSpecs.normalize(offered, selection);
  for (const s of offered) {
    if (s.kind !== 'thickness') continue;
    for (const o of s.options) {
      if (Number(map[s.id]) === Number(o.id) && o.thickness_mm !== null && o.thickness_mm !== undefined) return Number(o.thickness_mm);
    }
  }
  return null;
}

/**
 * D-270 (point 25): the Thickness (cm) box and the Thickness option stay in sync — a thickness
 * typed in cm chooses the option of that thickness, or, from the product's per-CBM thickness
 * (50 mm) up, its "priced per CBM" option. Returns [selection, refusal beside the field].
 */
async function syncThickness(catalogProductId, selection, thicknessCm) {
  if (!catalogProductId || thicknessCm === null || thicknessCm === undefined || thicknessCm === '') return [selection, null];
  const product = await require('../repositories/productRepository').find(Number(catalogProductId));
  if (!product) return [selection, null];
  const mm = Math.round(Number(thicknessCm) * 100) / 10;
  const cbmFrom = (parseInt(product.cbm_from_mm, 10) || 0) > 0 ? parseInt(product.cbm_from_mm, 10) : (await pricingService.settings()).cbm_from_mm;
  for (const s of await require('./productSpecs').offered(product)) {
    if (s.kind !== 'thickness') continue;
    let exact = null;
    let perCbm = null;
    for (const o of s.options) {
      if (o.thickness_mm !== null && o.thickness_mm !== undefined && Math.abs(Number(o.thickness_mm) - mm) < 0.05) exact = o.id;
      const isCbm = o.change_type === 'cbm' || (o.thickness_mm !== null && o.thickness_mm !== undefined && o.thickness_mm >= cbmFrom);
      if (isCbm && (perCbm === null || o.thickness_mm === null || o.thickness_mm === undefined)) perCbm = o.id;
    }
    const pick = exact ?? (mm >= cbmFrom ? perCbm : null);
    if (pick === null) {
      const listed = s.options.filter((o) => o.thickness_mm !== null && o.thickness_mm !== undefined && o.thickness_mm < cbmFrom).map((o) => lineUnits.num(o.thickness_mm / 10));
      return [selection, `There is no ${lineUnits.num(mm)} mm ${s.name} option for this product — enter `
        + (listed.length ? `${listed.join(', ')} cm, or ` : '') + `${lineUnits.num(cbmFrom / 10)} cm or more (priced per CBM).`];
    }
    const out = { ...(selection || {}) };
    out[Number(s.id)] = Number(pick);
    const sorted = {};
    for (const k of Object.keys(out).map(Number).sort((a, b) => a - b)) sorted[k] = out[k];
    return [sorted, null];
  }
  return [selection, null];
}

/** D-270 (point 25): how the line's unit compares with the unit of its figures. */
async function conversionFor(line, figures, product = null) {
  const [l, w, t] = lineSize(line);
  const refUnit = String(figures.unit ?? '');
  const lineUnit = String(line.unit ?? '');
  let density = figures.weight_t_per_cbm !== undefined ? lineUnits.size(figures.weight_t_per_cbm) : null;
  let optionMm = figures.thickness_mm !== undefined ? lineUnits.size(figures.thickness_mm) : null;
  let quick = lineUnits.factor(lineUnit, refUnit, l, w, t ?? (optionMm !== null ? optionMm / 10 : null), density);
  if (quick.factor === null && !quick.unconvertible && !product && line.catalog_product_id) {
    product = await require('../repositories/productRepository').find(Number(line.catalog_product_id));
  }
  if (quick.factor === null && !quick.unconvertible && product) {
    // Figures fixed before point 25 (PI, price request) do not carry these: take them from the product.
    density = density ?? await weightPerCbm(product);
    optionMm = optionMm ?? await optionThicknessMm(product, lineSelection(line));
    quick = lineUnits.factor(lineUnit, refUnit, l, w, t ?? (optionMm !== null ? optionMm / 10 : null), density);
  }
  let message = null;
  if (quick.unconvertible) message = `A price per ${lineUnits.label(lineUnit)} cannot be converted to the product's price per ${lineUnits.label(refUnit)} — needs MD approval.`;
  else if (quick.missing.length) message = lineUnits.missingMessage(quick.missing[0], lineUnit, refUnit);
  return { ...quick, line_unit: lineUnit, ref_unit: refUnit, message };
}

/** D-270: the figures per the line's unit (reference figures × factor). */
function scaleFigures(figures, factor, lineUnit) {
  const out = { ...figures };
  for (const k of ['cost', 'minimum', 'reserve', 'staff_floor', 'staff_quote', 'floor', 'quote']) {
    if (out[k] !== undefined && out[k] !== null) out[k] = Number(out[k]) * factor;
  }
  if (Math.abs(factor - 1) > 1e-9 && lineUnit !== '') out.unit = lineUnit;
  return out;
}

/**
 * D-270 (point 13): a line's chosen options as labels for the order page — every option, in the
 * product's order; one switched off since is shown as gone, never replaced. [{ name, gone }].
 */
async function optionChips(line, product = null) {
  const selection = lineSelection(line);
  if (!selection || !line.catalog_product_id) return [];
  product = product || await require('../repositories/productRepository').find(Number(line.catalog_product_id));
  if (!product) return [];
  const chips = [];
  const seen = {};
  for (const s of await require('./productSpecs').offered(product)) {
    if (!(s.id in selection)) continue;
    seen[s.id] = true;
    const found = s.options.find((o) => Number(o.id) === Number(selection[s.id]));
    chips.push(found ? { name: String(found.name), gone: false } : await goneChip(selection[s.id]));
  }
  for (const [specId, optionId] of Object.entries(selection)) {
    if (!seen[specId]) chips.push(await goneChip(optionId));
  }
  return chips;
}

async function goneChip(optionId) {
  const o = await require('../repositories/specRepository').findOption(Number(optionId));
  return { name: `"${o ? String(o.name) : 'This option'}" is no longer offered — choose again`, gone: true };
}

const intOk = (v) => /^\s*[+-]?(0|[1-9]\d*)\s*$/.test(String(v));

/** D-255: a line's chosen options from the form (JSON {spec id: option id}); null when none. */
function selectionInput(raw) {
  let data;
  if (raw && typeof raw === 'object') {
    data = raw;
  } else {
    raw = String(raw ?? '').trim();
    if (raw === '') return null;
    try { data = JSON.parse(raw); } catch (e) { return null; }
  }
  if (!data || typeof data !== 'object' || !Object.keys(data).length) return null;
  const out = {};
  for (const [spec, option] of Object.entries(data)) {
    if (intOk(spec) && (typeof option === 'number' ? Number.isInteger(option) : intOk(option))) out[parseInt(spec, 10)] = parseInt(option, 10);
  }
  return Object.keys(out).length ? out : null;
}

/** D-255: stores a line's chosen options and the price request it came from. */
async function saveSelection(lineId, selection, priceRequestId) {
  await db.execute('UPDATE order_products SET spec_selection = :s, price_request_id = :pr WHERE id = :id',
    { s: selection !== null && selection !== undefined ? JSON.stringify(selection) : null, pr: priceRequestId ?? null, id: lineId });
  // D-357: a line picked from Products takes its finish from the chosen Surface finish option.
  await require('./surfaceFinishList').applyToLine(lineId);
}

/** D-255: a line's options as stored (null = none). */
function lineSelection(line) {
  return selectionInput(String(line.spec_selection ?? ''));
}

/** D-255: the figures of the price request a line came from, while it still follows it. */
async function priceRequestFigures(line) {
  if (!line.price_request_id) return null;
  const r = await require('./priceRequestService').find(Number(line.price_request_id));
  let f = null;
  if (r && r.figures) { try { f = JSON.parse(r.figures); } catch (e) { f = null; } }
  if (!f || typeof f !== 'object') return null;
  if (f.minimum === undefined || f.minimum === null) f.minimum = f.floor;
  if (f.unit === undefined || f.unit === null) f.unit = r.unit ?? null;
  f.price_request = r.number;
  return f;
}

/** D-255: the quote and floor for a product with chosen options, for the order form (never cost). */
async function lineQuote(product, ctx, selection, unit = null, size = {}) {
  // D-270 (point 25): a typed thickness chooses the Thickness option, as on save.
  const [synced, syncError] = await syncThickness(Number(product.id), selection, size.piece_thickness_cm ?? null);
  if (syncError !== null) return { ok: false, reason: syncError, field: 'piece_thickness_cm' };
  selection = synced;
  const t = lineUnits.size(size.piece_thickness_cm);
  const f = await liveFigures(product, ctx, selection, t !== null ? t * 10 : null);
  if (f.missing !== undefined) return { ok: false, reason: f.missing, manual: !!f.manual };
  const out = { ok: true, quote: f.quote, floor: f.floor, unit: f.unit, spec_text: f.spec_text,
    selection, ref_unit: f.unit, ref_quote: f.quote, ref_floor: f.floor, factor: 1.0 };
  if (unit) {
    const conv = await conversionFor({ unit, ...size, spec_selection: selection ? JSON.stringify(selection) : null }, f, product);
    if (conv.factor === null) {
      return { ok: false, reason: String(conv.message), field: conv.missing[0] ?? null, unconvertible: conv.unconvertible,
        ref_unit: f.unit, ref_quote: f.quote, ref_floor: f.floor, selection };
    }
    out.factor = conv.factor;
    out.quote = round2(f.quote * conv.factor);
    out.floor = round2(f.floor * conv.factor);
    out.unit = Math.abs(conv.factor - 1) > 1e-9 ? unit : f.unit;
  }
  return out;
}

/** Whether a line is in scope of the check at all, with the reason when not. */
function scope(line, ctx) {
  if ((line.line_type || 'goods') !== 'goods') return 'Service and charge lines are not price-checked.';
  if (!line.catalog_product_id) return 'Typed by hand — pick it from Products for a price check.';
  if (!ctx.usd) return 'Prices are built in USD — no price check on orders in another currency.';
  if (line.unit_price === null || line.unit_price === undefined || line.unit_price === '') return 'No price yet.';
  return null;
}

function approvalHolds(line) {
  return Boolean(line.price_approved_at) && line.price_approved_unit_price !== null && line.price_approved_unit_price !== undefined
    && line.unit_price !== null && line.unit_price !== undefined && Math.abs(Number(line.unit_price) - Number(line.price_approved_unit_price)) < 0.005
    // Fresh-eyes review M4: and only for the options and unit it was given for.
    && (line.price_approved_selection === null || line.price_approved_selection === undefined || line.price_approved_selection === approvalSelectionKey(line));
}

/** The line's options and unit as one text, kept with the MD's approval (fresh-eyes review M4). */
function approvalSelectionKey(line) {
  let key = `${String(line.spec_selection ?? '').trim()}|${String(line.unit ?? '').trim()}`;
  // D-270: and the piece size, once one is given (a new size is a new price per piece).
  const sz = lineSize(line);
  if (sz.some((v) => v !== null)) key += `|${sz.map((v) => (v !== null ? lineUnits.num(v) : '')).join('x')}`;
  return key;
}

/** The check of one line: { status, figures, fixed, note, approval, requested }. */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** D-374: "Priced at ₹91.82 per USD on 12 Nov 2026", "Rate not recorded", or '' for a line without a price. Twin of pricedAtText(). */
function pricedAtText(line) {
  if (line.unit_price === null || line.unit_price === undefined) return '';
  if (line.priced_fx_rate === null || line.priced_fx_rate === undefined || !line.priced_at) return 'Rate not recorded';
  const [y, m, d] = String(line.priced_at).slice(0, 10).split('-').map(Number);
  const rate = Number(line.priced_fx_rate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `Priced at ₹${rate} per USD on ${d} ${MONTHS[m - 1]} ${y}`;
}

async function checkLine(line, ctx, product = null) {
  const out = { status: UNCHECKED, figures: null, fixed: false, note: null, approval: null, requested: Boolean(line.price_approval_requested_at), reference: null, conversion: null };
  const reason = scope(line, ctx);
  if (reason !== null) {
    out.note = reason;
    return out;
  }
  let figures;
  let snapshot = null;
  if (line.price_snapshot) {
    try { snapshot = JSON.parse(line.price_snapshot); } catch (e) { snapshot = null; }
  }
  if (snapshot && typeof snapshot === 'object') {
    figures = snapshot;
    out.fixed = true;
  } else {
    const prFigures = await priceRequestFigures(line);
    if (prFigures !== null) {
      // D-255: a line from a price request keeps the request's prices.
      figures = prFigures;
      out.fixed = true;
    } else {
      product = product || await require('../repositories/productRepository').find(Number(line.catalog_product_id));
      const t = lineSize(line)[2];
      figures = product ? await liveFigures(product, ctx, lineSelection(line), t !== null ? t * 10 : null) : { missing: 'The product is no longer in Products.' };
    }
  }
  if (approvalHolds(line)) {
    out.approval = {
      by: Number(line.price_approved_by), at: line.price_approved_at, reason: String(line.price_approval_reason),
      commission_zero: Number(line.price_approval_commission_zero) === 1,
      incentive_cut: line.price_approval_incentive_cut !== null && line.price_approval_incentive_cut !== undefined ? Number(line.price_approval_incentive_cut) : null,
    };
  }
  if (figures.missing !== undefined) {
    out.note = `No price set — needs MD approval. ${figures.missing}`;
    out.status = out.approval ? APPROVED : NO_PRICE;
    return out;
  }
  // D-270 (point 25): the line's price is compared in the product's reference unit — the
  // figures are shown per the line's unit, with the reference figures beside them.
  const conv = await conversionFor(line, figures, product);
  out.reference = figures;
  out.conversion = conv;
  if (conv.factor === null) {
    out.note = conv.message;
    // D-338 (1c): a line "As per annexure" has no size to convert with.
    if (Number(line.as_per_annexure || 0) === 1 && missingPieceSize(conv)) {
      out.note = annexureSizeMessage(Number(line.line_no || 0), String(line.unit ?? ''));
      out.annexure_size = true;
    }
    out.status = out.approval ? APPROVED : (conv.unconvertible ? NO_PRICE : NEEDS_SIZE);
    return out;
  }
  figures = scaleFigures(figures, conv.factor, String(line.unit ?? ''));
  out.conversion.price_ref = conv.factor > 0 ? Number(line.unit_price) / conv.factor : null;
  out.figures = figures;
  const price = Number(line.unit_price);
  if (out.approval) out.status = APPROVED;
  else if (price < Number(figures.minimum) - 0.0001) out.status = BELOW_MINIMUM;
  else if (price < Number(figures.floor) - 0.0001) out.status = BELOW;
  // D-338 (2b): at or above the floor, but the CBM price is only worked out — the MD approves it.
  else if (cbmCalculatedReason(figures) !== null) out.status = CBM_CALCULATED;
  else out.status = OK;
  out.cbm_reason = cbmCalculatedReason(figures);
  return out;
}

/** Every line of the order with its check, and the lines keeping the PI back. */
async function forOrder(orderId) {
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return { ctx: {}, lines: {}, blocking: [] };
  const ctx = await context(order);
  const out = { ctx, lines: {}, blocking: [] };
  for (const line of await require('../repositories/orderProductRepository').forOrder(orderId)) {
    const check = await checkLine(line, ctx);
    check.line = line;
    out.lines[Number(line.id)] = check;
    if (BLOCKING.includes(check.status)) out.blocking.push(check);
  }
  return out;
}

/**
 * Owner decision D-245 P2: why the Quotation (QT) or the Proforma Invoice (PI) cannot be
 * generated yet — a line below its lowest price, below the minimum price, or with no price
 * set, not approved by the MD — or null when it can.
 */
async function documentBlockReason(orderId, typeCode) {
  const first = (await forOrder(orderId)).blocking[0];
  if (!first) return null;
  const doc = typeCode === 'QT' ? 'the Quotation' : 'the Proforma Invoice';
  const no = Number(first.line.line_no);
  if (first.status === NEEDS_SIZE && first.annexure_size) return String(first.note); // D-338 (1c): the same wording as when the line is saved
  if (first.status === CBM_CALCULATED) return `Line ${no} needs MD approval: ${first.cbm_reason} — the MD must approve it before ${doc} (order page → Products).`;
  if (first.status === NEEDS_SIZE) {
    const note = String(first.note || '');
    return `Line ${no} needs its piece size to check its price — ${note.charAt(0).toLowerCase()}${note.slice(1)} Then generate ${doc} (order page → Products).`;
  }
  if (first.status === NO_PRICE) return `Line ${no} has no price set for its product — the MD must approve it before ${doc} (order page → Products).`;
  if (first.status === BELOW_MINIMUM) return `Line ${no} is below the price NexaCrest can accept — raise it before ${doc} (order page → Products).`;
  return `Line ${no} is below the lowest price for this order — raise it, or ask the MD to approve it, before ${doc} (order page → Products).`;
}

/**
 * Owner decision D-273 (point 17): the summary bar above the products table — "2 lines OK ·
 * 1 line needs MD approval (line 3) — the Quotation waits for it." Null when no line is checked.
 */
function summary(checks) {
  const groups = { ok: [], md: [], size: [], minimum: [] };
  for (const c of Object.values(checks)) {
    const no = Number(c.line.line_no);
    if (c.status === OK || c.status === APPROVED) groups.ok.push(no);
    else if (c.status === BELOW || c.status === NO_PRICE || c.status === CBM_CALCULATED) groups.md.push(no);
    else if (c.status === NEEDS_SIZE) groups.size.push(no);
    else if (c.status === BELOW_MINIMUM) groups.minimum.push(no);
  }
  const n = (k) => `${k} line${k === 1 ? '' : 's'}`;
  const which = (nos) => ` (line${nos.length === 1 ? ' ' : 's '}${nos.join(', ')})`;
  const parts = [];
  if (groups.ok.length) parts.push(`${n(groups.ok.length)} OK`);
  if (groups.md.length) parts.push(`${n(groups.md.length)}${groups.md.length === 1 ? ' needs' : ' need'} MD approval${which(groups.md)}`);
  if (groups.size.length) parts.push(`${n(groups.size.length)}${groups.size.length === 1 ? ' needs its' : ' need their'} piece size${which(groups.size)}`);
  if (groups.minimum.length) parts.push(`${n(groups.minimum.length)} below what NexaCrest can accept${which(groups.minimum)}`);
  if (!parts.length) return null;
  const blocking = groups.md.length + groups.size.length + groups.minimum.length;
  return { text: parts.join(' · ') + (blocking > 0 ? ` — the Quotation waits for ${blocking === 1 ? 'it.' : 'them.'}` : '.'), blocking: blocking > 0 };
}

/**
 * D-273 (point 17): the totals row — goods value, CBM (from each line's unit and piece size;
 * lines whose size is not known are not counted) and containers of 8 CBM.
 */
function totals(lines, checks) {
  const out = { amount: 0, cbm: 0, counted: 0, uncounted: 0, containers: 0 };
  for (const line of lines) {
    if ((line.line_type || 'goods') !== 'goods') continue;
    out.amount += Number(line.fob_value || 0);
    const qty = line.quantity !== null && line.quantity !== undefined && !Number(line.quantity_is_tbc) ? Number(line.quantity) : null;
    const ref = (checks[Number(line.id)] || {}).reference || null;
    let [l, w, t] = lineSize(line);
    if (t === null && ref && ref.thickness_mm !== null && ref.thickness_mm !== undefined) t = Number(ref.thickness_mm) / 10;
    const density = ref && ref.weight_t_per_cbm !== null && ref.weight_t_per_cbm !== undefined ? Number(ref.weight_t_per_cbm) : null;
    const per = lineUnits.cbmPerUnit(line.unit, l, w, t, density);
    if (qty === null || per === null) { out.uncounted += 1; continue; }
    out.cbm += qty * per;
    out.counted += 1;
  }
  out.amount = round2(out.amount);
  out.containers = lineUnits.containers(out.cbm);
  out.cbm = Math.round(out.cbm * 100) / 100;
  return out;
}

/** Why the PI cannot be issued yet, or null when it can. */
async function piBlockReason(orderId) {
  return documentBlockReason(orderId, 'PI');
}

const PICK_MESSAGE = 'Pick this goods line from Products — on a USD order goods lines cannot be typed by hand (service and charge lines can). The MD can change this in Admin → Pricing.';

/** Owner decision D-245 P2: whether goods lines on this currency must be picked from Products (Admin › Pricing). */
async function productPickRequired(currencyCode) {
  return String(currencyCode || '').toUpperCase() === 'USD' && (await pricingService.settings()).require_product_pick;
}

/** The refusal for a goods line typed by hand when picking from Products is required, else null. */
/**
 * SUI-005 (QA 13 Nov 2026): postedProductId is the product id the form posted. When it names a
 * product that is not quotable (D-361: not configured, no active supplier, or Inactive — e.g.
 * changed after the page was opened), the refusal says so instead of "typed by hand".
 */
async function pickRefusal(currencyCode, catalogProductId, lineType, postedProductId = null) {
  if (lineType !== 'goods' || catalogProductId || !(await productPickRequired(currencyCode))) return null;
  const posted = parseInt(postedProductId, 10) || 0;
  const product = posted > 0 ? await require('../repositories/productRepository').find(posted) : null;
  return product ? notQuotableMessage(product) : PICK_MESSAGE;
}

/** SUI-005: why a product picked on a line cannot be quoted now (D-361 plan rule 4). */
async function notQuotableMessage(product) {
  const st = await require('./supplierOffers').status(product);
  const why = st.can_activate ? 'it is Inactive — ask the product manager to switch it Active.' : String(st.reason);
  return `"${product.name}" cannot be quoted yet: ${why} Pick another product from Products.`;
}

/**
 * D-348 (12 Nov 2026, owner "go"): the specifications a goods line picked from Products
 * must have chosen — they change the price most, so the order form starts them on
 * "Choose…" instead of the base option. Matched by EXACT name ("Thickness",
 * "Size / format"); every other specification keeps its base option.
 * PHP twin: OrderLinePricing::REQUIRED_SPEC_NAMES.
 */
const REQUIRED_SPEC_NAMES = ['Thickness', 'Size / format'];

/**
 * D-348: the refusal for a goods line picked from Products whose product offers a
 * "Thickness" and/or "Size / format" specification that is not chosen in its
 * selection, else null. Lines typed by hand and products without those specs pass.
 */
async function requiredSpecsRefusal(lineNo, catalogProductId, lineType, selection) {
  if (!catalogProductId || lineType !== 'goods') return null;
  const product = await require('../repositories/productRepository').find(Number(catalogProductId));
  if (!product) return null;
  const missing = [];
  for (const s of await require('./productSpecs').offered(product)) {
    if (!REQUIRED_SPEC_NAMES.includes(String(s.name))) continue;
    const chosen = selection ? selection[Number(s.id)] : undefined;
    const ids = s.options.map((o) => Number(o.id));
    if (chosen === undefined || chosen === null || !ids.includes(Number(chosen))) missing.push(`the ${s.name}`);
  }
  if (!missing.length) return null;
  return `Line ${lineNo}: choose ${missing.join(' and ')} — the price depends on ${missing.length > 1 ? 'them' : 'it'}.`;
}

/** Refuses a price below the product's minimum price before it is saved. Null when the price may be saved. */
async function saveRefusal(order, catalogProductId, lineType, unitPrice, seesBuildup, selection = null, unit = null, priceRequest = null, size = {}, annexureLineNo = null) {
  if (!catalogProductId || lineType !== 'goods') return null;
  const ctx = await context(order);
  if (!ctx.usd) return null;
  const product = await require('../repositories/productRepository').find(Number(catalogProductId));
  if (!product) return null;
  if (selection !== null && selection !== undefined) {
    const productSpecs = require('./productSpecs');
    const [, errors] = productSpecs.normalize(await productSpecs.offered(product), selection);
    if (errors.length) return errors.join(' ');
  }
  // D-270 (point 25): any unit of the category — the old "50 mm and above: set the unit to CBM"
  // refusal is gone; the price is converted to the product's reference unit instead.
  let f;
  if (priceRequest && priceRequest.figures) {
    f = JSON.parse(priceRequest.figures);
    if (f.minimum === undefined || f.minimum === null) f.minimum = f.floor ?? 0;
    if (f.unit === undefined || f.unit === null) f.unit = priceRequest.unit ?? null;
  } else {
    const t = lineUnits.size((size || {}).piece_thickness_cm);
    f = await liveFigures(product, ctx, selection, t !== null ? t * 10 : null);
  }
  if (f.missing !== undefined) return null;
  const conv = await conversionFor({ unit, catalog_product_id: catalogProductId,
    spec_selection: selection ? JSON.stringify(selection) : null, ...(size || {}) }, f, product);
  // A missing size is asked for beside the field — never called "too low", never sent to the MD.
  if (conv.factor === null && !conv.unconvertible) {
    // D-338 (1c): a line "As per annexure" (annexureLineNo) has no size to give.
    return annexureLineNo !== null && annexureLineNo !== undefined && missingPieceSize(conv)
      ? annexureSizeMessage(annexureLineNo, unit)
      : String(conv.message);
  }
  if (unitPrice === null || unitPrice === undefined || unitPrice === '' || conv.factor === null) return null;
  const minimum = Number(f.minimum) * conv.factor;
  if (Number(unitPrice) >= minimum - 0.0001) return null;
  const per = Math.abs(conv.factor - 1) > 1e-9;
  return `This price is below what NexaCrest can accept for ${product.name} — raise it.`
    + (seesBuildup ? ` Minimum price: USD ${money(minimum)}${per ? ` per ${lineUnits.label(unit)} (USD ${money(f.minimum)} per ${lineUnits.label(f.unit)})` : ''}; to go lower, change the product's margin (Products → pricing).` : '');
}

/**
 * Sends a line to the MD for approval (in-app notification to everyone who
 * manages pricing). Null on success, else the reason it was not sent.
 */
async function requestApproval(orderId, lineId, userId, automatic = false) {
  const orderProductRepository = require('../repositories/orderProductRepository');
  const line = await orderProductRepository.find(lineId);
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!line || !order || Number(line.order_id) !== Number(orderId)) return 'Order line not found.';
  const check = await checkLine(line, await context(order));
  if (!APPROVABLE.includes(check.status)) {
    return check.status === BELOW_MINIMUM
      ? 'This price is below what NexaCrest can accept — raise it; the MD cannot approve it either.'
      : `Line ${Number(line.line_no)} does not need an approval.`;
  }
  if (line.price_approval_requested_at && automatic) return null;
  await db.execute('UPDATE order_products SET price_approval_requested_at = NOW(), price_approval_requested_by = :u WHERE id = :id', { u: userId, id: lineId });
  const ref = order.order_reference || `#${orderId}`;
  const message = `Order ${ref}, line ${line.line_no} (${line.description}) needs your price approval: `
    + (check.status === NO_PRICE ? 'its product has no price set.'
      : (check.status === CBM_CALCULATED ? `${check.cbm_reason}.` : 'it is below the lowest price for this order.'));
  const notificationRepository = require('../repositories/notificationRepository');
  for (const mdId of await require('../repositories/permissionRepository').usersWithPermission('manage_pricing')) {
    await notificationRepository.create(Number(mdId), null, 'line_price_approval', orderId, message.slice(0, 500)); // D-280: one e-mail per approval
  }
  await auditLog(userId, 'LINE_PRICE_APPROVAL_REQUESTED', 'order_products', lineId, null, null, message);
  return null;
}

/**
 * After a line is saved: a changed price voids its approval; a changed product
 * clears its fixed figures; a line now below its floor (or without a price for
 * its product) is sent to the MD for approval.
 */
async function afterSave(lineId, before, userId) {
  const orderProductRepository = require('../repositories/orderProductRepository');
  let line = await orderProductRepository.find(lineId);
  if (!line) return;
  const orderId = Number(line.order_id);
  // Owner decision D-374 (14 Nov 2026): a line remembers the pricing rate and the day its price was set,
  // until the approved Quotation fixes the prices. A new line (or one without a record yet) and a changed
  // price record today's rate; a copy keeps its original's (copied by duplicate()).
  const hasPrice = line.unit_price !== null && line.unit_price !== undefined;
  const priceSet = hasPrice && (!before
    ? !line.priced_at
    : (before.unit_price === null || before.unit_price === undefined || Math.abs(Number(before.unit_price) - Number(line.unit_price)) > 0.0001));
  if (priceSet) {
    const ord = await require('../repositories/orderRepository').find(orderId);
    if (ord && !require('./priceLock').isFixed(ord)) {
      await orderProductRepository.setPricedRate(lineId, await require('./fxRateService').rate(await pricingRepository.settings()));
      line = (await orderProductRepository.find(lineId)) || line;
    }
  }
  // Fresh-eyes review M4: a change of the line's options or unit is a different product for
  // pricing — like a product change it clears the fixed figures and voids the approval.
  const optionsChanged = !!before && approvalSelectionKey(before) !== approvalSelectionKey(line);
  if (before && (Number(before.catalog_product_id || 0) !== Number(line.catalog_product_id || 0) || optionsChanged) && line.price_snapshot) {
    await db.execute('UPDATE order_products SET price_snapshot = NULL WHERE id = :id', { id: lineId });
    line.price_snapshot = null;
  }
  if (line.price_approved_at && (!approvalHolds(line) || optionsChanged)) {
    await db.execute(
      `UPDATE order_products SET price_approved_by = NULL, price_approved_at = NULL, price_approval_reason = NULL, price_approved_unit_price = NULL, price_approved_selection = NULL,
              price_approval_commission_zero = 0, price_approval_incentive_cut = NULL WHERE id = :id`, { id: lineId }
    );
    await auditLog(userId, 'LINE_PRICE_APPROVAL_VOIDED', 'order_products', lineId, 'unit_price',
      n2(line.price_approved_unit_price), String(line.unit_price),
      optionsChanged && line.unit_price !== null && line.unit_price !== undefined && Math.abs(Number(line.unit_price) - Number(line.price_approved_unit_price)) < 0.005
        ? `Order line ${line.line_no}: the options or unit changed after the MD approved it — the approval no longer holds.`
        : `Order line ${line.line_no}: the price changed after the MD approved it — the approval no longer holds.`);
    line = (await orderProductRepository.find(lineId)) || line;
  }
  const order = await require('../repositories/orderRepository').find(orderId);
  const check = await checkLine(line, await context(order || {}));
  // D-270: a line still missing its size is asked for it on the page — not sent to the MD.
  if ([BELOW, BELOW_MINIMUM, NO_PRICE, CBM_CALCULATED].includes(check.status)) {
    await requestApproval(orderId, lineId, userId, true);
  } else if (line.price_approval_requested_at) {
    await db.execute('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id', { id: lineId });
  }
}

/** What approving this line would mean, for the MD's approval screen. */
async function approvalView(orderId, lineId) {
  const order = await require('../repositories/orderRepository').find(orderId);
  const line = await require('../repositories/orderProductRepository').find(lineId);
  if (!order || !line || Number(line.order_id) !== Number(orderId)) return null;
  const ctx = await context(order);
  const check = await checkLine(line, ctx);
  const view = { order, line, ctx, check, effect: null };
  const f = check.figures;
  if (f) {
    const price = Number(line.unit_price);
    const qty = line.quantity !== null && line.quantity !== undefined ? Number(line.quantity) : null;
    const reservePerUnit = Number(f.reserve);
    const cutPerUnit = Math.min(reservePerUnit, Math.max(0, Number(f.staff_floor) - price));
    const r2 = (v) => round2(v);
    view.effect = {
      margin_pct: price > 0 ? Math.round((price - Number(f.cost)) / price * 1000) / 10 : null,
      short_per_unit: r2(Math.max(0, Number(f.floor) - price)),
      reserve_per_unit: reservePerUnit,
      cut_per_unit: r2(cutPerUnit),
      qty,
      reserve_line: qty !== null ? r2(reservePerUnit * qty) : null,
      after_line: qty !== null ? r2((reservePerUnit - cutPerUnit) * qty) : null,
      cut_line: qty !== null ? r2(cutPerUnit * qty) : null,
      commission_zero: ctx.associate !== null,
    };
  }
  return view;
}

/** The MD approves a line's price. Null on success, else the refusal text. */
async function approve(orderId, lineId, userId, reason) {
  reason = String(reason ?? '').trim();
  if (reason.length < 10) return 'Give a reason of at least 10 characters for approving this price.';
  if (reason.length > 500) return 'Keep the reason under 500 characters.';
  const view = await approvalView(orderId, lineId);
  if (!view) return 'Order line not found.';
  const { line } = view;
  const status = view.check.status;
  const no = Number(line.line_no);
  if (status === BELOW_MINIMUM) {
    const f = view.check.figures;
    await auditLog(userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', lineId, 'unit_price', null, String(line.unit_price),
      `Order line ${no}: USD ${money(line.unit_price)} is below the minimum price USD ${money(f.minimum)} — not approvable.`);
    return `Line ${no}: USD ${money(line.unit_price)} is below the minimum price USD ${money(f.minimum)} — nobody can approve it, not even the MD. To sell lower, change the product's margin (Products → pricing; logged).`;
  }
  if (status === NEEDS_SIZE && view.check.annexure_size) return String(view.check.note); // D-338 (1c): the same wording everywhere
  if (status === NEEDS_SIZE) {
    const note = String(view.check.note || '');
    return `Line ${no} needs its piece size first — ${note.charAt(0).toLowerCase()}${note.slice(1)}`;
  }
  if (!APPROVABLE.includes(status)) return `Line ${no} does not need an approval.`;
  if (Number(line.price_set_by || 0) === Number(userId) && !(await require('./makerCheckerGuard').selfApprovalAllowed('price_discount', userId))) {
    return 'You set this price yourself — Admin → Self-approval rules do not let your role approve its own price.';
  }
  const effect = view.effect || {};
  await db.execute(
    `UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approval_reason = :r, price_approved_unit_price = :p, price_approved_selection = :sel,
            price_approval_commission_zero = :cz, price_approval_incentive_cut = :ic, price_approval_requested_at = NULL, price_approval_requested_by = NULL
     WHERE id = :id`,
    { u: userId, r: reason, p: n2(line.unit_price), sel: approvalSelectionKey(line), cz: effect.commission_zero ? 1 : 0, ic: effect.cut_line !== undefined && effect.cut_line !== null ? n2(effect.cut_line) : null, id: lineId }
  );
  const what = status === NO_PRICE ? 'no price set for its product'
    : (status === CBM_CALCULATED ? `USD ${money(line.unit_price)} — ${view.check.cbm_reason}`
      : `USD ${money(line.unit_price)} below the floor USD ${money(view.check.figures.floor)}`);
  await auditLog(userId, 'LINE_PRICE_APPROVED', 'order_products', lineId, 'unit_price', null, String(line.unit_price), `Order line ${no}: ${what} — reason: ${reason}`);
  if (Number(line.price_set_by || 0) === Number(userId)) {
    await auditLog(userId, 'LINE_PRICE_SELF_APPROVED', 'order_products', lineId, null, null, 'Self-approval allowed by Admin → Self-approval rules (price_discount).');
  }
  return null;
}

/**
 * Owner decision D-342: a price lowered (or a line added) by a "Products & prices" amendment
 * was approved by the MD with the amendment — recorded on the line as the MD's approval.
 */
async function approveByAmendment(lineId, mdUserId, reason) {
  const line = await require('../repositories/orderProductRepository').find(lineId);
  if (!line) return;
  // The same effect on commission and incentive as the MD's own line approval (approve()).
  const view = await approvalView(Number(line.order_id), lineId);
  const effect = (view && view.effect) || {};
  await db.execute(
    `UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approval_reason = :r, price_approved_unit_price = :p, price_approved_selection = :sel,
            price_approval_commission_zero = :cz, price_approval_incentive_cut = :ic, price_approval_requested_at = NULL, price_approval_requested_by = NULL
     WHERE id = :id`,
    { u: mdUserId, r: String(reason).slice(0, 500), p: n2(line.unit_price), sel: approvalSelectionKey(line), cz: effect.commission_zero ? 1 : 0,
      ic: effect.cut_line !== undefined && effect.cut_line !== null ? n2(effect.cut_line) : null, id: lineId }
  );
  await auditLog(mdUserId, 'LINE_PRICE_APPROVED', 'order_products', lineId, 'unit_price', null, String(line.unit_price), `Order line ${Number(line.line_no)}: ${reason}`);
}

/** The MD refuses a line's price: the request is closed and whoever asked is told why. Null on success. */
async function refuse(orderId, lineId, userId, reason) {
  reason = String(reason ?? '').trim();
  if (reason.length < 10) return 'Give a reason of at least 10 characters for refusing this price.';
  const view = await approvalView(orderId, lineId);
  if (!view) return 'Order line not found.';
  const { line } = view;
  await db.execute('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id', { id: lineId });
  const message = `Order ${view.order.order_reference || `#${orderId}`}, line ${Number(line.line_no)}: the MD refused USD ${money(line.unit_price)} — ${reason} Raise the price; the Proforma Invoice waits for it.`;
  if (line.price_approval_requested_by && Number(line.price_approval_requested_by) !== Number(userId)) {
    await require('../repositories/notificationRepository').createInAppOnly(Number(line.price_approval_requested_by), 'line_price_refused', orderId, message.slice(0, 500));
  }
  await auditLog(userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', lineId, 'unit_price', null, String(line.unit_price), message);
  return null;
}

/** Fixes the figures on every checked line that has none yet — called when the PI is issued. */
async function snapshotForPi(orderId) {
  // D-245 P3: the associate's commission level is fixed on the order with the PI, first,
  // so the line figures fixed below use that same level.
  await require('./associateCommission').fixLevelAtPi(orderId);
  await snapshotLines(orderId);
}

/**
 * Owner decision D-342: fixes the figures on every checked line that has none yet —
 * when the first Quotation is approved (at the rate fixed then), again when the PI is
 * issued, and for a line an activated amendment adds.
 */
async function snapshotLines(orderId) {
  const order = await require('../repositories/orderRepository').find(orderId);
  if (!order) return;
  const ctx = await context(order);
  const productRepository = require('../repositories/productRepository');
  for (const line of await require('../repositories/orderProductRepository').forOrder(orderId)) {
    if (line.price_snapshot || scope(line, ctx) !== null) continue;
    const product = await productRepository.find(Number(line.catalog_product_id));
    if (!product) continue;
    // D-255: a line from a price request fixes the request's prices; else the line's options.
    const t = lineSize(line)[2];
    const f = (await priceRequestFigures(line)) ?? await liveFigures(product, ctx, lineSelection(line), t !== null ? t * 10 : null);
    if (f.missing !== undefined) continue;
    f.fixed_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
    await db.execute('UPDATE order_products SET price_snapshot = :s WHERE id = :id AND price_snapshot IS NULL', { s: JSON.stringify(f), id: Number(line.id) });
  }
}

/** The quote price and floor of every picker product, per seller ("staff", "L<level id>"). */
async function pickerPrices(pickerProducts) {
  const levels = await pricingRepository.levels(true);
  const productRepository = require('../repositories/productRepository');
  const out = {};
  for (const pp of pickerProducts) {
    const product = await productRepository.find(Number(pp.id));
    if (!product) continue;
    const p = await pricingService.forProduct(product, levels);
    if (!p.build) continue;
    const prices = { staff: [p.build.staff_quote, p.build.staff_floor] };
    for (const l of p.levels) prices[`L${l.id}`] = [l.quote, l.floor];
    out[Number(pp.id)] = prices;
  }
  return out;
}

/**
 * D-255: each picker product's specifications and options — names only (no values,
 * no cost), and whether an option is priced per CBM — for the order form's option selects.
 */
async function pickerSpecs(pickerProducts) {
  const productSpecs = require('./productSpecs');
  const cbmFrom = (await pricingService.settings()).cbm_from_mm;
  const productRepository = require('../repositories/productRepository');
  const out = {};
  for (const pp of pickerProducts) {
    const product = await productRepository.find(Number(pp.id));
    if (!product) continue;
    const from = (parseInt(product.cbm_from_mm, 10) || 0) > 0 ? parseInt(product.cbm_from_mm, 10) : cbmFrom;
    const specs = (await productSpecs.offered(product)).map((s) => ({
      // D-357: 'finish' — the Surface finish (names from Settings › Lists); a picked line takes its finish from it.
      id: s.id, name: s.name, thickness: s.kind === 'thickness', finish: (s.list_key ?? null) === 'surface_finish', cbm_from: from, options: s.options.map((o) => ({
        id: o.id, name: o.name, base: o.is_base,
        cbm: s.kind === 'thickness' && (o.change_type === 'cbm' || (o.thickness_mm !== null && o.thickness_mm >= from)),
        mm: s.kind === 'thickness' ? (o.thickness_mm ?? null) : null,
      })),
    }));
    if (specs.length) out[Number(pp.id)] = specs;
  }
  return out;
}

/** For the New Order form: each client's seller key ("staff" or "L<level id>"). */
async function clientKeys(clients) {
  const byAssociate = {};
  const out = {};
  const associateRepository = require('../repositories/associateRepository');
  for (const c of clients) {
    let key = 'staff';
    if (c.associate_id) {
      const aid = Number(c.associate_id);
      if (!(aid in byAssociate)) {
        byAssociate[aid] = 'staff';
        const associate = await associateRepository.find(aid);
        if (associate) {
          const [level] = await levelForAssociate(associate, null);
          byAssociate[aid] = level ? `L${Number(level.id)}` : 'staff';
        }
      }
      key = byAssociate[aid];
    }
    out[Number(c.id)] = key;
  }
  return out;
}

module.exports = {
  OK, BELOW, BELOW_MINIMUM, NO_PRICE, APPROVED, UNCHECKED, NEEDS_SIZE, BLOCKING,
  CBM_CALCULATED, APPROVABLE, annexureSizeMessage, cbmCalculatedReason,
  weightPerCbm, lineSize, optionThicknessMm, syncThickness, conversionFor, scaleFigures, optionChips, summary, totals,
  PICK_MESSAGE, productPickRequired, pickRefusal, notQuotableMessage, documentBlockReason, REQUIRED_SPEC_NAMES, requiredSpecsRefusal,
  context, levelForAssociate, liveFigures, scope, approvalHolds, checkLine, forOrder, piBlockReason,
  saveRefusal, afterSave, requestApproval, approvalView, approve, refuse, snapshotForPi, snapshotLines, approveByAmendment, pickerPrices, pickerSpecs, clientKeys,
  selectionInput, saveSelection, lineSelection, priceRequestFigures, lineQuote, approvalSelectionKey, pricedAtText,
};
