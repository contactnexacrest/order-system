'use strict';

const crypto = require('crypto');
const flash = require('../helpers/flash');
const productRepository = require('../repositories/productRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const lineUnits = require('../services/lineUnits');
const pricingService = require('../services/pricingService');
const supplierOffers = require('../services/supplierOffers');
const thicknessPrices = require('../services/thicknessPrices');

/**
 * Port of App\Controllers\EditPriceController — owner decision D-359 (13 Nov 2026, "go with
 * recommendation", plan rule 8): the simple "Edit price" form for one supplier's offer of one
 * product — reached from the product's Market prices page (an "Edit price" button per supplier
 * row) and from the supplier's page ("Prices given for products", an "Edit price" link per
 * row). One form, one Save; the page stays; a green message with Undo and "See price
 * history". All maths and the price history are the existing services' (supplierOffers →
 * thicknessPrices / pricingService).
 */

const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);
const isSet = (v) => v !== null && v !== undefined;
const todayYmd = () => {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};
const ymdOf = (v) => {
  if (!isSet(v) || v === '') return '';
  if (v instanceof Date) return `${v.getFullYear()}-${String(v.getMonth() + 1).padStart(2, '0')}-${String(v.getDate()).padStart(2, '0')}`;
  return String(v).slice(0, 10);
};
const trimAmount = (v) => Number(v).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const longDate = (ymd) => {
  const d = new Date(`${ymd}T00:00:00`);
  return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
};

async function load(req) {
  const row = await productSupplierRepository.find(parseInt(req.params.supplierId, 10) || 0);
  const product = row ? await productRepository.find(Number(row.product_id)) : null;
  return [row, product];
}

/** Where Cancel / close goes: the supplier's page when the form was opened from there. */
function backUrl(row, from) {
  if (from === 'supplier' && row.supplier_id) return `/suppliers/${Number(row.supplier_id)}/edit#product-prices`;
  // D-360: opened from the Suppliers list.
  if (from === 'suppliers' && row.supplier_id) return `/suppliers#supplier-${Number(row.supplier_id)}`;
  // D-361: opened from the Products list ("Lowest – highest") or the Configure / Edit screen.
  if (from === 'products') return '/products?status=all';
  if (from === 'configure') return `/products/${Number(row.product_id)}/configure`;
  return `/products/${Number(row.product_id)}/market-prices`;
}

function fromOf(req) {
  const f = String((req.query && req.query.from) || (req.body && req.body.from) || '');
  return ['supplier', 'suppliers', 'products', 'configure'].includes(f) ? f : 'product';
}

/** The form's own address, keeping where it was opened from. */
function selfUrl(id, from) {
  return `/products/suppliers/${id}/edit-price${from !== 'product' ? `?from=${from}` : ''}`;
}

/** "185000" / "185,000.50" → 185000.5; null when not a ₹ amount with at most 2 decimals. */
function money(raw) {
  const v = String(raw ?? '').replace(/,/g, '').trim();
  return /^\d{1,12}(\.\d{1,2})?$/.test(v) ? Number(v) : null;
}

async function form(req, res) {
  const [row, product] = await load(req);
  if (!row || !product) {
    res.status(404).send('Supplier price not found.');
    return;
  }
  const s = await pricingService.settings();
  const rules = await thicknessPrices.rules(product, s);
  const grid = (row.price_basis || '') === 'grid';
  const base = await supplierOffers.offerBase(product, row, rules, s);
  const fromMm = grid ? thicknessPrices.baseOf(row, rules) : null;
  const id = Number(row.id);
  const preview = await supplierOffers.preview(product, row, fromMm, base ? base.inr : 0, {}, s);
  const msgs = req.session.edit_price_msg || {};
  const saved = msgs[id] ?? null;
  delete msgs[id];
  req.session.edit_price_msg = msgs;
  const undo = (req.session.edit_price_undo || {})[id] || null;
  const old = flash.pullOld(req);
  // D-365: "Add a 50 mm+ price" (Pricing ready?) opens this form at that thickness, price empty.
  const pick = /^\d+$/.test(String(req.query.thickness_mm ?? '')) ? Number(req.query.thickness_mm) : null;
  if (grid && pick !== null && pick !== fromMm && !isSet(old.thickness_mm) && supplierOffers.formThicknesses(rules, row).includes(pick)) {
    Object.assign(old, { thickness_mm: String(pick), unit: pick === thicknessPrices.CBM ? 'CBM' : 'm2', price: '' });
  }
  const from = fromOf(req);
  const today = todayYmd();
  const validUntil = isSet(old.valid_until) ? String(old.valid_until) : (ymdOf(row.valid_until) >= today ? ymdOf(row.valid_until) : '');
  const cha = isSet(old.cha_transport_per_container) ? String(old.cha_transport_per_container)
    : (isSet(row.cha_transport_per_container) ? trimAmount(row.cha_transport_per_container) : '');
  const incl = old.includes_packing_loading !== undefined ? String([].concat(old.includes_packing_loading).pop()) === '1' : Number(row.includes_packing_loading ?? 1) === 1;
  const prodCha = product.cha_transport_per_container;
  const amt = (v) => (isSet(v) ? `₹${thicknessPrices.amount(v)}` : '—');
  const sell = preview.selling;
  const ownOld = old.own && typeof old.own === 'object' ? old.own : {};
  res.renderView('products/edit_price', {
    product, row, grid, base, fromMm, from, backUrl: backUrl(row, from), saved,
    undoToken: undo ? undo.token : null,
    todayLine: `${base ? `${amt(base.inr)} ${base.label}` : 'no price yet'}${row.quoted_on ? `, quoted ${longDate(ymdOf(row.quoted_on))}` : ''}`,
    inactive: !supplierOffers.usable(row),
    unitLabel: (row.price_basis || '') === 'cbm' ? 'CBM' : (lineUnits.label(String(product.reference_unit || '')) || String(product.reference_unit || '') || 'unit'),
    fromUnit: fromMm === thicknessPrices.CBM ? 'CBM' : 'SQM',
    // D-364: a price per SQM may be typed per SQFT (saved as the SQM price).
    gridUnit: fromMm === thicknessPrices.CBM || (isSet(old.thickness_mm) && String(old.thickness_mm) === String(thicknessPrices.CBM)) ? 'CBM' : (['m2', 'SQFT'].includes(String(old.unit ?? '')) ? String(old.unit) : 'm2'),
    sqmUnit: !grid && supplierOffers.sqmPriced(product, false, null, String(row.price_basis || 'unit')) ? String(product.reference_unit) : null,
    oldUnit: String(old.unit ?? ''),
    thicknesses: grid ? supplierOffers.formThicknesses(rules, row).map((mm) => ({
      mm, label: thicknessPrices.label(mm, Number(rules.cbm_from)), unit: mm === thicknessPrices.CBM ? 'CBM' : 'SQM',
      selected: Number(isSet(old.thickness_mm) ? old.thickness_mm : fromMm) === mm, isBase: mm === fromMm, isRuleBase: mm === Number(rules.base_mm),
    })) : [],
    lines: preview.lines.map((l) => ({
      ...l, newText: isSet(l.price) ? `${amt(l.price)} / ${l.unit}` : '—', wasText: isSet(l.was) ? amt(l.was) : '—',
      placeholder: isSet(l.price) ? trimAmount(l.price) : '4500', own: isSet(ownOld[l.mm]) ? String(ownOld[l.mm]) : '',
    })),
    priceValue: isSet(old.price) ? String(old.price) : (base ? trimAmount(base.inr) : ''),
    wasLine: base ? `Was ${amt(base.inr)}` : '',
    quotedOn: isSet(old.quoted_on) ? String(old.quoted_on) : today, validUntil, cha, incl,
    chaPlaceholder: isSet(prodCha) ? `product default ${thicknessPrices.amount(prodCha)}` : 'e.g. 100000',
    notes: isSet(old.notes) ? String(old.notes) : String(row.notes || ''),
    moreOpen: cha !== '' || !incl || !!row.notes,
    canSeeSelling: can(req, 'view_price_buildup'),
    sellingText: sell && sell.applies && isSet(sell.min) ? {
      min: pricingService.numberFormat(sell.min, 2), quote: pricingService.numberFormat(sell.quote, 2),
      unit: lineUnits.label(String(sell.unit)) || String(sell.unit),
      wasMin: pricingService.numberFormat(sell.was_min, 2), wasQuote: pricingService.numberFormat(sell.was_quote, 2),
    } : null,
    sellingReason: sell && !(sell.applies && isSet(sell.min)) ? (sell.reason || '') : '',
    today,
  }, 'layout/base');
}

/** The preview as JSON while typing: other thicknesses (old and new) and the selling price. */
async function preview(req, res) {
  const [row, product] = await load(req);
  if (!row || !product) {
    res.status(404).json({ ok: false, error: 'Supplier price not found.' });
    return;
  }
  const q = req.query || {};
  const price = money(q.price) ?? 0;
  const mm = isSet(q.thickness_mm) && /^\d+$/.test(String(q.thickness_mm)) ? Number(q.thickness_mm) : null;
  const own = {};
  if (q.own && typeof q.own === 'object') {
    for (const [k, v] of Object.entries(q.own)) {
      const p = money(v);
      if (p !== null && p > 0 && /^\d+$/.test(k)) own[Number(k)] = p;
    }
  }
  // D-364: a price typed per SQFT is previewed as the SQM price it is saved as.
  const grid = (row.price_basis || '') === 'grid';
  const inp = supplierOffers.fromSqft({ price, unit: String(q.unit ?? ''), mm: mm ?? (grid ? thicknessPrices.baseOf(row, await thicknessPrices.rules(product)) : null) },
    product, grid, String(row.price_basis || 'unit'));
  const out = await supplierOffers.preview(product, row, mm, Number(inp.price), own);
  out.typed = isSet(inp.typed_sqft) ? supplierOffers.typedText(inp.typed_sqft) : null;
  if (!can(req, 'view_price_buildup')) out.selling = null; // Procurement sees supplier prices only, never selling prices.
  res.json(out);
}

/** Reads and checks the form (the same checks the browser does first): [input, error]. */
function read(body, rules, row, grid) {
  const price = money(body.price);
  if (price === null || !(price > 0)) return [{}, 'New price: enter the price in ₹ (above 0, at most 2 decimals).'];
  let fromMm = null;
  const own = {};
  if (grid) {
    const allowed = supplierOffers.formThicknesses(rules, row);
    const raw = String(body.thickness_mm ?? '').trim();
    fromMm = /^\d+$/.test(raw) ? Number(raw) : -1;
    if (!allowed.includes(fromMm)) return [{}, 'Choose the thickness of the price.'];
    const ownRaw = body.own && typeof body.own === 'object' ? body.own : {};
    for (const [k, v] of Object.entries(ownRaw)) {
      if (String(v ?? '').trim() === '' || !/^\d+$/.test(k) || Number(k) === fromMm || !allowed.includes(Number(k))) continue;
      const p = money(v);
      if (p === null || !(p > 0)) return [{}, `${thicknessPrices.label(Number(k), Number(rules.cbm_from))}: enter the price in ₹ (above 0, at most 2 decimals), or leave it empty to work it out.`];
      own[Number(k)] = p;
    }
  }
  // D-364: "per" — SQM or SQFT for a price per SQM (SQFT is saved as the SQM price), else the price's own unit.
  const unit = String(body.unit ?? '').trim();
  if (unit === supplierOffers.SQFT_INPUT && !supplierOffers.sqmPriced({ reference_unit: row._reference_unit ?? null }, grid, fromMm, String(row.price_basis || 'unit'))) {
    return [{}, 'Per: SQFT is only for a price per SQM — choose the unit the price is per.'];
  }
  const okDate = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) && ymdOf(new Date(`${v}T00:00:00`)) === v;
  const q = String(body.quoted_on ?? '').trim();
  if (!okDate(q)) return [{}, 'Quoted on: enter the date the supplier quoted this price.'];
  if (q > todayYmd()) return [{}, 'Quoted on: the date cannot be in the future.'];
  const v = String(body.valid_until ?? '').trim();
  if (v !== '') {
    if (!okDate(v)) return [{}, 'Valid until: enter a valid date, or leave it empty.'];
    if (v < q) return [{}, '"Valid until" cannot be before the quoted date.'];
  }
  const chaRaw = String(body.cha_transport_per_container ?? '').replace(/,/g, '').trim();
  if (chaRaw !== '' && !/^\d{1,10}(\.\d{1,2})?$/.test(chaRaw)) {
    return [{}, "CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty for the product's default."];
  }
  const notes = String(body.notes ?? '').trim();
  if ([...notes].length > 1000) return [{}, 'Notes: at most 1000 characters.'];
  // The hidden "0" and the ticked "1" arrive as a list when ticked (PHP keeps the last one).
  const incl = String([].concat(body.includes_packing_loading ?? '0').pop()) === '1';
  return [{
    price, unit, from_mm: fromMm, own, quoted_on: q, valid_until: v, cha: chaRaw !== '' ? Number(chaRaw) : null, includes_packing_loading: incl, notes,
  }, null];
}

async function save(req, res) {
  const [row, product] = await load(req);
  if (!row || !product) {
    flash.set(req, 'error', 'That supplier price no longer exists.');
    res.redirect('/products');
    return;
  }
  const id = Number(row.id);
  const from = fromOf(req);
  const self = selfUrl(id, from);
  const rules = await thicknessPrices.rules(product);
  const grid = (row.price_basis || '') === 'grid';
  const body = req.body || {};
  const [inp, error] = read(body, rules, { ...row, _reference_unit: product.reference_unit ?? null }, grid);
  if (error) {
    flash.set(req, 'error', error);
    flash.setOld(req, body);
    res.redirect(self);
    return;
  }
  const snap = await supplierOffers.snapshot(id);
  const [summary, saveError] = await supplierOffers.save(product, row, inp, req.user.id);
  if (saveError) {
    flash.set(req, 'error', saveError);
    flash.setOld(req, body);
    res.redirect(self);
    return;
  }
  req.session.edit_price_undo = { ...(req.session.edit_price_undo || {}), [id]: { token: crypto.randomBytes(12).toString('hex'), snap } };
  req.session.edit_price_msg = { ...(req.session.edit_price_msg || {}), [id]: summary };
  res.redirect(self);
}

async function undo(req, res) {
  const [row] = await load(req);
  const id = parseInt(req.params.supplierId, 10) || 0;
  const from = fromOf(req);
  const self = selfUrl(id, from);
  const all = req.session.edit_price_undo || {};
  const u = all[id] || null;
  const token = String((req.body && req.body.undo_token) || '');
  const same = u && token.length === String(u.token).length && crypto.timingSafeEqual(Buffer.from(token), Buffer.from(String(u.token)));
  if (!row || !same) {
    flash.set(req, 'error', 'There is nothing to undo here any more.');
    res.redirect(self);
    return;
  }
  const error = await supplierOffers.undo(u.snap, req.user.id);
  delete all[id];
  req.session.edit_price_undo = all;
  flash.set(req, error ? 'error' : 'success', error || 'Undone — the price is back as it was before your save. The undo is in the price history.');
  res.redirect(self);
}

module.exports = { form, preview, save, undo };
