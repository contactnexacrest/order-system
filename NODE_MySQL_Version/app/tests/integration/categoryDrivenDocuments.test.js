'use strict';

// Owner decision D-257 Phase 1 (5 Nov 2026): documents follow the product category.
// A test-only "Engineering" category (per piece, its own HS code, wooden boxes,
// MTC 3.1 + drawing approval, the engineering clause set) prints its own attributes
// and HS code, its packing words, the quality documents it needs and its own
// clauses — and none of the stone ones. A mixed order prints both sets. The stone
// side is stoneDocumentsUnchanged.test.js. PHP twin: CategoryDrivenDocumentsTest.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const productCategoryRepository = require('../../src/repositories/productCategoryRepository');
const termsClauseRepository = require('../../src/repositories/termsClauseRepository');
const categoryDocumentProfile = require('../../src/services/categoryDocumentProfile');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const decode = (s) => s.replace(/&#39;|&#x27;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

describe('D-257 Phase 1: category-driven documents', () => {
  afterAll(async () => { await db.pool.end(); });

  let userId;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Category Docs', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-catdocs-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = r.insertId;
  });

  async function engineeringCategory() {
    const id = await productCategoryRepository.create(`Engineering Products ${Math.random().toString(16).slice(2, 8)}`, ['Material', 'Size'], userId);
    await productCategoryRepository.updateSettings(id, {
      default_unit: 'PCS', allowed_units: 'PCS,SET,KG', default_hs_code: '848180',
      packing_type: 'Wooden Boxes', quality_documents: 'Mill Test Certificate 3.1\nDrawing Approval',
      tc_clause_set: 'engineering',
    });
    return id;
  }

  async function order(withStone = false) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const lineId = await orderProductRepository.add(orderId, 1, 'Ball Valve', null, null, '200', false, 'PCS', '42.00', '848180');
    await productCategoryRepository.setLineCategory(lineId, await engineeringCategory(), { Material: 'SS316', Size: 'DN50' });
    if (withStone) {
      await orderProductRepository.add(orderId, 2, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
    }
    await db.execute('UPDATE orders SET estimated_package_count = 8 WHERE id = :id', { id: orderId });
    return orderId;
  }

  async function generate(orderId, code) {
    const r = await documentGenerationService.generate(orderId, code, userId, null, true, true);
    const calls = renderPdfFromHtml.mock.calls;
    const pdf = decode(calls[calls.length - 1][0].replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' '));
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: r.docx_file_id });
    const docx = decode(execFileSync('unzip', ['-p', file.server_path, 'word/document.xml']).toString('utf8').replace(/<[^>]+>/g, ' '));
    return { pdf, docx };
  }

  const has = (where, text, s) => expect([where, s, text.includes(s)]).toEqual([where, s, true]);
  const hasNot = (where, text, s) => expect([where, s, text.includes(s)]).toEqual([where, s, false]);

  it('works out the profile of an engineering order', async () => {
    const p = await categoryDocumentProfile.forOrder(await order());
    expect(p.stone_only).toBe(false);
    expect(p.clause_sets).toEqual(['engineering']);
    expect(p.packing_label).toBe('Wooden Boxes');
    expect(p.quality_documents.map((q) => q.name)).toEqual(['Mill Test Certificate 3.1', 'Drawing Approval']);
    expect(categoryDocumentProfile.fromCategories([])).toEqual(categoryDocumentProfile.stone());
  });

  it("Natural Stone's own settings never change its documents", () => {
    const stoneCategory = { id: 1, name: 'Natural Stone', is_system: 1, tc_clause_set: 'engineering', packing_type: 'Steel Pallets', quality_documents: 'Inspection report' };
    expect(categoryDocumentProfile.fromCategories([stoneCategory])).toEqual(categoryDocumentProfile.stone());
  });

  it('a mixed order uses both clause sets and neutral packing words', async () => {
    const p = await categoryDocumentProfile.forOrder(await order(true));
    expect(p.clause_sets).toEqual(['engineering', 'natural_stone']);
    expect(p.packing_label).toBe('Packages');
    expect(termsClauseRepository.inClauseSets(null, ['engineering'])).toBe(true);
    expect(termsClauseRepository.inClauseSets('general_goods,engineering', ['engineering'])).toBe(true);
    expect(termsClauseRepository.inClauseSets('natural_stone', ['engineering'])).toBe(false);
  });

  it("the QT, PI and OC print the category's own lines, quality documents and clauses", async () => {
    const orderId = await order();
    for (const code of ['QT', 'PI', 'OC']) {
      for (const [format, text] of Object.entries(await generate(orderId, code))) {
        const where = `${code} ${format}`;
        has(where, text, 'Mill Test Certificate 3.1 — required for Engineering Products');
        has(where, text, 'Drawing Approval — required for Engineering Products');
        has(where, text, 'Specification & Drawings: Goods are made to the buyer-approved drawing');
        has(where, text, 'may occur due to production yield');
        has(where, text, 'Quality Documents: The quality documents listed in this document');
        for (const gone of ['minor variation in shade, grain and veining', 'natural stone production characteristics', 'Product Characteristics:']) hasNot(where, text, gone);
        if (code !== 'OC') {
          has(where, text, 'SS316');
          has(where, text, '848180');
          has(where, text, 'Net Weight (goods only)');
          hasNot(where, text, 'stone only');
          hasNot(where, text, 'Packages / Crates');
        }
      }
    }
    for (const [format, text] of Object.entries(await generate(orderId, 'QT'))) {
      has(`QT ${format}`, text.replace(/\.\s+/g, '. '), '6. Mill Test Certificate 3.1');
    }
  });

  it('the CI and the Packing List use package words', async () => {
    const orderId = await order();
    for (const [format, text] of Object.entries(await generate(orderId, 'CI'))) {
      has(`CI ${format}`, text, 'Mill Test Certificate 3.1 — required for Engineering Products');
    }
    for (const [format, text] of Object.entries(await generate(orderId, 'PL'))) {
      has(`PL ${format}`, text, 'Total No. of Packages');
      has(`PL ${format}`, text, 'PACKAGE-LEVEL BREAKDOWN');
      has(`PL ${format}`, text, 'Package-level breakdown not yet recorded.');
      hasNot(`PL ${format}`, text, 'Crate');
    }
  });

  it('the supplier PO asks for the quality documents and uses the engineering clauses', async () => {
    for (const [format, text] of Object.entries(await generate(await order(), 'SUPPO'))) {
      const where = `SUPPO ${format}`;
      has(where, text, 'Material / Product Type');
      has(where, text, 'As per the approved drawing / specification');
      has(where, text, 'Quality Documents Required');
      has(where, text, 'Mill Test Certificate 3.1; Drawing Approval — to be supplied with the goods');
      has(where, text, 'All items must conform exactly to the specification and drawing');
      has(where, text, 'traceable by its heat or serial number');
      for (const gone of ['Grade A material only', 'same quarry batch', 'Stone Type', '±0.5 mm on thickness']) hasNot(where, text, gone);
    }
  });

  it('a mixed order prints the stone and the engineering clauses', async () => {
    for (const [format, text] of Object.entries(await generate(await order(true), 'PI'))) {
      has(`PI ${format}`, text, 'minor variation in shade, grain and veining');
      has(`PI ${format}`, text, 'Specification & Drawings:');
      has(`PI ${format}`, text, 'Mill Test Certificate 3.1 — required for Engineering Products');
    }
  });
});
