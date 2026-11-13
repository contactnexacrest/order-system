'use strict';

// Owner decision 13 Nov 2026 (QA item 12, confirm 1 + confirm 2): a goods line that has dimensions and finish but no
// category attributes prints "Dimensions: … · Finish: … · HS Code: … · Country of Origin: India" on the PDF, as the
// PHP PDF and both Word copies do. Before, the Node templates tested `{% if p.attributes %}`; the assembler always
// gives an array and an empty array is true in Nunjucks, so the Node QT, PI and CI PDFs (and the detailed line
// table's "not sold by size" lines on QT/PI/OC/CI) printed only "HS Code · Country of Origin".
// PHP twin (pins PHP's unchanged output): tests/Integration/DocumentSpecLineItem12Test.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
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
// The spec lines under the goods lines ("1. … HS Code: … · Country of Origin: India").
const specLines = (text) => text.match(/\b\d+\. (?:(?!\b\d+\. )[^·]){0,80}?(?:· [^·]{0,80}?){0,4}Country of Origin: India/g) || [];

const SPEC = 'Dimensions: 300 x 60 x 2 cm · Finish: Polished · HS Code: 68022310 · Country of Origin: India';

describe('Item 12: PDF prints Dimensions and Finish for a line without category attributes', () => {
  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Item 12', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-item12-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });
  afterAll(async () => { await db.pool.end(); });

  it.each([
    ['classic', 'SQM', ['QT', 'PI', 'CI']],
    ['detailed', 'KG', ['QT', 'PI', 'OC', 'CI']],
  ])('%s layout (%s line): the spec line carries Dimensions and Finish', async (layout, unit, codes) => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute('UPDATE orders SET line_table_layout = :layout WHERE id = :id', { layout, id: orderId });
    await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, unit, '45.50');
    await db.execute("UPDATE order_products SET hs_code = '68022310' WHERE order_id = :id", { id: orderId });

    const evidenceDir = process.env.ITEM12_EVIDENCE_DIR || '';
    const found = {};
    for (const code of codes) {
      await documentGenerationService.generate(orderId, code, userId, null, false, true);
      const lines = specLines(lastPdfText());
      if (evidenceDir) fs.writeFileSync(path.join(evidenceDir, `node_${layout}_${code}_pdf_spec_lines.txt`), `${lines.join('\n')}\n`);
      found[code] = lines.some((l) => l.endsWith(SPEC));
    }
    expect(found).toEqual(Object.fromEntries(codes.map((c) => [c, true])));
  }, 120000);
});
