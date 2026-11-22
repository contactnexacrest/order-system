'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const assembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DebitNoteWordingD406Test.php. Owner decisions of 22 Nov 2026 (each confirmed twice): D-406 — the Freight
 * Debit Note's insurance remark names the cover, "Shipping Line" reads "Freight Forwarder", and a box says how the Debit
 * Note fits the order; D-407 — on the QT / PI a freight or insurance figure not entered yet reads "To Be Confirmed".
 */
describe('Debit Note wording (D-406) and QT / PI "To Be Confirmed" (D-407)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('the insurance remark names the cover', async () => {
    const order = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
    await orderProductRepository.add(order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
    expect(await assembler.dnInsuranceRemark(false, true, order)).toBe('ICC (A) incl. breakage, 110% of CIF value — actual premium');
    expect(await assembler.dnInsuranceRemark(false, false, order)).toBe('Buyer arranges insurance (CFR)');
    expect(await assembler.dnInsuranceRemark(true, false, order)).toBe('NIL — FOB order');
  });

  it('the fit note says how the Debit Note fits the order', () => {
    expect(assembler.dnFitNote(false, true, 'SC/PI/26/0001')).toBe('Freight and insurance under Proforma Invoice No. SC/PI/26/0001. The Commercial Invoice will show this Debit Note as paid; the balance on the Commercial Invoice is for the goods (FOB value) only.');
    expect(assembler.dnFitNote(false, false, 'SC/PI/26/0001')).toBe('Freight under Proforma Invoice No. SC/PI/26/0001. The Commercial Invoice will show this Debit Note as paid; the balance on the Commercial Invoice is for the goods (FOB value) only. Insurance is arranged by the buyer (CFR).');
    expect(assembler.dnFitNote(false, true, null)).toMatch(/^Freight and insurance under Proforma Invoice No\. To Be Confirmed\./);
    expect(assembler.dnFitNote(true, false, 'SC/PI/26/0001')).toBeNull();
  });

  it('the templates carry the new wording', () => {
    const read = (p) => fs.readFileSync(path.join(__dirname, '../..', p), 'utf8');
    const fdn = read('templates/FDN/freight_debit_note.njk');
    expect(fdn).toContain('<td class="k">Freight Forwarder</td>');
    expect(fdn).not.toContain('Shipping Line');
    expect(fdn).toContain('{{ order.dn_insurance_remark }}');
    expect(fdn).toContain('How this Debit Note fits your order');
    expect(fdn).not.toContain('CIF only — if FOB/CFR enter NIL');
    for (const t of ['templates/QT/quotation.njk', 'templates/PI/proforma_invoice.njk']) {
      const html = read(t);
      expect(html).not.toContain("'XXX'");
      expect(html).not.toContain("'XX'");
      expect(html).toContain('To Be Confirmed — worked out once the freight is known; billed by Debit Note when cargo is ready.');
    }
    const docx = read('src/services/docx/docxDocumentBuilder.js');
    expect(docx).not.toContain("'Shipping Line'");
    expect(docx).toContain("'Freight Forwarder'");
    expect(docx).toContain('How this Debit Note fits your order');
  });
});
