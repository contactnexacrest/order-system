'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const productRepository = require('../repositories/productRepository');
const productForms = require('../services/productForms');
const productSpecs = require('../services/productSpecs');

/**
 * Port of App\Controllers\ProductFormController — owner decisions D-382 … D-384 (18 Nov 2026):
 *  - Admin › Pricing › Forms of stone (/pricing/forms) — list, add, edit; switched off, never deleted
 *    once a specification uses it. manage_pricing (the permission of Pricing › Specifications).
 *  - Products › Specifications (HS) (/products/hs-specifications) — list, add, edit; switched off,
 *    never deleted once a product ticks it or an order line has it. manage_pricing too.
 *  - A product's "Sold as" ticks (/products/{id}/sold-as, on the product's Specifications page) —
 *    manage_product_specifications, as the rest of that page.
 * Every change needs a reason (reasonValidator) and is audited (PRODUCT_FORM_CHANGED,
 * HS_SPECIFICATION_CHANGED, PRODUCT_SOLD_AS_CHANGED).
 */

const can = (req, key) => !!(req.user && req.permissions && req.permissions[key]);
const uid = (req) => Number(req.user.id);
const intOrZero = (v) => parseInt(v, 10) || 0;
const reasonOf = (body) => String((body || {}).reason ?? '').trim();

// ------------------------------------------------------------------ forms of stone

async function forms(req, res) {
  const list = await productForms.forms();
  for (const f of list) {
    f.used = await productForms.formUsed(Number(f.id));
    // What the page shows (the PHP view works these out itself).
    f.units_list = productForms.formUnits(f);
    f.units_text = productForms.unitsText(f);
    f.default_unit_label = productForms.unitLabel(String(f.default_unit));
    f.group_label = Object.prototype.hasOwnProperty.call(productSpecs.FORMS, String(f.spec_group)) ? productSpecs.FORMS[String(f.spec_group)] : String(f.spec_group);
  }
  const old = flash.pullOld(req);
  res.renderView('pricing/forms', {
    forms: list,
    edit: intOrZero(req.query.edit),
    canManage: can(req, 'manage_pricing'),
    finishNames: await productForms.finishNames(),
    units: productForms.UNITS.map((u) => ({ code: u, label: productForms.unitLabel(u) })),
    groups: productSpecs.FORMS,
    old,
    oldUnits: old && old.units ? (Array.isArray(old.units) ? old.units : Object.values(old.units)).map(String) : [],
  }, 'layout/base');
}

async function saveForm(req, res) {
  const id = req.params.id !== undefined ? intOrZero(req.params.id) : null;
  const current = id !== null ? await productForms.findForm(id) : null;
  if (id !== null && !current) {
    flash.set(req, 'error', 'That form no longer exists.');
    res.redirect('/pricing/forms');
    return;
  }
  const body = req.body || {};
  const [d, errors] = await productForms.readFormInput(body, id);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    flash.setOld(req, body);
    res.redirect(`/pricing/forms${id !== null ? `?edit=${id}#form-${id}` : '#add-form'}`);
    return;
  }
  const savedId = await productForms.saveForm(id, d, uid(req));
  const after = await productForms.findForm(savedId);
  await auditLogRepository.log(uid(req), 'PRODUCT_FORM_CHANGED', 'product_forms', savedId, id === null ? 'added' : 'changed',
    productForms.formSummary(current), productForms.formSummary(after), reasonOf(body));
  flash.set(req, 'success', id === null ? `Form "${d.name}" added.` : `Form "${d.name}" saved.`);
  res.redirect(`/pricing/forms#form-${savedId}`);
}

async function deleteForm(req, res) {
  const form = await productForms.findForm(intOrZero(req.params.id));
  if (!form) {
    flash.set(req, 'error', 'That form no longer exists.');
    res.redirect('/pricing/forms');
    return;
  }
  const reasonError = reasonValidator.check(String((req.body || {}).reason ?? ''));
  if (reasonError !== null) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/pricing/forms#form-${Number(form.id)}`);
    return;
  }
  if (await productForms.formUsed(Number(form.id))) {
    flash.set(req, 'error', `"${form.name}" is used by a specification — switch it off instead (Edit › Active).`);
    res.redirect(`/pricing/forms#form-${Number(form.id)}`);
    return;
  }
  await productForms.deleteForm(Number(form.id));
  await auditLogRepository.log(uid(req), 'PRODUCT_FORM_CHANGED', 'product_forms', Number(form.id), 'deleted',
    productForms.formSummary(form), null, reasonOf(req.body));
  flash.set(req, 'success', `Form "${form.name}" deleted.`);
  res.redirect('/pricing/forms');
}

// ------------------------------------------------------------------ specifications (HS)

async function specifications(req, res) {
  const specs = await productForms.specifications();
  for (const s of specs) s.used = await productForms.specificationUsed(Number(s.id));
  res.renderView('products/hs_specifications', {
    specs,
    forms: await productForms.forms(),
    hsCodes: await hsCodeRepository.active(),
    finishNames: await productForms.finishNames(),
    edit: intOrZero(req.query.edit),
    canManage: can(req, 'manage_pricing'),
    old: flash.pullOld(req),
  }, 'layout/base');
}

async function saveSpecification(req, res) {
  const id = req.params.id !== undefined ? intOrZero(req.params.id) : null;
  const current = id !== null ? await productForms.findSpecification(id) : null;
  if (id !== null && !current) {
    flash.set(req, 'error', 'That specification no longer exists.');
    res.redirect('/products/hs-specifications');
    return;
  }
  const body = req.body || {};
  const [d, errors] = await productForms.readSpecInput(body, current);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    flash.setOld(req, body);
    res.redirect(`/products/hs-specifications${id !== null ? `?edit=${id}#spec-${id}` : '#add-spec'}`);
    return;
  }
  const savedId = await productForms.saveSpecification(id, d, uid(req));
  await auditLogRepository.log(uid(req), 'HS_SPECIFICATION_CHANGED', 'hs_specifications', savedId, id === null ? 'added' : 'changed',
    productForms.specSummary(current), productForms.specSummary(await productForms.findSpecification(savedId)), reasonOf(body));
  flash.set(req, 'success', `${id === null ? `Specification "${d.name}" added` : `Specification "${d.name}" saved`}`
    + ' — order lines saved before keep their HS code.');
  res.redirect(`/products/hs-specifications#spec-${savedId}`);
}

async function deleteSpecification(req, res) {
  const spec = await productForms.findSpecification(intOrZero(req.params.id));
  if (!spec) {
    flash.set(req, 'error', 'That specification no longer exists.');
    res.redirect('/products/hs-specifications');
    return;
  }
  const reasonError = reasonValidator.check(String((req.body || {}).reason ?? ''));
  if (reasonError !== null) {
    flash.set(req, 'error', reasonError);
    res.redirect(`/products/hs-specifications#spec-${Number(spec.id)}`);
    return;
  }
  if (await productForms.specificationUsed(Number(spec.id))) {
    flash.set(req, 'error', `"${spec.name}" is ticked on a product or used on an order line — switch it off instead (Edit › Active).`);
    res.redirect(`/products/hs-specifications#spec-${Number(spec.id)}`);
    return;
  }
  await productForms.deleteSpecification(Number(spec.id));
  await auditLogRepository.log(uid(req), 'HS_SPECIFICATION_CHANGED', 'hs_specifications', Number(spec.id), 'deleted',
    productForms.specSummary(spec), null, reasonOf(req.body));
  flash.set(req, 'success', `Specification "${spec.name}" deleted.`);
  res.redirect('/products/hs-specifications');
}

// ------------------------------------------------------------------ a product's "Sold as"

async function saveSoldAs(req, res) {
  const product = await productRepository.find(intOrZero(req.params.id));
  if (!product) {
    flash.set(req, 'error', 'That product no longer exists.');
    res.redirect('/products');
    return;
  }
  const pid = Number(product.id);
  const before = await productForms.productSpecIds(pid);
  const allowed = {};
  for (const s of await productForms.specifications()) {
    // Active ones, and any switched-off one the product already ticks (it may stay ticked).
    if ((Number(s.is_active) === 1 && Number(s.form_is_active) === 1) || before.includes(Number(s.id))) {
      allowed[Number(s.id)] = String(s.name);
    }
  }
  const body = req.body || {};
  const postedTicks = body.sold_as && typeof body.sold_as === 'object' ? body.sold_as : {};
  const ticked = [];
  for (const sid of Object.keys(postedTicks)) {
    if (postedTicks[sid] === undefined) continue;
    if (Object.prototype.hasOwnProperty.call(allowed, intOrZero(sid))) ticked.push(intOrZero(sid));
  }
  ticked.sort((a, b) => a - b);
  const errors = [];
  if (!ticked.length) errors.push('Tick at least one specification the product is sold as.');
  const reasonError = reasonValidator.check(String(body.reason ?? ''));
  if (reasonError !== null) errors.push(reasonError);
  if (errors.length) {
    flash.set(req, 'error', errors.join(' '));
    res.redirect(`/products/${pid}/specifications#sold-as`);
    return;
  }
  if (JSON.stringify(ticked) === JSON.stringify(before)) {
    flash.set(req, 'success', 'No changes were made.');
    res.redirect(`/products/${pid}/specifications#sold-as`);
    return;
  }
  await productForms.setProductSpecs(pid, ticked);
  const names = (ids) => (ids.length ? ids.map((i) => (Object.prototype.hasOwnProperty.call(allowed, i) ? allowed[i] : `#${i}`)).join(', ') : 'none (every specification)');
  await auditLogRepository.log(uid(req), 'PRODUCT_SOLD_AS_CHANGED', 'catalog_products', pid, 'sold_as',
    names(before), names(ticked), reasonOf(body));
  flash.set(req, 'success', `Sold as saved — ${names(ticked)}. New order lines offer these; lines saved before keep theirs.`);
  res.redirect(`/products/${pid}/specifications#sold-as`);
}

/** The groups (productSpecs.FORMS labels) a set of specifications brings, for the Specifications page. */
function groupsText(specs) {
  const groups = {};
  const order = [];
  for (const s of specs) {
    const g = String(s.form_spec_group ?? '');
    if (Object.prototype.hasOwnProperty.call(productSpecs.FORMS, g)) {
      if (!Object.prototype.hasOwnProperty.call(groups, g)) order.push(g);
      groups[g] = productSpecs.FORMS[g].toLowerCase();
    }
  }
  return order.map((g) => groups[g]).join(', ');
}

module.exports = { forms, saveForm, deleteForm, specifications, saveSpecification, deleteSpecification, saveSoldAs, groupsText };
