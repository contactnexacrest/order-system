'use strict';

const db = require('../config/db');

// Port of App\Repositories\HsCodeRepository.

async function all() {
  return db.query('SELECT * FROM hs_codes ORDER BY code');
}

/** Active codes only — what the order-creation typeahead offers. */
async function active() {
  return db.query('SELECT code, description FROM hs_codes WHERE is_active = 1 ORDER BY code');
}

async function findByCode(code) {
  return db.queryOne('SELECT * FROM hs_codes WHERE code = :code', { code });
}

async function isActiveCode(code) {
  const row = await db.queryOne('SELECT 1 AS x FROM hs_codes WHERE code = :code AND is_active = 1', { code });
  return !!row;
}

async function create(code, description, createdBy) {
  const result = await db.execute(
    'INSERT INTO hs_codes (code, description, is_active, created_by) VALUES (:code, :description, 1, :created_by)',
    { code, description, created_by: createdBy }
  );
  return result.insertId;
}

async function updateDescription(id, description) {
  await db.execute('UPDATE hs_codes SET description = :description WHERE id = :id', { description, id });
}

async function toggleActive(id) {
  await db.execute('UPDATE hs_codes SET is_active = 1 - is_active WHERE id = :id', { id });
}

async function usageCount(code) {
  const row = await db.queryOne('SELECT COUNT(*) AS c FROM order_products WHERE hs_code = :code', { code });
  return parseInt(row.c, 10);
}

async function remove(id) {
  await db.execute('DELETE FROM hs_codes WHERE id = :id', { id });
}

module.exports = { all, active, findByCode, isActiveCode, create, updateDescription, toggleActive, usageCount, remove };
