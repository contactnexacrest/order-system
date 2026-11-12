'use strict';

/**
 * Owner decision D-339 (12 Nov 2026, owner confirmed twice; mockup screen 2): the product
 * page's read-only card "Prices in other units" — for every thickness the product offers,
 * its price per CBM, per SQM and per SQFT, worked out with the existing D-270 conversion
 * (lineUnits.factor: 1 SQM = thickness × 1 m², 1 SQM = 10.7639 SQFT). Nothing is stored.
 *
 * Who sees what (existing permissions): the supplier price — people who may see purchase
 * prices; the staff quote and floor — people who see selling prices; every associate level —
 * people who see the price build-up (MD / ED). A thickness with its own typed supplier price
 * (D-335) is marked "typed" and its per-CBM figure is not shown (it is not a per-CBM price).
 * An associate sees only their own price, in the other units, on their price request.
 *
 * PHP twin: src/Services/UnitPrices.php.
 */

const lineUnits = require('./lineUnits');
const { roundTo } = require('./lineTable');

/**
 * A price per `unit` at thickness `mm`, per CBM / SQM / SQFT (2 decimals); null where it
 * cannot be worked out (e.g. per SQM of a per-CBM price without a thickness).
 */
function convert(price, unit, mm) {
  const out = { CBM: null, SQM: null, SQFT: null };
  if (price === null || price === undefined || String(unit ?? '').trim() === '') return out;
  const t = mm !== null && mm !== undefined && mm > 0 ? mm / 10 : null;
  for (const target of Object.keys(out)) {
    const f = lineUnits.factor(target, unit, null, null, t, null);
    if (f.factor !== null && !f.unconvertible) out[target] = roundTo(Number(price) * f.factor, 2);
  }
  return out;
}

/**
 * The thickness options of a product as selections for pricingService.forProduct() —
 * or one "Base price" choice when it offers no Thickness specification.
 */
async function thicknessChoices(product) {
  const productSpecs = require('./productSpecs');
  for (const s of await productSpecs.offered(product)) {
    if (s.kind !== 'thickness') continue;
    return s.options.map((o) => ({ label: String(o.name), mm: o.thickness_mm, selection: { [Number(s.id)]: Number(o.id) } }));
  }
  return [{ label: 'Base price', mm: null, selection: null }];
}

/**
 * The card: one row per thickness option (or one row for a product without a Thickness
 * specification), each with the price sets the viewer may see.
 *
 * @param {{supplier: boolean, staff: boolean, levels: boolean}} may
 */
async function card(product, may) {
  const pricingService = require('./pricingService');
  const sets = [];
  if (may.staff || may.levels) {
    sets.push({ key: 'staff_quote', label: 'Staff quote', currency: 'USD' });
    sets.push({ key: 'staff_floor', label: 'Staff floor (lowest)', currency: 'USD' });
  }
  const choices = await thicknessChoices(product);
  const rows = [];
  for (const c of choices) {
    const p = await pricingService.forProduct(product, null, null, null, c.selection);
    if (!p.build) {
      rows.push({ label: c.label, mm: c.mm, typed: false, prices: {}, missing: String(p.missing ?? 'No price yet.') });
      continue;
    }
    const unit = String(p.unit ?? product.reference_unit ?? '');
    let mm = c.mm ?? (p.spec && p.spec.adj ? p.spec.adj.thickness_mm : null);
    mm = mm !== null && mm !== undefined ? Number(mm) : null;
    const typed = ((p.supplier || {}).price_basis || '') === 'grid' && ((p.inr_parts || {}).how || '') === 'typed' && mm !== null;
    const prices = {};
    if (may.staff || may.levels) {
      prices.staff_quote = convert(Number(p.build.staff_quote), unit, mm);
      prices.staff_floor = convert(Number(p.build.staff_floor), unit, mm);
    }
    if (may.levels) {
      for (const l of p.levels || []) {
        const k = `level_${l.id}`;
        if (!sets.some((s) => s.key === `${k}_quote`)) {
          sets.push({ key: `${k}_quote`, label: `${l.name} — quote`, currency: 'USD' });
          sets.push({ key: `${k}_floor`, label: `${l.name} — lowest`, currency: 'USD' });
        }
        prices[`${k}_quote`] = convert(Number(l.quote), unit, mm);
        prices[`${k}_floor`] = convert(Number(l.floor), unit, mm);
      }
    }
    if (may.supplier && p.inr_parts && p.inr_parts.supplier !== undefined) {
      if (!sets.some((s) => s.key === 'supplier')) sets.push({ key: 'supplier', label: 'Supplier price', currency: 'INR' });
      prices.supplier = convert(Number(p.inr_parts.supplier), unit, mm);
    }
    if (typed) {
      // D-335: a typed thickness price is a price per its own unit, not per CBM.
      for (const k of Object.keys(prices)) prices[k].CBM = null;
    }
    rows.push({ label: c.label, mm, typed, prices, missing: null, unit });
  }
  return { rows, sets, note: choices.length === 0 ? 'This product has no price yet.' : null };
}

/** The thickness (mm) chosen in a selection (JSON {spec id: option id}), or null. */
async function selectionThicknessMm(product, selectionJson) {
  let sel;
  try {
    sel = JSON.parse(String(selectionJson ?? ''));
  } catch (e) {
    return null;
  }
  if (!sel || typeof sel !== 'object') return null;
  const productSpecs = require('./productSpecs');
  for (const s of await productSpecs.offered(product)) {
    if (s.kind !== 'thickness' || sel[s.id] === undefined) continue;
    const chosen = Number(sel[s.id]);
    for (const o of s.options) {
      if (Number(o.id) === chosen && o.thickness_mm !== null) return Number(o.thickness_mm);
    }
  }
  return null;
}

module.exports = { convert, card, thicknessChoices, selectionThicknessMm };
