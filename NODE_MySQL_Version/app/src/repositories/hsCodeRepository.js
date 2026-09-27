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

async function updateDescription(id, description, usageNote = null) {
  await db.execute('UPDATE hs_codes SET description = :description, usage_note = :usage_note WHERE id = :id', { description, usage_note: usageNote, id });
}

/**
 * Point 4 — importing a real customs reference sheet one code at a time
 * was the actual gap; this takes a whole pasted block (Excel's own
 * tab-separated copy/paste format, or comma-separated when typed by
 * hand) and imports every valid line in one pass.
 *
 * @returns {Promise<{inserted: string[], skipped: string[]}>}
 */
/** Splits on the FIRST occurrence of separator only, keeping the remainder joined — mirrors PHP's preg_split(..., 2). */
function splitFirst(line, separator) {
  const idx = line.indexOf(separator);
  if (idx === -1) {
    return null;
  }
  return [line.slice(0, idx), line.slice(idx + separator.length)];
}

async function bulkImport(rawText, createdBy) {
  const inserted = [];
  const skipped = [];

  for (const rawLine of rawText.split(/\r\n|\r|\n/)) {
    const line = rawLine.trim();
    if (line === '') {
      continue;
    }

    const parts = splitFirst(line, '\t') || splitFirst(line, ',');
    if (!parts) {
      skipped.push(`"${line}" — separate the code and description with a Tab or a comma.`);
      continue;
    }

    const code = parts[0].trim();
    const description = parts[1].trim();

    if (!/^\d{6}$|^\d{8}$/.test(code)) {
      skipped.push(`"${code}" — must be exactly 6 or 8 digits, no dots or other characters.`);
      continue;
    }
    if (description === '') {
      skipped.push(`${code} — description is blank.`);
      continue;
    }
    // eslint-disable-next-line no-await-in-loop
    if (await findByCode(code)) {
      skipped.push(`${code} — already exists.`);
      continue;
    }

    // eslint-disable-next-line no-await-in-loop
    await create(code, description, createdBy);
    inserted.push(code);
  }

  return { inserted, skipped };
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

module.exports = { all, active, findByCode, isActiveCode, create, updateDescription, toggleActive, usageCount, remove, bulkImport };
