'use strict';

const db = require('../config/db');
const auditLogRepository = require('../repositories/auditLogRepository');
const pricingRepository = require('../repositories/pricingRepository');
const productRepository = require('../repositories/productRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const productService = require('./productService');
const thicknessPrices = require('./thicknessPrices');
const lineUnits = require('./lineUnits');

/**
 * Port of App\Services\SupplierOffers — owner decision D-359 (13 Nov 2026, "go with
 * recommendation"): suppliers' offers of a product, the product's CONFIGURED base price, and
 * the simple "Edit price" form.
 *
 *  - An OFFER is one catalog_product_suppliers row. It is USABLE when the offer is switched
 *    on (offer_active = 1) and its directory supplier is active (suppliers.is_active = 1; a
 *    price typed before the directory link counts as active). An unusable offer is never used
 *    for pricing, matching or the lowest / highest supplier price.
 *  - The CONFIGURED base price (catalog_products.configured_base_*) is what the selling price
 *    is worked out from. While configured_base_follows_supplier = 1 (every product priced
 *    before D-359, and new ones until the Configure screen, D-361, fixes a price) it follows
 *    the usable supplier price "used for pricing" — exactly as before, so no price changes.
 *    Once fixed (follows = 0) it never changes by itself; it is turned into a price row with
 *    the same maths the engine uses for a supplier price (thicknessPrices / pricingService).
 *  - "Edit price": one form for one offer — the new price at one thickness, the other
 *    thicknesses worked out (thicknessPrices.calcFrom / addPrice), CHA + transport, packing,
 *    notes, the selling-price preview (pricingService.fobParts / buildFromFob), the price
 *    history (thicknessPrices.history) and Undo. No maths of its own.
 *
 * Functions for D-360 / D-361: offers(), usable(), offerBase(), lowestHighest(),
 * configuredBase(), setConfiguredBase(), followSupplier(), pricingRow(), setOfferActive();
 * the levels and the resets are in pricingLevels.
 */

const ps = () => require('./pricingService');
const isSet = (v) => v !== null && v !== undefined;
const fix2 = (v) => Number(v).toFixed(2);

// ------------------------------------------------------------------ offers

/** An offer is used only when it is switched on and its directory supplier is active. */
function usable(row) {
  const offer = !isSet(row.offer_active) || Number(row.offer_active) === 1;
  const supplier = !isSet(row.supplier_is_active) || Number(row.supplier_is_active) === 1;
  return offer && supplier;
}

/** Every supplier offer of a product (with `usable`); only the usable ones when asked. */
async function offers(productId, usableOnly = false) {
  const out = [];
  for (const r of await productSupplierRepository.forProduct(productId)) {
    const row = { ...r, usable: usable(r) };
    if (!usableOnly || row.usable) out.push(row);
  }
  return out;
}

/** The row marked "used for pricing" (a base price or a price for every thickness), usable or not. */
async function markedForPricing(productId) {
  return (await productSupplierRepository.forProduct(productId)).find((s) => Number(s.is_primary) === 1 && ['unit', 'grid'].includes(s.price_basis || 'unit')) || null;
}

/** D-360: switches one offer on or off (with the price history and the audit log). */
async function setOfferActive(offerId, active, userId) {
  const row = await productSupplierRepository.find(offerId);
  if (!row || Number(row.offer_active ?? 1) === (active ? 1 : 0)) return;
  await db.execute('UPDATE catalog_product_suppliers SET offer_active = :a WHERE id = :id', { a: active ? 1 : 0, id: offerId });
  await thicknessPrices.history(offerId, 'Offer active', active ? 'off' : 'on', active ? 'on' : 'off', userId);
  await auditLogRepository.log(userId, `SUPPLIER_OFFER_${active ? 'ON' : 'OFF'}`, 'product_suppliers', offerId, 'offer_active', active ? '0' : '1', active ? '1' : '0');
}

/**
 * An offer's BASE price as quoted: a price for every thickness → the price at its base
 * thickness (per m², or per CBM for 50 mm and above); otherwise its price per unit (per CBM
 * for a thick-stone row). Null when it has none (or for an exact price for a combination).
 */
async function offerBase(product, row, rules = null, s = null) {
  const basis = String(row.price_basis || 'unit');
  if (basis === 'combo') return null;
  if (basis === 'grid') {
    rules = rules || (await thicknessPrices.rules(product, s));
    const done = thicknessPrices.values(rules, row._stored || (await thicknessPrices.storedFor(Number(row.id))), thicknessPrices.autoFill(row));
    const mm = thicknessPrices.baseOf(row, rules);
    const v = done.values[mm];
    if (!v || !(Number(v.price) > 0)) return null;
    return {
      inr: Number(v.price), mm, unit: mm === thicknessPrices.CBM ? 'CBM' : 'm2',
      label: `${mm === thicknessPrices.CBM ? 'per CBM' : 'per m²'} (${thicknessPrices.label(mm, Number(rules.cbm_from))})`,
    };
  }
  const v = productService.effectiveFobForSupplier(row, product);
  if (!isSet(v) || !(Number(v) > 0)) return null;
  const unit = basis === 'cbm' ? 'CBM' : (String(product.reference_unit || '') || 'unit');
  return { inr: Number(v), mm: null, unit, label: `per ${lineUnits.label(unit) || unit}` };
}

/**
 * D-360 / D-361: the usable offers with their base price, and the lowest and highest of those
 * quoted the same way as the product's price (same unit and thickness).
 */
async function lowestHighest(product, s = null) {
  s = s || (await ps().settings());
  const rules = await thicknessPrices.rules(product, s);
  const rows = [];
  for (const r of await offers(Number(product.id), true)) {
    if (!['unit', 'grid'].includes(r.price_basis || 'unit')) continue;
    r.base = await offerBase(product, r, rules, s);
    if (r.base !== null) rows.push(r);
  }
  const cfg = await configuredBase(product, s);
  const keyOf = (b) => `${b.unit}|${b.mm ?? ''}`;
  const key = cfg.inr !== null ? `${cfg.unit}|${cfg.mm ?? ''}` : (rows.length ? keyOf(rows[0].base) : null);
  let low = null;
  let high = null;
  for (const r of rows) {
    if (keyOf(r.base) !== key) continue;
    if (low === null || r.base.inr < low.base.inr) low = r;
    if (high === null || r.base.inr > high.base.inr) high = r;
  }
  return { offers: rows, lowest: low, highest: high, count: rows.length };
}

// ------------------------------------------------------------------ the configured base price

/** The product's configured base price: while it follows the supplier, the usable price "used for pricing" (live); otherwise the stored one. */
async function configuredBase(product, s = null) {
  const follows = Number(product.configured_base_follows_supplier ?? 1) === 1;
  const stored = isSet(product.configured_base_price_inr) ? Number(product.configured_base_price_inr) : null;
  const offer = await ps().pricingSupplier(Number(product.id));
  if (follows || stored === null) {
    const b = offer ? await offerBase(product, offer, null, s) : null;
    return { inr: b ? b.inr : null, mm: b ? b.mm : null, unit: b ? b.unit : null, follows: true, source: b ? 'supplier' : 'none', offer, stored_inr: stored };
  }
  const mm = isSet(product.configured_base_thickness_mm) ? Number(product.configured_base_thickness_mm) : null;
  return { inr: stored, mm, unit: String(product.configured_base_unit || ''), follows: false, source: 'configured', offer, stored_inr: stored };
}

/** D-361: staff fix the base price themselves — it no longer follows the supplier price. */
async function setConfiguredBase(productId, inr, mm, unit, userId) {
  const before = await productRepository.find(productId);
  await db.execute(`UPDATE catalog_products SET configured_base_price_inr = :p, configured_base_thickness_mm = :mm, configured_base_unit = :u,
    configured_base_follows_supplier = 0, configured_at = NOW(), configured_by = :by, updated_by = :by2 WHERE id = :id`,
  { p: fix2(inr), mm: isSet(mm) ? Number(mm) : null, u: String(unit).slice(0, 20), by: userId, by2: userId, id: productId });
  await auditLogRepository.log(userId, 'PRODUCT_BASE_PRICE_SET', 'catalog_products', productId, 'configured_base_price_inr',
    before && isSet(before.configured_base_price_inr) ? String(before.configured_base_price_inr) : null, fix2(inr));
}

/** D-361: the base price follows the supplier price "used for pricing" again. */
async function followSupplier(productId, userId) {
  await db.execute('UPDATE catalog_products SET configured_base_follows_supplier = 1, configured_at = NOW(), configured_by = :by WHERE id = :id', { by: userId, id: productId });
  const p = await productRepository.find(productId);
  if (p) await refreshFollowedBase(p, userId);
  await auditLogRepository.log(userId, 'PRODUCT_BASE_PRICE_SET', 'catalog_products', productId, 'configured_base_follows_supplier', '0', '1');
}

/** Keeps the stored copy of a FOLLOWING base price the same as the supplier price it follows. */
async function refreshFollowedBase(product, userId = null) {
  if (Number(product.configured_base_follows_supplier ?? 1) !== 1) return;
  const offer = await ps().pricingSupplier(Number(product.id));
  const b = offer ? await offerBase(product, offer) : null;
  if (!b) return;
  await db.execute(`UPDATE catalog_products SET configured_base_price_inr = :p, configured_base_thickness_mm = :mm, configured_base_unit = :u,
    configured_at = NOW(), configured_by = COALESCE(:by, configured_by) WHERE id = :id`,
  { p: fix2(b.inr), mm: b.mm, u: String(b.unit).slice(0, 20), by: userId, id: Number(product.id) });
}

/**
 * The price row the engine works from. While the base price follows the supplier (or none is
 * fixed) it is the usable offer "used for pricing", unchanged. A fixed base price becomes a
 * price row: a price for every thickness at its base thickness (the other thicknesses worked
 * out by the price rules) or a price per unit — with the CHA, packing and dates of the offer
 * used for pricing when there is one.
 */
async function pricingRow(product, offer, s = null) {
  const follows = Number(product.configured_base_follows_supplier ?? 1) === 1;
  const inr = isSet(product.configured_base_price_inr) ? Number(product.configured_base_price_inr) : null;
  if (follows || inr === null || !(inr > 0)) return offer;
  const mm = isSet(product.configured_base_thickness_mm) ? Number(product.configured_base_thickness_mm) : null;
  const rules = await thicknessPrices.rules(product, s);
  if (offer) {
    const b = await offerBase(product, offer, rules, s);
    if (b !== null && Math.abs(b.inr - inr) < 0.005 && b.mm === mm) return offer;
  }
  const at = isSet(product.configured_at) ? String(product.configured_at) : '';
  const o = offer || {};
  const row = {
    id: 0, product_id: Number(product.id), supplier_name: 'Configured base price', supplier_id: null,
    fob_source: 'direct', fob_value: null, is_primary: 1, is_cbm_primary: 0, combo_in_use: 0,
    offer_active: 1, supplier_is_active: null, configured: true,
    includes_packing_loading: o.includes_packing_loading ?? 1,
    cha_transport_per_container: o.cha_transport_per_container ?? null,
    quoted_on: o.quoted_on ?? (at !== '' ? at.slice(0, 10) : null),
    valid_until: o.valid_until ?? null, updated_at: at !== '' ? at : null,
  };
  if (mm !== null && rules.grid) {
    return { ...row, price_basis: 'grid', base_thickness_mm: mm, auto_fill: 1, _stored: { [mm]: { price: inr, how: 'typed', calc_from_mm: null, quoted_on: null, since: null } } };
  }
  return { ...row, price_basis: 'unit', fob_value: inr, base_thickness_mm: null };
}

// ------------------------------------------------------------------ "Edit price"

/** The thicknesses the form offers for a price for every thickness, in display order. */
function formThicknesses(rules, row) {
  const mms = thicknessPrices.thicknesses(rules).map(Number);
  const base = thicknessPrices.baseOf(row, rules);
  if (!mms.includes(base)) mms.push(base);
  return mms;
}

/**
 * What the form shows for an entered price: every other thickness worked out (or typed by
 * the user), the old prices, and the selling price before / after (only when this offer is
 * the one the selling price comes from). own: { mm: price } typed with "Type it yourself".
 */
async function preview(product, row, fromMm, price, own = {}, s = null) {
  s = s || (await ps().settings());
  const rules = await thicknessPrices.rules(product, s);
  const grid = (row.price_basis || '') === 'grid';
  const cbmFrom = Number(rules.cbm_from);
  const out = { ok: true, error: null, lines: [], selling: null, grid };
  let next = { ...row };
  if (grid) {
    if (!isSet(fromMm)) fromMm = thicknessPrices.baseOf(row, rules);
    const was = thicknessPrices.values(rules, await thicknessPrices.storedFor(Number(row.id)), thicknessPrices.autoFill(row));
    const calc = price > 0 ? thicknessPrices.calcFrom(rules, fromMm, price) : { ok: false, error: 'Enter the price (above 0).', values: {} };
    if (!calc.ok) {
      out.ok = false;
      out.error = calc.error;
    }
    const stored = {};
    for (const mm of formThicknesses(rules, row)) {
      const isFrom = mm === fromMm;
      const p = isFrom ? price : (isSet(own[mm]) ? own[mm] : (calc.values[mm] ? calc.values[mm].price : null));
      const how = isFrom ? 'entered' : (isSet(own[mm]) ? 'typed' : (p !== null ? 'calculated' : null));
      out.lines.push({
        mm, label: thicknessPrices.label(mm, cbmFrom), unit: mm === thicknessPrices.CBM ? 'CBM' : 'm²',
        price: p, was: was.values[mm] ? Number(was.values[mm].price) : null, how, from: isFrom, rule: calc.values[mm] ? calc.values[mm].change : '',
      });
      if (p !== null && p > 0) {
        const typed = isFrom || isSet(own[mm]);
        stored[mm] = { price: p, how: typed ? 'typed' : 'calculated', calc_from_mm: typed ? null : fromMm, quoted_on: null, since: null };
      }
    }
    next = { ...next, _stored: stored, auto_fill: 0 };
  } else {
    next = { ...next, fob_source: 'direct', fob_value: price };
    const old = productService.effectiveFobForSupplier(row, product);
    out.lines.push({
      mm: null, label: 'Price', unit: (row.price_basis || '') === 'cbm' ? 'CBM' : (String(product.reference_unit || '') || 'unit'),
      price: price > 0 ? price : null, was: isSet(old) ? Number(old) : null, how: 'entered', from: true, rule: '',
    });
    if (!(price > 0)) {
      out.ok = false;
      out.error = 'Enter the price (above 0).';
    }
  }
  // The selling price moves only when this offer is the one the price comes from.
  const pricing = await ps().pricingSupplier(Number(product.id));
  const cfg = await configuredBase(product, s);
  const applies = !!pricing && Number(pricing.id) === Number(row.id) && cfg.follows && ['unit', 'grid'].includes(row.price_basis || 'unit');
  out.selling = {
    applies,
    reason: applies ? null
      : (!cfg.follows ? "The selling price comes from the product's configured base price, not from this supplier's price."
        : (!pricing ? 'No supplier price is used for pricing yet.' : `The selling price comes from ${pricing.supplier_name}'s price (used for pricing).`)),
  };
  if (applies) {
    const oldParts = await ps().fobParts(product, row, 'unit', null, s, rules);
    const newParts = out.ok ? await ps().fobParts(product, next, 'unit', null, s, rules) : null;
    const oldB = oldParts ? await ps().buildFromFob(product, Number(oldParts.total), s) : null;
    const newB = newParts ? await ps().buildFromFob(product, Number(newParts.total), s) : null;
    Object.assign(out.selling, {
      was_min: oldB ? oldB.minimum : null, was_quote: oldB ? oldB.staff_quote : null,
      min: newB ? newB.minimum : null, quote: newB ? newB.staff_quote : null, unit: newParts ? newParts.unit : (oldParts ? oldParts.unit : ''),
    });
  }
  return out;
}

/** Everything a save changes on one offer, to put it back with Undo. */
async function snapshot(offerId) {
  const row = await productSupplierRepository.find(offerId);
  if (!row) return null;
  const thickness = await db.query('SELECT thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_by FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id ORDER BY thickness_mm', { id: offerId });
  const product = await productRepository.find(Number(row.product_id));
  const pick = (o, keys) => Object.fromEntries(keys.map((k) => [k, o[k] === undefined ? null : o[k]]));
  const ymd = (v) => (v instanceof Date ? `${v.getFullYear()}-${String(v.getMonth() + 1).padStart(2, '0')}-${String(v.getDate()).padStart(2, '0')}` : v);
  return {
    offer_id: Number(offerId), product_id: Number(row.product_id),
    row: pick(row, ['fob_source', 'fob_value', 'auto_fill', 'base_thickness_mm', 'quoted_on', 'valid_until', 'includes_packing_loading', 'cha_transport_per_container', 'notes']),
    thickness: thickness.map((t) => ({ ...t, quoted_on: ymd(t.quoted_on) })),
    product: product ? pick(product, ['configured_base_price_inr', 'configured_base_thickness_mm', 'configured_base_unit', 'configured_at']) : {},
  };
}

/**
 * Saves the "Edit price" form for one offer. inp: price, from_mm (grid), own { mm: price },
 * quoted_on, valid_until, cha (₹ per container or null), includes_packing_loading, notes.
 * Returns [summary text for the green message, error].
 */
async function save(product, row, inp, userId) {
  const rules = await thicknessPrices.rules(product);
  const id = Number(row.id);
  const grid = (row.price_basis || '') === 'grid';
  const cbmFrom = Number(rules.cbm_from);
  const before = await offerBase(product, row, rules);
  // thicknessPrices.addPrice() runs its own transaction for the thickness prices.
  if (grid) {
    const from = Number(inp.from_mm);
    const own = inp.own || {};
    const ticked = formThicknesses(rules, row).filter((mm) => mm !== from && !isSet(own[mm]));
    let error = await thicknessPrices.addPrice(row, rules, from, Number(inp.price), String(inp.quoted_on), ticked, false, userId);
    if (error) return [null, error];
    for (const [mm, p] of Object.entries(own)) {
      const fresh = (await productSupplierRepository.find(id)) || row;
      error = await thicknessPrices.addPrice(fresh, rules, Number(mm), Number(p), String(inp.quoted_on), [], false, userId);
      if (error) return [null, `${thicknessPrices.label(Number(mm), cbmFrom)}: ${error}`];
    }
  } else {
    const old = productService.effectiveFobForSupplier(row, product);
    const upd = { ...((await productSupplierRepository.find(id)) || row), fob_source: 'direct', fob_value: Number(inp.price) };
    await productSupplierRepository.update(id, upd, userId);
    if (!isSet(old) || Math.abs(Number(old) - Number(inp.price)) > 0.005) {
      await thicknessPrices.history(id, `Price per ${(row.price_basis || '') === 'cbm' ? 'CBM' : (String(product.reference_unit || '') || 'unit')}`,
        isSet(old) ? fix2(old) : null, fix2(inp.price), userId);
    }
  }
  let fresh = (await productSupplierRepository.find(id)) || row;
  await pricingRepository.setSupplierDates(id, String(inp.quoted_on), inp.valid_until !== '' ? inp.valid_until : null);
  const oldCha = isSet(row.cha_transport_per_container) ? fix2(row.cha_transport_per_container) : null;
  const newCha = isSet(inp.cha) ? fix2(inp.cha) : null;
  const oldIncl = Number(row.includes_packing_loading ?? 1) === 1;
  await productSupplierRepository.setPriceDetails(id, isSet(fresh.supplier_id) ? Number(fresh.supplier_id) : null, !!inp.includes_packing_loading, inp.cha);
  if (oldCha !== newCha) await thicknessPrices.history(id, 'CHA + transport per container', oldCha ?? 'product default', newCha ?? 'product default', userId);
  if (oldIncl !== !!inp.includes_packing_loading) {
    await thicknessPrices.history(id, 'Price includes packing and loading', oldIncl ? 'yes' : 'no', inp.includes_packing_loading ? 'yes' : 'no', userId);
  }
  if (String(fresh.notes ?? '') !== String(inp.notes ?? '')) {
    fresh = { ...((await productSupplierRepository.find(id)) || fresh), notes: inp.notes !== '' ? inp.notes : null };
    await productSupplierRepository.update(id, fresh, userId);
  }
  const after = await offerBase(product, (await productSupplierRepository.find(id)) || row, rules);
  await refreshFollowedBase((await productRepository.find(Number(product.id))) || product, userId);
  const what = grid
    ? `${Number(inp.from_mm) === thicknessPrices.CBM ? 'per CBM' : 'per m²'} (${thicknessPrices.label(Number(inp.from_mm), cbmFrom)})`
    : `per ${(row.price_basis || '') === 'cbm' ? 'CBM' : (String(product.reference_unit || '') || 'unit')}`;
  const d = new Date(`${inp.quoted_on}T00:00:00`);
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const summary = `${row.supplier_name} · ${product.name}: ${before ? `₹${thicknessPrices.amount(before.inr)} → ` : ''}₹${thicknessPrices.amount(Number(inp.price))} ${what}`
    + `, quoted ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}.`
    + (grid && after && before && Number(inp.from_mm) !== (after.mm ?? -1) ? ` Base price now ₹${thicknessPrices.amount(after.inr)} ${after.label}.` : '')
    + (grid ? ' Other thicknesses updated.' : '');
  await auditLogRepository.log(userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', id, null,
    before ? `₹${ps().numberFormat(before.inr, 2)} ${before.label}` : null, `₹${ps().numberFormat(Number(inp.price), 2)} ${what} quoted ${inp.quoted_on} (Edit price)`);
  return [summary, null];
}

/** Undo: puts one offer back as the snapshot taken before a save, with a history row. */
async function undo(snap, userId) {
  const id = Number(snap.offer_id);
  const row = await productSupplierRepository.find(id);
  if (!row) return 'That supplier price no longer exists.';
  const product = await productRepository.find(Number(row.product_id));
  const rules = product ? await thicknessPrices.rules(product) : null;
  const before = product ? await offerBase(product, row, rules) : null;
  await db.transaction(async (conn) => {
    await conn.execute('DELETE FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id', { id });
    for (const t of snap.thickness) {
      await conn.execute('INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_by) VALUES (:id, :mm, :p, :how, :cf, :q, :u)',
        { id, mm: Number(t.thickness_mm), p: t.price_inr, how: t.how, cf: t.calc_from_mm ?? null, q: t.quoted_on ?? null, u: t.updated_by ?? null });
    }
    const r = snap.row;
    await conn.execute(`UPDATE catalog_product_suppliers SET fob_source = :fs, fob_value = :fv, auto_fill = :af, base_thickness_mm = :b, quoted_on = :q, valid_until = :v,
      includes_packing_loading = :i, cha_transport_per_container = :c, notes = :n, updated_by = :u WHERE id = :id`,
    { fs: r.fob_source, fv: r.fob_value, af: r.auto_fill, b: r.base_thickness_mm, q: r.quoted_on, v: r.valid_until, i: r.includes_packing_loading, c: r.cha_transport_per_container, n: r.notes, u: userId, id });
    if (snap.product && Object.keys(snap.product).length) {
      const p = snap.product;
      await conn.execute('UPDATE catalog_products SET configured_base_price_inr = :p, configured_base_thickness_mm = :mm, configured_base_unit = :un, configured_at = :at WHERE id = :id',
        { p: p.configured_base_price_inr, mm: p.configured_base_thickness_mm, un: p.configured_base_unit, at: p.configured_at, id: Number(snap.product_id) });
    }
  });
  const after = product ? await offerBase(product, (await productSupplierRepository.find(id)) || row, rules) : null;
  const nf = (v) => ps().numberFormat(v, 2);
  await thicknessPrices.history(id, 'Undo (Edit price)', before ? `₹${nf(before.inr)} ${before.label}` : '—', after ? `₹${nf(after.inr)} ${after.label} restored` : 'restored', userId);
  await auditLogRepository.log(userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', id, null, 'Edit price', 'undone');
  return null;
}

module.exports = {
  usable, offers, markedForPricing, setOfferActive, offerBase, lowestHighest,
  configuredBase, setConfiguredBase, followSupplier, refreshFollowedBase, pricingRow,
  formThicknesses, preview, snapshot, save, undo,
};
