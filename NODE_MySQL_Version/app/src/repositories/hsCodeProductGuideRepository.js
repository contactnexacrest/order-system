'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\HsCodeProductGuideRepository (PHP). Point 4 —
 * the "which code do I actually use for this product" half of the
 * business's HS Code Quick Reference workbook. Deliberately its own
 * table rather than a column on hs_codes: it's keyed by product name, not
 * by code, and a single product row can legitimately point at more than
 * one candidate code (resolving it to one exact code needs a human to
 * look at the real SKU) — read-only reference material, most useful for
 * a fresher who has never had to classify a product before.
 */

async function all() {
  return db.query('SELECT * FROM hs_code_product_examples ORDER BY sort_order, id');
}

/** Splits on the FIRST occurrence of separator only, keeping the remainder joined. */
function splitFirst(line, separator) {
  const idx = line.indexOf(separator);
  if (idx === -1) {
    return null;
  }
  return [line.slice(0, idx), line.slice(idx + separator.length)];
}

/** @returns {Promise<{inserted: number, skipped: string[]}>} */
async function bulkImport(rawText, createdBy) {
  const maxRow = await db.queryOne('SELECT COALESCE(MAX(sort_order), 0) AS m FROM hs_code_product_examples');
  let maxSort = parseInt(maxRow.m, 10);
  let inserted = 0;
  const skipped = [];

  for (const rawLine of rawText.split(/\r\n|\r|\n/)) {
    const line = rawLine.trim();
    if (line === '') {
      continue;
    }

    let product;
    let code;
    let note = '';

    const tabParts = line.split('\t');
    const pipeParts = line.split('|');
    if (tabParts.length >= 2) {
      [product, code, note = ''] = tabParts;
    } else if (pipeParts.length >= 2) {
      [product, code, note = ''] = pipeParts;
    } else {
      skipped.push(`"${line}" — expected Product, Code, and an optional Note, separated by Tabs or |.`);
      continue;
    }

    product = product.trim();
    code = code.trim();
    note = note.trim();

    if (product === '' || code === '') {
      skipped.push(`"${line}" — product and code are both required.`);
      continue;
    }

    maxSort += 1;
    // eslint-disable-next-line no-await-in-loop
    await db.execute(
      `INSERT INTO hs_code_product_examples (product_description, code_reference, note, sort_order, created_by)
       VALUES (:product, :code, :note, :sort_order, :created_by)`,
      { product, code, note: note !== '' ? note : null, sort_order: maxSort, created_by: createdBy }
    );
    inserted += 1;
  }

  return { inserted, skipped };
}

async function remove(id) {
  await db.execute('DELETE FROM hs_code_product_examples WHERE id = :id', { id });
}

module.exports = { all, bulkImport, remove };
