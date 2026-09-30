'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Point 6 — the New Order creation screen's product lines get the same
 * "Duplicate this line with its values" button the post-confirmation edit
 * screen already has, implemented client-side (JS clone, no blanking)
 * since these rows aren't saved yet.
 *
 * Point 7 — "Special Requirements" is renamed to "Special
 * Requirements/Instructions" everywhere it's shown to a user, and on
 * every PDF/DOCX that renders it, it gets its own amber-highlighted
 * section instead of blending into an ordinary navy section or a plain
 * table row.
 */
describe('Duplicate-line button (Point 6) and Special Requirements/Instructions (Point 7)', () => {
  const viewsDir = path.join(__dirname, '../../views');
  const templatesDir = path.join(__dirname, '../../templates');
  const srcDir = path.join(__dirname, '../../src');

  it('New Order create screen has a duplicate-line button and handler', () => {
    const html = fs.readFileSync(path.join(viewsDir, 'orders/create.njk'), 'utf8');
    expect(html).toContain('duplicate-row');
    expect(html).toContain("classList.contains('duplicate-row')");
    const handlerStart = html.indexOf("classList.contains('duplicate-row')");
    const handlerBlock = html.slice(handlerStart, handlerStart + 300);
    expect(handlerBlock).not.toContain("el.value = ''");
  });

  it('Special Requirements is renamed everywhere in views', () => {
    const files = ['orders/create.njk', 'orders/edit_details.njk', 'orders/show.njk'];
    for (const file of files) {
      const html = fs.readFileSync(path.join(viewsDir, file), 'utf8');
      expect(html).toContain('Special Requirements/Instructions');
    }
  });

  it('Special Requirements gets its own highlighted section on QT and SUPPO PDFs', () => {
    const qt = fs.readFileSync(path.join(templatesDir, 'QT/quotation.njk'), 'utf8');
    expect(qt).toContain('section-title-highlight');
    expect(qt).toContain('SPECIAL REQUIREMENTS / INSTRUCTIONS');

    const suppo = fs.readFileSync(path.join(templatesDir, 'SUPPO/supplier_po.njk'), 'utf8');
    expect(suppo).toContain('section-title-highlight');
    expect(suppo).toContain('SPECIAL REQUIREMENTS / INSTRUCTIONS');
    expect(suppo).not.toContain('<td class="k">Special Requirements</td>');
  });

  it('Special Requirements gets its own highlighted section in DOCX generation', () => {
    const docx = fs.readFileSync(path.join(srcDir, 'services/docx/docxDocumentBuilder.js'), 'utf8');
    expect(docx).toContain('SPECIAL REQUIREMENTS / INSTRUCTIONS');
    expect(docx).toContain('AMBER_BORDER');
    expect(docx).not.toContain("label: 'Special Requirements'");
  });
});
