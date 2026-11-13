'use strict';

const { buildPdfOptions } = require('../../src/services/pdfRenderService');

/**
 * External QA report L-04: continuation pages carried neither the logo
 * watermark nor the document reference — the reference only ever
 * appeared once, in the page-1 header table. `displayHeaderFooter`/
 * `footerTemplate` is Puppeteer's own built-in mechanism for a running
 * footer with live pagination totals (`pageNumber`/`totalPages` are
 * substituted by Chromium itself at print time — no static HTML content
 * could know the total page count in advance). Manually verified with a
 * real multi-page PDF (Puppeteer/Chromium cannot run under Jest — see
 * tests/support/mocks/puppeteerCoreStub.js's own docblock) that the
 * footer this builds actually renders correctly, with nothing clipped
 * or overlapping.
 */
describe('pdfRenderService.buildPdfOptions() (External QA report L-04)', () => {
  it('adds a running footer with the document reference when one is given', () => {
    const options = buildPdfOptions('SC/QT/2026/2210001');

    expect(options.displayHeaderFooter).toBe(true);
    expect(options.footerTemplate).toContain('SC/QT/2026/2210001');
    expect(options.footerTemplate).toContain('class="pageNumber"');
    expect(options.footerTemplate).toContain('class="totalPages"');
    // Puppeteer clips the footer to nothing unless given room to draw in.
    expect(options.margin.bottom).not.toBe('0px');
  });

  // MTX-006 (owner decision 13 Nov 2026, item 4, confirmed twice): the Node footer reads exactly as the PHP one —
  // one left-aligned line "{ref}  —  Page n of N", 8pt, colour rgb(0.35,0.37,0.45) = #596075, 42.5pt from the
  // left edge, same font (PHP: DocumentGenerationService::addRunningHeaderFooter). Before: ref on the left,
  // "Page n of N" on the right, no dash, 8px #5B6573. PHP twin: tests/Integration/PdfFooterFormatMtx006Test.php.
  it('MTX-006: one left-aligned line "{ref}  —  Page n of N" in the PHP footer\'s size, colour, font and offset', () => {
    const { footerTemplate } = buildPdfOptions('SC/PI/26/1311001');

    expect(footerTemplate).toContain('SC/PI/26/1311001  —  Page <span class="pageNumber"></span> of <span class="totalPages"></span>');
    expect(footerTemplate).toContain('font-size:8pt;');
    expect(footerTemplate).toContain('color:#596075;');
    expect(footerTemplate).toContain('padding:0 0 0 42.5pt;');
    expect(footerTemplate).toContain('text-align:left;');
    expect(footerTemplate).toContain('white-space:pre;');
    expect(footerTemplate).toContain('font-family:Aptos, Carlito, sans-serif;');
    expect(footerTemplate).not.toContain('space-between');
    // A single line: one element around the whole text, the two page-number spans inside it.
    expect(footerTemplate.match(/<div/g)).toHaveLength(1);
    expect(footerTemplate.match(/<span/g)).toHaveLength(2);
  });

  it('HTML-escapes the document reference before embedding it in the footer template', () => {
    const options = buildPdfOptions('<script>alert(1)</script>');

    expect(options.footerTemplate).not.toContain('<script>alert(1)</script>');
    expect(options.footerTemplate).toContain('&lt;script&gt;');
  });

  it('adds no footer at all when no document reference is given', () => {
    const options = buildPdfOptions(null);

    expect(options.displayHeaderFooter).toBeUndefined();
    expect(options.footerTemplate).toBeUndefined();
    expect(options.margin.bottom).toBe('0px');
  });
});
