'use strict';

// documentGenerationService.generate() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// cannot run at all (see tests/support/mocks/puppeteerCoreStub.js's own
// docblock) — mocked here, same pattern as annexureDocumentNumber.test.js.
// The mock also captures the exact arguments it was called with, which is
// exactly what this test needs: that the document reference is actually
// threaded through to pdfRenderService, where buildPdfOptions() (see
// pdfRenderServiceFooter.test.js) turns it into a running footer.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report L-04: continuation pages carried neither the logo
 * watermark nor the document reference — the reference only ever
 * appeared once, in the page-1 header table (an ordinary in-flow
 * element, not a running one). Fixed by threading each document's
 * reference through to pdfRenderService.renderPdfFromHtml(), which turns
 * it into a running footer via Puppeteer's own header/footer template
 * mechanism (see pdfRenderServiceFooter.test.js for that half).
 */
describe('Document reference threaded through to the running footer (External QA report L-04)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedReferenceArg() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][1];
  }

  it('generate() passes the QT document reference through to renderPdfFromHtml', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userRow = await db.queryOne("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') LIMIT 1");

    const result = await documentGenerationService.generate(orderId, 'QT', userRow.id);
    const document = await documentRepository.find(result.document_id);

    expect(document.document_reference).toBeTruthy();
    expect(lastRenderedReferenceArg()).toBe(document.document_reference);
  });
});
