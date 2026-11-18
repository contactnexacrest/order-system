'use strict';

const db = require('../config/db');
const reasonValidator = require('../helpers/reasonValidator');
const hsCodeRepository = require('../repositories/hsCodeRepository');
const lineUnits = require('./lineUnits');

/**
 * Port of App\Services\ProductForms — owner decisions D-382 … D-385 (18 Nov 2026, mockups approved,
 * plan confirmed twice):
 *
 *  - D-382 forms of stone (product_forms): the units each form is sold in (lineUnits.canonical
 *    codes), the unit a new line starts on, its group (one of productSpecs.FORMS — so
 *    spec_definitions.applies_to keeps working), its default finish. Admin › Pricing › Forms of stone.
 *  - D-383 the specification list (hs_specifications): name, form, usual finish, HS code (an active
 *    HS Code Master code), when to use. Products › Specifications (HS). Which finishes a form offers:
 *    surface_finish_forms (no rows for a form = every finish).
 *  - D-384 a product's "Sold as" ticks (product_hs_specifications); none = every active specification.
 *    The product's forms for spec applies_to = the groups of its ticked specifications' forms; none
 *    ticked → the old catalog_products.forms (kept, read-only) → all.
 *  - D-385 an order line's Sold as (order_products.hs_spec_id): it sets the line's HS code (locked —
 *    only override_line_hs_code with a reason may change it), the units offered and the finishes.
 *
 * A form or specification once used is switched off, never deleted. PHP twin: App\Services\ProductForms.
 */

/** The units a form can tick, in this order (lineUnits.canonical codes). */
const UNITS = ['CBM', 'M2', 'SQFT', 'PCS', 'TON', 'RMT', 'KG'];

/** The unit option preferred for a canonical code (the Natural Stone unit list's names). */
const PREFERRED_UNIT = { CBM: 'CBM', M2: 'SQM', SQFT: 'SQFT', PCS: 'PCS', TON: 'TON', RMT: 'RMT', KG: 'KG' };

const PERMISSION_OVERRIDE = 'override_line_hs_code';

// Lazy: productSpecs reads productSpecGroups from here.
const productSpecs = () => require('./productSpecs');
const has = (o, k) => o !== null && o !== undefined && Object.prototype.hasOwnProperty.call(o, k);
const intOrZero = (v) => parseInt(v, 10) || 0;
/** PHP filter_var(FILTER_VALIDATE_INT) */
function validInt(v) {
  const s = String(v ?? '').trim();
  return /^[+-]?(0|[1-9]\d*)$/.test(s) ? Number(s) : false;
}
/** PHP mb_strlen */
const mbLen = (s) => [...String(s)].length;
/** Named placeholders for an IN list: [':i0,:i1', { i0: …, i1: … }]. */
function inList(values, prefix = 'i') {
  const params = {};
  const names = values.map((v, k) => { params[`${prefix}${k}`] = v; return `:${prefix}${k}`; });
  return [names.join(','), params];
}
/** PHP !empty() on a posted value. */
const posted = (v) => !(v === undefined || v === null || v === '' || v === '0' || v === 0 || v === false || (Array.isArray(v) && !v.length));
/** A posted list (units[] / units[0] …) as an array of values. */
const asList = (v) => (v === undefined || v === null ? [] : Array.isArray(v) ? v : typeof v === 'object' ? Object.values(v) : [v]);

// ------------------------------------------------------------------ forms

async function forms(activeOnly = false) {
  return db.query(`SELECT * FROM product_forms${activeOnly ? ' WHERE is_active = 1' : ''} ORDER BY sort_order, name, id`);
}

async function findForm(id) {
  return db.queryOne('SELECT * FROM product_forms WHERE id = :id', { id: intOrZero(id) });
}

/** The form's units (canonical codes). */
function formUnits(form) {
  return String((form || {}).units ?? '').split(',').map((u) => String(u).trim().toUpperCase()).filter((u) => u !== '');
}

/** "CBM", "SQM" … for screens and messages. */
function unitLabel(code) {
  return code === 'M2' ? 'SQM' : String(code);
}

function unitsText(form) {
  return formUnits(form).map(unitLabel).join(', ');
}

/** The unit option a new line starts on for this form's default unit ("SQM" for M2). */
function preferredUnit(code) {
  return has(PREFERRED_UNIT, code) ? PREFERRED_UNIT[code] : code;
}

/** Reads the Forms of stone form. Returns [data, errors]. */
async function readFormInput(post, currentId = null) {
  const errors = [];
  const name = String(post.name ?? '').trim();
  if (name === '' || mbLen(name) > 80) errors.push('Name: up to 80 characters (e.g. Raw block).');
  const picked = [];
  for (const raw of asList(post.units)) {
    const u = String(raw ?? '').trim().toUpperCase();
    if (UNITS.includes(u) && !picked.includes(u)) picked.push(u);
  }
  // Keep the order of UNITS.
  const units = UNITS.filter((u) => picked.includes(u));
  if (!units.length) errors.push('Tick at least one unit the form is sold in.');
  const def = String(post.default_unit ?? '').trim().toUpperCase();
  if (def === '' || !units.includes(def)) errors.push('Starts on: choose one of the ticked units.');
  const group = String(post.spec_group ?? '').trim();
  if (!has(productSpecs().FORMS, group)) errors.push('Group: choose which specifications the form uses (Blocks, Slabs, Tiles …).');
  const finish = String(post.default_finish ?? '').trim();
  if (finish !== '' && !(await finishNames()).includes(finish)) {
    errors.push('Default finish: choose one of the finishes in Settings › Lists › surface_finish, or none.');
  }
  let order = validInt(post.sort_order ?? '0');
  if (order === false || order < 0 || order > 999) {
    errors.push('Order: a whole number from 0 to 999.');
    order = 0;
  }
  if (name !== '') {
    for (const f of await forms()) {
      if (Number(f.id) !== Number(currentId || 0) && String(f.name).toLowerCase() === name.toLowerCase()) {
        errors.push(`There is already a form called "${f.name}".`);
      }
    }
  }
  const reasonError = reasonValidator.check(String(post.reason ?? ''));
  if (reasonError !== null) errors.push(reasonError);
  return [{
    name, units: units.join(','), default_unit: def, spec_group: group,
    default_finish: finish === '' ? null : finish, sort_order: Number(order),
    is_active: posted(post.is_active) ? 1 : 0,
  }, errors];
}

async function uniqueCode(name) {
  let base = String(name).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'form';
  base = base.slice(0, 34);
  let code = base;
  let n = 2;
  // eslint-disable-next-line no-constant-condition
  while (true) {
    if (!(await db.queryOne('SELECT 1 AS x FROM product_forms WHERE code = :c', { c: code }))) return code;
    code = `${base}_${n++}`;
  }
}

async function saveForm(id, d, userId) {
  if (id === null || id === undefined) {
    const code = await uniqueCode(d.name);
    const r = await db.execute(
      `INSERT INTO product_forms (code, name, units, default_unit, spec_group, default_finish, sort_order, is_active, updated_by)
       VALUES (:c, :n, :u, :du, :g, :f, :o, :a, :by)`,
      { c: code, n: d.name, u: d.units, du: d.default_unit, g: d.spec_group, f: d.default_finish, o: d.sort_order, a: d.is_active, by: userId }
    );
    return Number(r.insertId);
  }
  await db.execute(
    'UPDATE product_forms SET name = :n, units = :u, default_unit = :du, spec_group = :g, default_finish = :f, sort_order = :o, is_active = :a, updated_by = :by WHERE id = :id',
    { n: d.name, u: d.units, du: d.default_unit, g: d.spec_group, f: d.default_finish, o: d.sort_order, a: d.is_active, by: userId, id }
  );
  return Number(id);
}

/** A form is used once a specification or an order line (through its specification) uses it. */
async function formUsed(id) {
  return !!(await db.queryOne('SELECT 1 AS x FROM hs_specifications WHERE form_id = :id LIMIT 1', { id }));
}

async function deleteForm(id) {
  await db.execute('DELETE FROM product_forms WHERE id = :id', { id });
}

/** "Raw block: CBM, TON · starts on CBM · Blocks · Roughly trimmed · order 4 · active" for the audit log. */
function formSummary(f) {
  if (!f) return null;
  const FORMS = productSpecs().FORMS;
  return `${f.name}: ${unitsText(f)} · starts on ${unitLabel(String(f.default_unit))}`
    + ` · ${has(FORMS, String(f.spec_group)) ? FORMS[String(f.spec_group)] : String(f.spec_group)}`
    + ` · ${f.default_finish ?? 'no default finish'} · order ${Number(f.sort_order) || 0} · ${Number(f.is_active) === 1 ? 'active' : 'switched off'}`;
}

// ----------------------------------------------------------- specifications (HS)

const SPEC_SELECT = `SELECT s.*, f.name AS form_name, f.units AS form_units, f.default_unit AS form_default_unit,
            f.spec_group AS form_spec_group, f.default_finish AS form_default_finish, f.is_active AS form_is_active
          FROM hs_specifications s JOIN product_forms f ON f.id = s.form_id`;

/** With the form's name, units, default unit, group and default finish. */
async function specifications(activeOnly = false) {
  return db.query(`${SPEC_SELECT}${activeOnly ? ' WHERE s.is_active = 1 AND f.is_active = 1' : ''} ORDER BY s.sort_order, s.name, s.id`);
}

async function findSpecification(id) {
  return db.queryOne(`${SPEC_SELECT} WHERE s.id = :id`, { id: intOrZero(id) });
}

/** The specification's form as a product_forms-shaped row. */
function formOf(spec) {
  return {
    id: spec.form_id, name: spec.form_name, units: spec.form_units, default_unit: spec.form_default_unit,
    spec_group: spec.form_spec_group, default_finish: spec.form_default_finish,
  };
}

/** Reads the Specifications (HS) form. Returns [data, errors]. */
async function readSpecInput(post, current = null) {
  const errors = [];
  const name = String(post.name ?? '').trim();
  if (name === '' || mbLen(name) > 120) errors.push('Name: up to 120 characters (e.g. Gangsaw slab).');
  const formId = intOrZero(post.form_id);
  const form = formId > 0 ? await findForm(formId) : null;
  if (!form || (Number(form.is_active) !== 1 && Number((current || {}).form_id || 0) !== formId)) {
    errors.push('Form: choose one of the active forms of stone.');
  }
  const finish = String(post.usual_finish ?? '').trim();
  if (finish !== '' && !(await finishNames()).includes(finish)) {
    errors.push('Usual finish: choose one of the finishes in Settings › Lists › surface_finish, or none.');
  }
  const hs = String(post.hs_code ?? '').trim();
  if (!/^(\d{6}|\d{8})$/.test(hs)) {
    errors.push('HS code: 6 or 8 digits, no dots (e.g. 68022310).');
  } else if (!(await hsCodeRepository.isActiveCode(hs))) {
    errors.push(`HS code ${hs} is not an active code of the HS Code Master — add or switch it on in HS codes first.`);
  }
  const when = String(post.when_to_use ?? '').trim();
  if (mbLen(when) > 255) errors.push('When to use: up to 255 characters.');
  let order = validInt(post.sort_order ?? '0');
  if (order === false || order < 0 || order > 999) {
    errors.push('Order: a whole number from 0 to 999.');
    order = 0;
  }
  if (name !== '') {
    for (const s of await specifications()) {
      if (Number(s.id) !== Number((current || {}).id || 0) && String(s.name).toLowerCase() === name.toLowerCase()) {
        errors.push(`There is already a specification called "${s.name}".`);
      }
    }
  }
  const reasonError = reasonValidator.check(String(post.reason ?? ''));
  if (reasonError !== null) errors.push(reasonError);
  return [{
    name, form_id: formId, usual_finish: finish === '' ? null : finish, hs_code: hs,
    when_to_use: when === '' ? null : when, sort_order: Number(order), is_active: posted(post.is_active) ? 1 : 0,
  }, errors];
}

async function saveSpecification(id, d, userId) {
  const p = { n: d.name, f: d.form_id, uf: d.usual_finish, hs: d.hs_code, w: d.when_to_use, o: d.sort_order, a: d.is_active, by: userId };
  if (id === null || id === undefined) {
    const r = await db.execute(
      `INSERT INTO hs_specifications (name, form_id, usual_finish, hs_code, when_to_use, sort_order, is_active, updated_by)
       VALUES (:n, :f, :uf, :hs, :w, :o, :a, :by)`, p
    );
    return Number(r.insertId);
  }
  await db.execute(
    'UPDATE hs_specifications SET name = :n, form_id = :f, usual_finish = :uf, hs_code = :hs, when_to_use = :w, sort_order = :o, is_active = :a, updated_by = :by WHERE id = :id',
    { ...p, id }
  );
  return Number(id);
}

/** A specification is used once a product ticks it or an order line has it. */
async function specificationUsed(id) {
  for (const sql of ['SELECT 1 AS x FROM product_hs_specifications WHERE hs_spec_id = :id LIMIT 1', 'SELECT 1 AS x FROM order_products WHERE hs_spec_id = :id LIMIT 1']) {
    if (await db.queryOne(sql, { id })) return true;
  }
  return false;
}

async function deleteSpecification(id) {
  await db.execute('DELETE FROM hs_specifications WHERE id = :id', { id });
}

function specSummary(s) {
  if (!s) return null;
  return `${s.name} · ${s.form_name ?? `form #${Number(s.form_id) || 0}`} · ${s.usual_finish ?? 'no usual finish'}`
    + ` · HS ${s.hs_code} · order ${Number(s.sort_order) || 0} · ${Number(s.is_active) === 1 ? 'active' : 'switched off'}`;
}

// --------------------------------------------------------------- a product's "Sold as"

/** The product's ticked specification ids. */
async function productSpecIds(productId) {
  return (await db.query('SELECT hs_spec_id FROM product_hs_specifications WHERE product_id = :p ORDER BY hs_spec_id', { p: productId }))
    .map((r) => Number(r.hs_spec_id));
}

/**
 * The specifications a line of this product may be sold as: its ticked ones that are active,
 * or every active one when none is ticked.
 */
async function offeredFor(productId) {
  const active = await specifications(true);
  const ticked = productId !== null && productId !== undefined ? await productSpecIds(productId) : [];
  if (!ticked.length) return active;
  return active.filter((s) => ticked.includes(Number(s.id)));
}

/** Each product's ticked specifications ("Sold as"), one query, for lists: { productId: [{ id, name, hs_code }] }. */
async function soldAsByProduct(productIds) {
  const ids = [...new Set((productIds || []).map((v) => intOrZero(v)).filter((i) => i > 0))];
  if (!ids.length) return {};
  const [list, params] = inList(ids);
  const rows = await db.query(
    `SELECT x.product_id, s.id, s.name, s.hs_code FROM product_hs_specifications x JOIN hs_specifications s ON s.id = x.hs_spec_id
      WHERE x.product_id IN (${list}) ORDER BY x.product_id, s.sort_order, s.name, s.id`, params
  );
  const out = {};
  for (const r of rows) {
    const pid = Number(r.product_id);
    (out[pid] = out[pid] || []).push({ id: Number(r.id), name: String(r.name), hs_code: String(r.hs_code) });
  }
  return out;
}

async function setProductSpecs(productId, specIds) {
  await db.execute('DELETE FROM product_hs_specifications WHERE product_id = :p', { p: productId });
  for (const sid of [...new Set(specIds.map((v) => intOrZero(v)))]) {
    await db.execute('INSERT INTO product_hs_specifications (product_id, hs_spec_id) VALUES (:p, :s)', { p: productId, s: sid });
  }
}

/**
 * The product's forms for spec_definitions.applies_to (productSpecs.FORMS keys): the groups of
 * its ticked specifications' forms; none ticked → null (the caller falls back to the old
 * catalog_products.forms, then all).
 */
async function productSpecGroups(productId) {
  const rows = await db.query(
    `SELECT DISTINCT f.spec_group FROM product_hs_specifications x JOIN hs_specifications s ON s.id = x.hs_spec_id
       JOIN product_forms f ON f.id = s.form_id WHERE x.product_id = :p ORDER BY f.spec_group`, { p: productId }
  );
  const FORMS = productSpecs().FORMS;
  const groups = rows.map((r) => String(r.spec_group)).filter((g) => has(FORMS, g));
  return groups.length ? groups : null;
}

// --------------------------------------------------------------- finishes per form

/** The active surface_finish names, in order. */
async function finishNames() {
  return (await db.query("SELECT option_value FROM dropdown_options WHERE list_key = 'surface_finish' AND is_active = 1 ORDER BY sort_order, id"))
    .map((r) => String(r.option_value));
}

/** dropdown option id => the form ids it is offered for. */
async function finishFormMap() {
  const out = {};
  for (const r of await db.query('SELECT dropdown_option_id, form_id FROM surface_finish_forms ORDER BY dropdown_option_id, form_id')) {
    const d = Number(r.dropdown_option_id);
    (out[d] = out[d] || []).push(Number(r.form_id));
  }
  return out;
}

async function setFinishForms(dropdownOptionId, formIds) {
  await db.execute('DELETE FROM surface_finish_forms WHERE dropdown_option_id = :d', { d: dropdownOptionId });
  for (const fid of [...new Set(formIds.map((v) => intOrZero(v)))]) {
    await db.execute('INSERT INTO surface_finish_forms (dropdown_option_id, form_id) VALUES (:d, :f)', { d: dropdownOptionId, f: fid });
  }
}

/**
 * The finishes a form offers: surface_finish dropdown rows [{ id, name }], or null when the form
 * has no rows (= every finish, the safety rule).
 */
async function finishesForForm(formId) {
  const rows = await db.query(
    `SELECT d.id, d.option_value FROM surface_finish_forms x JOIN dropdown_options d ON d.id = x.dropdown_option_id
      WHERE x.form_id = :f AND d.list_key = 'surface_finish' ORDER BY d.sort_order, d.id`, { f: formId }
  );
  if (!rows.length) return null;
  return rows.map((r) => ({ id: Number(r.id), name: String(r.option_value) }));
}

// --------------------------------------------------------------- order lines

/**
 * For other screens and the document builders: the form name of an order line's
 * specification (order_products.hs_spec_id), or null when the line has no Sold as.
 */
async function formNameForLine(line) {
  const specId = intOrZero((line || {}).hs_spec_id);
  if (specId <= 0) return null;
  const spec = await findSpecification(specId);
  return spec ? String(spec.form_name) : null;
}

/** Every line of an order that has a Sold as: { order_products.id: its form name } (one query). */
async function formNameForOrderLines(orderId) {
  const rows = await db.query(
    `SELECT op.id, f.name FROM order_products op JOIN hs_specifications s ON s.id = op.hs_spec_id
       JOIN product_forms f ON f.id = s.form_id WHERE op.order_id = :o`, { o: orderId }
  );
  const out = {};
  for (const r of rows) out[Number(r.id)] = String(r.name);
  return out;
}

/** The lines' specifications, one query: { order_products.id: { spec, form, hs_code } }. */
async function soldAsForOrderLines(orderId) {
  const rows = await db.query(
    `SELECT op.id, s.name AS spec, f.name AS form, s.hs_code FROM order_products op JOIN hs_specifications s ON s.id = op.hs_spec_id
       JOIN product_forms f ON f.id = s.form_id WHERE op.order_id = :o`, { o: orderId }
  );
  const out = {};
  for (const r of rows) out[Number(r.id)] = { spec: String(r.spec), form: String(r.form), hs_code: String(r.hs_code) };
  return out;
}

async function setLineSoldAs(lineId, specId, overrideReason) {
  await db.execute('UPDATE order_products SET hs_spec_id = :s, hs_override_reason = :r WHERE id = :id',
    { s: specId ?? null, r: overrideReason ?? null, id: lineId });
}

/** Whether the signed-in person (req.permissions) may change a line's HS code from its specification. */
function canOverride(req) {
  return !!(req && req.user && req.permissions && req.permissions[PERMISSION_OVERRIDE]);
}

/**
 * D-385: an order line's Sold as, checked before the line is saved.
 *
 * In: line_no, posted_spec (the posted hs_spec_id; null = the form has no such field), catalog_product_id,
 * line_type, currency_code, hs_code (posted), hs_override_reason (posted), unit, size (piece_*_cm),
 * selection (spec id => option id), existing (the saved line, or null for a new one), can_override.
 *
 * Out: spec (the specification row or null), hs_code (the line's HS code when a Sold as is chosen, else
 * null = keep the posted one), reason (hs_override_reason to save), overridden (a new change of the HS code
 * to audit: [from, to]) and refusal (the message, or null).
 */
async function checkLine(input) {
  const out = { spec: null, hs_code: null, reason: null, overridden: null, refusal: null };
  const lineNo = intOrZero(input.line_no);
  const existing = input.existing ?? null;
  const catalogId = input.catalog_product_id !== undefined && input.catalog_product_id !== null ? intOrZero(input.catalog_product_id) : null;
  if (String(input.line_type ?? 'goods') !== 'goods') return out;
  const postedSpec = input.posted_spec ?? null;
  const specId = postedSpec === null ? intOrZero((existing || {}).hs_spec_id) : intOrZero(postedSpec);
  if (specId <= 0) {
    // Required for a goods line picked from Products on a USD order (where picking is required);
    // a saved line whose product did not change may stay without one (lines saved before D-385).
    const productChanged = existing === null || intOrZero(existing.catalog_product_id) !== (catalogId ?? 0);
    if (catalogId !== null && productChanged && await require('./orderLinePricing').productPickRequired(input.currency_code ?? null)) {
      out.refusal = `Line ${lineNo}: choose what the product is sold as (Sold as) — it sets the line's HS code.`;
    }
    return out;
  }
  const spec = await findSpecification(specId);
  const kept = existing !== null && intOrZero(existing.hs_spec_id) === specId;
  if (!spec || (!kept && (Number(spec.is_active) !== 1 || Number(spec.form_is_active) !== 1))) {
    out.refusal = `Line ${lineNo}: that Sold as is no longer offered — choose another.`;
    return out;
  }
  if (catalogId !== null && !kept) {
    const ticked = await productSpecIds(catalogId);
    if (ticked.length && !ticked.includes(specId)) {
      const product = await require('../repositories/productRepository').find(catalogId);
      out.refusal = `Line ${lineNo}: ${product ? `"${product.name}"` : 'this product'} is not sold as "${spec.name}" — choose one of its Sold as.`;
      return out;
    }
  }
  out.spec = spec;
  const form = formOf(spec);
  // The unit must be one the form is sold in.
  const unit = lineUnits.canonical(String(input.unit ?? ''));
  if (unit === null || !formUnits(form).includes(unit)) {
    const what = String(spec.name) === String(form.name) ? String(spec.name) : `${spec.name} (${form.name})`;
    out.refusal = `Line ${lineNo}: ${what} is sold in ${unitsText(form)} — choose one of these units.`;
    return out;
  }
  // The chosen Surface finish must be offered for the form.
  const finishRef = await finishRefusal(lineNo, form, catalogId, input.selection ?? null);
  if (finishRef !== null) {
    out.refusal = finishRef;
    return out;
  }
  // The HS code comes from the specification; only the MD / ED change it, with a reason.
  const specCode = String(spec.hs_code);
  const postedHs = String(input.hs_code ?? '').trim();
  const postedReason = String(input.hs_override_reason ?? '').trim();
  if (postedHs === '' || postedHs === specCode) {
    out.hs_code = specCode;
    return out;
  }
  const mayOverride = !!input.can_override;
  if (kept && postedHs === String(existing.hs_code ?? '') && String(existing.hs_override_reason ?? '').trim() !== '') {
    // A change made before, unchanged: kept (a new reason only from someone who may change it).
    out.hs_code = postedHs;
    out.reason = mayOverride && postedReason !== '' && reasonValidator.check(postedReason) === null ? postedReason : String(existing.hs_override_reason);
    return out;
  }
  if (!mayOverride) {
    out.refusal = `Line ${lineNo}: the HS code comes from its Sold as ("${spec.name}": ${specCode}) — only the MD or the ED can change it.`;
    return out;
  }
  if (reasonValidator.check(postedReason) !== null) {
    out.refusal = `Line ${lineNo}: give the reason for changing the HS code from ${specCode} (at least ${reasonValidator.MIN_LENGTH} characters).`;
    return out;
  }
  if (!/^(\d{6}|\d{8})$/.test(postedHs) || !(await hsCodeRepository.isActiveCode(postedHs))) {
    out.refusal = `Line ${lineNo}: HS code "${postedHs}" is not an active code of the HS Code Master.`;
    return out;
  }
  out.hs_code = postedHs;
  out.reason = postedReason;
  const from = kept ? String(existing.hs_code ?? specCode) : specCode;
  out.overridden = [from, postedHs];
  return out;
}

/** The refusal when the line's chosen Surface finish option is not offered for the form, else null. */
async function finishRefusal(lineNo, form, catalogId, selection) {
  if (catalogId === null || selection === null || selection === undefined || !Object.keys(selection).length) return null;
  const offered = await finishesForForm(intOrZero(form.id));
  if (offered === null) return null;
  const optionIds = Object.values(selection).map((v) => intOrZero(v)).filter((i) => i > 0);
  if (!optionIds.length) return null;
  const [list, params] = inList(optionIds);
  const rows = await db.query(
    `SELECT o.name, o.dropdown_option_id FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id
      WHERE o.id IN (${list}) AND s.list_key = 'surface_finish' AND o.dropdown_option_id IS NOT NULL`, params
  );
  const ids = offered.map((f) => f.id);
  for (const o of rows) {
    if (!ids.includes(Number(o.dropdown_option_id))) {
      return `Line ${lineNo}: the finish "${o.name}" is not offered for ${form.name} — choose another Surface finish.`;
    }
  }
  return null;
}

/**
 * D-385: with a Sold as, the choices the line needs follow its unit (instead of D-348's Thickness and
 * Size / format): CBM / TON nothing more; SQM / SQFT the thickness (the Thickness option or the
 * Thickness (cm)); PCS the length, width and thickness. Null when nothing is missing.
 */
async function unitNeedsRefusal(lineNo, unit, size, catalogProductId, selection) {
  const kind = lineUnits.canonical(unit);
  const sz = size || {};
  const thicknessKnown = (lineUnits.size(sz.piece_thickness_cm ?? null) ?? 0) > 0 || await thicknessChosen(catalogProductId, selection);
  if (kind === 'M2' || kind === 'SQFT') {
    return thicknessKnown ? null
      : `Line ${lineNo}: choose the Thickness or enter the Thickness (cm) — a price per ${lineUnits.label(unit)} needs it.`;
  }
  if (kind === 'PCS') {
    const missing = [];
    for (const [field, what] of [['piece_length_cm', 'length'], ['piece_width_cm', 'width']]) {
      if ((lineUnits.size(sz[field] ?? null) ?? 0) <= 0) missing.push(what);
    }
    if (!thicknessKnown) missing.push('thickness');
    if (missing.length) {
      const last = missing.pop();
      return `Line ${lineNo}: enter the ${missing.length ? `${missing.join(', ')} and ` : ''}${last} (cm) — a price per piece needs ${missing.length ? 'them' : 'it'}.`;
    }
  }
  return null;
}

/** Whether the selection holds a Thickness option with a thickness in mm. */
async function thicknessChosen(catalogProductId, selection) {
  if (catalogProductId === null || catalogProductId === undefined || !selection || !Object.keys(selection).length) return false;
  const product = await require('../repositories/productRepository').find(Number(catalogProductId));
  if (!product) return false;
  for (const s of await productSpecs().offered(product)) {
    if (s.kind !== 'thickness' || !has(selection, s.id)) continue;
    for (const o of s.options) {
      if (Number(o.id) === Number(selection[s.id]) && o.thickness_mm !== null && o.thickness_mm !== undefined) return true;
    }
  }
  return false;
}

/**
 * For "Sold as" on the line panel and the New Order rows: every active specification with what the
 * browser needs to refill the line — its HS code, its form's units (canonical), the unit a new line
 * starts on, the default finish and the finishes the form offers (names; null = all).
 */
async function pickerSpecifications() {
  const finishCache = {};
  const out = [];
  for (const s of await specifications(true)) {
    const fid = Number(s.form_id);
    if (!has(finishCache, fid)) {
      const f = await finishesForForm(fid);
      finishCache[fid] = f === null ? null : f.map((x) => x.name);
    }
    const form = formOf(s);
    out.push({
      id: Number(s.id), name: String(s.name), hs_code: String(s.hs_code), form: String(s.form_name),
      units: formUnits(form), default_unit: preferredUnit(String(s.form_default_unit)),
      default_finish: s.form_default_finish !== null && s.form_default_finish !== undefined ? String(s.form_default_finish) : null,
      finishes: finishCache[fid],
    });
  }
  return out;
}

/** For "Pick from Products": { product id: its ticked active specification ids } (absent = every one). */
async function pickerSoldAs(pickerProducts) {
  const out = {};
  for (const [pid, specs] of Object.entries(await soldAsByProduct((pickerProducts || []).map((p) => Number(p.id))))) {
    out[pid] = specs.map((s) => s.id);
  }
  return out;
}

module.exports = {
  UNITS, PERMISSION_OVERRIDE,
  forms, findForm, formUnits, unitLabel, unitsText, preferredUnit, readFormInput, saveForm, formUsed, deleteForm, formSummary,
  specifications, findSpecification, formOf, readSpecInput, saveSpecification, specificationUsed, deleteSpecification, specSummary,
  productSpecIds, offeredFor, soldAsByProduct, setProductSpecs, productSpecGroups,
  finishNames, finishFormMap, setFinishForms, finishesForForm,
  formNameForLine, formNameForOrderLines, soldAsForOrderLines, setLineSoldAs, canOverride, checkLine, unitNeedsRefusal,
  pickerSpecifications, pickerSoldAs,
};
