'use strict';

// Owner decision D-339 (12 Nov 2026, owner "confirm 1" and "confirm 2 with option a"):
// the detailed product table on the documents of a new order, the classic table on every
// order from before (its documents never change), the No. of pieces worked out on the
// server, and the product card "Prices in other units".
// PHP twin: tests/Integration/D339DetailedLineTableTest.php (same cases, same wording).
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const lineTable = require('../../src/services/lineTable');
const unitPrices = require('../../src/services/unitPrices');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const flat = (s) => String(s).replace(/&nbsp;|&#160;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();

describe('D-339 detailed product table, No. of pieces, prices in other units', () => {
  let admin;
  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D339', :email, NULL, 'x', :r, 1, 0, 0)`,
      { email: `jest-d339-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, r: role.id }
    )).insertId;
  });
  afterAll(async () => { await db.pool.end(); });

  async function order(layout, currency = 'INR') {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', currency);
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

  async function docxText(fileId) {
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    return flat(execFileSync('unzip', ['-p', file.server_path, 'word/document.xml']).toString('utf8').replace(/<[^>]+>/g, ' '));
  }

  const fakeRes = () => {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (c) => { res.statusCode = c; return res; };
    res.redirect = (u) => { res.redirectedTo = u; };
    return res;
  };
  const add = async (orderId, body) => {
    const req = { params: { id: String(orderId) }, session: {}, user: { id: admin }, permissions: {}, body };
    await ordersController.addProduct(req, fakeRes());
    return req;
  };

  it('new orders get the detailed table and the migration keeps old ones classic', async () => {
    const col = await db.queryOne("SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'line_table_layout'");
    expect(String(col.d).replace(/'/g, '')).toBe('detailed');
    const sql = fs.readFileSync(path.join(__dirname, '..', '..', '..', '..', 'docs', 'migrations', '2026-11-12g-detailed-line-table.sql'), 'utf8');
    expect(sql).toContain("SET @s := IF(@add_layout, 'UPDATE orders SET line_table_layout = ''classic''', 'SELECT 1');");
  });

  it('a classic order prints exactly as before', async () => {
    const orderId = await order('classic');
    await ownersExample(orderId);
    for (const code of lineTable.DOCUMENTS) {
      expect((await documentDataAssembler.assemble(orderId, code)).line_table).toBeNull();
    }
    const r = await documentGenerationService.generate(orderId, 'QT', admin, null, true, true);
    const docx = await docxText(r.docx_file_id);
    expect(docx).toContain('Product Description');
    expect(docx).not.toContain('No. of pcs');
  });

  it("the owner's example on the detailed table", async () => {
    const orderId = await order('detailed');
    await ownersExample(orderId);
    const t = (await documentDataAssembler.assemble(orderId, 'QT')).line_table;
    expect(t.variant).toBe('full');
    expect([t.total.pcs, t.total.measure, t.total.amount]).toEqual(['60', '8.801', '15,40,175.00']); // D-343: an INR order prints its money in Indian grouping
    expect((await documentDataAssembler.assemble(orderId, 'SUPPO')).line_table).toBeNull(); // only QT, PI, OC, CI, Buyer PO and PL
    expect((await documentDataAssembler.assemble(orderId, 'PL')).line_table.with_prices).toBe(false);

    const qt = await documentGenerationService.generate(orderId, 'QT', admin, null, true, true);
    const row = '1 CHM Black P6 180 80 13 0.1872 25 4.680 1,75,000.00 32,760.00 8,19,000.00';
    expect(await docxText(qt.docx_file_id)).toContain(row);
    expect(await docxText(qt.docx_file_id)).toContain('TOTAL 60 8.801 15,40,175.00');
    const calls = renderPdfFromHtml.mock.calls;
    const html = flat(calls[calls.length - 1][0].replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' '));
    for (const word of ['0.1872', '32,760.00', '8,19,000.00', 'No. of', 'per piece', '15,40,175.00']) expect(html).toContain(word);
    const pl = await documentGenerationService.generate(orderId, 'PL', admin, null, true, true);
    const plText = await docxText(pl.docx_file_id);
    expect(plText).toContain('1 CHM Black P6 180 80 13 0.1872 25 4.680');
    expect(plText).not.toContain('1,75,000.00'); // the Packing List carries no prices
  });

  it('the server works out the quantity from the pieces', async () => {
    const orderId = await order('detailed');
    const post = { description: 'CHM Black', line_type: 'goods', hs_code: '68022310', unit: 'CBM', unit_price: '175000.00',
      piece_length_cm: '180', piece_width_cm: '80', piece_thickness_cm: '13', finish: 'P6' };
    // Without the pieces: refused, nothing saved.
    const refused = await add(orderId, { ...post, quantity: '4.68' });
    expect(await orderProductRepository.forOrder(orderId)).toEqual([]);
    expect(JSON.stringify(refused.session)).toContain('enter the No. of pieces');

    // The browser's quantity is never trusted.
    await add(orderId, { ...post, quantity: '999', pieces_count: '25', size_note: '' });
    const lineRow = (await orderProductRepository.forOrder(orderId))[0];
    expect([String(lineRow.quantity), Number(lineRow.pieces_count), String(lineRow.fob_value)]).toEqual(['4.680', 25, '819000.00']);

    // PCS: the quantity is the pieces. Without a size: typed as before, with the size note.
    await add(orderId, { description: 'Planter', line_type: 'goods', hs_code: '68022310', unit: 'PCS', unit_price: '85.00', quantity: '3', pieces_count: '20' });
    await add(orderId, { description: 'Blocks', line_type: 'goods', hs_code: '68022310', unit: 'CBM', unit_price: '1950.00', quantity: '25', pieces_count: '', size_note: 'Random sizes, as available' });
    const lines = await orderProductRepository.forOrder(orderId);
    expect(String(lines[1].quantity)).toBe('20.000');
    expect([String(lines[2].quantity), lines[2].size_note]).toEqual(['25.000', 'Random sizes, as available']);

    // A duplicated line keeps its pieces and size note.
    await orderProductRepository.duplicate(Number(lines[0].id));
    const copy = (await orderProductRepository.forOrder(orderId))[1];
    expect(Number(copy.pieces_count)).toBe(25);
  });

  it('a classic order line form is unchanged', async () => {
    const orderId = await order('classic');
    await add(orderId, { description: 'CHM Black', line_type: 'goods', hs_code: '68022310', unit: 'CBM', unit_price: '175000.00',
      piece_length_cm: '180', piece_width_cm: '80', piece_thickness_cm: '13', quantity: '4.5' });
    const lineRow = (await orderProductRepository.forOrder(orderId))[0];
    expect([String(lineRow.quantity), lineRow.pieces_count]).toEqual(['4.500', null]);
  });

  it('the price filled in never overwrites a typed price', () => {
    const js = fs.readFileSync(path.join(__dirname, '..', '..', 'views', 'orders', '_product_picker_js.njk'), 'utf8');
    expect(js).toContain("if (price.dataset.auto !== '0') {");
    expect(js).toContain("if (price.value.trim() === '' || (price.dataset.auto !== '0' && (fill || price.dataset.auto === '1'))) {");
    expect(js).toContain("if (base === 'unit_price') { el.dataset.auto = '0'; }");
  });

  it('prices in other units follow the permissions', async () => {
    const productRepository = require('../../src/repositories/productRepository');
    const pricingRepository = require('../../src/repositories/pricingRepository');
    const specRepository = require('../../src/repositories/specRepository');
    const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
    const productId = await productRepository.create({ name: `D339 CHM Black ${Math.random().toString(16).slice(2, 8)}`, specifications: 'Granite', hs_code: '68022390', reference_unit: 'SQM' }, admin);
    await pricingRepository.saveProductOverrides(productId, { pricing_margin_pct: null, pricing_other_cost_mode: 'pct', pricing_other_cost_value: '0',
      pricing_incentive_mode: null, pricing_incentive_value: null, pricing_buffer_pct: null });
    await specRepository.setProductFields(productId, null, 'slab,tile', null);
    const s = await productSupplierRepository.create({ product_id: productId, supplier_name: 'Test Quarry', location: 'Test location', contact_person: null,
      contact_phone: null, contact_email: null, fob_source: 'direct', fob_value: 3280.0, factory_cost: null, transportation_cost: null,
      packing_cost: null, loading_cost: null, cha_cost: null, is_primary: 1, notes: null }, admin);
    await pricingRepository.setSupplierDates(s, new Date().toISOString().slice(0, 10), null);
    const product = await productRepository.find(productId);

    const staff = await unitPrices.card(product, { supplier: false, staff: true, levels: false });
    expect(staff.sets.map((x) => x.key)).toEqual(['staff_quote', 'staff_floor']);
    expect(staff.rows.length).toBeGreaterThan(0);
    const mdEd = await unitPrices.card(product, { supplier: true, staff: true, levels: true });
    expect(mdEd.sets.map((x) => x.key)).toContain('supplier');
    expect(mdEd.sets.length).toBeGreaterThan(3); // MD / ED see every level
    const purchase = await unitPrices.card(product, { supplier: true, staff: false, levels: false });
    expect(purchase.sets.map((x) => x.key)).toEqual(['supplier']);
    // A 20 mm row converts the per-SQM price to per SQFT (1 SQM = 10.7639 SQFT).
    for (const r of staff.rows) {
      if (r.missing === null && r.prices.staff_quote.SQM !== null) {
        expect(Math.abs(r.prices.staff_quote.SQM / 10.7639 - r.prices.staff_quote.SQFT)).toBeLessThan(0.01);
      }
    }
    // An associate sees only their own two prices, converted (the price request page).
    const controller = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'controllers', 'associatePortalController.js'), 'utf8');
    expect(controller).toContain('quote: unitPrices.convert(Number(r.quote)');
    expect(controller).not.toContain('unitPrices.card');
  });
});
