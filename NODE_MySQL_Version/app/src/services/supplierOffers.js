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
      label: `${mm === thicknessPrices.CBM ? 'per CBM' : 'per SQM'} (${thicknessPrices.label(mm, Number(rules.cbm_from))})`,
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
  // D-361 (owner decision 1): the base price never changes by itself — after migration 13b no
  // configured product follows the supplier, and a product never configured has no base price to
  // keep in step (Configure sets it), so this only acts on a base price still following.
  if (Number(product.configured_base_follows_supplier ?? 1) !== 1 || !isSet(product.configured_base_price_inr)) return;
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
        mm, label: thicknessPrices.label(mm, cbmFrom), unit: mm === thicknessPrices.CBM ? 'CBM' : 'SQM',
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
  // D-364: a price typed per SQFT is saved as the SQM price; the notes keep what was typed.
  inp = fromSqft({ unit: '', mm: inp.from_mm ?? null, ...inp }, product, grid, String(row.price_basis || 'unit'));
  inp.notes = notesWithTyped(String(inp.notes ?? ''), inp.typed_sqft) ?? '';
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
  if (isSet(inp.typed_sqft)) await thicknessPrices.history(id, 'Price typed per SQFT', null, typedText(inp.typed_sqft), userId);
  const after = await offerBase(product, (await productSupplierRepository.find(id)) || row, rules);
  await refreshFollowedBase((await productRepository.find(Number(product.id))) || product, userId);
  const what = grid
    ? `${Number(inp.from_mm) === thicknessPrices.CBM ? 'per CBM' : 'per SQM'} (${thicknessPrices.label(Number(inp.from_mm), cbmFrom)})`
    : `per ${(row.price_basis || '') === 'cbm' ? 'CBM' : (String(product.reference_unit || '') || 'unit')}`;
  const d = new Date(`${inp.quoted_on}T00:00:00`);
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const summary = `${row.supplier_name} · ${product.name}: ${before ? `₹${thicknessPrices.amount(before.inr)} → ` : ''}₹${thicknessPrices.amount(Number(inp.price))} ${what}`
    + (isSet(inp.typed_sqft) ? ` (${typedText(inp.typed_sqft)})` : '')
    + `, quoted ${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}.`
    + (grid && after && before && Number(inp.from_mm) !== (after.mm ?? -1) ? ` Base price now ₹${thicknessPrices.amount(after.inr)} ${after.label}.` : '')
    + (grid ? ' Other thicknesses updated.' : '');
  await auditLogRepository.log(userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', id, null,
    before ? `₹${ps().numberFormat(before.inr, 2)} ${before.label}` : null, `₹${ps().numberFormat(Number(inp.price), 2)} ${what}${isSet(inp.typed_sqft) ? ` (${typedText(inp.typed_sqft)})` : ''} quoted ${inp.quoted_on} (Edit price)`);
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

// ------------------------------------------------------------------ D-360: the Suppliers pages

/** D-360: one offer as the Suppliers pages show it. */
async function listRow(product, r, rules, s = null) {
  const basis = String(r.price_basis || 'unit');
  const base = await offerBase(product, r, rules, s);
  const cbmFrom = Number(rules.cbm_from);
  let price;
  if (base) {
    const short = base.unit === 'CBM' ? 'CBM' : (base.unit === 'm2' ? 'SQM' : (lineUnits.label(base.unit) || base.unit));
    price = `₹${thicknessPrices.amount(base.inr)} / ${short}${base.mm !== null ? ` (${thicknessPrices.label(base.mm, cbmFrom)})` : ''}`;
  } else {
    price = basis === 'combo' ? 'Exact price for a combination' : '—';
  }
  const day = (v) => (v ? (v instanceof Date ? ymd(v) : String(v).slice(0, 10)) : null);
  return {
    id: Number(r.id), product_id: Number(product.id), product_name: String(product.name),
    product_active: Number(product.is_active ?? 1) === 1,
    price, thickness: basis === 'grid' ? thicknessPrices.label(thicknessPrices.baseOf(r, rules), cbmFrom) : '—', basis,
    quoted_on: day(r.quoted_on), valid_until: day(r.valid_until),
    // "12 Nov 2026 · 31 Dec 2026" (quoted on · valid until)
    dates: `${day(r.quoted_on) ? longDay(day(r.quoted_on)) : '—'} · ${day(r.valid_until) ? longDay(day(r.valid_until)) : 'no end date'}`,
    offer_active: Number(r.offer_active ?? 1) === 1,
    supplier_active: Number(r.supplier_is_active ?? 1) === 1,
    // "Edit price" is the simple form for one typed price (not an exact price for a combination).
    editable: (r.fob_source || 'direct') === 'direct' && basis !== 'combo',
  };
}

/** "2026-11-12" → "12 Nov 2026" (PHP date('j M Y')). */
function longDay(v) {
  const [y, m, d] = v.split('-').map(Number);
  return `${d} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m - 1]} ${y}`;
}

function ymd(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** D-360: every offer linked to these directory suppliers, by supplier id, for the Suppliers list and the supplier page. */
async function forSuppliers(supplierIds, s = null) {
  const ids = [...new Set(supplierIds.map(Number))];
  if (!ids.length) return {};
  s = s || (await ps().settings());
  const params = {};
  ids.forEach((id, i) => { params[`s${i}`] = id; });
  const rows = await db.query(`SELECT cps.*, sd.is_active AS supplier_is_active FROM catalog_product_suppliers cps JOIN suppliers sd ON sd.id = cps.supplier_id
    JOIN catalog_products p ON p.id = cps.product_id WHERE cps.supplier_id IN (${ids.map((_, i) => `:s${i}`).join(', ')}) ORDER BY p.name, cps.id`, params);
  const products = {};
  const rules = {};
  const out = {};
  for (const r of rows) {
    const pid = Number(r.product_id);
    if (!(pid in products)) products[pid] = await productRepository.find(pid);
    const product = products[pid];
    if (!product) continue;
    if (!(pid in rules)) rules[pid] = await thicknessPrices.rules(product, s);
    (out[Number(r.supplier_id)] = out[Number(r.supplier_id)] || []).push(await listRow(product, r, rules[pid], s));
  }
  return out;
}

/** D-360: how many offers each directory supplier has (linked rows). */
async function countsBySupplier() {
  const out = {};
  for (const r of await db.query('SELECT supplier_id, COUNT(*) AS n FROM catalog_product_suppliers WHERE supplier_id IS NOT NULL GROUP BY supplier_id')) {
    out[Number(r.supplier_id)] = Number(r.n);
  }
  return out;
}

/** D-360: this supplier's offer of this product, if it already has one. */
async function existingOffer(supplierId, productId) {
  const r = await db.queryOne('SELECT id FROM catalog_product_suppliers WHERE supplier_id = :s AND product_id = :p ORDER BY id LIMIT 1', { s: supplierId, p: productId });
  return r ? productSupplierRepository.find(Number(r.id)) : null;
}

/**
 * D-360: what "+ Add product" can price for a product (or a new product of a category): a price
 * for every thickness (the thicknesses, the base, m² / CBM) or one price per unit.
 */
async function priceShape(product, s = null, newProduct = false) {
  const rules = await thicknessPrices.rules(product, s);
  if (rules.grid) {
    const cbmFrom = Number(rules.cbm_from);
    return {
      grid: true, base_mm: Number(rules.base_mm),
      thicknesses: thicknessPrices.thicknesses(rules).map((mm) => ({ mm, label: thicknessPrices.label(mm, cbmFrom), unit: mm === thicknessPrices.CBM ? 'CBM' : 'm2' })),
      // D-364: a price per SQM may be typed per SQFT (saved as the SQM price).
      units: [{ value: 'm2', label: 'SQM' }, { value: SQFT_INPUT, label: 'SQFT', sqft: true }, { value: 'CBM', label: 'CBM' }],
    };
  }
  const units = [];
  if (newProduct) {
    for (const o of await require('../repositories/lookupRepository').dropdownOptions('unit')) {
      units.push({ value: String(o.option_value), label: lineUnits.label(String(o.option_value)) || String(o.option_value) });
    }
  } else {
    const u = String(product.reference_unit || '');
    units.push({ value: u !== '' ? u : 'unit', label: u !== '' ? (lineUnits.label(u) || u) : 'unit' });
    if (lineUnits.canonical(u) === 'M2') units.push({ value: SQFT_INPUT, label: 'SQFT', sqft: true }); // D-364
  }
  if (!units.some((x) => x.value.toUpperCase() === 'CBM')) units.push({ value: 'CBM', label: 'CBM' });
  return { grid: false, base_mm: null, thicknesses: [], units };
}

// ------------------------------------------------------------------ D-364: a price typed per SQFT

/** D-364: the unit a per-area supplier price may be typed in; it is saved as the SQM price. */
const SQFT_INPUT = 'SQFT';

/** D-364: ₹ per SQFT → ₹ per SQM (× 10.7639, lineUnits.SQFT_PER_M2), 2 decimals. */
function sqftToSqm(perSqft) {
  return require('./pricingEngine').round2(Number(perSqft) * lineUnits.SQFT_PER_M2);
}

/** D-364: whether a price is per SQM — a thickness below "50 mm and above" (grid), else a product sold per SQM. */
function sqmPriced(product, grid, mm, basis = 'unit') {
  if (grid) return mm !== null && mm !== undefined && Number(mm) !== thicknessPrices.CBM;
  return basis !== 'cbm' && lineUnits.canonical(String((product && product.reference_unit) || '')) === 'M2';
}

/**
 * D-364: a price typed per SQFT becomes the SQM price, stored exactly as an SQM price is;
 * typed_sqft keeps what was typed (for the notes and the price history). Any other unit (or a
 * product whose own unit is SQFT) is left as it is.
 */
function fromSqft(inp, product, grid, basis = 'unit') {
  const out = { ...inp, typed_sqft: null };
  if (String(inp.unit ?? '').trim().toUpperCase() !== SQFT_INPUT
    || !sqmPriced(product, grid, isSet(inp.mm) ? Number(inp.mm) : null, basis)) return out;
  out.typed_sqft = Number(inp.price);
  out.price = sqftToSqm(Number(inp.price));
  out.unit = grid ? 'm2' : String(product.reference_unit);
  return out;
}

/** D-364: "typed ₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)". */
function typedText(perSqft) {
  return `typed ₹${thicknessPrices.amount(perSqft)} / SQFT = ₹${thicknessPrices.amount(sqftToSqm(perSqft))} / SQM (× ${lineUnits.SQFT_PER_M2})`;
}

/** D-364: the offer's notes with its "typed … / SQFT" line set (or dropped for a price typed per SQM); staff notes are kept. */
function notesWithTyped(notes, typedSqft) {
  const keep = String(notes ?? '').split(/\r\n|\r|\n/).filter((l) => !/^typed ₹[0-9,.]+ \/ SQFT\b/u.test(l.trim()));
  let text = keep.join('\n').trim();
  if (isSet(typedSqft)) text = `${text}\n${typedText(typedSqft)}`.trim();
  return text !== '' ? text : null;
}

/**
 * D-360: the "+ Add product" choices — every product (those with no directory supplier yet first,
 * marked), with what this supplier already offers, and every active category.
 */
async function addProductChoices(supplierId, s = null) {
  s = s || (await ps().settings());
  const linked = new Set((await db.query('SELECT DISTINCT product_id FROM catalog_product_suppliers WHERE supplier_id IS NOT NULL')).map((r) => Number(r.product_id)));
  const mine = {};
  for (const r of await db.query('SELECT product_id, MIN(id) AS id FROM catalog_product_suppliers WHERE supplier_id = :s GROUP BY product_id', { s: supplierId })) {
    mine[Number(r.product_id)] = Number(r.id);
  }
  const products = [];
  for (const p of await db.query('SELECT * FROM catalog_products ORDER BY name, id')) {
    products.push({
      id: Number(p.id), name: String(p.name), active: Number(p.is_active) === 1,
      no_supplier: !linked.has(Number(p.id)), offer_id: mine[Number(p.id)] ?? null,
      shape: await priceShape(p, s),
    });
  }
  products.sort((a, b) => (a.no_supplier === b.no_supplier ? 0 : (a.no_supplier ? -1 : 1))
    || (a.name.toLowerCase() < b.name.toLowerCase() ? -1 : (a.name.toLowerCase() > b.name.toLowerCase() ? 1 : 0)));
  const categories = [];
  for (const c of await require('../repositories/productCategoryRepository').all(true)) {
    categories.push({ id: Number(c.id), name: String(c.name), shape: await priceShape({ id: 0, category_id: Number(c.id) }, s, true) });
  }
  return { products, categories };
}

/**
 * D-360: a new product from a supplier's "+ Add product" — name (unique), category, HS code
 * (optional: the category's default HS code, else empty until Configure). It starts Inactive
 * and not configured (D-361 adds Configure). Returns the new product's id.
 */
async function createProduct(name, categoryId, hsCode, unit, userId) {
  const category = await require('../repositories/productCategoryRepository').find(categoryId);
  const hs = hsCode !== '' ? hsCode : String((category && category.default_hs_code) || '');
  const id = Number(await productRepository.create({ name, specifications: '', hs_code: hs, reference_unit: unit, is_active: 0 }, userId));
  // Not configured: configured_at stays empty until Configure (D-361).
  await require('../repositories/specRepository').setProductFields(id, categoryId, null, null, null);
  await auditLogRepository.log(userId, 'PRODUCT_CREATED', 'products', id, null, null, `${name} (from a supplier, Inactive, not configured)`);
  return id;
}

/**
 * D-360: "+ Add product" — this directory supplier's offer of a product: one price at one
 * thickness (the other thicknesses worked out by the price rules, as Market prices does) or one
 * price per unit / per CBM, quoted on, valid until; the offer is switched on. It is "used for
 * pricing" only when the product has no supplier price used for pricing yet.
 * inp: price, mm (grid; null otherwise), unit, quoted_on, valid_until. Returns the offer id.
 */
async function addOffer(supplier, product, inp, userId, newProduct = false) {
  const productId = Number(product.id);
  const rules = await thicknessPrices.rules(product);
  const grid = inp.mm !== null && rules.grid;
  inp = fromSqft(inp, product, grid); // D-364: a price per SQFT is saved as the SQM price
  const unitIsCbm = String(inp.unit).toUpperCase() === 'CBM';
  const basis = grid ? 'grid' : (unitIsCbm && lineUnits.canonical(String(product.reference_unit || '')) !== 'CBM' ? 'cbm' : 'unit');
  const primary = basis !== 'cbm' && (await markedForPricing(productId)) === null;
  const id = Number(await productSupplierRepository.create({
    product_id: productId, supplier_name: String(supplier.supplier_legal_name),
    location: [String(supplier.city || ''), String(supplier.state || '')].filter((x) => x !== '').join(', ') || null,
    contact_person: null, contact_phone: null, contact_email: null,
    fob_source: 'direct', fob_value: grid ? null : Number(inp.price),
    factory_cost: null, transportation_cost: null, packing_cost: null, loading_cost: null, cha_cost: null,
    is_primary: 0, notes: notesWithTyped(null, inp.typed_sqft),
  }, userId));
  await pricingRepository.setSupplierDates(id, String(inp.quoted_on), inp.valid_until !== '' ? String(inp.valid_until) : null);
  await productSupplierRepository.setPriceDetails(id, Number(supplier.id), true, null);
  await pricingRepository.setSupplierBasis(id, basis);
  if (grid) {
    const row = (await productSupplierRepository.find(id)) || { id };
    const others = thicknessPrices.thicknesses(rules).filter((mm) => mm !== Number(inp.mm));
    const error = await thicknessPrices.addPrice(row, rules, Number(inp.mm), Number(inp.price), String(inp.quoted_on), others, true, userId);
    if (error !== null && error !== undefined) {
      await productSupplierRepository.remove(id);
      throw new Error(error);
    }
  }
  if (primary) await productService.setPrimarySupplier(productId, id);
  // D-361 (owner decision 1): the product's base price does not change by itself — a manager
  // configures it (or clicks "Use ₹X"). A product with no supplier yet now has one.
  await supplierLinked(productId, userId);
  const fresh = (await productRepository.find(productId)) || product;
  const after = await offerBase(fresh, (await productSupplierRepository.find(id)) || { id }, rules);
  const f2c = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  await thicknessPrices.history(id, 'Offer added (+ Add product)', null, after ? `₹${f2c(after.inr)} ${after.label}` : 'added', userId);
  if (isSet(inp.typed_sqft)) await thicknessPrices.history(id, 'Price typed per SQFT', null, typedText(inp.typed_sqft), userId);
  await auditLogRepository.log(userId, 'MARKET_PRICE_ADDED', 'product_suppliers', id, null, null,
    `${supplier.supplier_legal_name}: ${product.name} ₹${f2c(inp.price)} `
    + (grid ? `${Number(inp.mm) === thicknessPrices.CBM ? 'per CBM' : 'per SQM'} (${thicknessPrices.label(Number(inp.mm), Number(rules.cbm_from))})` : `per ${inp.unit}`)
    + (isSet(inp.typed_sqft) ? ` (${typedText(inp.typed_sqft)})` : '')
    + ` quoted ${inp.quoted_on} (+ Add product)${primary ? ' (used for pricing)' : ''}`);
  return id;
}

// ------------------------------------------------------------------ D-361: products, Configure, Supplier PO

/** SQL: an offer row (alias cps) that is usable — switched on and its directory supplier (if any) active. */
const USABLE_SQL = '(cps.offer_active = 1 AND (cps.supplier_id IS NULL OR EXISTS (SELECT 1 FROM suppliers sd WHERE sd.id = cps.supplier_id AND sd.is_active = 1)))';
/** SQL: product (alias p) is configured — its base price is fixed (owner decision 1). */
const CONFIGURED_SQL = '(p.configured_base_follows_supplier = 0 AND p.configured_base_price_inr IS NOT NULL)';
/** SQL: product (alias p) existed before 13b with no supplier, and still has none linked. */
const NO_SUPPLIER_YET_SQL = '(p.legacy_no_supplier = 1 AND NOT EXISTS (SELECT 1 FROM catalog_product_suppliers cps2 WHERE cps2.product_id = p.id AND cps2.supplier_id IS NOT NULL))';

/** SQL: product (alias p) may be active and quoted (plan rule 4 with the decision-1 exception). */
function quotableSql() {
  return `(${NO_SUPPLIER_YET_SQL} OR (${CONFIGURED_SQL} AND EXISTS (SELECT 1 FROM catalog_product_suppliers cps WHERE cps.product_id = p.id AND ${USABLE_SQL})))`;
}

/** Whether the product's base price is configured (fixed). */
function isConfigured(product) {
  return Number(product.configured_base_follows_supplier ?? 1) === 0 && isSet(product.configured_base_price_inr);
}

/**
 * D-361: where a product stands — configured, its usable / paused offers, "No supplier yet" (existed
 * before 13b with no linked supplier), whether it may be switched Active (and so quoted), and the
 * one status the Products list shows.
 */
async function status(product, list = null) {
  const all = list || (await offers(Number(product.id)));
  const usableN = all.filter((o) => o.usable).length;
  const linked = all.some((o) => isSet(o.supplier_id));
  const configured = isConfigured(product);
  const noSupplierYet = Number(product.legacy_no_supplier || 0) === 1 && !linked;
  const canActivate = noSupplierYet || (configured && usableN > 0);
  let key; let label; let badge;
  if (noSupplierYet) [key, label, badge] = ['no_supplier_yet', 'No supplier yet', 'badge-danger'];
  else if (!configured) [key, label, badge] = ['not_configured', 'Not configured', 'badge-warning'];
  else if (usableN === 0) [key, label, badge] = ['no_active_supplier', 'No active supplier', 'badge-danger'];
  else [key, label, badge] = ['configured', 'Configured', 'badge-success'];
  const reason = canActivate ? null : (!configured ? 'Not configured yet — ask the product manager.' : "No active supplier — ask Procurement to add or switch on a supplier's price.");
  return {
    configured, usable: usableN, paused: all.length - usableN, linked, no_supplier_yet: noSupplierYet,
    can_activate: canActivate, quotable: canActivate && Number(product.is_active || 0) === 1, key, label, badge, reason,
  };
}

/** D-361: a directory supplier is now linked to the product — "No supplier yet" ends (the normal rule applies). */
async function supplierLinked(productId, userId = null) {
  const r = await db.execute('UPDATE catalog_products SET legacy_no_supplier = 0 WHERE id = :id AND legacy_no_supplier = 1', { id: productId });
  const n = Number((r && (r.affectedRows ?? (r[0] && r[0].affectedRows))) || 0);
  if (n > 0 && userId !== null && userId !== undefined) await auditLogRepository.log(userId, 'PRODUCT_SUPPLIER_LINKED', 'catalog_products', productId, 'legacy_no_supplier', '1', '0');
}

/** When an offer's price last changed (its thickness prices or its history), as "YYYY-MM-DD HH:MM:SS". */
async function offerChangedAt(offerId) {
  const r = await db.queryOne(`SELECT DATE_FORMAT(GREATEST(
      COALESCE((SELECT MAX(updated_at) FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :a), '1000-01-01'),
      COALESCE((SELECT MAX(changed_at) FROM catalog_supplier_price_history WHERE product_supplier_id = :b AND what NOT LIKE 'Linked to supplier%' AND what <> 'Offer active'), '1000-01-01')), '%Y-%m-%d %H:%i:%s') AS t`, { a: offerId, b: offerId });
  const v = r && r.t ? String(r.t) : '';
  return v === '' || v.startsWith('1000-01-01') ? null : v;
}

function dateTimeText(v) {
  if (!isSet(v)) return '';
  if (v instanceof Date) return `${ymd(v)} ${String(v.getHours()).padStart(2, '0')}:${String(v.getMinutes()).padStart(2, '0')}:${String(v.getSeconds()).padStart(2, '0')}`;
  return String(v);
}

/**
 * D-361 (owner decision 1): "Supplier prices changed: highest is now ₹X" — when the highest usable
 * supplier price (quoted the same way as the base price) differs from the configured base price and
 * a supplier price changed after the product was configured. Null otherwise.
 */
async function priceChange(product, lh = null) {
  if (!isConfigured(product)) return null;
  lh = lh || (await lowestHighest(product));
  const high = lh.highest;
  if (!high || Math.abs(Number(high.base.inr) - Number(product.configured_base_price_inr)) < 0.005) return null;
  const at = dateTimeText(product.configured_at);
  let changed = false;
  for (const o of lh.offers) {
    const c = await offerChangedAt(Number(o.id));
    if (c !== null && (at === '' || c > at)) { changed = true; break; }
  }
  if (!changed) return null;
  return { inr: Number(high.base.inr), mm: high.base.mm, unit: String(high.base.unit), label: String(high.base.label), supplier: String(high.supplier_name) };
}

/** "per CBM (50 mm and above)", "per m² (20 mm)", "per Sq.Ft" for the configured base price. */
/**
 * D-365: a price's unit and thickness, short — "CBM (50 mm+)", "SQM (20 mm)", "SQM", "piece" — for
 * the Products list ("₹160,000 – ₹190,000 / CBM (50 mm+)").
 */
function shortUnit(unit, mm, cbmFrom, withThickness = true) {
  if (mm !== null && mm !== undefined) {
    const u = Number(mm) === thicknessPrices.CBM ? 'CBM' : 'SQM';
    return withThickness ? `${u} (${Number(mm) === thicknessPrices.CBM ? `${cbmFrom} mm+` : `${mm} mm`})` : u;
  }
  const u = String(unit ?? '');
  return u === '' ? 'unit' : (lineUnits.label(u) || u);
}

/** D-365: the configured base price, short — "₹2,000 / SQM, 20 mm", "₹190,000 / CBM, 50 mm+", "₹80,000 / unit". */
function configuredShort(product, cbmFrom) {
  const mm = isSet(product.configured_base_thickness_mm) ? Number(product.configured_base_thickness_mm) : null;
  const text = `₹${thicknessPrices.amount(Number(product.configured_base_price_inr))} / ${shortUnit(String(product.configured_base_unit || ''), mm, cbmFrom, false)}`;
  return mm === null ? text : `${text}, ${mm === thicknessPrices.CBM ? `${cbmFrom} mm+` : `${mm} mm`}`;
}

async function baseLabel(product, s = null) {
  const mm = isSet(product.configured_base_thickness_mm) ? Number(product.configured_base_thickness_mm) : null;
  if (mm !== null) {
    const rules = await thicknessPrices.rules(product, s);
    return `${mm === thicknessPrices.CBM ? 'per CBM' : 'per SQM'} (${thicknessPrices.label(mm, Number(rules.cbm_from))})`;
  }
  const u = String(product.configured_base_unit || '');
  return `per ${u === '' ? 'unit' : (lineUnits.label(u) || u)}`;
}

/**
 * D-361: the Products list row figures — suppliers (usable / paused), lowest – highest supplier price
 * (each a link to that offer's Edit price), the configured base price and its status.
 */
async function productRow(product, s = null) {
  const all = await offers(Number(product.id));
  const st = await status(product, all);
  const lh = await lowestHighest(product, s);
  const cbmFrom = Number((await thicknessPrices.rules(product, s)).cbm_from);
  const cfgMm = isSet(product.configured_base_thickness_mm) ? Number(product.configured_base_thickness_mm) : null;
  const cfg = isConfigured(product) ? {
    inr: Number(product.configured_base_price_inr), label: await baseLabel(product, s),
    // D-365: "₹180,000 / CBM" on the Products list.
    amount: thicknessPrices.amount(Number(product.configured_base_price_inr)),
    short: shortUnit(String(product.configured_base_unit || ''), cfgMm, cbmFrom, false),
  } : null;
  // D-365: "/ CBM (50 mm+)" after the lowest – highest supplier price.
  const lhShort = lh.lowest ? shortUnit(String(lh.lowest.base.unit), lh.lowest.base.mm, cbmFrom) : null;
  let latest = null;
  for (const o of all) {
    const d = o.quoted_on ? (o.quoted_on instanceof Date ? ymd(o.quoted_on) : String(o.quoted_on).slice(0, 10)) : null;
    if (o.usable && d !== null && (latest === null || d > latest)) latest = d;
  }
  for (const r of [lh.lowest, lh.highest]) if (r) r.base.amount = thicknessPrices.amount(r.base.inr);
  return { status: st, lowest: lh.lowest, highest: lh.highest, base: cfg, latest_quote: latest, short: lhShort };
}

/** D-361 (owner decision 3): supplier prices typed before the Suppliers directory and still not linked to a supplier record. */
async function unlinkedPrices(s = null) {
  s = s || (await ps().settings());
  const rows = await db.query(`SELECT cps.* FROM catalog_product_suppliers cps JOIN catalog_products p ON p.id = cps.product_id
    WHERE cps.supplier_id IS NULL ORDER BY p.name, cps.supplier_name, cps.id`);
  const out = [];
  const products = {};
  for (const r of rows) {
    const pid = Number(r.product_id);
    if (!(pid in products)) products[pid] = await productRepository.find(pid);
    if (!products[pid]) continue;
    const row = await listRow(products[pid], r, await thicknessPrices.rules(products[pid], s), s);
    row.typed_name = String(r.supplier_name);
    row.used_for_pricing = Number(r.is_primary) === 1;
    out.push(row);
  }
  return out;
}

/** How many legacy supplier prices still need linking. */
async function unlinkedCount() {
  const r = await db.queryOne('SELECT COUNT(*) AS n FROM catalog_product_suppliers cps JOIN catalog_products p ON p.id = cps.product_id WHERE cps.supplier_id IS NULL');
  return Number((r || {}).n || 0);
}

/** D-361 (owner decision 3): links one legacy supplier price to a directory supplier. Returns an error or null. */
async function linkPrice(offerId, supplierId, userId) {
  const row = await productSupplierRepository.find(offerId);
  if (!row) return 'That supplier price no longer exists.';
  if (isSet(row.supplier_id)) return 'That supplier price is already linked to a supplier.';
  const supplier = await require('../repositories/supplierRepository').find(supplierId);
  if (!supplier || !Number(supplier.is_active)) return 'Choose an active supplier from the Suppliers directory.';
  const name = String(supplier.supplier_legal_name);
  await db.execute('UPDATE catalog_product_suppliers SET supplier_id = :s, supplier_name = :n, updated_by = :u WHERE id = :id AND supplier_id IS NULL',
    { s: supplierId, n: [...name].slice(0, 255).join(''), u: userId, id: offerId });
  await thicknessPrices.history(offerId, 'Linked to supplier', [...`typed: ${row.supplier_name}`].slice(0, 80).join(''), [...name].slice(0, 80).join(''), userId);
  await auditLogRepository.log(userId, 'SUPPLIER_PRICE_LINKED', 'product_suppliers', offerId, 'supplier_id', `typed: ${row.supplier_name}`, `${name} (#${supplierId})`);
  await supplierLinked(Number(row.product_id), userId);
  return null;
}

/**
 * D-361 (decision 2, mockups p8 / decision_d2): every supplier offer of an order line's product for
 * the Supplier PO terms form — quoted ₹ per unit (at the line's thickness when the offer has a price
 * for every thickness), valid until (expired marked), the PO value for the line quantity when the
 * units match; lowest first and marked. Switched-off offers and inactive suppliers: "not offered".
 */
async function forOrderPo(orderId, s = null) {
  s = s || (await ps().settings());
  const supplierRepository = require('../repositories/supplierRepository');
  const lines = await db.query(`SELECT id, line_no, catalog_product_id, description, quantity, unit, piece_thickness_cm FROM order_products
    WHERE order_id = :o AND is_active = 1 AND catalog_product_id IS NOT NULL ORDER BY line_no, id`, { o: orderId });
  const out = [];
  const today = ymd(new Date());
  for (const line of lines) {
    const product = await productRepository.find(Number(line.catalog_product_id));
    if (!product) continue;
    const rules = await thicknessPrices.rules(product, s);
    const cbmFrom = Number(rules.cbm_from);
    const mm = isSet(line.piece_thickness_cm) && Number(line.piece_thickness_cm) > 0 ? Math.round(Number(line.piece_thickness_cm) * 10) : null;
    const key = mm !== null ? (mm >= cbmFrom ? thicknessPrices.CBM : mm) : null;
    const qty = isSet(line.quantity) ? Number(line.quantity) : null;
    const lineUnit = String(lineUnits.canonical(String(line.unit || '')) || '');
    const rows = [];
    for (const o of await offers(Number(product.id))) {
      if (!isSet(o.supplier_id) || !['unit', 'grid', 'cbm'].includes(o.price_basis || 'unit')) continue;
      const supplier = await supplierRepository.find(Number(o.supplier_id));
      let price = null; let unit = null; let label = '';
      if ((o.price_basis || '') === 'grid') {
        const vals = thicknessPrices.values(rules, await thicknessPrices.storedFor(Number(o.id)), thicknessPrices.autoFill(o));
        if (key !== null && vals.values[key] && Number(vals.values[key].price) > 0) {
          price = Number(vals.values[key].price);
          unit = key === thicknessPrices.CBM ? 'CBM' : 'm2';
          label = `${key === thicknessPrices.CBM ? 'per CBM' : 'per SQM'} (${thicknessPrices.label(key, cbmFrom)})`;
        }
      }
      if (price === null) {
        const b = await offerBase(product, o, rules, s);
        if (b) [price, unit, label] = [Number(b.inr), String(b.unit), String(b.label)];
      }
      const sameUnit = unit !== null && lineUnit !== '' && String(lineUnits.canonical(unit) || '') === lineUnit;
      const validUntil = o.valid_until ? (o.valid_until instanceof Date ? ymd(o.valid_until) : String(o.valid_until).slice(0, 10)) : null;
      rows.push({
        offer_id: Number(o.id), supplier_id: Number(o.supplier_id), supplier: String((supplier && supplier.supplier_legal_name) || o.supplier_name),
        offered: !!o.usable && price !== null,
        why_not: !o.usable ? (supplier && !Number(supplier.is_active) ? 'inactive supplier' : 'offer switched off') : (price === null ? 'no price' : null),
        price, unit, label, valid_until: validUntil, expired: validUntil !== null && validUntil < today,
        po_value: price !== null && qty !== null && sameUnit ? Math.round(price * qty * 100) / 100 : null, lowest: false,
      });
    }
    rows.sort((a, b) => ((a.offered ? 0 : 1) - (b.offered ? 0 : 1)) || ((a.price ?? Infinity) - (b.price ?? Infinity)));
    if (rows.length && rows[0].offered) rows[0].lowest = true;
    out.push({
      line_id: Number(line.id), line_no: Number(line.line_no), product_id: Number(product.id), product: String(product.name),
      description: String(line.description || ''), quantity: qty, unit: String(line.unit || ''), thickness: key !== null ? thicknessPrices.label(key, cbmFrom) : null, offers: rows,
    });
  }
  return out;
}

module.exports = {
  usable, offers, markedForPricing, setOfferActive, offerBase, lowestHighest,
  configuredBase, setConfiguredBase, followSupplier, refreshFollowedBase, pricingRow,
  formThicknesses, preview, snapshot, save, undo,
  // D-360
  listRow, forSuppliers, countsBySupplier, existingOffer, priceShape, addProductChoices, createProduct, addOffer,
  // D-364
  SQFT_INPUT, sqftToSqm, sqmPriced, fromSqft, typedText, notesWithTyped,
  // D-361
  USABLE_SQL, CONFIGURED_SQL, NO_SUPPLIER_YET_SQL, quotableSql, isConfigured, status, supplierLinked, priceChange,
  baseLabel, productRow, unlinkedPrices, unlinkedCount, linkPrice, forOrderPo,
  // D-365
  shortUnit, configuredShort,
};
