'use strict';

// Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"):
// (1) the money on an INR order's documents in Indian grouping (1,75,000.00), every other
// currency and the CAFIN as before; (2) "Total CBM (m³)" of a detailed order's QT / PI worked
// out from the lines; (3) the Word copy numbers its sections exactly as the PDF.
// PHP twin: tests/Integration/D343DocumentNumbersTest.php (same cases, same wording).
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const lineTable = require('../../src/services/lineTable');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder, allowHandTypedGoodsLines } = require('../support/fixtures');

const decode = (s) => String(s).replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&sup3;/g, '³').replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/ /g, ' ');
const flat = (s) => decode(s).replace(/\s+/g, ' ').trim();

// The numbered section headings in reading order: "5. SHIPPING / COMMERCIAL TERMS", "8. BANK DETAILS (for balance T/T payment)".
function headings(text) {
  const re = /(?:^|(?<=\s))(\d{1,2})\.[ \t]+((?=[A-Z(])(?:[A-Z0-9()&/—.,'-]+(?:[ \t]+|(?=\n)|$))+)(\([^)\n]*\))?/gmu;
  const out = [];
  for (const m of text.matchAll(re)) {
    if (m[2].length >= 5) out.push(`${m[1]}. ${(m[2] + (m[3] || '')).replace(/\s+/g, ' ').trim()}`);
  }
  return out;
}

describe('D-343 Indian grouping, Total CBM, Word copy section numbers', () => {
  let admin;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D343', :email, NULL, 'x', :r, 1, 0, 0)`,
      { email: `jest-d343-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, r: role.id }
    )).insertId;
  });
  afterAll(async () => { await db.pool.end(); });

  async function order(layout, currency) {
    await allowHandTypedGoodsLines();
    const orderId = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', currency);
    await db.execute('UPDATE orders SET line_table_layout = :l WHERE id = :id', { l: layout, id: orderId });
    return orderId;
  }

  // The owner's example: CHM Black P6, 180 × 80 × 13 × 25, 140 × 70 × 13 × 25, 120 × 60 × 13 × 10 at 1,75,000 per CBM.
  async function ownersExample(orderId) {
    const rows = [[180, 80, 25], [140, 70, 25], [120, 60, 10]];
    for (let i = 0; i < rows.length; i++) {
      const [l, w, pcs] = rows[i];
      const qty = lineTable.quantityFromPieces('CBM', l, w, 13, pcs);
      const id = await orderProductRepository.add(orderId, i + 1, 'CHM Black', `${l} × ${w} × 13 cm`, 'P6', qty, false, 'CBM', '175000.00', '68022310');
      await orderProductRepository.setPieceSize(id, { piece_length_cm: String(l), piece_width_cm: String(w), piece_thickness_cm: '13' });
      await orderProductRepository.setPieces(id, pcs, null);
    }
  }

  // The PDF's text (from the HTML it is rendered from, one line per block) and the Word text (one line per paragraph).
  async function texts(orderId, code) {
    const r = await documentGenerationService.generate(orderId, code, admin, null, true, true);
    const calls = renderPdfFromHtml.mock.calls;
    const html = calls[calls.length - 1][0].replace(/<style[\s\S]*?<\/style>/g, ' ');
    const pdf = decode(html.replace(/<\/(div|p|tr|li|h\d)>|<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, ' ').replace(/[ \t]*\n[ \t]*/g, '\n'));
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: r.docx_file_id });
    const xml = execFileSync('unzip', ['-p', file.server_path, 'word/document.xml']).toString('utf8');
    const docx = decode(xml.replace(/<\/w:p>/g, '\n').replace(/<[^>]+>/g, ''));
    return { pdf, docx };
  }

  it('an INR order prints Indian grouping on the PDF and the Word copy', async () => {
    const orderId = await order('detailed', 'INR');
    await ownersExample(orderId);
    for (const code of ['QT', 'PI', 'CI']) {
      for (const [format, text] of Object.entries(await texts(orderId, code))) {
        const f = flat(text);
        for (const amount of ['1,75,000.00', '8,19,000.00', '5,57,375.00', '1,63,800.00', '15,40,175.00']) expect([code, format, amount, f.includes(amount)]).toEqual([code, format, amount, true]);
        for (const international of ['175,000.00', '819,000.00', '1,540,175.00']) expect([code, format, international, f.includes(international)]).toEqual([code, format, international, false]);
      }
    }
    // The advance (40%) and balance on the PI; the amount in words keeps its own wording.
    const pi = flat((await texts(orderId, 'PI')).pdf);
    expect(pi).toContain('INR 6,16,070.00');
    expect(pi).toContain('INR 9,24,105.00');
    const data = await documentDataAssembler.assemble(orderId, 'CI');
    expect(data.financial.fob_value).toBe('15,40,175.00');
    expect(data.financial.fob_value_in_words).toBe('Indian Rupees One Million Five Hundred Forty Thousand One Hundred Seventy-Five Only');
  });

  it('a USD order and the CAFIN keep their grouping', async () => {
    const usd = await order('detailed', 'USD');
    await ownersExample(usd);
    const qt = flat((await texts(usd, 'QT')).docx);
    expect(qt).toContain('1 CHM Black P6 180 80 13 0.1872 25 4.680 175,000.00 32,760.00 819,000.00');
    expect(qt).toContain('TOTAL 60 8.801 1,540,175.00');
    expect(qt).not.toContain('15,40,175.00');

    // The CAFIN (internal) is not a client document: its figures are unchanged, INR order or not.
    const inr = await order('detailed', 'INR');
    await ownersExample(inr);
    const cafin = JSON.stringify(await caFinancialSnapshotService.build(inr));
    expect(cafin).toContain('1,540,175.00');
    expect(cafin).not.toContain('15,40,175.00');
  });

  it('Total CBM in the Weight & Volume box', async () => {
    const orderId = await order('detailed', 'INR');
    await ownersExample(orderId);
    // Owner, 12 Nov 2026: an old typed estimate (8.2) never wins over the lines (8.801).
    await db.execute('UPDATE orders SET estimated_total_cbm = 8.2 WHERE id = :id', { id: orderId });
    for (const code of ['QT', 'PI']) {
      expect([code, (await documentDataAssembler.assemble(orderId, code)).order.line_table_total_cbm]).toEqual([code, '8.801']);
    }
    let qt = await texts(orderId, 'QT');
    expect(flat(qt.pdf)).toContain('Total CBM (m³) 8.801 m³');
    expect(flat(qt.docx)).toContain('Total CBM (m³) 8.801 m³');

    // A goods line that cannot be turned into CBM (SQM without a thickness): "To Be Confirmed".
    const id = await orderProductRepository.add(orderId, 4, 'Kashmir White', '60 × 60 cm', 'Flamed', '18.00', false, 'SQM', '2500.00', '68022310');
    await orderProductRepository.setPieceSize(id, { piece_length_cm: '60', piece_width_cm: '60', piece_thickness_cm: null });
    await orderProductRepository.setPieces(id, 50, null);
    expect((await documentDataAssembler.assemble(orderId, 'QT')).order.line_table_total_cbm).toBeNull();
    qt = await texts(orderId, 'QT');
    expect(flat(qt.pdf)).toContain('Total CBM (m³) To Be Confirmed m³');
    expect(flat(qt.docx)).toContain('Total CBM (m³) To Be Confirmed m³');

    // A classic order prints what it printed before.
    const classic = await order('classic', 'INR');
    await ownersExample(classic);
    expect((await documentDataAssembler.assemble(classic, 'QT')).order.line_table_total_cbm).toBeNull();
    expect(flat((await texts(classic, 'QT')).pdf)).toContain('Total CBM (m³) To Be Confirmed m³');
  });

  it('the Word copy numbers its sections as the PDF', async () => {
    const expected = {
      QT: '1. SELLER / EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. PRODUCT / ORDER DETAILS|5. SHIPPING / COMMERCIAL TERMS|6. PAYMENT TERMS|7. DOCUMENTS TO BE PROVIDED|8. TERMS & CONDITIONS|9. LEGAL TERMS & DEFINITIONS',
      PI: '1. SELLER / EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. NOTIFY PARTY|5. PRODUCT / ORDER DETAILS|6. SHIPPING / COMMERCIAL TERMS|7. PAYMENT TERMS|8. BANK DETAILS|9. EXPORT DOCUMENTATION|10. TERMS & CONDITIONS|11. LEGAL TERMS & DEFINITIONS',
      OC: '1. SELLER / EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. ORDER SUMMARY|5. PAYMENT STATUS|6. PRODUCTION & ESTIMATED SHIPMENT|7. DOCUMENTS TO BE PROVIDED|8. ORDER CONDITIONS|9. LEGAL TERMS & DEFINITIONS',
      CI: '1. EXPORTER / SELLER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. NOTIFY PARTY|5. SHIPPING DETAILS|6. PRODUCT / ORDER DETAILS|7. INVOICE VALUE & PAYMENT SETTLEMENT|8. BANK DETAILS (for balance T/T payment)|9. DOCUMENTS PROVIDED WITH THIS SHIPMENT|10. DECLARATION|11. LEGAL TERMS & DEFINITIONS',
      PL: '1. EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. NOTIFY PARTY|5. PRODUCT SUMMARY|6. CRATE-LEVEL BREAKDOWN (from factory packing data)|7. DECLARATION|8. LEGAL TERMS & DEFINITIONS',
    };
    for (const layout of ['detailed', 'classic']) {
      const orderId = await order(layout, 'USD');
      await ownersExample(orderId);
      for (const [code, list] of Object.entries(expected)) {
        const t = await texts(orderId, code);
        expect([code, layout, 'PDF', headings(t.pdf)]).toEqual([code, layout, 'PDF', list.split('|')]);
        expect([code, layout, 'Word copy = PDF', headings(t.docx)]).toEqual([code, layout, 'Word copy = PDF', headings(t.pdf)]);
      }
    }
  });
});
