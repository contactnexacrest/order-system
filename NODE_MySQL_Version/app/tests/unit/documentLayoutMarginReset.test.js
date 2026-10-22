'use strict';

// documentGenerationService.generate() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// cannot run at all (see tests/support/mocks/puppeteerCoreStub.js's own
// docblock) — mocked here, same pattern as continuationPageFooter.test.js.
// The mock captures the exact HTML string that was about to be printed,
// which is exactly what this test needs: that the margin reset actually
// made it into the rendered output.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report L-17: PHP and Node rendered the same order content
 * at different page counts. One confirmed, fixable contributor: Chromium's
 * default UA stylesheet gives <body> an 8px margin that DOMPDF's own
 * default stylesheet never applies, stacking on top of the shared
 * `@page { margin: 0.5in 0.59in; }` rule and pushing every page's content
 * in further than the PHP stack — measured directly (pdftotext -bbox) at
 * ~6.3pt extra on the left before this fix, ~0pt after. A further rowheight/
 * font-metrics gap between DOMPDF and Chromium remains (not safely closable
 * via CSS without risking other regressions — see this session's commit
 * message for the full investigation) and is tracked separately.
 */
describe('document layout margin reset (External QA report L-17)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0];
  }

  it('resets the UA default body margin so only the shared @page margin governs page content position', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userRow = await db.queryOne("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') LIMIT 1");

    await documentGenerationService.generate(orderId, 'QT', userRow.id);

    const html = lastRenderedHtml();
    expect(html).toMatch(/html\s*,\s*body\s*\{[^}]*margin\s*:\s*0\b/);
  });
});
