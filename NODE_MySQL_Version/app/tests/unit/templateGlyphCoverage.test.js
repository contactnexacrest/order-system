'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of TemplateGlyphCoverageTest.php. 90-item backlog item #7/#19
 * ("'?' symbol in some PDFs"): several templates used decorative
 * Unicode symbols (stopwatch, warning triangle, check mark, heavy
 * check mark, double arrow) that the embedded Carlito font (registered
 * as "Aptos") has no glyph for. Fixed the same way on both stacks —
 * drop the icon (stopwatch) or swap to a semantically-close codepoint
 * Carlito does carry (warning -> '!!' U+203C, check marks -> square
 * root U+221A, double arrow -> single arrow U+2192). This test scans
 * every PDF template for the specific numeric entities confirmed
 * missing from Carlito, so a future template can't silently
 * reintroduce the same bug.
 */
describe('Template glyph coverage (90-item backlog "? symbol in PDFs")', () => {
  const KNOWN_MISSING_ENTITIES = ['&#9201;', '&#9888;', '&#10003;', '&#9989;', '&#8658;'];

  function walk(dir) {
    let files = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        files = files.concat(walk(full));
      } else if (entry.name.endsWith('.njk')) {
        files.push(full);
      }
    }
    return files;
  }

  it('no template uses a known Carlito-missing glyph entity', () => {
    const templatesDir = path.join(__dirname, '../../templates');
    const offenders = [];

    for (const file of walk(templatesDir)) {
      const contents = fs.readFileSync(file, 'utf8');
      for (const entity of KNOWN_MISSING_ENTITIES) {
        if (contents.includes(entity)) {
          offenders.push(`${file} contains ${entity}`);
        }
      }
    }

    expect(offenders).toEqual([]);
  });
});
