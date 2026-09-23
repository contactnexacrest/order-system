'use strict';

const db = require('../config/db');

async function all() {
  return db.query('SELECT * FROM company_holidays ORDER BY holiday_date ASC');
}

/**
 * Every holiday_date between from and to inclusive, as a flat set of
 * 'YYYY-MM-DD' strings — the shape workingDaysCalculator needs for a fast
 * lookup per candidate day, rather than one query per day.
 */
async function datesBetween(from, to) {
  const rows = await db.query('SELECT holiday_date FROM company_holidays WHERE holiday_date BETWEEN :from AND :to', { from, to });
  return rows.map((r) => r.holiday_date);
}

async function create(holidayDate, description, createdBy) {
  const result = await db.execute(
    'INSERT INTO company_holidays (holiday_date, description, created_by) VALUES (:holiday_date, :description, :created_by)',
    { holiday_date: holidayDate, description, created_by: createdBy }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM company_holidays WHERE id = :id', { id });
}

async function update(id, holidayDate, description) {
  await db.execute(
    'UPDATE company_holidays SET holiday_date = :holiday_date, description = :description WHERE id = :id',
    { holiday_date: holidayDate, description, id }
  );
}

async function remove(id) {
  await db.execute('DELETE FROM company_holidays WHERE id = :id', { id });
}

module.exports = { all, datesBetween, create, find, update, remove };
