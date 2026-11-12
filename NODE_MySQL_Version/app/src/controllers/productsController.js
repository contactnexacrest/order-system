'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const env = require('../config/env');
const flash = require('../helpers/flash');
const sniffMime = require('../helpers/sniffMime');
const auditLogRepository = require('../repositories/auditLogRepository');
const productRepository = require('../repositories/productRepository');
const productImageRepository = require('../repositories/productImageRepository');
const productSupplierRepository = require('../repositories/productSupplierRepository');
const productMiscChargeRepository = require('../repositories/productMiscChargeRepository');
const productService = require('../services/productService');
const pricingService = require('../services/pricingService');
const pricingRepository = require('../repositories/pricingRepository');

/**
 * Port of App\Controllers\ProductController — the product catalogue
 * (docs/schema.sql Section U). Products are picked on order lines and used
 * for price checks; prices here are internal and never shown to buyers. A
 * used product is deactivated, never deleted (owner, 12 Nov 2026 — see remove()).
 *
 * Two visibility boundaries are enforced SERVER-SIDE (not just hidden in
 * the view), because both are real information-disclosure boundaries:
 *  - browse_product_catalog: without it, a user with only
 *    view_product_catalog gets a search box and no full-list query ever
 *    runs for them (see index()).
 *  - view_product_pricing: without it, pricing/supplier/misc-charge data
 *    is stripped out of the objects passed to the view, not merely hidden
 *    with CSS (see index() and show()).
 */

const ALLOWED_MIME = { 'image/png': 'png', 'image/jpeg': 'jpg' };
const MAX_BYTES = 5 * 1024 * 1024; // 5MB

/**
 * Owner decision D-245: [sees the full build-up, sees only the quote and lowest price].
 * The build-up needs view_price_buildup; the two prices need the selling-price money class.
 */
function pricingPerms(req) {
  const p = req.permissions || {};
  const full = !!p.view_price_buildup;
  return { canBuildup: full, canStaffPrice: !full && !!p.view_selling_prices };
}

const trimOverride = (v) => (v === null || v === undefined ? '' : String(v).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''));

function perms(req) {
  const p = req.permissions || {};
  return {
    canBrowse: !!p.browse_product_catalog,
    // Owner points 6/15: prices only for people who may also see selling prices.
    canViewPricing: !!p.view_product_pricing && !!p.view_selling_prices,
    canManage: !!p.manage_product_catalog,
  };
}

async function index(req, res) {
  const { canBrowse, canViewPricing, canManage } = perms(req);

  const query = String(req.query.q || '').trim();
  const searched = query !== '';
  // Owner (12 Nov 2026): "Active (n) · Inactive (n) · All (n)" — on the full list and on a search.
  const status = productRepository.statusFilter(req.query.status || 'active');
  let statusCounts = null;

  let products;
  if (searched) {
    products = await productRepository.search(query, status);
    statusCounts = await productRepository.statusCounts(query);
  } else if (canBrowse) {
    products = await productRepository.listing(status);
    statusCounts = await productRepository.statusCounts();
  } else {
    // Search-only user with no query submitted yet — no full-list query
    // executes at all. This is the actual enforcement point; everything
    // else here is just what gets rendered.
    products = [];
  }
  // An inactive product says where it is used, so it is clear whether it may be deleted.
  for (const product of products) {
    if (!Number(product.is_active)) {
      product.usage = await productRepository.usage(product.id);
      product.usage_text = productRepository.usageText(product.usage);
      product.delete_refusal = productRepository.deleteRefusal(product, product.usage);
    }
  }

  // Owner decision D-245 (4 Nov 2026): the price build-up for MD/ED; the quote price and
  // lowest price for anyone who may see selling prices. Worked out before anything is stripped.
  const { canBuildup, canStaffPrice } = pricingPerms(req);
  if (canBuildup || canStaffPrice) {
    const s = await pricingService.settings();
    const program = await pricingService.programOn();
    const levels = await pricingRepository.levels(true);
    for (const product of products) {
      const pr = await pricingService.forProduct(product, levels, s, program);
      product.pricing = canBuildup ? pr : { build: pr.build === null ? null : { staff_quote: pr.build.staff_quote, staff_floor: pr.build.staff_floor } };
      // D-256: products with something to fix before they can be priced are flagged.
      product.ready_to_fix = canBuildup ? (await pricingService.readiness(product, s)).to_fix : 0;
    }
  }

  const canSeeHeadlineFob = canViewPricing && !!(req.permissions || {}).view_purchase_prices;
  if (canSeeHeadlineFob) {
    for (const product of products) {
      const suppliers = await productSupplierRepository.forProduct(product.id);
      let primary = null;
      for (const supplier of suppliers) {
        if (supplier.is_primary) {
          primary = supplier;
          break;
        }
      }
      primary = primary ?? (suppliers[0] ?? null);
      // D-304 (point 14): "Supplier price ₹ / unit" — the used-for-pricing price in the product's unit
      // (D-300: a price for every thickness shows its base thickness).
      const parts = primary ? await pricingService.fobParts(product, primary, (primary.price_basis || '') === 'cbm' ? 'cbm' : 'unit', null) : null;
      product.headline_fob = parts ? parts.supplier : null;
      product.headline_basis = primary === null ? null : ((primary.price_basis || '') === 'grid'
        ? (parts && parts.mm !== null && parts.mm !== undefined ? `${require('../services/thicknessPrices').fmt(parts.mm)} mm base` : 'base')
        : ((primary.price_basis || '') === 'cbm' ? 'per CBM' : (Number(primary.is_primary) === 1 ? 'used for pricing' : 'latest price')));
    }
  } else {
    // Strip pricing data out of the response entirely — not just hidden in
    // the view — for roles without view_product_pricing.
    for (const product of products) {
      delete product.default_factory_cost;
      delete product.default_transportation_cost;
      delete product.default_packing_cost;
      delete product.default_loading_cost;
      delete product.default_cha_cost;
    }
  }

  res.renderView('products/index', {
    products,
    query,
    searched,
    status,
    statusCounts,
    canBrowse,
    canViewPricing: canSeeHeadlineFob,
    canManage,
    canBuildup,
    canStaffPrice,
    // D-304 (point 14): the rate line (build-up viewers) and "≈ USD" under the supplier price (product editors).
    fx: canBuildup || (canSeeHeadlineFob && canManage) ? await require('../services/fxRateService').current() : null,
    canFixHs: canManage,
    canLinkSuppliers: !!(req.permissions || {}).record_market_prices || !!(req.permissions || {}).manage_suppliers,
    canPriceRules: canBuildup || !!(req.permissions || {}).manage_pricing,
    canPricingSettings: canBuildup || !!(req.permissions || {}).manage_pricing,
    ...catalogueUnits(products),
  }, 'layout/base');
}

/** D-304: one unit in the column headers when every row shares it, else "unit" and a unit per row. */
function catalogueUnits(products) {
  const units = [...new Set(products.map((p) => String(p.reference_unit || '') || 'unit'))];
  return { headerUnit: units.length === 1 ? units[0] : 'unit', unitPerRow: units.length > 1 };
}

/**
 * D-302 (point 11): the HS code is picked from the HS Code Master List, pre-filled with the
 * category's default; people with manage_hs_codes may add a code there. D-300: the product's
 * CHA + transport default and its own price rules.
 */
async function formExtras(req, product) {
  const productSpecs = require('../services/productSpecs');
  const specRepository = require('../repositories/specRepository');
  const categoryId = product ? await productSpecs.categoryIdFor(product) : await specRepository.systemCategoryId();
  const category = categoryId ? await require('../repositories/productCategoryRepository').find(categoryId) : null;
  const p = req.permissions || {};
  return {
    hsCodes: await require('../repositories/hsCodeRepository').active(),
    categoryDefaultHs: String((category && category.default_hs_code) || ''),
    categoryName: String((category && category.name) || ''),
    categoryId: categoryId || null,
    canManageHs: !!p.manage_hs_codes,
    canManagePricing: !!p.manage_pricing,
    categoryRules: category || {},
    // D-335: "Costs to FOB" and the price rules in effect for this product (category's, or its own).
    fobForm: await fobFormView(product, categoryId),
  };
}

/** D-335: what "Costs to FOB" and "Price rules in effect for this product" show (same as PHP _form.php). */
async function fobFormView(product, categoryId) {
  const tp = require('../services/thicknessPrices');
  const pr = await tp.rules(product || { id: 0, category_id: categoryId });
  const has = (k) => !!product && product[k] !== null && product[k] !== undefined;
  const chaV = has('cha_transport_per_container') ? Number(product.cha_transport_per_container) : null;
  const fit = tp.chaPerCbm(chaV, Number(pr.container_cbm), pr.container_max_t, pr.t_per_cbm);
  const fmt2 = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const rows = [];
  for (const r of pr.rows) {
    if (Number(r.mm) === Number(pr.base_mm)) continue;
    rows.push({ rule: `${r.mm} mm`, value: `${pr.base_mm} mm ${r.pct < 0 ? '−' : '+'} ${tp.fmt(Math.abs(r.pct))} %`, own: pr.own.includes(r.option) });
  }
  if (pr.k !== null && pr.k !== undefined) rows.push({ rule: `${pr.cbm_from} mm and above, per CBM`, value: `${pr.base_mm} mm price per m² × ${tp.fmt(pr.k)}`, own: has('thick_k_factor') });
  rows.push({ rule: 'Container', value: `${tp.fmt(pr.container_cbm)} CBM · max ${tp.fmt(pr.container_max_t)} t`, own: has('container_cbm') || has('container_max_t') });
  return {
    chaHint: `÷ ${tp.fmt(Math.round(fit.fits_cbm * 100) / 100)} CBM per container${fit.per_cbm !== null ? ` = ₹${fmt2(fit.per_cbm)} per CBM` : ''}${fit.by_weight ? ' (the weight limit is reached first)' : ''}`,
    packV: has('default_packing_cost') || has('default_loading_cost') ? (Number(product.default_packing_cost || 0) + Number(product.default_loading_cost || 0)).toFixed(2) : '',
    rows, hasOwn: has('thick_k_factor') || has('container_cbm') || has('container_max_t'),
    cbmFrom: pr.cbm_from, baseMm: pr.base_mm,
  };
}

/** D-300: the CHA + transport default (product editors) and the product's own rules (MD only): [fields, error]. */
function readPriceRuleFields(req) {
  const body = req.body || {};
  const out = {};
  let spec = { cha_transport_per_container: ['CHA + transport per container (₹)', 0, 9999999999, 2] };
  // D-335: "Use own rules for this product" switched off = the category's rules (own values cleared).
  if ((req.permissions || {}).manage_pricing && body.own_rules_shown && !body.own_rules) {
    Object.assign(out, { thick_k_factor: null, container_cbm: null, container_max_t: null });
    spec = { cha_transport_per_container: spec.cha_transport_per_container };
  } else if ((req.permissions || {}).manage_pricing) {
    Object.assign(spec, { thick_k_factor: ['50 mm and above: 20 mm price per m² ×', 1, 1000, 3], container_cbm: ['Container holds (CBM)', 0.5, 100, 2],
      container_max_t: ['Container maximum (tonnes)', 1, 100, 2] });
  }
  const packing = String(body.packing_loading_cost ?? '').trim();
  if (packing !== '' && !/^\d{1,10}(\.\d{1,2})?$/.test(packing)) {
    return [{}, 'Packing + loading per unit (₹): enter a number with at most 2 decimals, or leave it empty.'];
  }
  for (const [key, [lbl, min, max, dec]] of Object.entries(spec)) {
    if (!Object.prototype.hasOwnProperty.call(body, key)) continue;
    const v = String(body[key]).trim();
    if (v === '') {
      out[key] = null;
      continue;
    }
    if (!new RegExp(`^\\d{1,10}(\\.\\d{1,${dec}})?$`).test(v) || Number(v) < min || Number(v) > max) {
      return [{}, `${lbl}: enter a number from ${min} to ${max} with at most ${dec} decimals, or leave it empty for the category's value.`];
    }
    out[key] = Number(v).toFixed(dec);
  }
  return [out, null];
}

/** D-302: only a code from the HS Code Master List — so an order line can always use it. */
async function hsMasterError(req, code) {
  if (code === '' || (await require('../repositories/hsCodeRepository').isActiveCode(code))) return null;
  return `HS code "${code}" is not on the HS Code Master List — pick one from the list${(req.permissions || {}).manage_hs_codes ? ', or add it with "+ Add a new HS code".' : ' (ask someone who manages HS codes to add it).'}`;
}

async function create(req, res) {
  res.renderView('products/create', {
    product: null,
    referenceUnits: await require('../repositories/lookupRepository').dropdownOptions('unit'),
    ...(await formExtras(req, null)),
  }, 'layout/base');
}

async function store(req, res) {
  const data = readProductForm(req);
  const [ruleFields, ruleError] = readPriceRuleFields(req);
  const error = validateProduct(data) || (await hsMasterError(req, data.hs_code)) || (await applySellingUnit(req, data)) || ruleError;
  if (error) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body || {});
    res.redirect('/products/create');
    return;
  }

  const user = req.user;
  const id = await productRepository.create(data, user.id);
  await productRepository.setPriceRuleFields(id, ruleFields);
  await auditLogRepository.log(user.id, 'PRODUCT_CREATED', 'products', id, null, null, data.name);
  flash.set(req, 'success', 'Product added to the catalog.');
  res.redirect(`/products/${id}`);
}

async function show(req, res) {
  const { canViewPricing, canManage } = perms(req);

  const id = parseInt(req.params.id, 10) || 0;
  const details = await productService.getProductWithDetails(id);
  if (!details.product) {
    res.status(404).send('Product not found.');
    return;
  }

  // Owner decision D-245 (4 Nov 2026): see index().
  const { canBuildup, canStaffPrice } = pricingPerms(req);
  let pricingFull = null;
  let pricingStaff = null;
  if (canBuildup || canStaffPrice) {
    const pr = await pricingService.forProduct(details.product);
    if (canBuildup) pricingFull = pr;
    else pricingStaff = { build: pr.build === null ? null : { staff_quote: pr.build.staff_quote, staff_floor: pr.build.staff_floor } };
  }
  // Supplier prices are the cost the selling price is built from — only people who may see
  // purchase prices get the supplier list and misc charges.
  const canSeeSuppliers = canViewPricing && !!(req.permissions || {}).view_purchase_prices;
  if (!canSeeSuppliers) {
    details.suppliers = [];
    details.misc_charges = [];
  }
  if (!canViewPricing) {
    delete details.product.default_factory_cost;
    delete details.product.default_transportation_cost;
    delete details.product.default_packing_cost;
    delete details.product.default_loading_cost;
    delete details.product.default_cha_cost;
    details.suppliers = [];
    details.misc_charges = [];
  }

  // Owner decision D-256 (5 Nov 2026): "Pricing ready?" (Procurement sees only the
  // supplier-price lines) and "Try a price" (people who may see the build-up).
  const perm = req.permissions || {};
  const canTry = canBuildup || !!perm.manage_pricing;
  let readiness = null;
  if (canTry || perm.record_market_prices) {
    readiness = await pricingService.readiness(details.product);
    if (!canTry) {
      readiness.items = readiness.items.filter((i) => i.supplier);
      readiness.to_fix = readiness.items.filter((i) => !i.ok).length;
    }
  }
  let tryPrice = null;
  if (canTry) {
    const productSpecs = require('../services/productSpecs');
    const offered = await productSpecs.offered(details.product);
    // try[specId]=optionId, read from the raw query string: Express's qs would squash
    // numeric keys such as try[3] into a gap-less list and lose which specification they belong to.
    const sel = {};
    const rawQuery = String(req.originalUrl || '').split('?')[1] || '';
    for (const [k, v] of new URLSearchParams(rawQuery)) {
      const m = /^try\[(\d+)\]$/.exec(k);
      if (m) sel[m[1]] = String(v);
    }
    // The Thickness select starts on "Supplier's base price": no thickness chosen, so the
    // supplier row's own BASE is priced (D-335) — the same figure as the product list.
    const thickSpec = offered.find((sp) => sp.kind === 'thickness');
    const thick = thickSpec ? Number(thickSpec.id) : null;
    let baseLabel = null;
    if (thick !== null) {
      if ((sel[thick] ?? '') === '') sel[thick] = productSpecs.SUPPLIER_BASE;
      const ps = await pricingService.pricingSupplier(Number(details.product.id));
      if (ps !== null && (ps.price_basis || '') === 'grid') {
        const thicknessPrices = require('../services/thicknessPrices');
        const r = await thicknessPrices.rules(details.product);
        baseLabel = thicknessPrices.label(thicknessPrices.baseOf(ps, r), Number(r.cbm_from));
      }
    }
    const baseOption = `Supplier's base price${baseLabel !== null ? ` (${baseLabel})` : ''}`;
    const p = offered.length ? await pricingService.forProduct(details.product, null, null, null, sel) : null;
    if (p !== null) {
      const map = p.spec ? p.spec.map : {};
      const specs = offered.map((sp) => ({
        id: sp.id, name: sp.name,
        baseOption: Number(sp.id) === thick ? baseOption : null,
        baseSelected: map[sp.id] === undefined,
        options: sp.options.map((o) => ({ id: o.id, name: o.name, selected: map[sp.id] !== undefined && Number(map[sp.id]) === Number(o.id),
          change: o.change_type === 'none' ? 'base' : productSpecs.changeLabel(String(o.change_type), o.value !== null ? Number(o.value) : null) })),
      }));
      let opts = [];
      let charges = [];
      let basis = 'unit';
      if (p.build !== null) {
        const adj = p.spec.adj;
        basis = adj.basis;
        charges = adj.charges;
        opts = adj.lines.map((l) => `${l.option} ${l.kind === 'exact' ? '(in the exact price)' : l.kind === 'included' ? '(included in base price)'
          : l.kind === 'pct' ? productSpecs.changeLabel('pct', Number(l.value)) : `+USD ${Number(l.usd_effect).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${l.converted ? ' per CBM' : ''}`}`);
      }
      tryPrice = { specs, p, opts, charges, basis, otherLabel: pricingService.modeLabel(p.effective.other_mode, p.effective.other_value),
        marginLabel: pricingService.pct(p.effective.margin_pct), bufferLabel: pricingService.pct(p.effective.buffer_pct) };
    }
  }

  // Owner decision D-339: "Prices in other units" (read-only, nothing stored) — the supplier price for
  // people who may see purchase prices, the staff quote and floor for people who see selling prices,
  // every associate level for people who see the price build-up (MD / ED).
  const unitMay = { supplier: canSeeSuppliers, staff: canBuildup || canStaffPrice, levels: canBuildup };
  const unitPrices = Object.values(unitMay).includes(true) ? await require('../services/unitPrices').card(details.product, unitMay) : null;

  const usage = await productRepository.usage(id);
  res.renderView('products/show', {
    unitPrices,
    usage,
    usageText: productRepository.usageText(usage),
    deleteRefusal: productRepository.deleteRefusal(details.product, usage),
    readiness,
    tryPrice,
    product: details.product,
    images: details.images,
    suppliers: details.suppliers,
    miscCharges: details.misc_charges,
    canViewPricing,
    canManage,
    canSeeSuppliers,
    pricingFull,
    pricingStaff,
    canManagePricing: !!(req.permissions || {}).manage_pricing,
    canMarketPrices: !!(req.permissions || {}).record_market_prices || canBuildup,
    // D-300 (point 12): the category's price rules page; "own rule" when the product has its own values.
    productCategoryId: await require('../services/productSpecs').categoryIdFor(details.product),
    pricingOverrideValues: {
      margin: trimOverride(details.product.pricing_margin_pct),
      buffer: trimOverride(details.product.pricing_buffer_pct),
      other: trimOverride(details.product.pricing_other_cost_value),
      incentive: trimOverride(details.product.pricing_incentive_value),
    },
  }, 'layout/base');
}

async function edit(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }
  res.renderView('products/edit', {
    product,
    referenceUnits: await require('../repositories/lookupRepository').dropdownOptions('unit'),
    ...(await formExtras(req, product)),
  }, 'layout/base');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const data = readProductForm(req, product);
  const [ruleFields, ruleError] = readPriceRuleFields(req);
  const error = validateProduct(data) || (await hsMasterError(req, data.hs_code)) || (await applySellingUnit(req, data)) || ruleError;
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${id}/edit`);
    return;
  }

  const user = req.user;
  await productRepository.update(id, data, user.id);
  for (const [k, v] of Object.entries(ruleFields)) {
    const old = product[k] ?? null;
    if ((old === null) !== (v === null) || (old !== null && Number(old) !== Number(v))) {
      await auditLogRepository.log(user.id, 'PRODUCT_PRICE_RULE_CHANGED', 'products', id, k, old === null ? 'category' : String(old), v ?? 'category');
    }
  }
  await productRepository.setPriceRuleFields(id, ruleFields);
  await auditLogRepository.log(user.id, 'PRODUCT_UPDATED', 'products', id, null, null, data.name);
  flash.set(req, 'success', 'Product updated.');
  res.redirect(`/products/${id}`);
}

async function remove(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  // Owner (12 Nov 2026): only an inactive product that is not used anywhere is deleted —
  // checked here, not only by hiding the button.
  const refusal = productRepository.deleteRefusal(product, await productRepository.usage(id));
  if (refusal !== null) {
    flash.set(req, 'error', `Not deleted: ${refusal}`);
    res.redirect(`/products/${id}`);
    return;
  }

  const user = req.user;
  await productRepository.remove(id);
  await auditLogRepository.log(user.id, 'PRODUCT_DELETED', 'products', id, null, product.name, null);
  flash.set(req, 'success', `Product "${product.name}" deleted.`);
  res.redirect('/products?status=inactive');
}

async function setActive(req, res, active) {
  const id = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(id);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }
  const back = (req.body || {}).return_to === 'list' ? `/products?status=${active ? 'inactive' : 'active'}` : `/products/${id}`;
  if (!!Number(product.is_active) === active) {
    flash.set(req, 'success', `Product "${product.name}" is already ${active ? 'active' : 'inactive'}.`);
    res.redirect(back);
    return;
  }
  const user = req.user;
  await productRepository.setActive(id, active, user.id);
  await auditLogRepository.log(user.id, active ? 'PRODUCT_REACTIVATED' : 'PRODUCT_DEACTIVATED', 'products', id, 'is_active', active ? '0' : '1', active ? '1' : '0');
  flash.set(req, 'success', active
    ? `Product "${product.name}" is active again — it shows in the list and can be picked on order lines.`
    : `Product "${product.name}" is deactivated — it is hidden from the active list and cannot be picked on new order lines.`);
  res.redirect(back);
}

/** Owner (12 Nov 2026): POST /products/:id/reactivate — back in the active list and the order-line picker. */
async function reactivate(req, res) {
  await setActive(req, res, true);
}

/** Owner (12 Nov 2026): POST /products/:id/deactivate — hidden from the active list and the order-line picker; nothing is deleted. */
async function deactivate(req, res) {
  await setActive(req, res, false);
}

async function uploadImage(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  if (!req.file) {
    flash.set(req, 'error', 'No file was uploaded, or the upload failed.');
    res.redirect(`/products/${productId}`);
    return;
  }

  const file = req.file; // multer memoryStorage: { buffer, size, originalname, mimetype }
  if (file.size > MAX_BYTES) {
    flash.set(req, 'error', 'File too large (max 5MB).');
    res.redirect(`/products/${productId}`);
    return;
  }

  const mime = sniffMime.sniff(file.buffer);
  if (!mime || !ALLOWED_MIME[mime]) {
    flash.set(req, 'error', 'Unsupported file type. Use PNG or JPG.');
    res.redirect(`/products/${productId}`);
    return;
  }

  const ext = ALLOWED_MIME[mime];
  const storageBase = env.storageBasePath();
  const targetDir = path.join(storageBase, 'products', String(productId), 'images');
  fs.mkdirSync(targetDir, { recursive: true });

  const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 15);
  const filename = `img_${stamp}_${crypto.randomBytes(4).toString('hex')}.${ext}`;
  const targetPath = path.join(targetDir, filename);

  fs.writeFileSync(targetPath, file.buffer);

  const user = req.user;
  const imageId = await productImageRepository.create(productId, targetPath, mime, user.id);
  await auditLogRepository.log(user.id, 'PRODUCT_IMAGE_UPLOADED', 'product_images', imageId, null, null, targetPath);
  flash.set(req, 'success', 'Image uploaded.');
  res.redirect(`/products/${productId}`);
}

async function deleteImage(req, res) {
  const imageId = parseInt(req.params.imageId, 10) || 0;
  const image = await productImageRepository.find(imageId);
  if (!image) {
    flash.set(req, 'error', 'Image not found.');
    res.redirect('/products');
    return;
  }

  const productId = image.product_id;
  const user = req.user;
  await productImageRepository.remove(imageId);
  if (fs.existsSync(image.server_path)) {
    try { fs.unlinkSync(image.server_path); } catch (e) { /* best-effort, same as PHP's @unlink */ }
  }
  await auditLogRepository.log(user.id, 'PRODUCT_IMAGE_DELETED', 'product_images', imageId, null, image.server_path, null);
  flash.set(req, 'success', 'Image removed.');
  res.redirect(`/products/${productId}`);
}

/** Streams a product image file — storage lives outside the web root, exactly like assetController.preview(). */
async function viewImage(req, res) {
  const imageId = parseInt(req.params.imageId, 10) || 0;
  const image = await productImageRepository.find(imageId);
  if (!image || !fs.existsSync(image.server_path)) {
    res.status(404).end();
    return;
  }
  res.set('Content-Type', image.mime_type || 'application/octet-stream');
  res.set('Cache-Control', 'private, max-age=60');
  fs.createReadStream(image.server_path).pipe(res);
}

async function addSupplier(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const data = readSupplierForm(req);
  data.product_id = productId;
  const error = validateSupplier(data, null);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${productId}`);
    return;
  }

  const user = req.user;
  const supplierId = await productSupplierRepository.create(data, user.id);
  if (data.is_primary) {
    await productService.setPrimarySupplier(productId, supplierId);
  }
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_ADDED', 'product_suppliers', supplierId, null, null, data.supplier_name);
  flash.set(req, 'success', 'Supplier added.');
  res.redirect(`/products/${productId}`);
}

async function updateSupplier(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const existing = await productSupplierRepository.find(supplierId);
  if (!existing) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/products');
    return;
  }
  const productId = existing.product_id;

  const data = readSupplierForm(req, existing);
  data.product_id = productId;
  const error = validateSupplier(data, existing);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(`/products/${productId}`);
    return;
  }

  const user = req.user;
  await productSupplierRepository.update(supplierId, data, user.id);
  if (data.is_primary) {
    await productService.setPrimarySupplier(productId, supplierId);
  }
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_UPDATED', 'product_suppliers', supplierId, null, null, data.supplier_name);
  flash.set(req, 'success', 'Supplier updated.');
  res.redirect(`/products/${productId}`);
}

async function deleteSupplier(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const existing = await productSupplierRepository.find(supplierId);
  if (!existing) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/products');
    return;
  }
  const productId = existing.product_id;

  const user = req.user;
  await productSupplierRepository.remove(supplierId);
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_DELETED', 'product_suppliers', supplierId, null, existing.supplier_name, null);
  flash.set(req, 'success', 'Supplier removed.');
  res.redirect(`/products/${productId}`);
}

async function setPrimarySupplier(req, res) {
  const supplierId = parseInt(req.params.supplierId, 10) || 0;
  const existing = await productSupplierRepository.find(supplierId);
  if (!existing) {
    flash.set(req, 'error', 'Supplier not found.');
    res.redirect('/products');
    return;
  }
  const productId = existing.product_id;

  const user = req.user;
  await productService.setPrimarySupplier(productId, supplierId);
  await auditLogRepository.log(user.id, 'PRODUCT_SUPPLIER_SET_PRIMARY', 'product_suppliers', supplierId);
  flash.set(req, 'success', 'Primary supplier updated.');
  res.redirect(`/products/${productId}`);
}

async function addMiscCharge(req, res) {
  const productId = parseInt(req.params.id, 10) || 0;
  const product = await productRepository.find(productId);
  if (!product) {
    res.status(404).send('Product not found.');
    return;
  }

  const label = String(req.body.label || '').trim();
  const amountRaw = String(req.body.amount || '').trim();
  if (label === '' || amountRaw === '' || !isNumeric(amountRaw)) {
    flash.set(req, 'error', 'A label and a numeric amount are required for a misc charge.');
    res.redirect(`/products/${productId}`);
    return;
  }

  const user = req.user;
  const notes = String(req.body.notes || '').trim();
  const chargeId = await productMiscChargeRepository.create(productId, label, parseFloat(amountRaw), notes !== '' ? notes : null, user.id);
  await auditLogRepository.log(user.id, 'PRODUCT_MISC_CHARGE_ADDED', 'product_misc_charges', chargeId, null, null, `${label}: ${amountRaw}`);
  flash.set(req, 'success', 'Misc charge added.');
  res.redirect(`/products/${productId}`);
}

async function deleteMiscCharge(req, res) {
  const chargeId = parseInt(req.params.chargeId, 10) || 0;
  const charge = await productMiscChargeRepository.find(chargeId);
  if (!charge) {
    flash.set(req, 'error', 'Misc charge not found.');
    res.redirect('/products');
    return;
  }
  const productId = charge.product_id;

  const user = req.user;
  await productMiscChargeRepository.remove(chargeId);
  await auditLogRepository.log(user.id, 'PRODUCT_MISC_CHARGE_DELETED', 'product_misc_charges', chargeId, null, `${charge.label}: ${charge.amount}`, null);
  flash.set(req, 'success', 'Misc charge removed.');
  res.redirect(`/products/${productId}`);
}

/**
 * D-335: the form no longer has the default factory / transportation / CHA cost (the columns stay,
 * kept as they are); "Packing + loading per unit" is stored in default_packing_cost (default_loading_cost
 * then empty — the two are still added up where they are read).
 */
function readProductForm(req, existing = null) {
  const b = req.body || {};
  const inBody = (k) => Object.prototype.hasOwnProperty.call(b, k);
  const keep = (k) => (existing && existing[k] !== null && existing[k] !== undefined ? Number(existing[k]) : null);
  const hasPacking = inBody('packing_loading_cost');
  return {
    name: String(b.name || '').trim(),
    specifications: String(b.specifications || '').trim(),
    hs_code: String(b.hs_code || '').trim(),
    origin: nullableString(b.origin),
    default_factory_cost: inBody('default_factory_cost') ? nullableDecimal(b.default_factory_cost) : keep('default_factory_cost'),
    default_transportation_cost: inBody('default_transportation_cost') ? nullableDecimal(b.default_transportation_cost) : keep('default_transportation_cost'),
    default_packing_cost: hasPacking ? nullableDecimal(b.packing_loading_cost) : (inBody('default_packing_cost') ? nullableDecimal(b.default_packing_cost) : keep('default_packing_cost')),
    default_loading_cost: hasPacking ? null : (inBody('default_loading_cost') ? nullableDecimal(b.default_loading_cost) : keep('default_loading_cost')),
    default_cha_cost: inBody('default_cha_cost') ? nullableDecimal(b.default_cha_cost) : keep('default_cha_cost'),
    is_active: b.is_active !== undefined ? 1 : 0,
  };
}


/** Owner decision D-245 P2: the product's selling unit (from the unit list), filled into an order line. */
async function applySellingUnit(req, data) {
  const unit = String(req.body.reference_unit ?? '').trim();
  const units = (await require('../repositories/lookupRepository').dropdownOptions('unit')).map((u) => u.option_value);
  if (unit !== '' && !units.includes(unit)) return 'Choose the selling unit from the list.';
  data.reference_unit = unit !== '' ? unit : null;
  return null;
}

/** hs_code (and name/specifications, both NOT NULL columns) validated server-side — never trusted from client-side only. */
function validateProduct(data) {
  if (data.name === '') {
    return 'Product name is required.';
  }
  if (data.specifications === '') {
    return 'Specifications are required.';
  }
  if (data.hs_code === '') {
    return 'HS Code is required.';
  }
  return null;
}

/**
 * Owner decision D-338 (2a): a new supplier price is always one typed price ("direct");
 * "add up the cost parts" ("computed") is kept only for a price already saved that way.
 * The cost parts are read only when the form sends them — a form without them (a typed
 * price) keeps what the row holds, so nothing is lost.
 */
function readSupplierForm(req, existing = null) {
  let fobSource = String(req.body.fob_source || (existing && existing.fob_source) || 'direct');
  if (!['direct', 'computed'].includes(fobSource)) {
    fobSource = 'direct';
  }
  const part = (k) => (Object.prototype.hasOwnProperty.call(req.body, k)
    ? nullableDecimal(req.body[k])
    : (existing && existing[k] !== null && existing[k] !== undefined ? Number(existing[k]) : null));
  return {
    supplier_name: String(req.body.supplier_name || '').trim(),
    location: nullableString(req.body.location),
    contact_person: nullableString(req.body.contact_person),
    contact_phone: nullableString(req.body.contact_phone),
    contact_email: nullableString(req.body.contact_email),
    fob_source: fobSource,
    fob_value: nullableDecimal(req.body.fob_value),
    factory_cost: part('factory_cost'),
    transportation_cost: part('transportation_cost'),
    packing_cost: part('packing_cost'),
    loading_cost: part('loading_cost'),
    cha_cost: part('cha_cost'),
    is_primary: req.body.is_primary !== undefined ? 1 : 0,
    notes: nullableString(req.body.notes),
  };
}

/** D-338 (2a): the refusal for a new "add up the cost parts" price (the same wording on both stacks). */
const COST_PARTS_REFUSAL = 'Adding up the cost parts is no longer offered for a new price — type the supplier\'s price in FOB Value (₹). Prices saved that way earlier keep working.';

function validateSupplier(data, existing) {
  if (data.supplier_name === '') {
    return 'Supplier name is required.';
  }
  // D-338 (2a): only a price already saved as "add up the cost parts" may stay so.
  if (data.fob_source === 'computed' && (!existing || (existing.fob_source || 'direct') !== 'computed')) {
    return COST_PARTS_REFUSAL;
  }
  return null;
}

function nullableString(value) {
  const v = String(value ?? '').trim();
  return v === '' ? null : v;
}

function nullableDecimal(value) {
  const v = String(value ?? '').trim();
  return (v === '' || !isNumeric(v)) ? null : parseFloat(v);
}

function isNumeric(value) {
  return value !== '' && !isNaN(Number(value));
}

module.exports = {
  COST_PARTS_REFUSAL,
  readSupplierForm, validateSupplier, // D-338 (exported for its test)
  index,
  create,
  store,
  show,
  edit,
  update,
  remove,
  reactivate,
  deactivate,
  uploadImage,
  deleteImage,
  viewImage,
  addSupplier,
  updateSupplier,
  deleteSupplier,
  setPrimarySupplier,
  addMiscCharge,
  deleteMiscCharge,
};
