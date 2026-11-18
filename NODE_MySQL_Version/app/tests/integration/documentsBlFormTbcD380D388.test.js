'use strict';

// Owner decisions of 18 Nov 2026 (document changes, each confirmed twice), PDF and Word:
//  - D-380: the Bill of Lading wording — QT / PI / CI section "Documents" item 4 (the Word QT / PI now
//    say the same as the PDF), the BL Instruction (FULL SET), T&C clause 95 and the reference library;
//  - D-386: "Product Form: <form>" in the grey line of a line with a Sold as — QT, PI, OC, CI only,
//    immediately before "HS Code:" (detailed table) or first (simple table); lines without a Sold as
//    and every other document print as before;
//  - D-387: Supplier PO Section 3 "Product Form" (prefilled from the order lines' form, printed only
//    when filled) and the Products Amendment's second line "Product Form: <form>";
//  - D-388: a quantity "To Be Confirmed" — the line total never 0.00; TOTAL, FOB Value, TOTAL QUOTED
//    VALUE and the amount in words: "To Be Confirmed" / "<known> + line 2 To Be Confirmed" /
//    "<known> + lines 2, 4 To Be Confirmed".
// The PDF text is the HTML the PDF is printed from (the renderer is mocked), the Word text the
// document.xml of the Word copy. PHP twin: tests/Integration/DocumentsBlFormTbcD380D388Test.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const docxDocumentBuilder = require('../../src/services/docx/docxDocumentBuilder');
const productsAmendmentService = require('../../src/services/productsAmendmentService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const BL_LINE = 'Bill of Lading — full set of original Bills of Lading as issued by the shipping line, couriered to the Buyer within 3 Working Days of the Balance Payment being confirmed as Payment Cleared (or Telex Release / surrendered BL on request)';

describe('D-380 / D-386 / D-387 / D-388 documents (owner, 18 Nov 2026)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D380 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-d380-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });

  const norm = (s) => s.replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&amp;/g, '&').replace(/&nbsp;| /g, ' ')
    .replace(/&middot;/g, '·').replace(/\s+/g, ' ').trim();

  function docxText(buffer) {
    const tmp = path.join(os.tmpdir(), `jest-d380-${crypto.randomBytes(6).toString('hex')}.docx`);
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
    const ctx = { ...data, meta: { document_reference: 'JEST', revision_label: 'Rev.00', generated_date: '18 Nov 2026' }, watermark: {} };
    return { pdf, docx: docxText(await docxDocumentBuilder.render(code, ctx)) };
  }

  async function order(layout) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute('UPDATE orders SET line_table_layout = :l WHERE id = :id', { l: layout, id: orderId });
    return orderId;
  }

  async function soldAs(lineId, specName) {
    const spec = await db.queryOne('SELECT id, hs_code FROM hs_specifications WHERE name = :n', { n: specName });
    expect([specName, !!spec]).toEqual([specName, true]); // seeded specification
    await db.execute('UPDATE order_products SET hs_spec_id = :s, hs_code = :h WHERE id = :id', { s: Number(spec.id), h: spec.hs_code, id: lineId });
  }

  const count = (s, sub) => s.split(sub).length - 1;
  const has = (where, text, sub) => expect([where, sub, text.includes(sub)]).toEqual([where, sub, true]);
  const hasNot = (where, text, sub) => expect([where, sub, text.includes(sub)]).toEqual([where, sub, false]);

  // ------------------------------------------------------------------ D-380

  it('D-380: QT / PI / CI print the full-set wording in PDF and Word', async () => {
    const orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '1250.00');
    for (const code of ['QT', 'PI', 'CI']) {
      for (const [format, text] of Object.entries(await generate(orderId, code))) {
        has(`${code} ${format}`, text, `4. ${BL_LINE}`);
        for (const gone of ['3 originals', 'Original Negotiable, 3 originals', '1 original retained by NexaCrest']) hasNot(`${code} ${format}`, text, gone);
      }
    }
  });

  it('D-380: the BL Instruction asks for the full set', async () => {
    const orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '1250.00');
    for (const [format, text] of Object.entries(await generate(orderId, 'BLI'))) {
      const w = `BLI ${format}`;
      has(w, text, 'ORIGINAL NEGOTIABLE BILL OF LADING — FULL SET');
      has(w, text, 'No. of Original Copies Full set as issued by the shipping line (the number is stated on the BL)');
      has(w, text, 'No. of Non-Negotiable Copies 3'); // unchanged
      has(w, text, 'MANDATORY INSTRUCTION — DO NOT ISSUE SEA WAYBILL OR EXPRESS BL'); // unchanged
      has(w, text, 'CHA to hand the full set of originals to NexaCrest only — never to the buyer or anyone else.');
      hasNot(w, text, '3 ORIGINALS');
      hasNot(w, text, 'three originals');
    }
  });

  it('D-380: clause 95 and the reference library as seeded', async () => {
    const clause = String((await db.queryOne("SELECT clause_text FROM tc_clauses WHERE clause_title = 'Bill of Lading Release'")).clause_text);
    expect(clause.startsWith('Bill of Lading: The full set of original negotiable Bills of Lading, as issued by the shipping line, will be released to the buyer only after {balance_pct}% balance payment is CLEARED')).toBe(true);
    expect(clause).toContain('Remittance copy alone does not constitute payment receipt.'); // the rest of the clause is unchanged
    const library = (await db.query('SELECT content FROM internal_reference_docs')).map((r) => String(r.content)).join('\n');
    for (const gone of ['3 Originals ONLY', '3 originals ONLY', 'all 3 originals', 'HS Code: 6802.93', 'HS CODE: 6802.93', 'PI and CI: 6802.93', '60% balance is cleared', 'until 60% balance']) {
      hasNot('reference library', library, gone);
    }
    for (const expected of [
      "BL TYPE: Original Negotiable Bill of Lading — full set of originals. Telex Release / surrendered BL only on the buyer's request and with MD approval, after the balance is cleared.",
      'NexaCrest retains the full set of originals until the balance T/T is cleared',
      'NEVER request Sea Waybill or Express BL',
      'HS Code: 68029300 for all granite/monument products',
      'HS Code on PL matches PI and CI: 68029300',
      'HS CODE: 68029300 for all worked monumental granite products',
    ]) has('reference library', library, expected);
  });

  // ------------------------------------------------------------------ D-386

  it('D-386: Product Form on the detailed table of the QT / PI / OC / CI only', async () => {
    const orderId = await order('detailed');
    const block = await orderProductRepository.add(orderId, 1, 'CHM Black block', null, 'Roughly trimmed', '25.000', false, 'CBM', '600.00', '25161100');
    await orderProductRepository.add(orderId, 2, 'CHM Black slab', null, 'Polished', '100.00', false, 'SQM', '40.00', '68022310');
    await soldAs(block, 'Raw block');

    for (const code of ['QT', 'PI', 'OC', 'CI']) {
      const rows = (await documentDataAssembler.assemble(orderId, code)).line_table.rows;
      expect([code, rows[0].detail[0]]).toEqual([code, { label: 'Product Form:', text: 'Raw block' }]);
      expect([code, rows[0].detail[1].label]).toEqual([code, 'HS Code:']); // immediately before the HS Code
      expect([code, rows[1].detail.map((d) => d.label).includes('Product Form:')]).toEqual([code, false]); // a line without a Sold as is unchanged
      for (const [format, text] of Object.entries(await generate(orderId, code))) {
        has(`${code} ${format}`, text, 'Product Form: Raw block · HS Code: 25161100');
        expect([code, format, count(text, 'Product Form:')]).toEqual([code, format, 1]); // only the line with a Sold as
      }
    }
    for (const code of ['PL', 'BUYERPO']) {
      for (const r of (await documentDataAssembler.assemble(orderId, code)).line_table.rows) {
        expect([code, r.detail.map((d) => d.label).includes('Product Form:')]).toEqual([code, false]);
      }
    }
    for (const code of ['SUPPO', 'BLI', 'COOPREP', 'PL', 'BUYERPO']) {
      expect([code, (await documentDataAssembler.assemble(orderId, code)).products.map((p) => p.product_form).filter(Boolean)]).toEqual([code, []]);
    }
  });

  it("D-386: Product Form first in the simple table's grey line", async () => {
    const orderId = await order('classic');
    const line = await orderProductRepository.add(orderId, 1, 'Black Galaxy Monument', '180 x 80 x 13 cm', 'Polished', '20', false, 'PCS', '300.00', '68029300');
    await orderProductRepository.add(orderId, 2, 'Kashmir White Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
    await soldAs(line, 'Monument slab');
    for (const code of ['QT', 'PI', 'CI']) {
      for (const [format, text] of Object.entries(await generate(orderId, code))) {
        has(`${code} ${format}`, text, 'Product Form: Monument slab · Dimensions: 180 x 80 x 13 cm');
        expect([code, format, count(text, 'Product Form:')]).toEqual([code, format, 1]);
      }
    }
  });

  // ------------------------------------------------------------------ D-387

  it('D-387: the Supplier PO "Product Form" row — prefilled, and printed only when filled', async () => {
    const orderId = await order('classic');
    const line = await orderProductRepository.add(orderId, 1, 'CHM Black block', null, 'Roughly trimmed', '25.000', false, 'CBM', '600.00', '25161100');
    await soldAs(line, 'Raw block');
    expect(await orderSupplierPoRepository.defaultProductForm(orderId)).toBe('Raw block');
    const supplierId = await supplierRepository.create({ supplier_legal_name: `D387 Quarry ${crypto.randomBytes(3).toString('hex')}`, types: [] }, userId);
    const terms = { material_stone_type: 'CHM Black', quantity: '25', unit_price_inr: '45000', basic_value_inr: '1125000', total_payable_inr: '1125000', balance_amount_inr: '1125000', required_delivery_date: '' };
    const ref = () => `SC/SPO/2026/D387${crypto.randomBytes(3).toString('hex')}`;
    // Without the field: the order lines' form.
    await orderSupplierPoRepository.create(orderId, supplierId, ref(), terms);
    for (const [format, text] of Object.entries(await generate(orderId, 'SUPPO'))) {
      has(`SUPPO ${format}`, text, 'CHM Black Product Form Raw block Grade'); // right after the material row
    }
    // Emptied on the form: no row.
    await orderSupplierPoRepository.create(orderId, supplierId, ref(), { ...terms, product_form: '  ' });
    for (const [format, text] of Object.entries(await generate(orderId, 'SUPPO'))) {
      hasNot(`SUPPO ${format}`, text, 'Product Form'); // printed only when filled
    }
    // Typed on the form: as typed.
    await orderSupplierPoRepository.create(orderId, supplierId, ref(), { ...terms, product_form: 'Sawn block' });
    expect((await documentDataAssembler.assemble(orderId, 'SUPPO')).supplier_po.product_form).toBe('Sawn block');
  });

  it("D-387: the Products Amendment's second line", async () => {
    const orderId = await order('classic');
    const withForm = await orderProductRepository.add(orderId, 1, 'Black Galaxy Monument', null, 'Polished', '20', false, 'PCS', '300.00', '68029300');
    const plain = await orderProductRepository.add(orderId, 2, 'Kashmir White Tiles', null, 'Flamed', '80', false, 'SQM', '32.25');
    await soldAs(withForm, 'Monument slab');
    const amendmentId = (await db.execute(
      `INSERT INTO amendments (amendment_reference, order_id, reason, requested_by, original_terms_snapshot, amendment_type, products_totals_json)
       VALUES (:r, :o, 'Buyer asked for more headstones', 'importer', '{}', 'products', :t)`,
      { r: `SC/AMD/2026/D387${crypto.randomBytes(3).toString('hex')}`, o: orderId, t: JSON.stringify({ currency: 'USD', old_total: 8580, new_total: 11580 }) }
    )).insertId;
    const ins = `INSERT INTO amendment_product_lines (amendment_id, line_no, order_product_id, change_kind, description, unit, old_quantity, new_quantity, old_unit_price, new_unit_price, old_line_total, new_line_total)
      VALUES (:a, :n, :op, 'change', :d, :u, :oq, :nq, :p, :p2, :ot, :nt)`;
    await db.execute(ins, { a: amendmentId, n: 1, op: withForm, d: 'Black Galaxy Monument', u: 'PCS', oq: '20', nq: '30', p: '300.00', p2: '300.00', ot: '6000.00', nt: '9000.00' });
    await db.execute(ins, { a: amendmentId, n: 2, op: plain, d: 'Kashmir White Tiles', u: 'SQM', oq: '80', nq: '80', p: '32.25', p2: '32.25', ot: '2580.00', nt: '2580.00' });
    const amendment = await db.queryOne('SELECT * FROM amendments WHERE id = :id', { id: amendmentId });
    const rows = (await productsAmendmentService.documentBlock(amendment)).rows;
    expect(rows.map((r) => r.product_form)).toEqual(['Monument slab', null]);
    const template = fs.readFileSync(path.join(__dirname, '../../templates/AMD/products_amendment.njk'), 'utf8');
    expect(template).toContain('{{ r.product }}{% if r.product_form %}<br><span class="amd-form">Product Form: {{ r.product_form }}</span>{% endif %}');
  });

  // ------------------------------------------------------------------ D-388

  it('D-388: every line to be confirmed', async () => {
    const orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, null, true, 'PCS', '1250.00');
    const f = (await documentDataAssembler.assemble(orderId, 'QT')).financial;
    expect([f.fob_value, f.total_value]).toEqual(['To Be Confirmed', 'To Be Confirmed']);
    expect((await documentDataAssembler.assemble(orderId, 'CI')).financial.total_invoice_value_in_words).toBe('To Be Confirmed');
    expect((await documentDataAssembler.assemble(orderId, 'QT')).products[0].amount).toBe('To Be Confirmed'); // the line total is never 0.00
    for (const [format, text] of Object.entries(await generate(orderId, 'QT'))) {
      has(`QT ${format}`, text, 'FOB Value (USD) To Be Confirmed');
      has(`QT ${format}`, text, 'TOTAL QUOTED VALUE (USD) To Be Confirmed');
    }
  });

  it('D-388: some lines to be confirmed', async () => {
    const orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '100.00');
    await orderProductRepository.add(orderId, 2, 'Granite Kerb', null, null, null, true, 'PCS', '50.00');
    const f = (await documentDataAssembler.assemble(orderId, 'QT')).financial;
    expect(f.fob_value).toBe('1,000.00 + line 2 To Be Confirmed');
    expect(f.total_value).toBe('1,000.00 + line 2 To Be Confirmed');
    expect((await documentDataAssembler.assemble(orderId, 'CI')).financial.total_invoice_value_in_words.endsWith(' + line 2 To Be Confirmed')).toBe(true);
    for (const [format, text] of Object.entries(await generate(orderId, 'QT'))) {
      has(`QT ${format}`, text, 'TOTAL QUOTED VALUE (USD) 1,000.00 + line 2 To Be Confirmed');
    }
    await orderProductRepository.add(orderId, 3, 'Granite Step', null, null, '5', false, 'PCS', '200.00');
    await orderProductRepository.add(orderId, 4, 'Granite Cobble', null, null, null, true, 'PCS', '5.00');
    for (const code of ['QT', 'PI', 'OC', 'CI']) {
      const d = await documentDataAssembler.assemble(orderId, code);
      expect([code, d.financial.fob_value]).toEqual([code, '2,000.00 + lines 2, 4 To Be Confirmed']);
      expect([code, d.financial.total_value]).toEqual([code, '2,000.00 + lines 2, 4 To Be Confirmed']);
      expect([code, d.products[1].amount]).toEqual([code, 'To Be Confirmed']); // the line total of a TBC line
    }
    for (const [format, text] of Object.entries(await generate(orderId, 'PI'))) {
      has(`PI ${format}`, text, '2,000.00 + lines 2, 4 To Be Confirmed');
    }
  });

  // D-388 extended (owner, 18 Nov 2026, confirmed twice): the QT / PI / OC advance and balance lines.
  it('D-388: the advance and balance lines never show 0.00', async () => {
    let orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, null, true, 'PCS', '1250.00');
    for (const code of ['QT', 'PI', 'OC']) {
      const f = (await documentDataAssembler.assemble(orderId, code)).financial;
      expect([code, f.advance_due, f.advance_due_text, f.balance_due, f.balance_due_text])
        .toEqual([code, 'To Be Confirmed', 'To Be Confirmed', 'To Be Confirmed', 'To Be Confirmed']);
    }
    for (const code of ['QT', 'PI']) {
      for (const [format, text] of Object.entries(await generate(orderId, code))) {
        expect([`${code} ${format}`, /Advance Amount:[\s ]*To Be Confirmed/u.test(text)]).toEqual([`${code} ${format}`, true]);
        expect([`${code} ${format}`, /Balance Amount:[\s ]*To Be Confirmed/u.test(text)]).toEqual([`${code} ${format}`, true]);
        expect([`${code} ${format}`, /(Advance|Balance) Amount:[\s ]*USD 0\.00/u.test(text)]).toEqual([`${code} ${format}`, false]);
      }
    }

    // Line 1 known (10 × 100.00), line 2 To Be Confirmed: the known part + "line 2 To Be Confirmed".
    orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '100.00');
    await orderProductRepository.add(orderId, 2, 'Granite Kerb', null, null, null, true, 'PCS', '50.00');
    let f = (await documentDataAssembler.assemble(orderId, 'QT')).financial;
    expect(f.advance_due).toBe(`${f.advance_amount} + line 2 To Be Confirmed`);
    expect(f.advance_due_text).toBe(`USD ${f.advance_amount} + line 2 To Be Confirmed`);
    expect(f.balance_due_text).toBe(`USD ${f.balance_amount} + line 2 To Be Confirmed`);
    for (const [format, text] of Object.entries(await generate(orderId, 'QT'))) {
      has(`QT ${format}`, text, `USD ${f.advance_amount} + line 2 To Be Confirmed`);
    }

    // No line To Be Confirmed: exactly as before ("USD <amount>").
    orderId = await order('classic');
    await orderProductRepository.add(orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '100.00');
    f = (await documentDataAssembler.assemble(orderId, 'PI')).financial;
    expect([f.advance_due_text, f.balance_due_text, f.advance_due]).toEqual([`USD ${f.advance_amount}`, `USD ${f.balance_amount}`, f.advance_amount]);
  });

  it("D-388: the detailed table's TOTAL row", async () => {
    const orderId = await order('detailed');
    await orderProductRepository.add(orderId, 1, 'CHM Black block', null, 'Roughly trimmed', '5.000', false, 'CBM', '600.00', '25161100');
    await orderProductRepository.add(orderId, 2, 'CHM Black block', null, 'Roughly trimmed', null, true, 'CBM', '600.00', '25161100');
    expect((await documentDataAssembler.assemble(orderId, 'QT')).line_table.total.amount).toBe('3,000.00 + line 2 To Be Confirmed');
    for (const [format, text] of Object.entries(await generate(orderId, 'QT'))) {
      has(`QT ${format}`, text, '3,000.00 + line 2 To Be Confirmed');
    }
  });
});
