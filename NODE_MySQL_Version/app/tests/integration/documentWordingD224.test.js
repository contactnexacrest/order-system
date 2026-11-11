'use strict';

// Owner decision D-224 (2 Nov 2026, D1–D5, confirmed twice), PDF and Word:
// D1 Order Confirmation prints the buyer's PO number (only when entered);
// D2 the Debit Note's "2. TO (BUYER)" is the full buyer block;
// D3 the BL Instruction gives the CHA the instruction only;
// D4 the Commercial Invoice no longer mentions an IGST refund (LUT export);
// D5 the Commercial Invoice prints its total in words.
// PHP twin: DocumentWordingD224Test.php.
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
const amountInWords = require('../../src/helpers/amountInWords');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

describe('D-224 document wording (owner, 2 Nov 2026)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D224 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-d224-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });

  async function order(buyersPo = 'PO-2026-0457') {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '1250.05');
    await db.execute(
      `UPDATE orders SET buyers_po_ref = :po, billing_address_line1 = '7 Harbour Road', billing_address_line2 = 'Unit 4',
              billing_city = 'Poti', billing_postcode = '4400', country_of_destination = 'Georgia',
              vat_eori_tax_no = 'GE-VAT-778899', contact_person = 'Head Buyer',
              client_phone = '+995 555 123 456', client_email = :email WHERE id = :id`,
      { po: buyersPo, email: `nino-${orderId}@buyer.test`, id: orderId }
    );
    return orderId;
  }

  const norm = (s) => s.replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ');

  function docxText(buffer) {
    const tmp = path.join(os.tmpdir(), `jest-d224-${Math.random().toString(16).slice(2)}.docx`);
    fs.writeFileSync(tmp, buffer);
    try {
      return norm(execFileSync('unzip', ['-p', tmp, 'word/document.xml']).toString('utf8').replace(/<[^>]+>/g, ' '));
    } finally {
      fs.unlinkSync(tmp);
    }
  }

  async function generate(orderId, code) {
    await documentGenerationService.generate(orderId, code, userId, null, false, true);
    const calls = renderPdfFromHtml.mock.calls;
    const pdf = norm(calls[calls.length - 1][0].replace(/<[^>]+>/g, ' '));
    const data = await documentDataAssembler.assemble(orderId, code);
    const ctx = { ...data, meta: { document_reference: 'JEST', revision_label: 'Rev.00', generated_date: '02 Nov 2026' }, watermark: {} };
    return { pdf, docx: docxText(await docxDocumentBuilder.render(code, ctx)) };
  }

  const count = (s, sub) => s.split(sub).length - 1;

  it("D1: the Order Confirmation shows the buyer's PO only when entered", async () => {
    for (const [format, text] of Object.entries(await generate(await order(), 'OC'))) {
      expect([format, count(text, "Buyer's PO / Ref No.")]).toEqual([format, 1]);
      expect(text).toContain('PO-2026-0457');
      expect(text.indexOf("Buyer's PO / Ref No.")).toBeGreaterThan(text.indexOf('Proforma Invoice No.'));
    }
    for (const [format, text] of Object.entries(await generate(await order('NIL'), 'OC'))) {
      expect([format, text.includes("Buyer's PO / Ref No.")]).toEqual([format, false]);
    }
  });

  it('D2: the Debit Note has the full buyer block', async () => {
    for (const [format, text] of Object.entries(await generate(await order(), 'FDN'))) {
      expect(text).toContain('2. TO (BUYER)');
      for (const expected of ['Billing Address Line 1', '7 Harbour Road', 'Unit 4', 'City / Town', 'Poti', '4400', 'Georgia',
        'VAT / EORI / Tax Reg. No.', 'GE-VAT-778899', 'Head Buyer', 'Phone', '+995 555 123 456', '@buyer.test']) {
        expect([format, expected, text.includes(expected)]).toEqual([format, expected, true]);
      }
    }
  });

  it('D3: the BL Instruction gives the CHA the instruction only', async () => {
    for (const [format, text] of Object.entries(await generate(await order(), 'BLI'))) {
      expect(text).toContain('MANDATORY INSTRUCTION — DO NOT ISSUE SEA WAYBILL OR EXPRESS BL');
      expect(text).toContain('ORIGINAL NEGOTIABLE BILL OF LADING — 3 ORIGINALS');
      expect(text).toMatch(/Original BL release: +CHA to hand all 3 originals to NexaCrest only — never to the buyer or anyone else\./);
      for (const gone of ['Reason:', 'payment terms are', 'NexaCrest will hold all 3 originals', 'released freely',
        'Original BL release process', 'balance T/T', 'ensures the BL is to NexaCrest']) {
        expect([format, gone, text.includes(gone)]).toEqual([format, gone, false]);
      }
    }
  });

  it('D4 + D5: Commercial Invoice wording and the total in words', async () => {
    for (const [format, text] of Object.entries(await generate(await order(), 'CI'))) {
      expect([format, text.includes('IGST refund')]).toEqual([format, false]);
      expect(text).toContain('FOB Value is the basis for all payments and is the value declared on the Indian Shipping Bill');
      expect(text).toContain('the value declared on the Indian Shipping Bill');
      expect(text).toContain('US Dollars Twelve Thousand Five Hundred and Fifty Cents Only');
      expect([format, text.includes('_______________')]).toEqual([format, false]);
    }
  });

  it('amount in words (same text as PHP)', () => {
    const cases = [
      ['0.50', 'US Dollars Zero and Fifty Cents Only'],
      ['1', 'US Dollars One Only'],
      ['12', 'US Dollars Twelve Only'],
      ['100', 'US Dollars One Hundred Only'],
      ['1005', 'US Dollars One Thousand Five Only'],
      ['12500.50', 'US Dollars Twelve Thousand Five Hundred and Fifty Cents Only'],
      ['1000000', 'US Dollars One Million Only'],
    ];
    for (const [amount, expected] of cases) expect(amountInWords.format(amount, 'USD')).toBe(expected);
    expect(amountInWords.format('999.01', 'EUR')).toBe('Euros Nine Hundred Ninety-Nine and One Cents Only');
    expect(amountInWords.format('21.05', 'GBP')).toBe('Pounds Sterling Twenty-One and Five Pence Only');
    expect(amountInWords.format('70', 'AED')).toBe('UAE Dirhams Seventy Only');
    expect(amountInWords.format('2000.10', 'INR')).toBe('Indian Rupees Two Thousand and Ten Paise Only');
    expect(amountInWords.format('5', 'CAD')).toBe('CAD Five Only');
  });
});
