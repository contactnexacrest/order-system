'use strict';

/**
 * Owner decision D-215 (5a): product categories with their own attributes.
 * The system category "Natural Stone" is today's products — its attributes
 * are the order line's existing Dimensions and Finish, so stone lines print
 * word for word as before. Another category's attribute values are kept on
 * the order line (order_products.attribute_values, JSON label => value).
 * Port of App\Repositories\ProductCategoryRepository.
 */
const db = require('../config/db');

const MAX_ATTRIBUTES = 6;

async function attributeLabels(categoryId) {
  return (await db.query('SELECT label FROM product_category_attributes WHERE category_id = :c ORDER BY sort_order, id', { c: categoryId })).map((r) => String(r.label));
}

async function all(activeOnly = false) {
  const rows = await db.query(`SELECT * FROM product_categories${activeOnly ? ' WHERE is_active = 1' : ''} ORDER BY is_system DESC, sort_order, name`);
  for (const row of rows) {
    row.attributes = await attributeLabels(row.id);
  }
  return rows;
}

async function find(id) {
  const row = await db.queryOne('SELECT * FROM product_categories WHERE id = :id', { id });
  if (!row) return null;
  row.attributes = await attributeLabels(id);
  return row;
}

async function nameTaken(name, exceptId = null) {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM product_categories WHERE name = :n AND id <> :id', { n: name, id: exceptId || 0 });
  return Number(row.n) > 0;
}

async function writeLabels(tx, categoryId, labels) {
  for (let i = 0; i < labels.length; i++) {
    await tx.execute('INSERT INTO product_category_attributes (category_id, label, sort_order) VALUES (:c, :l, :s)', { c: categoryId, l: labels[i], s: i + 1 });
  }
}

async function create(name, labels, userId) {
  return db.transaction(async (tx) => {
    const result = await tx.execute('INSERT INTO product_categories (name, is_system, is_active, sort_order, created_by) VALUES (:n, 0, 1, 10, :u)', { n: name, u: userId });
    await writeLabels(tx, result.insertId, labels);
    return result.insertId;
  });
}

async function update(id, name, labels, active) {
  await db.transaction(async (tx) => {
    await tx.execute('UPDATE product_categories SET name = :n, is_active = :a WHERE id = :id AND is_system = 0', { n: name, a: active ? 1 : 0, id });
    await tx.execute('DELETE FROM product_category_attributes WHERE category_id = :id', { id });
    await writeLabels(tx, id, labels);
  });
}

/** @returns {[string[], string|null]} the attribute labels, or the error */
function parseLabels(raw) {
  const labels = [];
  for (const value of [].concat(raw || [])) {
    const label = String(value ?? '').trim();
    if (label === '') continue;
    if ([...label].length > 60) return [[], `Attribute names can be at most 60 characters ("${label}").`];
    if (labels.map((l) => l.toLowerCase()).includes(label.toLowerCase())) return [[], `"${label}" is listed twice — each attribute once.`];
    labels.push(label);
  }
  if (labels.length === 0) return [[], 'Give the category at least one attribute (e.g. Size, Grade, Colour).'];
  if (labels.length > MAX_ATTRIBUTES) return [[], `A category can have at most ${MAX_ATTRIBUTES} attributes.`];
  return [labels, null];
}

/** Saves an order line's category and, for a non-stone category, its attribute values (label => value, in the category's order). */
async function setLineCategory(orderProductId, categoryId, values) {
  await db.execute('UPDATE order_products SET category_id = :c, attribute_values = :v WHERE id = :id',
    { c: categoryId, v: values === null ? null : JSON.stringify(values), id: orderProductId });
}

/** What a document prints for the line's attributes: [] for a stone line, else label/value with "To Be Confirmed" for an empty value. */
function documentAttributes(line) {
  if (!line.category_id || !line.attribute_values) return [];
  let values;
  try {
    values = JSON.parse(line.attribute_values);
  } catch (e) {
    return [];
  }
  if (!values || typeof values !== 'object') return [];
  return Object.entries(values).map(([label, value]) => ({ label: String(label), value: String(value ?? '').trim() !== '' ? String(value) : 'To Be Confirmed' }));
}

/**
 * Owner decision D-257 Phase 0B (5 Nov 2026): the T&C clause sets a
 * category can name. The documents print them from Phase 1; until then
 * the choice is only stored (Natural Stone = today's stone clauses).
 */
const TC_CLAUSE_SETS = {
  natural_stone: "Natural stone clauses (today's)",
  general_goods: 'General goods clauses',
  engineering: 'Engineering goods clauses',
};
const MAX_QUALITY_DOCUMENTS = 10;

/** The units a category allows (upper case); [] = any unit. */
function allowedUnits(category) {
  const raw = String((category && category.allowed_units) ?? '').trim();
  return raw === '' ? [] : raw.split(',').map((u) => u.trim().toUpperCase()).filter((u) => u !== '');
}

/** The quality documents a category asks for on an order; [] = none. */
function qualityDocuments(category) {
  const raw = String((category && category.quality_documents) ?? '').trim();
  return raw === '' ? [] : raw.split(/\r\n|\r|\n/).map((d) => d.trim()).filter((d) => d !== '');
}

/** Reads the posted category settings. @returns {[object, string|null]} the settings, or the error */
async function parseSettings(body, knownUnits, packageTypes) {
  const hsCodeRepository = require('./hsCodeRepository');
  const known = knownUnits.map((u) => u.toUpperCase());
  const allowed = [];
  for (const raw of [].concat(body.allowed_units ?? [])) {
    const u = String(raw ?? '').trim().toUpperCase();
    if (u === '') continue;
    if (!known.includes(u)) return [{}, `Unit "${u}" is not on the Units list.`];
    if (!allowed.includes(u)) allowed.push(u);
  }
  const def = String(body.default_unit ?? '').trim().toUpperCase();
  if (def !== '' && !known.includes(def)) return [{}, `Unit "${def}" is not on the Units list.`];
  if (def !== '' && allowed.length > 0 && !allowed.includes(def)) return [{}, `The default unit ${def} must be one of the allowed units.`];
  const hs = String(body.default_hs_code ?? '').trim();
  if (hs !== '' && !(await hsCodeRepository.isActiveCode(hs))) return [{}, `HS code "${hs}" is not on the HS Code master list — add it there first (HS Codes, under Admin).`];
  const packing = String(body.packing_type ?? '').trim();
  if (packing !== '' && !packageTypes.includes(packing)) return [{}, 'Choose the packing type from the Package Types list.'];
  const docs = [];
  for (const raw of String(body.quality_documents ?? '').split(/\r\n|\r|\n/)) {
    const d = raw.trim();
    if (d === '') continue;
    if ([...d].length > 120) return [{}, `A quality document name can be at most 120 characters ("${d}").`];
    if (docs.map((x) => x.toLowerCase()).includes(d.toLowerCase())) return [{}, `"${d}" is listed twice — each quality document once.`];
    docs.push(d);
  }
  if (docs.length > MAX_QUALITY_DOCUMENTS) return [{}, `A category can ask for at most ${MAX_QUALITY_DOCUMENTS} quality documents.`];
  const tc = String(body.tc_clause_set ?? '').trim();
  if (tc !== '' && !Object.prototype.hasOwnProperty.call(TC_CLAUSE_SETS, tc)) return [{}, 'Choose the T&C clause set from the list.'];
  return [{
    default_unit: def !== '' ? def : null,
    allowed_units: allowed.length > 0 ? allowed.join(',') : null,
    default_hs_code: hs !== '' ? hs : null,
    packing_type: packing !== '' ? packing : null,
    quality_documents: docs.length > 0 ? docs.join('\n') : null,
    tc_clause_set: tc !== '' ? tc : null,
  }, null];
}

async function updateSettings(id, s) {
  await db.execute(
    'UPDATE product_categories SET default_unit = :du, allowed_units = :au, default_hs_code = :hs, packing_type = :pt, quality_documents = :qd, tc_clause_set = :tc WHERE id = :id',
    { du: s.default_unit, au: s.allowed_units, hs: s.default_hs_code, pt: s.packing_type, qd: s.quality_documents, tc: s.tc_clause_set, id },
  );
}

/** One line for the audit log: what the settings are. */
function settingsSummary(c) {
  const docs = qualityDocuments(c);
  return `unit ${c.default_unit || 'as today'} (allowed: ${c.allowed_units || 'any'})`
    + `; HS ${c.default_hs_code || '-'}; packing ${c.packing_type || '-'}`
    + `; quality documents: ${docs.length > 0 ? docs.join(', ') : 'none'}`
    + `; T&C ${c.tc_clause_set || '-'}`;
}

/**
 * D-257 0B: the attributes of a category's option on the order-line form —
 * its default HS code and unit (filled by app.js) and its allowed units
 * (checked by app.js). '' for Natural Stone, which stays as today.
 */
function lineOptionAttributes(category) {
  if (Number(category.is_system ?? 0) === 1) return '';
  const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const fills = {};
  if (String(category.default_hs_code ?? '') !== '') fills.hs_code = String(category.default_hs_code);
  if (String(category.default_unit ?? '') !== '') fills.unit = String(category.default_unit);
  let out = Object.keys(fills).length > 0 ? ` data-fills="${esc(JSON.stringify(fills))}"` : '';
  const allowed = allowedUnits(category);
  if (allowed.length > 0) out += ` data-allowed-units="${esc(allowed.join(','))}"`;
  return out;
}

/**
 * D-257 0B: a new or edited order line in a category other than Natural
 * Stone takes the category's default HS code (goods) and unit when they
 * were left empty, and refuses a unit the category does not allow.
 * Natural Stone (and no category) is left exactly as today.
 * Changes body in place; returns the error, if any.
 */
async function applyLineDefaults(body) {
  const categoryId = parseInt(body.category_id, 10) || 0;
  const lineType = String(body.line_type ?? 'goods');
  if (categoryId === 0 || lineType === 'service') return null;
  const category = await find(categoryId);
  if (!category || Number(category.is_system) === 1) return null;
  if (lineType === 'goods' && String(body.hs_code ?? '').trim() === '' && category.default_hs_code) body.hs_code = String(category.default_hs_code);
  if (String(body.unit ?? '').trim() === '' && category.default_unit) body.unit = String(category.default_unit);
  const allowed = allowedUnits(category);
  const unit = String(body.unit ?? '').trim().toUpperCase();
  if (allowed.length > 0 && unit !== '' && !allowed.includes(unit)) return `Unit ${unit} is not one of the units for ${category.name} (${allowed.join(', ')}).`;
  return null;
}

module.exports = {
  MAX_ATTRIBUTES, all, find, attributeLabels, nameTaken, create, update, parseLabels, setLineCategory, documentAttributes,
  TC_CLAUSE_SETS, MAX_QUALITY_DOCUMENTS, allowedUnits, qualityDocuments, parseSettings, updateSettings, settingsSummary, lineOptionAttributes, applyLineDefaults,
};
