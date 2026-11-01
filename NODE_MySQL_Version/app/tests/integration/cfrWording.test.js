'use strict';

// PDF rendering is mocked (as in buyerPoConsigneeFields.test.js) — the HTML it receives is what the PDF prints.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
}));

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const docxDocumentBuilder = require('../../src/services/docx/docxDocumentBuilder');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CfrWordingTest.php. Owner decision C-16 (confirmed twice, 1 Nov
 * 2026): under CFR the buyer arranges the insurance. The Buyer PO's freight
 * sentence follows the incoterm (CIF freight and insurance, CFR freight
 * only, FOB none), and the "Shipment Booking (CFR/CIF)" clause asks CFR
 * buyers for freight only.
 */
describe('C-16: CFR wording', () => {
  const CIF = 'Freight &amp; Insurance: invoiced separately by Debit Note before shipment.';
  const CFR = 'Freight: invoiced separately by Debit Note before shipment (insurance arranged by the buyer).';

  afterAll(async () => {
    await db.pool.end();
  });

  async function adminId() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role, 1, 0, 0)`,
      { email: `jest-cfr-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role: role.id }
    );
    return r.insertId;
  }

  function lastHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0].replace(/\s+/g, ' ');
  }

  async function docxText(orderId, code) {
    const data = await documentDataAssembler.assemble(orderId);
    const buffer = await docxDocumentBuilder.render(code, { ...data, meta: { document_reference: 'JEST-C16', revision_label: 'Rev.00', generated_date: '01 Nov 2026' }, watermark: {} });
    const tmp = path.join(os.tmpdir(), `jest-c16-${Math.random().toString(16).slice(2)}.docx`);
    fs.writeFileSync(tmp, buffer);
    try {
      return execFileSync('unzip', ['-p', tmp, 'word/document.xml']).toString('utf8').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ');
    } finally {
      fs.unlinkSync(tmp);
    }
  }

  it('the sentence follows the incoterm', () => {
    expect(docxDocumentBuilder.freightTermsSentence('CIF')).toBe(' Freight & Insurance: invoiced separately by Debit Note before shipment.');
    expect(docxDocumentBuilder.freightTermsSentence('cfr')).toBe(` ${CFR}`);
    expect(docxDocumentBuilder.freightTermsSentence('FOB')).toBe('');
  });

  it('the Buyer PO PDF and Word print the right sentence', async () => {
    const user = await adminId();
    for (const [incoterm, expected] of [['CIF', CIF], ['CFR', CFR], ['FOB', null]]) {
      const orderId = await createTestOrder(await createTestClient(), incoterm);
      await documentGenerationService.generate(orderId, 'BUYERPO', user, null, false, true);
      const html = lastHtml();
      const word = await docxText(orderId, 'BUYERPO');
      for (const [what, text] of [[`${incoterm} PDF`, html], [`${incoterm} Word`, word]]) {
        expect([what, text.includes('(CFR/CIF): invoiced separately')]).toEqual([what, false]);
        if (expected === null) {
          expect([what, text.includes('invoiced separately by Debit Note')]).toEqual([what, false]);
        } else {
          expect([what, text.includes(expected)]).toEqual([what, true]);
        }
      }
    }
  });

  it('the Shipment Booking clause asks CFR buyers for freight only', async () => {
    const row = await db.queryOne("SELECT clause_text FROM tc_clauses WHERE clause_title = 'Shipment Booking (CFR/CIF)'");
    expect(row.clause_text).toBe("Shipment Booking: For CFR/CIF orders, shipment booking will be confirmed only after full freight payment (and, for CIF orders, the insurance premium) is received and cleared in NexaCrest's bank account against the Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.");
    const orderId = await createTestOrder(await createTestClient(), 'CFR');
    await documentGenerationService.generate(orderId, 'PI', await adminId(), null, false, true);
    const html = lastHtml();
    expect(html).toContain('full freight payment (and, for CIF orders, the insurance premium)');
    expect(html).not.toContain('full freight and insurance payment');
  });
});
