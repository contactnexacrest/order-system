'use strict';

// QA-5 CONC-04: documentGenerationService.generate() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// deliberately stubs out (see tests/support/mocks/puppeteerCoreStub.js) —
// mocked here instead so concurrent generate() calls resolve instantly and
// the test exercises the actual revision-number race, not PDF rendering.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-5 (CONC-04 — external QA report cross-verification): revision_number
 * was computed with a plain `existing ? existing.revision_number + 1 : 0`
 * read, and the actual INSERT didn't land until AFTER the PDF (and,
 * optionally, DOCX) had been fully rendered — a much longer unlocked
 * window than the other CONC races, since rendering is not fast. There is
 * no UNIQUE constraint on (order_id, document_type_id, revision_number)
 * either, so two concurrent regenerations of the same document type for
 * the same order didn't even 500 — they silently left two `documents`
 * rows sharing one revision number. Fixed by reserving the number
 * atomically up front (referenceNumberService.nextDocumentRevisionNumber())
 * before any rendering starts.
 */
describe('Document revision-number race under concurrent generation (QA-5 CONC-04)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('assigns every concurrently-generated document of the same type for one order a distinct revision number', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const CONCURRENT_GENERATIONS = 6;
    const results = await Promise.all(
      Array.from({ length: CONCURRENT_GENERATIONS }, () =>
        documentGenerationService.generate(orderId, 'QT', 1, null, false, false)
      )
    );

    const documentIds = results.map((r) => r.document_id);
    expect(new Set(documentIds).size).toBe(CONCURRENT_GENERATIONS);

    const rows = await db.query(
      `SELECT revision_number FROM documents WHERE id IN (${documentIds.map((id) => parseInt(id, 10)).join(',')})`
    );
    expect(rows.length).toBe(CONCURRENT_GENERATIONS);

    const revisionNumbers = rows.map((r) => r.revision_number);
    expect(new Set(revisionNumbers).size).toBe(CONCURRENT_GENERATIONS);
  });
});
