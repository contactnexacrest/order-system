'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const productCategoryRepository = require('../repositories/productCategoryRepository');
const productRepository = require('../repositories/productRepository');
const specRepository = require('../repositories/specRepository');
const productSpecs = require('../services/productSpecs');
const surfaceFinishList = require('../services/surfaceFinishList');
const pricingService = require('../services/pricingService');

/**
 * Port of App\Controllers\SpecController — owner decision D-255 (5 Nov 2026): the
 * specifications that change the price.
 *  - Pricing → Specifications: a category's specifications and options — manage_pricing.
 *  - A product's specifications: hide a specification or option, change an option's value,
 *    add options or specifications for that product only, reset —
 *    manage_product_specifications (a role permission; Admin and MD to start).
 * An option or specification already used on an order line or price request can only be
 * deactivated. Every change is audit-logged; issued prices do not change.
 */

const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);
const uid = (req) => Number(req.user.id);
const id = (v) => parseInt(v, 10) || 0;
const asList = (v) => (v === undefined || v === null ? [] : Array.isArray(v) ? v : typeof v === 'object' ? Object.values(v) : [v]);
const asMap = (v) => (v && typeof v === 'object' ? (Array.isArray(v) ? Object.fromEntries(v.map((x, i) => [i, x]).filter(([, x]) => x !== undefined)) : v) : {});
const valueOrNull = (v) => (v !== null && v !== undefined ? Number(v) : null);

/**
 * D-306 (point 20, 11 Nov 2026): a category's options are a tab of its page now — the old
 * Admin › Pricing › Specifications address (and every redirect to it) opens that tab.
 */
async function categoryTab(to) {
  const m = /^\/pricing\/specifications(?:\?category=(\d+))?(?:&edit=(\d+))?(#.*)?$/.exec(String(to));
  if (!m) return to;
  const cat = m[1] || String((await specRepository.systemCategoryId()) || 0);
  return `/products/categories/${cat}?tab=options${m[2] ? `&edit=${m[2]}` : ''}${m[3] || ''}`;
}

async function go(res, to) {
  res.redirect(await categoryTab(to));
}

/** On a product's routes (/products/{pid}/…) the spec or option must be that product's own. */
function belongs(req, res, ownerProduct) {
  if (req.params.pid === undefined) return true;
  if (ownerProduct === null || ownerProduct === undefined || Number(ownerProduct) !== id(req.params.pid)) {
    flash.set(req, 'error', "That is not one of this product's own specifications or options.");
    res.status(403);
    go(res, `/products/${id(req.params.pid)}/specifications`);
    return false;
  }
  return true;
}

function refuseProduct(req, res, to) {
  flash.set(req, 'error', "You may not change this product's specifications.");
  go(res, to);
}

// ------------------------------------------------------------ category level

/** D-306 (point 20): the old Admin › Pricing › Specifications address opens the category page's Options tab. */
async function index(req, res) {
  const categories = await productCategoryRepository.all(true);
  const categoryId = id(req.query.category) || (await specRepository.systemCategoryId()) || Number((categories[0] || {}).id || 0);
  const edit = id(req.query.edit);
  res.redirect(`/products/categories/${categoryId}?tab=options${edit > 0 ? `&edit=${edit}#spec-${edit}` : ''}`);
}

/** A category's specifications and options with whether each is used (for its Options tab). */
async function categoryData(categoryId) {
  const specs = await specRepository.categorySpecs(categoryId);
  const options = await specRepository.optionsFor(specs.map((x) => Number(x.id)));
  for (const sp of specs) {
    sp.options = (options[Number(sp.id)] || []).filter((o) => o.product_id === null);
    sp.used = await specRepository.specUsed(Number(sp.id));
    for (const o of sp.options) {
      o.used = await specRepository.optionUsed(Number(o.id));
      o.change_label = productSpecs.changeLabel(String(o.change_type), valueOrNull(o.value));
      o.price_unset = surfaceFinishList.priceText(o)[1]; // D-357: no price change set yet
    }
    sp.forms_label = productSpecs.formsLabel(sp.applies_to);
    sp.applies_list = productSpecs.splitForms(sp.applies_to);
  }
  return { categoryId, specs, forms: productSpecs.FORMS, kinds: productSpecs.KINDS, changeTypes: productSpecs.CHANGE_TYPES };
}

async function addSpec(req, res) {
  const body = req.body || {};
  const categoryId = id(body.category_id);
  const [d, errors] = productSpecs.readSpecForm(body);
  if (categoryId <= 0 || !(await productCategoryRepository.find(categoryId))) errors.push('Choose a category.');
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, `/pricing/specifications?category=${categoryId}`);
    return;
  }
  d.category_id = categoryId;
  const newId = await specRepository.createSpec(d, uid(req));
  await auditLogRepository.log(uid(req), 'SPEC_ADDED', 'spec_definitions', newId, null, null, `${d.name} (category #${categoryId})`);
  // D-357: a category's "Surface finish" takes its options from Settings › Lists › surface_finish.
  if (String(d.name).trim().toLowerCase() === 'surface finish') {
    await specRepository.setListKey(newId, surfaceFinishList.LIST_KEY);
    await surfaceFinishList.sync([], uid(req));
    flash.set(req, 'success', `Specification "${d.name}" added with the finishes of Settings › Lists › surface_finish, at no price change — set each finish's price below.`);
    await go(res, `/pricing/specifications?category=${categoryId}&edit=${newId}#spec-${newId}`);
    return;
  }
  flash.set(req, 'success', `Specification "${d.name}" added — add its options below.`);
  await go(res, `/pricing/specifications?category=${categoryId}&edit=${newId}#spec-${newId}`);
}

async function updateSpec(req, res) {
  const spec = await specRepository.findSpec(id(req.params.id));
  if (!spec) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  if (!belongs(req, res, spec.product_id)) return;
  const [d, errors] = productSpecs.readSpecForm(req.body || {});
  const to = spec.product_id !== null ? `/products/${spec.product_id}/specifications` : `/pricing/specifications?category=${spec.category_id}&edit=${spec.id}#spec-${spec.id}`;
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, to);
    return;
  }
  if (spec.product_id !== null && !can(req, 'manage_product_specifications')) {
    refuseProduct(req, res, to);
    return;
  }
  await specRepository.updateSpec(Number(spec.id), d, uid(req));
  await auditLogRepository.log(uid(req), 'SPEC_CHANGED', 'spec_definitions', Number(spec.id), null, String(spec.name), d.name);
  flash.set(req, 'success', 'Specification saved.');
  await go(res, to);
}

async function toggleSpec(req, res) {
  const spec = await specRepository.findSpec(id(req.params.id));
  if (!spec || spec.product_id !== null) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  const active = Number(spec.is_active) !== 1;
  await specRepository.setSpecActive(Number(spec.id), active, uid(req));
  await auditLogRepository.log(uid(req), active ? 'SPEC_ACTIVATED' : 'SPEC_DEACTIVATED', 'spec_definitions', Number(spec.id), null, null, String(spec.name));
  flash.set(req, 'success', `"${spec.name}" ${active ? 'is active again.' : 'is deactivated — no longer offered; orders and price requests that used it keep it.'}`);
  await go(res, `/pricing/specifications?category=${spec.category_id}`);
}

async function deleteSpec(req, res) {
  const spec = await specRepository.findSpec(id(req.params.id));
  if (!spec) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  if (!belongs(req, res, spec.product_id)) return;
  const to = spec.product_id !== null ? `/products/${spec.product_id}/specifications` : `/pricing/specifications?category=${spec.category_id}`;
  if (spec.product_id !== null && !can(req, 'manage_product_specifications')) {
    refuseProduct(req, res, to);
    return;
  }
  if (await specRepository.specUsed(Number(spec.id))) {
    if (spec.product_id !== null) {
      await specRepository.setSpecActive(Number(spec.id), false, uid(req));
      flash.set(req, 'success', `"${spec.name}" has been used on orders or price requests, so it was deactivated instead of deleted.`);
    } else {
      flash.set(req, 'error', `"${spec.name}" has been used on orders or price requests — deactivate it instead.`);
    }
    await go(res, to);
    return;
  }
  await specRepository.deleteSpec(Number(spec.id));
  await auditLogRepository.log(uid(req), 'SPEC_DELETED', 'spec_definitions', Number(spec.id), null, String(spec.name), null);
  flash.set(req, 'success', `"${spec.name}" deleted.`);
  await go(res, to);
}

async function addOption(req, res) {
  const spec = await specRepository.findSpec(id(req.params.id));
  if (!spec) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  const productOnly = spec.product_id !== null;
  if (productOnly && !can(req, 'manage_product_specifications')) {
    refuseProduct(req, res, `/products/${spec.product_id}/specifications`);
    return;
  }
  const [d, errors] = productSpecs.readOptionForm(req.body || {});
  const to = productOnly ? `/products/${spec.product_id}/specifications` : `/pricing/specifications?category=${spec.category_id}&edit=${spec.id}#spec-${spec.id}`;
  if (surfaceFinishList.isLinkedSpec(spec)) {
    // D-357: finish names are added in Settings › Lists; they appear here at no price change.
    flash.set(req, 'error', 'Add a surface finish in Settings › Lists › surface_finish — it appears here at once with no price change; then set its price here.');
    await go(res, to);
    return;
  }
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, to);
    return;
  }
  d.spec_id = Number(spec.id);
  d.product_id = productOnly ? Number(spec.product_id) : null;
  const newId = await specRepository.createOption(d, uid(req));
  if (d.is_base === 1) await specRepository.clearBase(Number(spec.id), d.product_id, newId);
  await auditLogRepository.log(uid(req), 'SPEC_OPTION_ADDED', 'spec_options', newId, null, null,
    `${spec.name}: ${d.name} ${productSpecs.changeLabel(d.change_type, d.value !== null ? Number(d.value) : null)}`);
  flash.set(req, 'success', `Option "${d.name}" added.`);
  await go(res, to);
}

async function updateOption(req, res) {
  const o = await specRepository.findOption(id(req.params.id));
  const spec = o ? await specRepository.findSpec(Number(o.spec_id)) : null;
  if (!o || !spec) {
    flash.set(req, 'error', 'That option no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  const ownerProduct = o.product_id ?? spec.product_id;
  if (!belongs(req, res, ownerProduct)) return;
  if (ownerProduct !== null && !can(req, 'manage_product_specifications')) {
    refuseProduct(req, res, `/products/${ownerProduct}/specifications`);
    return;
  }
  // D-357: a linked option's name comes from Settings › Lists — only its price is saved here.
  const post = { ...(req.body || {}) };
  if (o.dropdown_option_id) post.name = String(o.name);
  const [d, errors] = productSpecs.readOptionForm(post);
  const to = ownerProduct !== null ? `/products/${ownerProduct}/specifications` : `/pricing/specifications?category=${spec.category_id}&edit=${spec.id}#spec-${spec.id}`;
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, to);
    return;
  }
  await specRepository.updateOption(Number(o.id), d, uid(req));
  if (d.is_base === 1) await specRepository.clearBase(Number(spec.id), o.product_id !== null ? Number(o.product_id) : null, Number(o.id));
  await auditLogRepository.log(uid(req), 'SPEC_OPTION_CHANGED', 'spec_options', Number(o.id), null,
    `${o.name} ${productSpecs.changeLabel(String(o.change_type), valueOrNull(o.value))}`,
    `${d.name} ${productSpecs.changeLabel(d.change_type, d.value !== null ? Number(d.value) : null)}`);
  flash.set(req, 'success', 'Option saved. New quotes use it; prices already issued do not change.');
  await go(res, to);
}

async function toggleOption(req, res) {
  const o = await specRepository.findOption(id(req.params.id));
  const spec = o ? await specRepository.findSpec(Number(o.spec_id)) : null;
  if (!o || !spec || o.product_id !== null) {
    flash.set(req, 'error', 'That option no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  if (o.dropdown_option_id) {
    flash.set(req, 'error', `"${o.name}" is switched on or off in Settings › Lists › surface_finish (D-357) — the one list of finish names.`);
    await go(res, `/pricing/specifications?category=${spec.category_id}&edit=${spec.id}#spec-${spec.id}`);
    return;
  }
  const active = Number(o.is_active) !== 1;
  await specRepository.setOptionActive(Number(o.id), active, uid(req));
  await auditLogRepository.log(uid(req), active ? 'SPEC_OPTION_ACTIVATED' : 'SPEC_OPTION_DEACTIVATED', 'spec_options', Number(o.id), null, null, `${spec.name}: ${o.name}`);
  flash.set(req, 'success', `"${o.name}" ${active ? 'is active again.' : 'is deactivated everywhere — orders and price requests that used it keep it.'}`);
  await go(res, `/pricing/specifications?category=${spec.category_id}&edit=${spec.id}#spec-${spec.id}`);
}

async function deleteOption(req, res) {
  const o = await specRepository.findOption(id(req.params.id));
  const spec = o ? await specRepository.findSpec(Number(o.spec_id)) : null;
  if (!o || !spec) {
    flash.set(req, 'error', 'That option no longer exists.');
    await go(res, '/pricing/specifications');
    return;
  }
  const ownerProduct = o.product_id ?? spec.product_id;
  if (!belongs(req, res, ownerProduct)) return;
  if (ownerProduct !== null && !can(req, 'manage_product_specifications')) {
    refuseProduct(req, res, `/products/${ownerProduct}/specifications`);
    return;
  }
  const to = ownerProduct !== null ? `/products/${ownerProduct}/specifications` : `/pricing/specifications?category=${spec.category_id}&edit=${spec.id}#spec-${spec.id}`;
  if (o.dropdown_option_id && o.product_id === null) {
    flash.set(req, 'error', `"${o.name}" comes from Settings › Lists › surface_finish (D-357) — switch it off there.`);
    await go(res, to);
    return;
  }
  if (await specRepository.optionUsed(Number(o.id))) {
    await specRepository.setOptionActive(Number(o.id), false, uid(req));
    flash.set(req, 'success', `"${o.name}" has been used on orders or price requests, so it was deactivated instead of deleted.`);
    await go(res, to);
    return;
  }
  await specRepository.deleteOption(Number(o.id));
  await auditLogRepository.log(uid(req), 'SPEC_OPTION_DELETED', 'spec_options', Number(o.id), null, `${spec.name}: ${o.name}`, null);
  flash.set(req, 'success', `"${o.name}" removed.`);
  await go(res, to);
}

// ------------------------------------------------------------- product level

async function product(req, res) {
  const p = await productRepository.find(id(req.params.id));
  if (!p) {
    res.status(404).send('Product not found.');
    return;
  }
  const tree = await productSpecs.tree(p, true);
  for (const s of tree) for (const o of s.options) {
    o.change_label = productSpecs.changeLabel(o.change_type, o.value);
    o.category_label = productSpecs.changeLabel(o.change_type, o.category_value);
  }
  res.renderView('products/specifications', {
    product: p, tree, categories: await productCategoryRepository.all(true),
    categoryId: await productSpecs.categoryIdFor(p), canEdit: can(req, 'manage_product_specifications'),
    // D-306 (point 20): "From category X — edit for all products in X".
    categoryName: String(((await productCategoryRepository.find(await productSpecs.categoryIdFor(p))) || {}).name || ''),
    cbmFrom: (await pricingService.settings()).cbm_from_mm, edit: id(req.query.edit),
    forms: productSpecs.FORMS, kinds: productSpecs.KINDS, changeTypes: productSpecs.CHANGE_TYPES,
    productForms: productSpecs.productForms(p),
  });
}

async function productOr404(req, res) {
  const p = await productRepository.find(id(req.params.id));
  if (!p) {
    flash.set(req, 'error', 'That product no longer exists.');
    await go(res, '/products');
  }
  return p;
}

async function saveProductFields(req, res) {
  const p = await productOr404(req, res);
  if (!p) return;
  const pid = Number(p.id);
  const body = req.body || {};
  const errors = [];
  const categoryId = id(body.category_id);
  if (categoryId <= 0 || !(await productCategoryRepository.find(categoryId))) errors.push('Choose a category.');
  const forms = asList(body.forms).map(String).filter((f) => Object.prototype.hasOwnProperty.call(productSpecs.FORMS, f));
  const cbm = String(body.cbm_from_mm ?? '').trim();
  let cbmVal = null;
  if (cbm !== '') {
    const n = productSpecs.validInt(cbm);
    if (n === false || n < 1 || n > 2000) errors.push('CBM from: whole millimetres from 1 to 2000, or leave it empty for the global value.');
    else cbmVal = n;
  }
  // D-270 (point 25): the product's own weight per CBM (empty = its category's).
  const [weight, weightError] = productCategoryRepository.weightInput(body.weight_t_per_cbm);
  if (weightError !== null) errors.push(weightError);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, `/products/${pid}/specifications`);
    return;
  }
  const before = `${p.category_id ?? '—'} / ${p.forms ?? 'all'} / ${p.cbm_from_mm ?? 'global'} / ${p.weight_t_per_cbm ?? 'category'}`;
  await specRepository.setProductFields(pid, categoryId, forms.length ? forms.join(',') : null, cbmVal, weight);
  await auditLogRepository.log(uid(req), 'PRODUCT_SPEC_FIELDS_CHANGED', 'catalog_products', pid, 'category / forms / cbm_from_mm / weight_t_per_cbm', before,
    `${categoryId} / ${forms.length ? forms.join(',') : 'all'} / ${cbmVal ?? 'global'} / ${weight ?? 'category'}`);
  flash.set(req, 'success', 'Saved.');
  await go(res, `/products/${pid}/specifications`);
}

/** A category specification's options for this product: offered or not, and its own value. */
async function saveProductSpec(req, res) {
  const p = await productOr404(req, res);
  if (!p) return;
  const pid = Number(p.id);
  const spec = await specRepository.findSpec(id(req.params.specId));
  if (!spec || spec.product_id !== null) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, `/products/${pid}/specifications`);
    return;
  }
  const body = req.body || {};
  const offered = asMap(body.offered);
  const values = asMap(body.value);
  const errors = [];
  const changes = [];
  let anyOffered = false;
  for (const o of (await specRepository.optionsFor([Number(spec.id)], pid))[Number(spec.id)] || []) {
    if (o.product_id !== null || Number(o.is_active) !== 1) continue;
    const oid = Number(o.id);
    const hidden = !offered[oid];
    anyOffered = anyOffered || !hidden;
    const v = String(values[oid] ?? '').trim();
    let value = null;
    if (v !== '' && !['none', 'cbm'].includes(o.change_type)) {
      if (!/^-?\d+(\.\d{1,4})?$/.test(v)) {
        errors.push(`${o.name}: enter a number with at most 4 decimals, or leave it empty to follow the category.`);
        continue;
      }
      value = Number(v).toFixed(4);
    }
    changes.push([oid, hidden, value, o.name]);
  }
  if (!anyOffered) errors.push('Keep at least one option offered, or hide the whole specification for this product.');
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, `/products/${pid}/specifications?edit=${spec.id}`);
    return;
  }
  const summary = [];
  for (const [oid, hidden, value, name] of changes) {
    await specRepository.setOptionOverride(pid, oid, hidden, value);
    if (hidden || value !== null) summary.push(name + (hidden ? ' hidden' : ` ${value}`));
  }
  await auditLogRepository.log(uid(req), 'PRODUCT_SPEC_CHANGED', 'catalog_products', pid, String(spec.name), null, summary.length ? summary.join('; ') : 'follows the category');
  flash.set(req, 'success', `"${spec.name}" saved for ${p.name}. New quotes use it.`);
  await go(res, `/products/${pid}/specifications`);
}

async function setHidden(req, res, hidden) {
  const p = await productOr404(req, res);
  if (!p) return;
  const spec = await specRepository.findSpec(id(req.params.specId));
  if (!spec || spec.product_id !== null) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, `/products/${p.id}/specifications`);
    return;
  }
  await specRepository.setSpecHidden(Number(p.id), Number(spec.id), hidden);
  await auditLogRepository.log(uid(req), hidden ? 'PRODUCT_SPEC_HIDDEN' : 'PRODUCT_SPEC_SHOWN', 'catalog_products', Number(p.id), String(spec.name), null, null);
  flash.set(req, 'success', `"${spec.name}" is ${hidden ? 'not offered' : 'offered again'} for ${p.name}.`);
  await go(res, `/products/${p.id}/specifications`);
}
const hideProductSpec = (req, res) => setHidden(req, res, true);
const showProductSpec = (req, res) => setHidden(req, res, false);

async function resetOne(req, productId, specId) {
  await specRepository.resetSpecForProduct(productId, specId);
  for (const o of (await specRepository.optionsFor([specId], productId))[specId] || []) {
    if (Number(o.product_id || 0) === productId) {
      if (await specRepository.optionUsed(Number(o.id))) await specRepository.setOptionActive(Number(o.id), false, uid(req));
      else await specRepository.deleteOption(Number(o.id));
    }
  }
}

async function resetProductSpec(req, res) {
  const p = await productOr404(req, res);
  if (!p) return;
  const spec = await specRepository.findSpec(id(req.params.specId));
  if (!spec || spec.product_id !== null) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, `/products/${p.id}/specifications`);
    return;
  }
  await resetOne(req, Number(p.id), Number(spec.id));
  await auditLogRepository.log(uid(req), 'PRODUCT_SPEC_RESET', 'catalog_products', Number(p.id), String(spec.name), null, 'follows the category');
  flash.set(req, 'success', `"${spec.name}" follows the category again for ${p.name}.`);
  await go(res, `/products/${p.id}/specifications`);
}

async function resetProduct(req, res) {
  const p = await productOr404(req, res);
  if (!p) return;
  const pid = Number(p.id);
  for (const s of await productSpecs.tree(p, true)) {
    if (s.source === 'category') await resetOne(req, pid, s.id);
    else if (await specRepository.specUsed(s.id)) await specRepository.setSpecActive(s.id, false, uid(req));
    else await specRepository.deleteSpec(s.id);
  }
  await auditLogRepository.log(uid(req), 'PRODUCT_SPECS_RESET', 'catalog_products', pid, null, null, 'every specification follows the category');
  flash.set(req, 'success', `${p.name} follows its category again.`);
  await go(res, `/products/${pid}/specifications`);
}

async function addProductSpec(req, res) {
  const p = await productOr404(req, res);
  if (!p) return;
  const [d, errors] = productSpecs.readSpecForm(req.body || {});
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, `/products/${p.id}/specifications`);
    return;
  }
  d.product_id = Number(p.id);
  d.applies_to = null;
  const specId = await specRepository.createSpec(d, uid(req));
  await auditLogRepository.log(uid(req), 'PRODUCT_SPEC_ADDED', 'spec_definitions', specId, null, null, `${d.name} (only ${p.name})`);
  flash.set(req, 'success', `Specification "${d.name}" added for ${p.name} only — add its options.`);
  await go(res, `/products/${p.id}/specifications?edit=${specId}`);
}

/** An option for this product only, on a category specification. */
async function addProductOption(req, res) {
  const p = await productOr404(req, res);
  if (!p) return;
  const spec = await specRepository.findSpec(id(req.params.specId));
  if (!spec || (spec.product_id !== null && Number(spec.product_id) !== Number(p.id))) {
    flash.set(req, 'error', 'That specification no longer exists.');
    await go(res, `/products/${p.id}/specifications`);
    return;
  }
  const [d, errors] = productSpecs.readOptionForm(req.body || {});
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    await go(res, `/products/${p.id}/specifications?edit=${spec.id}`);
    return;
  }
  if (surfaceFinishList.isLinkedSpec(spec)) {
    // D-357: a product's own finish is one of the Settings list's names.
    const row = await surfaceFinishList.settingsRowByName(d.name);
    if (!row) {
      flash.set(req, 'error', `"${d.name}" is not in Settings › Lists › surface_finish — add it there first (the one list of finish names).`);
      await go(res, `/products/${p.id}/specifications?edit=${spec.id}`);
      return;
    }
    d.name = [...String(row.option_value)].slice(0, surfaceFinishList.MAX_NAME).join('');
    d.dropdown_option_id = Number(row.id);
  }
  d.spec_id = Number(spec.id);
  d.product_id = Number(p.id);
  const oid = await specRepository.createOption(d, uid(req));
  if (d.is_base === 1) await specRepository.clearBase(Number(spec.id), Number(p.id), oid);
  await auditLogRepository.log(uid(req), 'PRODUCT_OPTION_ADDED', 'spec_options', oid, null, null,
    `${spec.name}: ${d.name} ${productSpecs.changeLabel(d.change_type, d.value !== null ? Number(d.value) : null)} (only ${p.name})`);
  flash.set(req, 'success', `Option "${d.name}" added for ${p.name} only.`);
  await go(res, `/products/${p.id}/specifications`);
}

module.exports = {
  categoryData, categoryTab,
  index, addSpec, updateSpec, toggleSpec, deleteSpec, addOption, updateOption, toggleOption, deleteOption,
  product, saveProductFields, saveProductSpec, hideProductSpec, showProductSpec, resetProductSpec, resetProduct, addProductSpec, addProductOption,
};
