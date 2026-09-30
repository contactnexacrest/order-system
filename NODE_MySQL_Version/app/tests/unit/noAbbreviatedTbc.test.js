'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Point 5 — "TBC" was unclear to anyone not already familiar with trade
 * jargon; every place it was shown to a user (screens and generated PDFs)
 * now spells out "To Be Confirmed" instead. This guards against a future
 * edit reintroducing the bare abbreviation as display text. The
 * `quantity_is_tbc`/`quantity_tbc`/`product_quantity_tbc` identifiers are
 * the underlying field/column names, not display text, and are exempt.
 */

function walk(dir, exts, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(full, exts, out);
    } else if (exts.includes(path.extname(entry.name))) {
      out.push(full);
    }
  }
}

describe('No abbreviated "TBC" in views/templates (Point 5)', () => {
  it('no view or template file displays the bare TBC abbreviation', () => {
    const files = [];
    walk(path.join(__dirname, '../../views'), ['.njk'], files);
    walk(path.join(__dirname, '../../templates'), ['.njk'], files);
    expect(files.length).toBeGreaterThan(0);

    for (const file of files) {
      const lines = fs.readFileSync(file, 'utf8').split('\n');
      lines.forEach((line, i) => {
        if (/quantity_is_tbc|quantity_tbc/.test(line)) return;
        if (/\bTBC\b/.test(line)) {
          throw new Error(`${file}:${i + 1} displays the bare "TBC" abbreviation — spell out "To Be Confirmed" instead.\n${line}`);
        }
      });
    }
  });
});
