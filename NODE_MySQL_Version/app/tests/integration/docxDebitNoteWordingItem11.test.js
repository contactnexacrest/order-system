'use strict';

// Owner decision 13 Nov 2026 (QA item 11, confirm 1 + confirm 2): the Word copies of the Quotation, Proforma Invoice
// and Order Confirmation use the PDF's wording — "Debit Note", never "Freight Debit Note" or "FDN". Before, the Word
// QT/PI CIF insurance row said "Confirmed by Freight Debit Note when cargo is ready." and the Word OC freight row said
// "A Freight Debit Note will be raised …". The PDFs did not change. Follows MTX-005 (item 3: Word CI and Debit Note).
// PHP twin: tests/Integration/DocxDebitNoteWordingItem11Test.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const OC_FREIGHT = 'A Debit Note will be raised when cargo is packed and ready, stating the actual confirmed freight amount. Payment required within 3 Calendar Days of the Debit Note date. NexaCrest will confirm shipment booking and hand over cargo to the shipping line only upon receipt of full freight payment.';

const decode = (s) => s.replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&nbsp;|&#160;/g, ' ').replace(/&middot;/g, '·').replace(/&#0*(\d+);/g, (m, n) => String.fromCodePoint(Number(n))).replace(/&amp;/g, '&');
const flat = (s) => s.replace(/\s+/g, ' ').trim();
const lastPdfText = () => {
  const calls = renderPdfFromHtml.mock.calls;
  return flat(decode(calls[calls.length - 1][0].replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ')));
};
async function docxText(fileId) {
  const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
  const xml = execFileSync('unzip', ['-p', file.server_path, 'word/document.xml']).toString('utf8');
  return flat(decode(xml.replace(/<w:br\/>|<\/w:p>|<\/w:tc>/g, ' ').replace(/<[^>]+>/g, '')));
}
// Evidence only (before/after diff): dates, document numbers and the random buyer ref are masked.
const mask = (t) => t.replace(/\b\d{4}-\d{2}-\d{2}\b/g, '<DATE>').replace(/\b\d{1,2} [A-Z][a-z]+ \d{4}\b/g, '<DATE>')
  .replace(/SC\/[A-Z]+\/[0-9A-Za-z/-]+/g, '<REF>').replace(/JEST-[0-9a-f]+/g, 'JEST-<ID>').replace(/lient-[0-9a-f]{8}@/g, 'lient-<ID>@');

describe('Item 11: Word Quotation, PI and OC say "Debit Note" like the PDF', () => {
  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Item 11', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-item11-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });
  afterAll(async () => { await db.pool.end(); });

  it.each(['CFR', 'CIF'])('%s: Word QT/PI/OC never print "Freight Debit Note" or "FDN"', async (incoterm) => {
    const orderId = await createTestOrder(await createTestClient(), incoterm, 'Standard — New Buyer', 'USD');
    await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');

    const evidenceDir = process.env.ITEM11_EVIDENCE_DIR || '';
    const texts = {};
    for (const code of ['QT', 'PI', 'OC']) {
      const doc = await documentGenerationService.generate(orderId, code, userId, null, true, true);
      const pdf = lastPdfText();
      texts[code] = [await docxText(doc.docx_file_id), pdf];
      if (evidenceDir) {
        fs.writeFileSync(path.join(evidenceDir, `node_${incoterm}_${code}_word.txt`), `${mask(texts[code][0])}\n`);
        fs.writeFileSync(path.join(evidenceDir, `node_${incoterm}_${code}_pdf.txt`), `${mask(texts[code][1])}\n`);
      }
    }
    for (const [code, [word, pdf]] of Object.entries(texts)) {
      if (code === 'OC') {
        expect([incoterm, code, word.includes(OC_FREIGHT)]).toEqual([incoterm, code, true]);
        expect([incoterm, code, 'pdf', pdf.includes('A Debit Note will be raised when cargo is packed and ready')]).toEqual([incoterm, code, 'pdf', true]);
      } else if (incoterm === 'CIF') {
        expect([code, word.includes('Confirmed by Debit Note when cargo is ready.')]).toEqual([code, true]);
        expect([code, 'pdf', pdf.includes('Confirmed by Debit Note when cargo is ready.')]).toEqual([code, 'pdf', true]);
      } else {
        expect(word).toContain('NIL — buyer arranges insurance (CFR).');
      }
      expect(word).not.toMatch(/\bFDN\b/);
      expect(word.toLowerCase()).not.toContain('freight debit note');
      expect(pdf.toLowerCase()).not.toContain('freight debit note');
    }
  }, 120000);
});
