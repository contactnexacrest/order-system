'use strict';

// documentGenerationService renders PDFs through headless Chrome, which Jest
// stubs out — the mock captures the HTML it was asked to convert, which is
// what the PDF checks below inspect (same pattern as paymentTermsWording.test.js).
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

const BEFORE_SHIPMENT = 'Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.';
const AGAINST_BL = 'Payable against scanned copy of Bill of Lading, within 7 Calendar Days of the date NexaCrest emails the scanned BL copy.';
const BANK = 'All bank charges outside India are for the Buyer\'s account — please remit the full amount (charges option "OUR").';

const PRESETS = [
  ['Standard — New Buyer', '40', '1,800.00', '2,700.00', BEFORE_SHIPMENT],
  ['Established Buyer — Post-BL', '40', '1,800.00', '2,700.00', AGAINST_BL],
  ['Extended Terms — Before Shipment', '30', '1,350.00', '3,150.00', BEFORE_SHIPMENT],
  ['Extended Terms — Post-BL', '30', '1,350.00', '3,150.00', AGAINST_BL],
  ['Irrevocable Letter of Credit at Sight', '0', '0.00', '4,500.00', AGAINST_BL],
];
const SCENARIOS = [];
PRESETS.forEach(([preset, pct, advance, balance, terms], p) => {
  ['FOB', 'CFR', 'CIF'].forEach((incoterm, i) => {
    SCENARIOS.push([`${preset} / ${incoterm}`, preset, incoterm, ['USD', 'EUR', 'GBP'][(p * 3 + i) % 3], pct, advance, balance, terms]);
  });
});

/**
 * Port of PaymentTermsScenariosTest.php. Scenario run of the PI / CI payment
 * wording (owner decisions 2026-10-28, paymentTermsWording.test.js) across
 * every payment preset, every incoterm and every currency, in the PDF and
 * the Word file alike: the advance box amounts, the balance line following
 * the order's own terms, the freight note, the bank-charges sentence and
 * Calendar Days.
 *
 * Owner decision (2026-10-28, confirmed twice): an order with no advance
 * (the Letter of Credit preset) says "NO ADVANCE PAYABLE — … payable by
 * Letter of Credit", without the advance sentences, and the CFR box reads
 * "Freight is paid separately".
 */
describe('PI / CI payment wording across every preset, incoterm and currency', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-scen-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  const flat = (s) => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#0*39;|&apos;|&#x27;/g, "'").replace(/&nbsp;| /g, ' ').replace(/\s+/g, ' ').trim();

  async function pdfText(orderId, type) {
    await documentGenerationService.generate(orderId, type, await createTestUser(), null, false, true);
    const calls = renderPdfFromHtml.mock.calls;
    return flat(calls[calls.length - 1][0].replace(/<[^>]+>/g, ' '));
  }

  async function wordText(orderId, type) {
    const data = await documentDataAssembler.assemble(orderId, type);
    const context = { ...data, meta: { document_reference: 'JEST-TEST', revision_label: 'Rev.00', generated_date: '28 Oct 2026' }, watermark: {} };
    const tmp = path.join(os.tmpdir(), `jest-scen-${Math.random().toString(16).slice(2)}.docx`);
    fs.writeFileSync(tmp, await docxDocumentBuilder.render(type, context));
    try {
      const xml = execFileSync('unzip', ['-p', tmp, 'word/document.xml']).toString('utf8');
      return flat(xml.replace(/<w:br\/>|<\/w:p>/g, ' ').replace(/<[^>]+>/g, ''));
    } finally {
      fs.unlinkSync(tmp);
    }
  }

  const has = (kind, text, phrase) => expect([kind, text.includes(phrase) ? phrase : text]).toEqual([kind, phrase]);
  const hasNot = (kind, text, phrase) => expect([kind, text.includes(phrase)]).toEqual([kind, false]);

  it.each(SCENARIOS)('%s', async (_label, preset, incoterm, currency, pct, advance, balance, terms) => {
    const orderId = await createTestOrder(await createTestClient(), incoterm, preset, currency);
    await orderProductRepository.add(orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '450.00');
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '950.00', insurance_amount: '120.00' });

    const financial = (await documentDataAssembler.assemble(orderId, 'PI')).financial;
    expect(financial.advance_amount).toBe(advance);
    expect(financial.balance_amount).toBe(balance);
    expect(financial.balance_terms_text).toBe(terms);
    expect(financial.bank_charges_text).toBe(BANK);

    const pi = { 'PI PDF': await pdfText(orderId, 'PI'), 'PI Word': await wordText(orderId, 'PI') };
    for (const [kind, text] of Object.entries(pi)) {
      if (pct !== '0') {
        has(kind, text, `ADVANCE PAYABLE NOW: ${currency} ${advance} (${pct}% of FOB value) — due by`);
        has(kind, text, 'only once the advance is received in our account as cleared funds.');
        has(kind, text, `Balance ${currency} ${balance} — not payable now. ${terms}`);
        // The T&C clauses are filled in by documentGenerationService, which the
        // Word text here (built straight from the assembler) leaves out.
        if (kind === 'PI PDF') has(kind, text, `acceptance and initiate the ${pct}% advance payment within this period.`);
      } else {
        has(kind, text, `NO ADVANCE PAYABLE — the full FOB value ${currency} ${balance} is payable by Letter of Credit. ${terms}`);
        hasNot(kind, text, 'ADVANCE PAYABLE NOW');
        hasNot(kind, text, 'only once the advance is received');
        hasNot(kind, text, 'initiate the 0% advance payment');
        if (kind === 'PI PDF') has(kind, text, 'notify NexaCrest of acceptance within this period.');
        // Owner decision (2026-10-28): no "0% Advance" lines in Payment Terms, and
        // production follows the Letter of Credit, not a "0% advance payment".
        hasNot(kind, text, '0% Advance');
        hasNot(kind, text, '0% advance');
        has(kind, text, `100% Payment on FOB Value by Letter of Credit — ${terms}`);
        has(kind, text, `100% Amount: ${currency} ${balance}`);
        if (kind === 'PI PDF') has(kind, text, "Production commences only after the Letter of Credit is opened and confirmed by NexaCrest's bank");
      }
      has(kind, text, `Bank charges: ${BANK}`);
      if (incoterm === 'CIF') {
        has(kind, text, 'Freight & insurance are paid separately against our Debit Note.');
      } else if (incoterm === 'CFR') {
        has(kind, text, 'Freight is paid separately against our Debit Note.');
        hasNot(kind, text, 'Freight & insurance are paid separately');
      } else {
        hasNot(kind, text, 'paid separately against our Debit Note');
      }
    }

    const ci = { 'CI PDF': await pdfText(orderId, 'CI'), 'CI Word': await wordText(orderId, 'CI') };
    for (const [kind, text] of Object.entries(ci)) {
      has(kind, text, `Bank Charges ${BANK}`);
      has(kind, text, terms);
      has(kind, text, `${currency} ${balance}`);
    }
    // Owner decision (2026-10-28): the Quotation and Order Confirmation of an
    // order with no advance (Letter of Credit) say so too — no "0% advance".
    const qt = { 'QT PDF': await pdfText(orderId, 'QT'), 'QT Word': await wordText(orderId, 'QT') };
    for (const [kind, text] of Object.entries(qt)) {
      if (pct !== '0') {
        has(kind, text, `${pct}% Advance Amount: ${currency} ${advance}`);
      } else {
        hasNot(kind, text, '0% Advance');
        hasNot(kind, text, '0% advance');
        has(kind, text, `100% Payment on FOB Value by Letter of Credit — ${terms}`);
        if (kind === 'QT PDF') has(kind, text, "Production commences only after the Letter of Credit is opened and confirmed by NexaCrest's bank");
        hasNot(kind, text, 'details for advance payment');
      }
    }
    const oc = { 'OC PDF': await pdfText(orderId, 'OC'), 'OC Word': await wordText(orderId, 'OC') };
    for (const [kind, text] of Object.entries(oc)) {
      if (pct !== '0') {
        has(kind, text, `This order is confirmed upon receipt and clearance of ${pct}% advance T/T`);
        has(kind, text, `${pct}% Advance Due (${currency})`);
      } else {
        hasNot(kind, text, '0% advance');
        hasNot(kind, text, 'Advance Due');
        has(kind, text, 'This order is confirmed upon receipt of the Letter of Credit against the referenced Proforma Invoice.');
        has(kind, text, `Payable by Letter of Credit (${currency})`);
      }
    }

    hasNot('PI Word', pi['PI Word'], '3 working days');
    hasNot('CI Word', ci['CI Word'], '3 working days');
  });
});
