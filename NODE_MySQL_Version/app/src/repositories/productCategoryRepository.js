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

module.exports = { MAX_ATTRIBUTES, all, find, attributeLabels, nameTaken, create, update, parseLabels, setLineCategory, documentAttributes };
