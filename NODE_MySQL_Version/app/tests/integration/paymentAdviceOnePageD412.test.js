'use strict';

/**
 * Owner decision D-412 (24 Nov 2026, point 6, confirmed twice): a corrected Commission Payment Advice (Rev 1, one more
 * banner) for a transfer of two orders fits one page — spacing only, no word or figure changes. Fixture: the
 * associate life-cycle scenario's October correction (test data). PHP twin: tests/Integration/PaymentAdviceOnePageD412Test.php.
 */
const path = require('path');
const fs = require('fs');
const db = require('../../src/config/db');
const associatePaymentAdvice = require('../../src/services/associatePaymentAdvice');

describe('Payment advice on one page (D-412)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const advice = () => {
    const c = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'cpa-rev1-two-orders.json'), 'utf8'));
    return { advice_no: c.number, revision: 1, context_json: JSON.stringify(c), superseded_at: null };
  };

  // Jest stubs out the PDF renderer, so the one-page PDF is checked in PHPUnit (same template, same spacing) and by
  // hand; here: the Word copy's two detail tables have no space under the text inside their cells (as the PHP copy).
  it('the Word copy uses the compact detail tables', async () => {
    const JSZip = require('jszip');
    const xml = await (await JSZip.loadAsync(await associatePaymentAdvice.docx(advice()))).file('word/document.xml').async('string');
    const tables = xml.split('<w:tbl>').slice(1);
    const paidBy = tables.find((t) => t.includes('Company / Legal Entity'));
    const paidTo = tables.find((t) => t.includes('Bank Account'));
    for (const t of [paidBy, paidTo]) {
      expect(t).toBeDefined();
      expect(t).toMatch(/<w:spacing w:after="0"\/>/);
      expect(t).not.toMatch(/<w:spacing w:after="60"\/>/);
    }
  });

  it('the words and figures stay the same', async () => {
    const html = await associatePaymentAdvice.html(advice());
    for (const text of ['Rev 1 — CORRECTED:', 'Bank reference typed wrong on the October transfer. It replaces the earlier advice with the same number.',
      '₹1,85,652.16', 'TOTAL PAID TO YOUR BANK', '4. HOW THE COMMISSION IS WORKED OUT', '5. TAX (TDS)', 'This is a computer-generated advice and needs no signature.']) {
      expect(html).toContain(text);
    }
    expect(html).toContain('table.kv td { padding: 2.5pt 7pt; }');
  });
});
