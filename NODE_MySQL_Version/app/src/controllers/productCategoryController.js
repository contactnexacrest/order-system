'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const productCategoryRepository = require('../repositories/productCategoryRepository');

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
  res.renderView('products/categories', {
    categories: await productCategoryRepository.all(),
    maxAttributes: productCategoryRepository.MAX_ATTRIBUTES,
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

module.exports = { index, store, update };
