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
