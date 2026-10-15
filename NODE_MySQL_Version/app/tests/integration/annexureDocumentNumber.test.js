'use strict';

// documentGenerationService.generate() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// deliberately stubs out (see tests/support/mocks/puppeteerCoreStub.js) —
// mocked here instead, same pattern as legalTermsDraftFinalConsistency.test.js.
// The mock also captures the HTML it was asked to convert, which is exactly
// what this test needs to inspect.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderRepository = require('../../src/repositories/orderRepository');
const orderAnnexureRepository = require('../../src/repositories/orderAnnexureRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AnnexureDocumentNumberTest.php. 90-item backlog #71: Annexure
 * A's own generated document never printed its own document reference
 * ("ANNEXURE NO.") in its meta bar, unlike every other document type (QT
 * prints "QUOTATION NO.", PI prints "PI NUMBER", BLI prints "BL
 * INSTRUCTION NO.", etc.). Root cause: document_types.ref_format for
 * ANNEXA was seeded NULL — the same NULL used for genuinely
 * internal/non-generated types (CHECKLIST, SOP_*, STAGEGATE, WALLREF) —
 * even though ANNEXA is 'customer_facing' and goes through the exact same
 * generate/approve/send lifecycle as QT/PI/OC. With ref_format NULL,
 * documentReferenceFor() always returned null, so there was never a
 * reference to print in the first place. Fixed with a DB data correction
 * (document_types.ref_format = 'SC/ANX/{YYYY}/{DDMM}{NNN}' for
 * code='ANNEXA' — see docs/seed.sql) plus adding an "ANNEXURE NO. *"
 * entry (document_reference + revision_label, the same convention every
 * other document type's meta bar already uses) to both the PDF
 * template's meta_table and the DOCX builder's renderAnnexa().
 */
describe('Annexure A document reference in its own meta bar (backlog #71)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0];
  }

  it('Annexure A PDF prints its own document reference as ANNEXURE NO.', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userRow = await db.queryOne("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') LIMIT 1");
    await orderRepository.setIncludeAnnexureA(orderId, true);
    await orderAnnexureRepository.createProduct(orderId, {
      name: 'Marble Side Table',
      description: '',
      dimensions: '',
      finish: '',
      components: '',
      technical_notes: '',
    });

    const result = await documentGenerationService.generate(orderId, 'ANNEXA', userRow.id);
    const document = await documentRepository.find(result.document_id);

    expect(document.document_reference).not.toBeNull();
    expect(document.document_reference).toMatch(/^SC\/ANX\//);

    const html = lastRenderedHtml();
    expect(html).toContain('ANNEXURE NO.');
    expect(html).toContain(document.document_reference);
  });
});
