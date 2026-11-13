'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const productCategoryRepository = require('../repositories/productCategoryRepository');
const lookupRepository = require('../repositories/lookupRepository');

// Port of App\Controllers\ProductCategoryController. Owner decision D-215
// (5a): Products → Categories — each category names the attributes its
// order lines carry (e.g. Size, Grade, Colour). The system category
// "Natural Stone" is today's products and keeps its Dimensions and Finish.

async function nameError(name, id) {
  const len = [...name].length;
  if (len < 2 || len > 80) return 'Name the category in 2 to 80 characters.';
  if (await productCategoryRepository.nameTaken(name, id)) return `There is already a category called "${name}".`;
  return null;
}

async function index(req, res) {
  const categories = await productCategoryRepository.all();
  for (const c of categories) {
    c.allowedUnits = productCategoryRepository.allowedUnits(c);
    c.qualityDocuments = productCategoryRepository.qualityDocuments(c);
  }
  res.renderView('products/categories', {
    categories,
    maxQualityDocuments: productCategoryRepository.MAX_QUALITY_DOCUMENTS,
    maxAttributes: productCategoryRepository.MAX_ATTRIBUTES,
    // D-257 0B: each category's units, HS code, packing, quality documents and T&C clause set.
    units: (await lookupRepository.dropdownOptions('unit')).map((o) => String(o.option_value)),
    packageTypes: (await lookupRepository.dropdownOptions('package_type')).map((o) => String(o.option_value)),
    hsCodes: await require('../repositories/hsCodeRepository').active(),
    tcClauseSets: productCategoryRepository.TC_CLAUSE_SETS,
  }, 'layout/base');
}

async function store(req, res) {
  const name = String(req.body.name ?? '').trim();
  const [labels, labelError] = productCategoryRepository.parseLabels(req.body.attributes);
  const error = (await nameError(name, null)) ?? labelError;
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect('/products/categories');
    return;
  }
  const id = await productCategoryRepository.create(name, labels, req.user.id);
  await auditLogRepository.log(req.user.id, 'PRODUCT_CATEGORY_CREATED', 'product_categories', id, 'attributes', null, `${name}: ${labels.join(', ')}`);
  flash.set(req, 'success', `Category "${name}" added with ${labels.join(', ')}.`);
  res.redirect('/products/categories');
}

async function update(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const category = await productCategoryRepository.find(id);
  if (!category) {
    res.status(404).send('Category not found.');
    return;
  }
  if (Number(category.is_system) === 1) {
    flash.set(req, 'error', "Natural Stone is today's products — its Dimensions and Finish stay as they are.");
    res.redirect(backTo(req, '/products/categories'));
    return;
  }
  const name = String(req.body.name ?? '').trim();
  const [labels, labelError] = productCategoryRepository.parseLabels(req.body.attributes);
  const error = (await nameError(name, id)) ?? labelError;
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect(backTo(req, '/products/categories'));
    return;
  }
  const active = Boolean(req.body.is_active);
  await productCategoryRepository.update(id, name, labels, active);
  await auditLogRepository.log(req.user.id, 'PRODUCT_CATEGORY_UPDATED', 'product_categories', id, 'attributes',
    `${category.name}: ${category.attributes.join(', ')}`, `${name}: ${labels.join(', ')}${active ? '' : ' (inactive)'}`);
  flash.set(req, 'success', `Category "${name}" saved.`);
  res.redirect(backTo(req, '/products/categories'));
}

/**
 * Owner decision D-257 Phase 0B (5 Nov 2026): a category's settings —
 * default and allowed units, default HS code, packing type, the quality
 * documents its orders need, and its T&C clause set. Natural Stone's
 * settings can be edited too (its attributes stay locked).
 */
async function settings(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const category = await productCategoryRepository.find(id);
  if (!category) {
    res.status(404).send('Category not found.');
    return;
  }
  const [values, error] = await productCategoryRepository.parseSettings(
    req.body,
    (await lookupRepository.dropdownOptions('unit')).map((o) => String(o.option_value)),
    (await lookupRepository.dropdownOptions('package_type')).map((o) => String(o.option_value)),
  );
  if (error !== null) {
    flash.set(req, 'error', `${category.name}: ${error}`);
    res.redirect(backTo(req, '/products/categories'));
    return;
  }
  await productCategoryRepository.updateSettings(id, values);
  await auditLogRepository.log(req.user.id, 'PRODUCT_CATEGORY_SETTINGS_UPDATED', 'product_categories', id, 'settings',
    productCategoryRepository.settingsSummary(category), productCategoryRepository.settingsSummary(values));
  flash.set(req, 'success', `Settings for "${category.name}" saved.`);
  res.redirect(backTo(req, '/products/categories'));
}

/** D-306: where to go after saving — back to the category page when the form came from it. */
function backTo(req, fallback) {
  const to = String((req.body || {}).return_to ?? '');
  return /^\/products\/categories\/\d+(\?tab=[a-z]+)?$/.test(to) ? to : fallback;
}

/**
 * Owner decision D-306 (point 20, 11 Nov 2026): Products › Categories › <category> is one page with
 * tabs — General | Options and their price effect (was Admin › Pricing › Specifications) | Price
 * rules (D-300) | Products in this category.
 */
async function show(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const category = await productCategoryRepository.find(id);
  if (!category) {
    res.status(404).send('Category not found.');
    return;
  }
  const can = (k) => !!(req.permissions && req.permissions[k]);
  const seesPricing = can('view_price_buildup') || can('manage_pricing');
  let tab = String(req.query.tab || 'general');
  if (!['general', 'options', 'rules', 'products'].includes(tab) || (!seesPricing && ['options', 'rules'].includes(tab))) tab = 'general';
  const productSpecs = require('../services/productSpecs');
  const products = [];
  for (const p of await require('../repositories/productRepository').all(false)) {
    if (Number(await productSpecs.categoryIdFor(p)) === id) products.push(p);
  }
  category.allowedUnits = productCategoryRepository.allowedUnits(category);
  const tabs = [['general', 'General (name, units, HS code, packing, documents)']];
  if (seesPricing) tabs.push(['options', 'Options and their price effect'], ['rules', 'Category price rules (thickness %, container)']);
  tabs.push(['products', `Products in this category (${products.length})`]);
  const data = {
    category, tab, tabs, seesPricing, canManageCatalog: can('manage_product_catalog'), canManagePricing: can('manage_pricing'), products,
    maxQualityDocuments: productCategoryRepository.MAX_QUALITY_DOCUMENTS,
    maxAttributes: productCategoryRepository.MAX_ATTRIBUTES,
    units: (await lookupRepository.dropdownOptions('unit')).map((o) => String(o.option_value)),
    packageTypes: (await lookupRepository.dropdownOptions('package_type')).map((o) => String(o.option_value)),
    hsCodes: await require('../repositories/hsCodeRepository').active(),
    tcClauseSets: productCategoryRepository.TC_CLAUSE_SETS,
    categories: await productCategoryRepository.all(),
  };
  if (tab === 'options') {
    Object.assign(data, await require('./specController').categoryData(id), { edit: parseInt(req.query.edit, 10) || 0, canManage: can('manage_pricing') });
  }
  if (tab === 'rules') {
    const r = await require('../services/priceRules').forCategory(id);
    const fmt = require('../services/thicknessPrices').fmt;
    const ex = 1600;
    const old = flash.pullOld ? (flash.pullOld(req) || {}) : {};
    const oldPct = old.pct && typeof old.pct === 'object' ? old.pct : {};
    data.rules = r;
    data.rulesView = {
      example: ex,
      thickness: r.thickness.map((t) => ({ ...t, value: String(oldPct[t.id] ?? fmt(t.pct)), example: `₹${Math.round(ex * (1 + t.pct / 100)).toLocaleString('en-US')} / m²` })),
      k: String(old.thick_k_factor ?? (r.k !== null ? fmt(r.k) : '')), kExample: r.k !== null ? `₹${Math.round(ex * r.k).toLocaleString('en-US')} / CBM` : '—',
      containerCbm: String(old.container_cbm ?? (r.container_cbm !== null ? fmt(r.container_cbm) : '')),
      containerMaxT: String(old.container_max_t ?? (r.container_max_t !== null ? fmt(r.container_max_t) : '')),
      weight: String(old.weight_t_per_cbm ?? (r.weight !== null ? fmt(r.weight) : '')),
      newMm: String(old.new_mm ?? ''), newChange: String(old.new_change ?? ''), reason: String(old.reason ?? ''),
      // D-361 (mockup p6): the category's own %s (empty = global) and the global values for the empty fields.
      marginPct: String(old.margin_pct ?? (r.margin_pct !== null ? fmt(r.margin_pct) : '')),
      otherCostPct: String(old.other_cost_pct ?? (r.other_cost_pct !== null ? fmt(r.other_cost_pct) : '')),
      bufferPct: String(old.buffer_pct ?? (r.buffer_pct !== null ? fmt(r.buffer_pct) : '')),
      g: {
        margin: Number(r.global.margin_pct).toFixed(2), other: Number(r.global.other_cost_pct).toFixed(2), buffer: Number(r.global.buffer_pct).toFixed(2),
        cbm: fmt(r.global.container_cbm), maxT: fmt(r.global.container_max_t), weight: fmt(r.global.weight_t_per_cbm), k: fmt(r.global.thick_k_factor),
      },
    };
  }
  res.renderView('products/category', data, 'layout/base');
}

/** D-300: the category's price rules — MD only (manage_pricing), with a reason. */
async function saveRules(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const [changes, error] = await require('../services/priceRules').save(id, req.body || {}, req.user.id);
  if (error !== null) {
    flash.set(req, 'error', error);
    flash.setOld(req, req.body || {});
  } else {
    flash.set(req, 'success', changes === 0 ? 'Nothing changed.' : `Category price rules saved (${changes} change${changes === 1 ? '' : 's'}). Prices worked out from now on use them; quotes already sent keep their prices.`);
  }
  res.redirect(`/products/categories/${id}?tab=rules`);
}

/** D-300: Products menu › Price rules — the first (system) category's rules page. */
async function priceRules(req, res) {
  const id = parseInt(req.query.category, 10) || (await require('../repositories/specRepository').systemCategoryId()) || Number(((await productCategoryRepository.all())[0] || {}).id || 0);
  res.redirect(`/products/categories/${id}?tab=rules`);
}

module.exports = { index, store, update, settings, show, saveRules, priceRules };
