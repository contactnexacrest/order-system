'use strict';

const db = require('../config/db');
const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const productRepository = require('../repositories/productRepository');
const supplierRepository = require('../repositories/supplierRepository');
const lineUnits = require('../services/lineUnits');
const pricingLevels = require('../services/pricingLevels');
const pricingService = require('../services/pricingService');
const productSpecs = require('../services/productSpecs');
const supplierOffers = require('../services/supplierOffers');
const thicknessPrices = require('../services/thicknessPrices');

/**
 * Port of App\Controllers\ProductConfigureController — owner decision D-361 (13 Nov 2026, "go with
 * recommendation"; mockups p4 and configure_three_levels_mockup.png): Products › Configure / Edit —
 * the price part of one product.
 *
 *  - Base price: default = the highest active supplier's base price; "See all supplier prices"; a
 *    warning below the lowest; it never changes by itself (owner decision 1) — when supplier prices
 *    change the screen says "Supplier prices changed: highest is now ₹X" with "Use ₹X".
 *  - Three levels: Global | Category | This product | Comes from, a reset per row, "Reset all to
 *    category values" and "Reset all to global values" with a confirm box listing the changes.
 *  - Active: only when the product is configured with at least one active supplier offer, or it is
 *    a product from before 13b with no supplier yet.
 *  Only users with manage_product_catalog (owner decision 2).
 *
 * Also (owner decisions 1 and 3): "Link a supplier" for a product with no supplier yet, and
 * Products › "Link these prices" for legacy supplier prices with no supplier record.
 */

/** The rows of the three-level table: key => [label, product column, decimals, min, max]. */
const ROWS = {
  margin_pct: ['Margin %', 'pricing_margin_pct', 2, 0, 89.99],
  other_cost: ['Other costs', 'pricing_other_cost_value', 4, 0, 100],
  buffer_pct: ['Buffer %', 'pricing_buffer_pct', 2, 0, 100],
  weight_t_per_cbm: ['Weight t / CBM', 'weight_t_per_cbm', 3, 0.1, 20],
  container_cbm: ['Container CBM', 'container_cbm', 2, 0.5, 100],
  container_max_t: ['Container max tonnes', 'container_max_t', 2, 1, 100],
  thick_k_factor: ['Thickness factor', 'thick_k_factor', 3, 1, 1000],
};

const isSet = (v) => v !== null && v !== undefined;
const fmt = (v) => (isSet(v) && v !== '' ? thicknessPrices.fmt(Number(v)) : '');
const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

async function loadProduct(req, res) {
  const p = await productRepository.find(parseInt(req.params.id, 10) || 0);
  if (!p) res.status(404).send('Product not found.');
  return p;
}

function bareOf(product) {
  const bare = { ...product };
  for (const c of pricingLevels.PRODUCT_OVERRIDES) bare[c] = null;
  return bare;
}

/** The base price's unit and thickness for a product that is not configured yet: [mm, unit]. */
async function defaultShape(product, lh, s) {
  if (supplierOffers.isConfigured(product)) {
    return [isSet(product.configured_base_thickness_mm) ? Number(product.configured_base_thickness_mm) : null, String(product.configured_base_unit || '')];
  }
  if (lh.highest) return [lh.highest.base.mm, String(lh.highest.base.unit)];
  const rules = await thicknessPrices.rules(product, s);
  if (rules.grid) return [Number(rules.base_mm), Number(rules.base_mm) === thicknessPrices.CBM ? 'CBM' : 'm2'];
  return [null, String(product.reference_unit || '') || 'unit'];
}

/** "₹ / CBM, 50 mm and above", "₹ / m², 20 mm", "₹ / Sq.Ft". */
async function shapeLabel(mm, unit, product, s) {
  if (mm !== null) {
    const rules = await thicknessPrices.rules(product, s);
    return `₹ / ${mm === thicknessPrices.CBM ? 'CBM' : 'SQM'}, ${thicknessPrices.label(mm, Number(rules.cbm_from))}`;
  }
  return `₹ / ${lineUnits.label(unit) || unit}`;
}

/**
 * D-363 (13 Nov 2026, mockup ui3_u4): Configure › Thickness rules — per thickness the category's
 * % next to this product's own (Thickness | Category | This product | Comes from | reset). Saved
 * with the product's own Price options save (specController.saveProductSpec, from=configure),
 * which writes only this product's overrides — never the category or another product.
 * Same as ProductConfigureController::thicknessRules (PHP).
 */
async function thicknessRules(product, s, perms) {
  const rules = await thicknessPrices.rules(product, s);
  const cbmFrom = Number(rules.cbm_from);
  const pct = (v) => (v === null || v === undefined ? '—' : (Math.abs(v) < 1e-9 ? '0 %' : `${v < 0 ? '−' : '+'}${thicknessPrices.fmt(Math.abs(v))} %`));
  const specs = [];
  for (const sp of await productSpecs.tree(product, true)) {
    if (sp.kind !== 'thickness' || sp.hidden) continue;
    const rows = [];
    const keep = [];
    for (const o of sp.options) {
      const isRow = !o.hidden && o.thickness_mm !== null && o.thickness_mm < cbmFrom && ['none', 'pct'].includes(o.change_type);
      if (!isRow) {
        // Not shown: posted back unchanged (offered or not, and its own value).
        keep.push({ id: o.id, offered: !o.hidden, value: o.source === 'changed' && o.value !== null ? thicknessPrices.fmt(o.value) : '' });
        continue;
      }
      const base = o.is_base || o.change_type === 'none';
      rows.push({
        id: o.id, mm: Number(o.thickness_mm), label: thicknessPrices.label(Number(o.thickness_mm), cbmFrom), base,
        category: o.source === 'product' ? '—' : (base ? 'base' : pct(o.category_value)),
        category_value: o.category_value !== null ? thicknessPrices.fmt(o.category_value) : '',
        value: o.value !== null ? thicknessPrices.fmt(o.value) : '',
        value_label: base ? 'base' : pct(o.value),
        from: o.source === 'category' ? 'category' : 'product', editable: o.source !== 'product' && !base,
      });
    }
    rows.sort((a, b) => a.mm - b.mm);
    specs.push({ id: sp.id, name: sp.name, editable: sp.source === 'category', rows, keep, changed: rows.filter((r) => r.editable && r.from === 'product') });
  }
  return {
    specs, k: rules.k !== null && rules.k !== undefined ? thicknessPrices.fmt(rules.k) : null,
    k_own: isSet(product.thick_k_factor), cbm_label: thicknessPrices.label(thicknessPrices.CBM, cbmFrom),
    can_edit: !!(perms || {}).manage_product_specifications,
  };
}

async function form(req, res) {
  const product = await loadProduct(req, res);
  if (!product) return;
  const s = await pricingService.settings();
  const catId = await productSpecs.categoryIdFor(product);
  const category = catId ? await require('../repositories/productCategoryRepository').find(catId) : null;
  const now = await pricingLevels.resolveFor(product, s);
  const inherit = await pricingLevels.resolveFor(bareOf(product), s);
  const rows = [];
  for (const [key, [label, col, dec]] of Object.entries(ROWS)) {
    const isOther = key === 'other_cost';
    const g = isOther ? now[key].global.value : now[key].global;
    const catVal = category ? (isOther ? category.other_cost_pct : category[key]) : null;
    const own = isOther ? isSet(product.pricing_other_cost_mode) : isSet(product[col]);
    const inh = isOther ? inherit[key].value.value : inherit[key].value;
    const value = isOther ? now[key].value.value : now[key].value;
    rows.push({
      key, label, global: fmt(g), category: fmt(catVal), value: fmt(value), inherit: fmt(inh), inherit_from: inherit[key].from,
      own, from: now[key].from, mode: isOther ? String(now[key].value.mode) : null, decimals: dec,
    });
  }
  const lh = await supplierOffers.lowestHighest(product, s);
  const [mm, unit] = await defaultShape(product, lh, s);
  const configured = supplierOffers.isConfigured(product);
  const old = flash.pullOld(req);
  const offers = [];
  for (const o of await supplierOffers.offers(Number(product.id))) {
    const b = await supplierOffers.offerBase(product, o, null, s);
    const day = (v) => (v ? (v instanceof Date ? `${v.getFullYear()}-${String(v.getMonth() + 1).padStart(2, '0')}-${String(v.getDate()).padStart(2, '0')}` : String(v).slice(0, 10)) : '—');
    offers.push({
      id: Number(o.id), supplier: String(o.supplier_name), usable: !!o.usable, linked: isSet(o.supplier_id),
      price: b ? `₹${thicknessPrices.amount(b.inr)} ${b.label}` : '—', quoted_on: day(o.quoted_on), valid_until: day(o.valid_until),
      editable: (o.fob_source || 'direct') === 'direct' && (o.price_basis || '') !== 'combo',
    });
  }
  const amt = (v) => `₹${thicknessPrices.amount(v)}`;
  res.renderView('products/configure', {
    product, category, rows, followGlobal: Number(product.pricing_follow_global) === 1,
    configured, status: await supplierOffers.status(product),
    basePrice: isSet(old.base_price) ? String(old.base_price) : (configured ? Number(product.configured_base_price_inr).toFixed(2) : (lh.highest ? Number(lh.highest.base.inr).toFixed(2) : '')),
    baseMm: mm, baseUnit: unit, baseLabel: await shapeLabel(mm, unit, product, s),
    lowest: lh.lowest ? { ...lh.lowest, amount: amt(lh.lowest.base.inr) } : null,
    highest: lh.highest ? { ...lh.highest, amount: amt(lh.highest.base.inr) } : null,
    offers,
    change: await (async () => { const c = await supplierOffers.priceChange(product, lh); return c ? { ...c, amount: amt(c.inr) } : null; })(),
    previewCategory: figureChanges(await pricingLevels.resetPreview(product, 'category', s)),
    previewGlobal: figureChanges(await pricingLevels.resetPreview(product, 'global', s)),
    canEditPrice: !!(req.permissions || {}).record_market_prices,
    thickness: await thicknessRules(product, s, req.permissions),
    oldLevel: old.level && typeof old.level === 'object' ? old.level : null,
  }, 'layout/base');
}

/**
 * The confirm box lists the values whose figure changes; values that only change where they come
 * from (same figure) are named in one line.
 */
function figureChanges(lines) {
  return { changes: lines.filter((l) => l.now !== l.after), same: lines.filter((l) => l.now === l.after).map((l) => l.label) };
}

/** "190,000.00" / "190000" → 190000; null when not a ₹ amount above 0 with at most 2 decimals. */
function money(v) {
  const t = String(v ?? '').replace(/[, ]/g, '').trim();
  return /^\d{1,12}(\.\d{1,2})?$/.test(t) && Number(t) > 0 ? Number(t) : null;
}

async function save(req, res) {
  const product = await loadProduct(req, res);
  if (!product) return;
  const id = Number(product.id);
  const back = `/products/${id}/configure`;
  const body = req.body || {};
  const userId = req.user.id;
  const s = await pricingService.settings();
  const fail = (msg) => {
    flash.set(req, 'error', msg);
    flash.setOld(req, body);
    res.redirect(back);
  };
  const price = money(body.base_price);
  if (price === null) return fail('Base price: enter the price in ₹ (above 0, at most 2 decimals), e.g. 190000.');
  const lh = await supplierOffers.lowestHighest(product, s);
  const [mm, unit] = await defaultShape(product, lh, s);
  // The values of the three-level table: a value equal to the inherited one is not the product's own.
  const inherit = await pricingLevels.resolveFor(bareOf(product), s);
  const vals = body.level && typeof body.level === 'object' ? body.level : {};
  const set = {};
  for (const [key, [label, col, dec, min, max]] of Object.entries(ROWS)) {
    const raw = String(vals[key] ?? '').trim();
    const mode = key === 'other_cost' ? (String(body.other_cost_mode || 'pct') === 'usd' ? 'usd' : 'pct') : null;
    const inh = key === 'other_cost' ? Number(inherit[key].value.value) : Number(inherit[key].value);
    let next = null;
    if (raw !== '') {
      const maxV = mode === 'usd' ? 1000000 : max;
      if (!new RegExp(`^\\d{1,7}(\\.\\d{1,${dec}})?$`).test(raw) || Number(raw) < min || Number(raw) > maxV) {
        return fail(`${label}: enter a number from ${thicknessPrices.fmt(min)} to ${thicknessPrices.fmt(maxV)} with at most ${dec} decimals, or reset it.`);
      }
      next = (mode === null || mode === 'pct') && Math.abs(Number(raw) - inh) < 1e-9 ? null : Number(raw);
    }
    if (key === 'other_cost') {
      set.pricing_other_cost_mode = next === null ? null : mode;
      set.pricing_other_cost_value = next === null ? null : next.toFixed(4);
    } else {
      set[col] = next === null ? null : next.toFixed(dec);
    }
  }
  const wantActive = !!body.is_active && String([].concat(body.is_active).pop()) === '1';
  let changes = 0;
  if (!supplierOffers.isConfigured(product) || Math.abs(Number(product.configured_base_price_inr) - price) > 0.004
    || String(product.configured_base_thickness_mm ?? '') !== String(mm ?? '') || String(product.configured_base_unit || '') !== unit) {
    await supplierOffers.setConfiguredBase(id, price, mm, unit, userId);
    changes++;
  }
  for (const [col, v] of Object.entries(set)) {
    const old = isSet(product[col]) ? String(product[col]) : null;
    if (old === v || (old !== null && v !== null && !Number.isNaN(Number(old)) && !Number.isNaN(Number(v)) && Math.abs(Number(old) - Number(v)) < 1e-9)) continue;
    await db.execute(`UPDATE catalog_products SET ${col} = :v, updated_by = :u WHERE id = :id`, { v, u: userId, id });
    await auditLogRepository.log(userId, 'PRODUCT_PRICING_CHANGED', 'products', id, col, old ?? 'category / global', v ?? 'category / global');
    changes++;
  }
  const fresh = (await productRepository.find(id)) || product;
  const st = await supplierOffers.status(fresh);
  let note = '';
  let refused = false;
  if (wantActive !== (Number(fresh.is_active) === 1)) {
    if (wantActive && !st.can_activate) {
      note = ` It stays Inactive: ${st.reason}`;
      refused = true;
    } else {
      await productRepository.setActive(id, wantActive, userId);
      await auditLogRepository.log(userId, wantActive ? 'PRODUCT_REACTIVATED' : 'PRODUCT_DEACTIVATED', 'products', id, 'is_active', wantActive ? '0' : '1', wantActive ? '1' : '0');
      note = wantActive ? ' The product is Active — it can be picked on order lines.' : ' The product is Inactive — it cannot be picked on new order lines.';
      changes++;
    }
  }
  flash.set(req, refused ? 'error' : 'success', (changes === 0 ? 'Nothing changed.'
    : `Saved: ${product.name} — base price ₹${thicknessPrices.amount(price)} ${await supplierOffers.baseLabel(fresh, s)}. Quotations and Proforma Invoices already issued keep their prices.`) + note);
  res.redirect(back);
}

/** "Reset all to category values" / "Reset all to global values" (after the confirm box). */
async function reset(req, res) {
  const product = await loadProduct(req, res);
  if (!product) return;
  const to = String((req.body || {}).to || '') === 'global' ? 'global' : 'category';
  const lines = figureChanges(await pricingLevels.resetPreview(product, to)).changes;
  if (to === 'global') await pricingLevels.resetToGlobal(Number(product.id), req.user.id);
  else await pricingLevels.resetToCategory(Number(product.id), req.user.id);
  flash.set(req, 'success', (to === 'global' ? 'Reset to the global values' : 'Reset to the category values') + (lines.length === 0 ? ' — nothing changed in the figures.'
    : `: ${lines.map((l) => `${l.label} ${l.now} → ${l.after}`).join(' · ')}.`));
  res.redirect(`/products/${Number(product.id)}/configure`);
}

/** "Use ₹X": the base price becomes the highest active supplier price now. */
async function usePrice(req, res) {
  const product = await loadProduct(req, res);
  if (!product) return;
  const change = await supplierOffers.priceChange(product);
  if (!change) {
    flash.set(req, 'success', 'The base price already matches the supplier prices — nothing changed.');
  } else {
    await supplierOffers.setConfiguredBase(Number(product.id), change.inr, change.mm, change.unit, req.user.id);
    flash.set(req, 'success', `Base price now ₹${thicknessPrices.amount(change.inr)} ${change.label} (${change.supplier}). Quotations and Proforma Invoices already issued keep their prices.`);
  }
  res.redirect(`/products/${Number(product.id)}/configure`);
}

// ------------------------------------------------------------------ "Link a supplier" (decision 1, part A)

async function supplierTypes() {
  return (await require('../repositories/lookupRepository').dropdownOptions('supplier_type')).map((o) => String(o.option_value));
}

async function linkSupplierForm(req, res) {
  const product = await loadProduct(req, res);
  if (!product) return;
  const s = await pricingService.settings();
  const old = flash.pullOld(req);
  const configured = supplierOffers.isConfigured(product);
  res.renderView('products/link_supplier', {
    product, shape: await supplierOffers.priceShape(product, s),
    pickList: await supplierRepository.pickList(), supplierTypes: await supplierTypes(),
    picked: Number(old.supplier_id || req.query.supplier_id || 0),
    configured, baseText: configured ? `₹${thicknessPrices.amount(product.configured_base_price_inr)} ${await supplierOffers.baseLabel(product, s)}` : null,
    old, today: today(),
  }, 'layout/base');
}

async function linkSupplier(req, res) {
  const product = await loadProduct(req, res);
  if (!product) return;
  const id = Number(product.id);
  const back = `/products/${id}/link-supplier`;
  const body = req.body || {};
  const supplier = await supplierRepository.find(parseInt(body.supplier_id, 10) || 0);
  const shape = await supplierOffers.priceShape(product);
  const price = money(body.price);
  const q = String(body.quoted_on || '').trim();
  const v = String(body.valid_until || '').trim();
  const mmRaw = String(body.mm || '').trim();
  let unit = String(body.unit || '').trim();
  const dateRe = /^\d{4}-\d{2}-\d{2}$/;
  let error = null;
  if (!supplier || !Number(supplier.is_active)) error = 'Supplier: choose an active supplier from the list (or add one).';
  else if (await supplierOffers.existingOffer(Number(supplier.id), id)) error = `${supplier.supplier_legal_name} already has a price for this product — change it with Edit price.`;
  else if (price === null) error = 'Their price: enter the price in ₹ (above 0, at most 2 decimals).';
  else if (!dateRe.test(q) || q > today()) error = 'Quoted on: the date the supplier gave the price (today or earlier).';
  else if (v !== '' && (!dateRe.test(v) || v < q)) error = 'Valid until: a date on or after the quoted date, or leave it empty.';
  let mm = null;
  if (error === null && shape.grid) {
    mm = /^\d+$/.test(mmRaw) ? Number(mmRaw) : -1;
    if (!shape.thicknesses.map((t) => Number(t.mm)).includes(mm)) error = 'For: choose the thickness the price is for.';
    // D-364: per SQM or SQFT (saved as the SQM price) below "50 mm and above", per CBM from it.
    const want = mm === thicknessPrices.CBM ? ['CBM'] : ['m2', supplierOffers.SQFT_INPUT];
    if (error === null && !want.includes(unit)) {
      unit = unit === '' ? want[0] : unit;
      if (!want.includes(unit)) error = `Per: a price for ${thicknessPrices.label(mm)} is per ${mm === thicknessPrices.CBM ? 'CBM' : 'SQM or SQFT'}.`;
    }
  } else if (error === null && !shape.units.map((u) => u.value).includes(unit)) {
    error = 'Per: choose the unit the price is for.';
  }
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, body);
    res.redirect(back);
    return;
  }
  try {
    await supplierOffers.addOffer(supplier, product, { price, mm, unit, quoted_on: q, valid_until: v }, req.user.id);
  } catch (e) {
    flash.set(req, 'error', e.message);
    flash.setOld(req, body);
    res.redirect(back);
    return;
  }
  const fresh = (await productRepository.find(id)) || product;
  const st = await supplierOffers.status(fresh);
  flash.set(req, 'success', `Linked. ${product.name} now has ${st.usable} active supplier${st.usable === 1 ? '' : 's'} (${supplier.supplier_legal_name}, `
    + `${unit === supplierOffers.SQFT_INPUT && supplierOffers.sqmPriced(product, !!shape.grid, mm) ? supplierOffers.typedText(price) : `₹${thicknessPrices.amount(price)}`}).`
    + ' The red "No supplier yet" badge is gone; from now on the normal rule applies — if every supplier is switched off, the product is not offered on new orders.'
    + (supplierOffers.isConfigured(fresh) ? ' The configured base price stays as it is.' : ' Configure the product to set its selling price.'));
  res.redirect('/products?status=all');
}

// ------------------------------------------------------------------ "Link these prices" (decision 3)

async function linkPricesForm(req, res) {
  res.renderView('products/link_prices', {
    rows: await supplierOffers.unlinkedPrices(),
    pickList: await supplierRepository.pickList(),
    supplierTypes: await supplierTypes(),
    picked: Number(req.query.supplier_id || 0),
  }, 'layout/base');
}

async function linkPrices(req, res) {
  const body = req.body || {};
  const error = await supplierOffers.linkPrice(parseInt(body.offer_id, 10) || 0, parseInt(body.supplier_id, 10) || 0, req.user.id);
  const left = await supplierOffers.unlinkedCount();
  flash.set(req, error === null ? 'success' : 'error', error ?? `Linked.${left > 0 ? ` ${left} still to link.` : ' Every supplier price is now linked to a supplier.'}`);
  res.redirect(left > 0 || error !== null ? '/products/link-prices' : '/products?status=all');
}

module.exports = { form, save, reset, usePrice, linkSupplierForm, linkSupplier, linkPricesForm, linkPrices };
