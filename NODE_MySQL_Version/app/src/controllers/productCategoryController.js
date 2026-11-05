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
    res.redirect('/products/categories');
    return;
  }
  const name = String(req.body.name ?? '').trim();
  const [labels, labelError] = productCategoryRepository.parseLabels(req.body.attributes);
  const error = (await nameError(name, id)) ?? labelError;
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect('/products/categories');
    return;
  }
  const active = Boolean(req.body.is_active);
  await productCategoryRepository.update(id, name, labels, active);
  await auditLogRepository.log(req.user.id, 'PRODUCT_CATEGORY_UPDATED', 'product_categories', id, 'attributes',
    `${category.name}: ${category.attributes.join(', ')}`, `${name}: ${labels.join(', ')}${active ? '' : ' (inactive)'}`);
  flash.set(req, 'success', `Category "${name}" saved.`);
  res.redirect('/products/categories');
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
    res.redirect('/products/categories');
    return;
  }
  await productCategoryRepository.updateSettings(id, values);
  await auditLogRepository.log(req.user.id, 'PRODUCT_CATEGORY_SETTINGS_UPDATED', 'product_categories', id, 'settings',
    productCategoryRepository.settingsSummary(category), productCategoryRepository.settingsSummary(values));
  flash.set(req, 'success', `Settings for "${category.name}" saved.`);
  res.redirect('/products/categories');
}

module.exports = { index, store, update, settings };
