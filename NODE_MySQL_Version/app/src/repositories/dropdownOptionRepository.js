'use strict';

const db = require('../config/db');

// Port of App\Repositories\DropdownOptionRepository — admin CRUD for
// dropdown_options (docs/schema.sql Section BD). Options are never
// hard-deleted (same convention as tc_clauses/payment_presets) — a
// no-longer-wanted option is deactivated instead, since an existing
// order/submission may still carry its exact text in a plain VARCHAR
// column with no FK to this table.

async function allGrouped(includeInactive = false) {
  let sql = 'SELECT * FROM dropdown_options';
  if (!includeInactive) {
    sql += ' WHERE is_active = 1';
  }
  sql += ' ORDER BY list_key, sort_order, id';
  const rows = await db.query(sql);

  const grouped = {};
  for (const row of rows) {
    if (!grouped[row.list_key]) {
      grouped[row.list_key] = [];
    }
    grouped[row.list_key].push(row);
  }
  return grouped;
}

async function find(id) {
  return db.queryOne('SELECT * FROM dropdown_options WHERE id = :id', { id });
}

async function create(listKey, value, sortOrder) {
  const result = await db.execute(
    'INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active) VALUES (:list_key, :value, :sort_order, 0, 1)',
    { list_key: listKey, value, sort_order: sortOrder }
  );
  return result.insertId;
}

async function update(id, value, sortOrder) {
  await db.execute('UPDATE dropdown_options SET option_value = :value, sort_order = :sort_order WHERE id = :id', { value, sort_order: sortOrder, id });
}

/** Unsets is_default on every other row sharing this option's list_key, then sets it on this one. */
async function setDefault(id, listKey) {
  await db.execute('UPDATE dropdown_options SET is_default = 0 WHERE list_key = :list_key', { list_key: listKey });
  await db.execute('UPDATE dropdown_options SET is_default = 1 WHERE id = :id', { id });
}

async function toggleActive(id) {
  await db.execute('UPDATE dropdown_options SET is_active = 1 - is_active WHERE id = :id', { id });
}

module.exports = { allGrouped, find, create, update, setDefault, toggleActive };
