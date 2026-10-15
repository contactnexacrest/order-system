'use strict';

// documentGenerationService.generate()/finalizeApproval()/markSuperseded()
// all render a real PDF via pdfRenderService (headless Chrome through
// puppeteer-core), which Jest deliberately stubs out (see
// tests/support/mocks/puppeteerCoreStub.js) — mocked here instead, same
// pattern as documentSupersede.test.js. The mock also captures the HTML
// it was asked to convert, which is exactly what this test needs to
// inspect: whether the Legal Terms/Definitions section made it into the
// rendered template, without needing a real PDF to extract text from.
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
 * 90-item backlog #17 — "draft-vs-final Legal Terms inconsistency".
 * documentGenerationService.generate() (draft) resolves the "Legal
 * Terms" (red box) and "Definitions" (blue box) clause groups and puts
 * them into the Nunjucks context, but finalizeApproval() and
 * markSuperseded() — which re-render the SAME template for the
 * approved/superseded PDF — never did, so the whole "N. LEGAL TERMS &
 * DEFINITIONS" section silently vanished once a document moved past
 * draft. Fixed by adding the same two resolveClauseGroup() calls (with
 * the order's balance_trigger_option, also previously omitted on these
 * two paths) to both functions. Exact mirror of the PHP-side fix.
 */
describe('Legal Terms/Definitions draft-vs-final consistency (backlog #17)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0];
  }

  it('draft PDF includes Legal Terms and Definitions', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userRow = await db.queryOne("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') LIMIT 1");

    await documentGenerationService.generate(orderId, 'QT', userRow.id);
    const html = lastRenderedHtml();

    expect(html).toContain('Limitation of Liability');
    expect(html).toContain('FCL (Full Container Load)');
  });

  it('finalized/approved PDF still includes Legal Terms and Definitions', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userRow = await db.queryOne("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') LIMIT 1");

    const result = await documentGenerationService.generate(orderId, 'QT', userRow.id);
    await documentGenerationService.finalizeApproval(result.document_id);

    const finalDocument = await documentRepository.find(result.document_id);
    expect(finalDocument.status).toBe('approved');

    const html = lastRenderedHtml();
    expect(html).toContain('Limitation of Liability');
    expect(html).toContain('FCL (Full Container Load)');
  });

  it('superseded re-render still includes Legal Terms and Definitions', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userRow = await db.queryOne("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') LIMIT 1");

    const result = await documentGenerationService.generate(orderId, 'QT', userRow.id);
    await documentGenerationService.markSuperseded(result.document_id);

    const supersededDocument = await documentRepository.find(result.document_id);
    expect(supersededDocument.status).toBe('superseded');

    const html = lastRenderedHtml();
    expect(html).toContain('Limitation of Liability');
    expect(html).toContain('FCL (Full Container Load)');
  });
});
