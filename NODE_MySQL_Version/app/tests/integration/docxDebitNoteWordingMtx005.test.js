'use strict';

// QA matrix MTX-005 — owner decision 13 Nov 2026 (item 3, Option A, confirmed twice): the Word copies of the
// Commercial Invoice and the Debit Note use exactly the PDF's wording. Before, the Word copy still said
// "FREIGHT DEBIT NOTE", "Freight Debit Note No.", "PAID VIA FDN", "Against FDN No.", "+ FDN (if applicable)",
// "paid separately via FDN", "Please quote FDN No. … in your wire transfer remarks", "immediately after payment",
// "TBC — must match this invoice date", the old Bill of Lading line, and printed "+ — (freight)" when no freight
// amount was recorded. The PDFs did not change. PHP twin: tests/Integration/DocxDebitNoteWordingMtx005Test.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

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

describe('MTX-005: Word Debit Note and Commercial Invoice use the PDF wording', () => {
  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest MTX-005', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-mtx005-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });
  afterAll(async () => { await db.pool.end(); });

  it.each(['CFR', 'CIF'])('%s: Word copy says what the PDF says, and never "FDN"', async (incoterm) => {
    const freightLabel = incoterm === 'CIF' ? 'Freight & Insurance' : 'Freight';
    const orderId = await createTestOrder(await createTestClient(), incoterm, 'Standard — New Buyer', 'USD');
    await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');

    const fdn = await documentGenerationService.generate(orderId, 'FDN', userId, null, true, true);
    const fdnPdf = lastPdfText();
    const ciPending = await documentGenerationService.generate(orderId, 'CI', userId, null, true, true);
    const u = await db.execute('UPDATE order_payment_status SET freight_cleared_at = NOW(), freight_amount = 1250 WHERE order_id = :id', { id: orderId });
    if (!u.affectedRows) await db.execute('INSERT INTO order_payment_status (order_id, freight_cleared_at, freight_amount) VALUES (:id, NOW(), 1250)', { id: orderId });
    const ciPaid = await documentGenerationService.generate(orderId, 'CI', userId, null, true, true);
    const ciPdf = lastPdfText();
    const ref = (await db.queryOne(
      "SELECT d.document_reference FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE t.code = 'FDN' AND d.order_id = :id ORDER BY d.id DESC LIMIT 1",
      { id: orderId }
    )).document_reference;
    expect(ref).toBeTruthy();

    const fdnWord = await docxText(fdn.docx_file_id);
    for (const line of [
      'NEXACREST INTERNATIONAL PRIVATE LIMITED DEBIT NOTE FREIGHT COST RECOVERY',
      'Payment of this Debit Note is required within 3 Calendar Days of the date above.',
      'Share bank remittance copy as per Section 1 contact details above within 24 hours of payment.',
      `Please quote Debit Note No. ${ref} in your T/T remarks.`,
    ]) expect([incoterm, 'FDN word', fdnWord.includes(line), line]).toEqual([incoterm, 'FDN word', true, line]);
    for (const line of [
      'Payment of this Debit Note is required within 3 Calendar Days of the date above.',
      'Share bank remittance copy as per Section 1 contact details above within 24 hours of payment.',
      `Please quote Debit Note No. ${ref} in your T/T remarks.`,
    ]) expect([incoterm, 'FDN pdf', fdnPdf.includes(line), line]).toEqual([incoterm, 'FDN pdf', true, line]);

    const ciWord = await docxText(ciPaid.docx_file_id);
    for (const line of [
      `Paid separately via Debit Note No. ${ref} dated`,
      `√ ${freightLabel} — PAID VIA DEBIT NOTE`,
      `CFR/CIF: Against Debit Note No. ${ref} dated`,
      'USD 2,184.00 + 1,250.00 (freight)',
      'Advance + Debit Note (if applicable)',
      `${freightLabel} is paid separately via Debit Note, not through this Advance/Balance schedule`,
      'Bill of Lading — 3 originals issued by the shipping line | 2 originals couriered to Buyer within 3 Working Days of Balance Payment being confirmed as Payment Cleared | 1 original retained by NexaCrest',
      'Bill of Lading Date To Be Confirmed — must match this invoice date',
    ]) expect([incoterm, 'CI word', ciWord.includes(line), line]).toEqual([incoterm, 'CI word', true, line]);
    for (const line of [`Paid separately via Debit Note No. ${ref}`, 'PAID VIA DEBIT NOTE', `Against Debit Note No. ${ref}`, 'Advance + Debit Note (if applicable)', 'paid separately via Debit Note, not through']) {
      expect([incoterm, 'CI pdf', ciPdf.includes(line), line]).toEqual([incoterm, 'CI pdf', true, line]);
    }

    // Freight not yet paid / no freight amount recorded: no "+ — (freight)" (the PDF prints nothing there).
    const pendingWord = await docxText(ciPending.docx_file_id);
    expect(pendingWord).toContain(`√ ${freightLabel} — PENDING`);
    expect(pendingWord).not.toContain('(freight)');

    for (const text of [fdnWord, ciWord, pendingWord]) {
      expect(text).not.toMatch(/\bFDN\b/);
      expect(text.toLowerCase()).not.toContain('freight debit note');
      expect(text).not.toContain('TBC');
    }
  }, 120000);
});
