'use strict';

// Owner decision (1 Nov 2026, confirmed twice): the Quotation and the
// Proforma Invoice no longer print a heading-less table of Certificate of
// Origin Type / Port of Discharge / Buyer's PO between the party sections
// and Product / Order Details. Nothing is lost: Port of Discharge stays in
// Shipping / Commercial Terms, the Certificate of Origin line (with the
// selected type) stays in Documents to be provided / Export Documentation,
// and the PI's Buyer's PO / Ref No. moves into Buyer Details, printed only
// when one has been entered. Same in the PDF and the Word version.
// PHP twin: QuotationPiLayoutTest.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
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

describe('Quotation / Proforma Invoice layout (owner, 1 Nov 2026)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Layout User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-layout-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });

  async function order(buyersPo, coo = 'Non-Preferential') {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute(
      'UPDATE orders SET port_of_discharge_id = NULL, port_of_discharge_text = :pod, coo_type = :coo, buyers_po_ref = :po WHERE id = :id',
      { pod: 'Poti, Georgia', coo, po: buyersPo, id: orderId }
    );
    return orderId;
  }

  function htmlText(html) {
    return html.replace(/<[^>]+>/g, ' ').replace(/&#39;|&#x27;/g, "'").replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ');
  }

  function docxText(buffer) {
    const tmp = path.join(os.tmpdir(), `jest-qtpi-layout-${Math.random().toString(16).slice(2)}.docx`);
    fs.writeFileSync(tmp, buffer);
    try {
      return execFileSync('unzip', ['-p', tmp, 'word/document.xml']).toString('utf8').replace(/<[^>]+>/g, ' ').replace(/&apos;/g, "'").replace(/\s+/g, ' ');
    } finally {
      fs.unlinkSync(tmp);
    }
  }

  async function generate(orderId, code) {
    await documentGenerationService.generate(orderId, code, userId, null, false, true);
    const calls = renderPdfFromHtml.mock.calls;
    const pdf = htmlText(calls[calls.length - 1][0]);
    const data = await documentDataAssembler.assemble(orderId, code);
    const ctx = { ...data, meta: { document_reference: 'JEST', revision_label: 'Rev.00', generated_date: '01 Nov 2026' }, watermark: {} };
    const docx = docxText(await docxDocumentBuilder.render(code, ctx));
    return { pdf, docx };
  }

  const count = (s, sub) => s.split(sub).length - 1;

  it('Quotation: no untitled table; Port of Discharge once in Shipping; CoO line keeps the type', async () => {
    const doc = await generate(await order('PO-2026-0457'), 'QT');
    for (const [format, text] of Object.entries(doc)) {
      expect([format, text.includes('Certificate of Origin Type')]).toEqual([format, false]);
      expect([format, count(text, 'Port of Discharge *')]).toEqual([format, 1]);
      expect(text).toContain('Poti, Georgia');
      expect(text).toContain('Certificate of Origin — Non-Preferential — Issued by CAPEXIL');
    }
    expect(doc.pdf).toMatch(/CONSIGNEE DETAILS(?:(?!Certificate of Origin|Port of Discharge).)*PRODUCT \/ ORDER DETAILS/s);
    expect(doc.pdf.indexOf('Port of Discharge *')).toBeGreaterThan(doc.pdf.indexOf('SHIPPING / COMMERCIAL TERMS'));
  });

  it('Quotation: the CoO line follows the selected type', async () => {
    const doc = await generate(await order(null, 'GSP Form A (Preferential)'), 'QT');
    for (const text of Object.values(doc)) {
      expect(text).toContain('Certificate of Origin — GSP Form A (Preferential) — Issued by CAPEXIL');
    }
  });

  it("Proforma: Buyer's PO in Buyer Details only when entered; no untitled table", async () => {
    const doc = await generate(await order('PO-2026-0457'), 'PI');
    for (const [format, text] of Object.entries(doc)) {
      expect([format, text.includes('Certificate of Origin Type')]).toEqual([format, false]);
      expect([format, count(text, "Buyer's PO / Ref No.")]).toEqual([format, 1]);
      expect(text).toContain('PO-2026-0457');
      expect(text).toContain('Certificate of Origin — Non-Preferential — Issued by CAPEXIL');
      expect(text).toContain('Poti, Georgia');
    }
    const po = doc.pdf.indexOf("Buyer's PO / Ref No.");
    expect(po).toBeGreaterThan(doc.pdf.indexOf('BUYER DETAILS'));
    expect(po).toBeLessThan(doc.pdf.indexOf('CONSIGNEE DETAILS'));

    for (const empty of [null, '', 'NIL']) {
      const blank = await generate(await order(empty), 'PI');
      for (const [format, text] of Object.entries(blank)) {
        expect([format, empty, text.includes("Buyer's PO / Ref No.")]).toEqual([format, empty, false]);
      }
    }
  });
});
