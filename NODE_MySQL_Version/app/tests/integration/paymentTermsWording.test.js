'use strict';

// documentGenerationService renders PDFs through headless Chrome, which Jest
// stubs out — the mock captures the HTML it was asked to convert, which is
// exactly what the PDF checks below inspect (same pattern as
// buyerPoConsigneeFields.test.js).
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
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const BANK = 'All bank charges outside India are for the Buyer\'s account — please remit the full amount (charges option "OUR").';

/**
 * Port of PaymentTermsWordingTest.php. Owner decisions (2026-10-28),
 * confirmed twice on before/after previews:
 *  - the PI says exactly what to pay now — an ADVANCE PAYABLE NOW box with
 *    the advance, its due date, the balance "not payable now" and who bears
 *    the bank charges — and that production starts only on cleared funds;
 *  - the CI's Bank Details end with the same bank-charges sentence;
 *  - the Word CI's balance line follows the order's own terms (it always
 *    said "within N days of BL date", even on before-shipment orders);
 *  - every buyer payment deadline in the Word QT/PI/OC/FDN reads Calendar
 *    Days, like the PDFs, and the FDN "by [date]" is 3 calendar days on.
 */
describe('Payment terms wording on the PI, CI, QT, OC and Debit Note (owner decisions 2026-10-28)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-terms-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function order(incoterm = 'CIF', preset = 'Standard — New Buyer') {
    const orderId = await createTestOrder(await createTestClient(), incoterm, preset);
    await orderProductRepository.add(orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '450.00');
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '950.00', insurance_amount: '120.00' });
    return orderId;
  }

  const flat = (s) => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;|&apos;|&#x27;/g, "'").replace(/\s+/g, ' ').trim();

  /** The rendered PDF HTML as text, for one document. */
  async function pdfText(orderId, type) {
    await documentGenerationService.generate(orderId, type, await createTestUser(), null, false, true);
    const calls = renderPdfFromHtml.mock.calls;
    return flat(calls[calls.length - 1][0].replace(/<[^>]+>/g, ' '));
  }

  /** The Word file's text, for one document. */
  async function wordText(orderId, type) {
    const data = await documentDataAssembler.assemble(orderId, type);
    const context = { ...data, meta: { document_reference: 'JEST-TEST', revision_label: 'Rev.00', generated_date: '28 Oct 2026' }, watermark: {} };
    const tmp = path.join(os.tmpdir(), `jest-terms-${Math.random().toString(16).slice(2)}.docx`);
    fs.writeFileSync(tmp, await docxDocumentBuilder.render(type, context));
    try {
      const xml = execFileSync('unzip', ['-p', tmp, 'word/document.xml']).toString('utf8');
      return flat(xml.replace(/<w:br\/>|<\/w:p>/g, ' ').replace(/<[^>]+>/g, ''));
    } finally {
      fs.unlinkSync(tmp);
    }
  }

  it('the PI and CI carry one and the same bank-charges sentence', async () => {
    const orderId = await order();
    const pi = (await documentDataAssembler.assemble(orderId, 'PI')).financial;
    const ci = (await documentDataAssembler.assemble(orderId, 'CI')).financial;
    expect(pi.bank_charges_text).toBe(BANK);
    expect(ci.bank_charges_text).toBe(pi.bank_charges_text);
  });

  it('the PI says what to pay now and when production starts', async () => {
    const orderId = await order();
    const texts = { PDF: await pdfText(orderId, 'PI'), Word: await wordText(orderId, 'PI') };
    for (const [kind, text] of Object.entries(texts)) {
      expect([kind, text]).toEqual([kind, expect.stringContaining('ADVANCE PAYABLE NOW')]);
      expect([kind, text]).toEqual([kind, expect.stringContaining('(40% of FOB value) — due by')]);
      expect([kind, text]).toEqual([kind, expect.stringContaining('— not payable now. Payable before shipment — within 3 Calendar Days')]);
      expect([kind, text]).toEqual([kind, expect.stringContaining("Bank charges: All bank charges outside India are for the Buyer's account")]);
      expect([kind, text]).toEqual([kind, expect.stringContaining('only once the advance is received in our account as cleared funds.')]);
    }
    expect(texts.Word).not.toContain('3 working days');
  });

  it('the CI balance follows the order\'s own terms and Bank Details end with bank charges', async () => {
    const orderId = await order();
    const texts = { PDF: await pdfText(orderId, 'CI'), Word: await wordText(orderId, 'CI') };
    for (const [kind, text] of Object.entries(texts)) {
      expect([kind, text]).toEqual([kind, expect.stringContaining("Bank Charges All bank charges outside India are for the Buyer's account")]);
      expect([kind, text]).toEqual([kind, expect.stringContaining('Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation')]);
    }
    expect(texts.Word).not.toContain('days of BL date'); // a before-shipment order never says the balance is due against the BL

    const postBl = await wordText(await order('CIF', 'Established Buyer — Post-BL'), 'CI');
    expect(postBl).toContain('Payable against scanned copy of Bill of Lading, within 7 Calendar Days of the date NexaCrest emails the scanned BL copy.');
  });

  it('the Word quotation, confirmation and debit note count buyer payments in Calendar Days', async () => {
    const orderId = await order();
    const expected = {
      QT: 'Payment required within 3 Calendar Days of Freight Debit Note date.',
      OC: 'Payment required within 3 Calendar Days of the Debit Note date.',
      FDN: 'Within 3 Calendar Days of this Debit Note date — by',
    };
    for (const [type, phrase] of Object.entries(expected)) {
      const text = await wordText(orderId, type);
      expect([type, text]).toEqual([type, expect.stringContaining(phrase)]);
      expect([type, text.includes('3 working days')]).toEqual([type, false]);
    }
  });

  it('the Debit Note due date is three calendar days on', async () => {
    const fdn = await documentDataAssembler.assemble(await order(), 'FDN');
    const { todayIst } = require('../../src/helpers/dbDateTime');
    const due = new Date(`${todayIst()}T00:00:00Z`);
    due.setUTCDate(due.getUTCDate() + 3);
    expect(fdn.freight.payment_due_date).toBe(documentDataAssembler.formatDate(due.toISOString().slice(0, 10)));
  });
});
