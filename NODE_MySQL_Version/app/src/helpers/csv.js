'use strict';

// Port of App\Helpers\Csv. Spec Section 16 — "Export to CSV/Excel." CSV
// only (see the PHP original's own judgment-call note): it opens natively
// in Excel with correct commas/quoting/UTF-8, so a spreadsheet-writing
// dependency wasn't worth it for a format Excel already reads perfectly.

function escapeField(value) {
  const s = value === null || value === undefined ? '' : String(value);
  if (/[",\r\n]/.test(s)) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

/**
 * Streams a CSV directly to the response. Call this last, after all
 * validation — it sends headers and ends the response.
 *
 * @param {import('express').Response} res
 * @param {string} filename
 * @param {string[]} headers
 * @param {Array<object|Array>} rows each row either a plain array (already
 *   in header order) or a plain object keyed the same as headers
 */
function stream(res, filename, headers, rows) {
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);

  let out = '﻿'; // UTF-8 BOM so Excel opens non-ASCII text correctly
  out += headers.map(escapeField).join(',') + '\r\n';
  for (const row of rows) {
    const ordered = Array.isArray(row) ? row : headers.map((h) => (row[h] !== undefined ? row[h] : ''));
    out += ordered.map(escapeField).join(',') + '\r\n';
  }
  res.send(out);
}

module.exports = { stream };
