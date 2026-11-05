'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const hsCodeRepository = require('../../src/repositories/hsCodeRepository');
const lookupRepository = require('../../src/repositories/lookupRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderQualityDocumentRepository = require('../../src/repositories/orderQualityDocumentRepository');
const productCategoryRepository = require('../../src/repositories/productCategoryRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const specRepository = require('../../src/repositories/specRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const pricingService = require('../../src/services/pricingService');
const productSpecs = require('../../src/services/productSpecs');
const { createTestClient, createTestOrder } = require('../support/fixtures');

beforeAll(() => require('../support/fixtures').allowHandTypedGoodsLines());

/**
 * Port of D257ProductNeutralTest.php. Owner decision D-257 Phase 0B (5 Nov
 * 2026): product-neutral groundwork. A product category decides its units,
 * HS code, packing, quality documents and T&C clause set; Natural Stone
 * keeps exactly today's behaviour. A test-only "Engineering" category (unit
 * piece, HS 730791, per-piece pricing, MTC 3.1 and drawing approval) is
 * worked end to end.
 */
describe('Product-neutral groundwork (D-257 Phase 0B)', () => {
  const PASSWORD = 'JestD257Neutral!1';
  let orderId;
  let userId;
  let agent;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    orderId = await createTestOrder(await createTestClient(), 'FOB');
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-d257-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    userId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D257 User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id },
    )).insertId;
    if (!(await hsCodeRepository.isActiveCode('730791'))) await hsCodeRepository.create('730791', 'Flanges of iron or steel (test)', userId);
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
  });

  const decode = (s) => s.trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&#039;/g, "'").replace(/&amp;/g, '&');
  async function flashAfter(res) {
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? decode(m[2]) : '';
  }
  async function csrf(from) {
    return (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }
  async function post(url, body, from = `/orders/${orderId}`) {
    const token = await csrf(from);
    return flashAfter(await agent.post(url).type('form').send({ _csrf: token, ...body }));
  }
  const stone = async () => (await productCategoryRepository.all()).find((c) => Number(c.is_system) === 1);
  const lastLine = () => db.queryOne('SELECT * FROM order_products WHERE order_id = :o ORDER BY id DESC LIMIT 1', { o: orderId });

  /** The test-only Engineering category: unit piece, HS 730791, MTC 3.1 and drawing approval. */
  async function engineering() {
    const name = `Engineering ${crypto.randomBytes(3).toString('hex')}`;
    await post('/products/categories', { name, attributes: ['Material', 'Size', 'Pressure class'] }, '/products/categories');
    const id = Number((await db.queryOne('SELECT id FROM product_categories WHERE name = :n', { n: name })).id);
    expect(await post(`/products/categories/${id}/settings`, {
      default_unit: 'PCS', allowed_units: ['PCS', 'NOS', 'SET'], default_hs_code: '730791', packing_type: 'Wooden Crates',
      quality_documents: 'Mill test certificate 3.1\r\nDrawing approval\r\n', tc_clause_set: 'engineering',
    }, '/products/categories')).toBe(`Settings for "${name}" saved.`);
    return id;
  }

  it('seeds Natural Stone with today\'s behaviour, and the new units and supplier types', async () => {
    const s = await stone();
    expect([s.default_unit, s.allowed_units, s.default_hs_code, s.packing_type, s.quality_documents, s.tc_clause_set])
      .toEqual([null, null, '680293', 'Wooden Crates', null, 'natural_stone']);
    expect(productCategoryRepository.allowedUnits(s)).toEqual([]);
    expect(productCategoryRepository.qualityDocuments(s)).toEqual([]);
    expect(productCategoryRepository.lineOptionAttributes(s)).toBe('');
    const units = (await lookupRepository.dropdownOptions('unit')).map((o) => o.option_value);
    for (const u of ['PCS', 'SET', 'KG', 'MT', 'MTR', 'LTR', 'HOUR', 'DAY', 'SQM', 'CBM']) expect(units).toContain(u);
    const types = (await lookupRepository.dropdownOptions('supplier_type')).map((o) => o.option_value);
    for (const t of ['Quarry Owner', 'Trader', 'Manufacturer', 'Machine Shop', 'Forging / Casting Unit']) expect(types).toContain(t);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM file_upload_contexts WHERE context_key = 'quality_document'")).n)).toBe(1);
  });

  it('checks a category\'s settings, then saves them', async () => {
    const id = await engineering();
    const n = (await productCategoryRepository.find(id)).name;
    const base = { default_unit: 'PCS', allowed_units: ['PCS'], default_hs_code: '', packing_type: '', quality_documents: '', tc_clause_set: '' };
    const url = `/products/categories/${id}/settings`;
    expect(await post(url, { ...base, allowed_units: ['BUCKET'] }, '/products/categories')).toBe(`${n}: Unit "BUCKET" is not on the Units list.`);
    expect(await post(url, { ...base, default_unit: 'KG' }, '/products/categories')).toBe(`${n}: The default unit KG must be one of the allowed units.`);
    expect(await post(url, { ...base, default_hs_code: '99999999' }, '/products/categories')).toBe(`${n}: HS code "99999999" is not on the HS Code master list — add it there first (HS Codes, under Admin).`);
    expect(await post(url, { ...base, packing_type: 'Sack' }, '/products/categories')).toBe(`${n}: Choose the packing type from the Package Types list.`);
    expect(await post(url, { ...base, quality_documents: 'MTC\nmtc' }, '/products/categories')).toBe(`${n}: "mtc" is listed twice — each quality document once.`);
    expect(await post(url, { ...base, tc_clause_set: 'x' }, '/products/categories')).toBe(`${n}: Choose the T&C clause set from the list.`);
    const cat = await productCategoryRepository.find(id);
    expect([cat.default_unit, cat.allowed_units, cat.default_hs_code, cat.packing_type, cat.quality_documents, cat.tc_clause_set])
      .toEqual(['PCS', 'PCS,NOS,SET', '730791', 'Wooden Crates', 'Mill test certificate 3.1\nDrawing approval', 'engineering']);
    const audit = await db.queryOne("SELECT new_value FROM audit_log WHERE action_type = 'PRODUCT_CATEGORY_SETTINGS_UPDATED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id });
    expect(audit.new_value).toBe('unit PCS (allowed: PCS,NOS,SET); HS 730791; packing Wooden Crates; quality documents: Mill test certificate 3.1, Drawing approval; T&C engineering');
    expect(productCategoryRepository.lineOptionAttributes(cat)).toBe(' data-fills="{&quot;hs_code&quot;:&quot;730791&quot;,&quot;unit&quot;:&quot;PCS&quot;}" data-allowed-units="PCS,NOS,SET"');
    expect((await agent.get(`/orders/${orderId}`)).text).toContain(`data-fills="{&quot;hs_code&quot;:&quot;730791&quot;,&quot;unit&quot;:&quot;PCS&quot;}" data-allowed-units="PCS,NOS,SET">${n}</option>`);

    const s = await stone();
    expect(await post(`/products/categories/${s.id}/settings`, { default_unit: '', default_hs_code: '680293', packing_type: 'Wooden Crates', quality_documents: '', tc_clause_set: 'natural_stone' }, '/products/categories'))
      .toBe('Settings for "Natural Stone" saved.');
    expect((await productCategoryRepository.find(s.id)).attributes).toEqual(['Dimensions', 'Finish']);
  });

  it('gives an Engineering line its HS code and unit from the category', async () => {
    const eng = await engineering();
    const n = (await productCategoryRepository.find(eng)).name;
    expect(await post(`/orders/${orderId}/products`, {
      description: 'Weld neck flange', quantity: '40', unit: '', unit_price: '18.50', line_type: 'goods', hs_code: '',
      category_id: String(eng), [`attr_${eng}`]: ['ASTM A105', '4 inch', '150#'],
    })).toBe('Product line added.');
    let line = await lastLine();
    expect([line.hs_code, line.unit, String(line.category_id)]).toEqual(['730791', 'PCS', String(eng)]);

    expect(await post(`/orders/${orderId}/products`, {
      description: 'Blind flange', quantity: '10', unit: 'KG', unit_price: '9.00', line_type: 'goods', hs_code: '', category_id: String(eng), [`attr_${eng}`]: ['', '', ''],
    })).toBe(`Product line: Unit KG is not one of the units for ${n} (PCS, NOS, SET).`);
    await post(`/orders/${orderId}/products`, {
      description: 'Granite flange base', quantity: '2', unit: 'SET', unit_price: '30', line_type: 'goods', hs_code: '680293', category_id: String(eng), [`attr_${eng}`]: ['', '', ''],
    });
    line = await lastLine();
    expect([line.hs_code, line.unit]).toEqual(['680293', 'SET']);

    expect(await post(`/orders/${orderId}/products`, {
      description: 'Black granite slab', quantity: '10', unit: 'SQM', unit_price: '40', line_type: 'goods', hs_code: '', category_id: '',
    })).toBe('HS code "" is not on the HS Code master list — add it there first (HS Codes, under Admin).');
  });

  it('adds a per-piece, per-kg or per-set option to the price only when the unit matches', () => {
    expect([productSpecs.changeLabel('usd_piece', 4.5), productSpecs.changeLabel('usd_kg', 0.35), productSpecs.changeLabel('usd_set', -2), productSpecs.changeLabel('usd_piece', 0)])
      .toEqual(['+$4.5/piece', '+$0.35/kg', '−$2/set', 'Included in base price']);
    const offered = [{ id: 1, name: 'Machining', kind: 'normal', options: [
      { id: 11, name: 'As forged', is_base: 1, change_type: 'none', value: 0, thickness_mm: null },
      { id: 12, name: 'Machined', is_base: 0, change_type: 'usd_piece', value: 4.5, thickness_mm: null },
    ] }, { id: 2, name: 'Coating', kind: 'normal', options: [
      { id: 21, name: 'Galvanised', is_base: 0, change_type: 'usd_kg', value: 0.35, thickness_mm: null },
    ] }, { id: 3, name: 'Bolts', kind: 'normal', options: [
      { id: 31, name: 'With bolt set', is_base: 0, change_type: 'usd_set', value: 2.25, thickness_mm: null },
    ] }];
    const pcs = productSpecs.adjustments({ reference_unit: 'PCS' }, offered, { 1: 12, 2: 21, 3: 31 }, 50);
    expect(Math.round(pcs.usd * 100) / 100).toBe(4.5);
    expect(pcs.charges).toEqual(['Galvanised: USD 0.35 per kg, charged separately', 'With bolt set: USD 2.25 per set, charged separately']);
    const kg = productSpecs.adjustments({ reference_unit: 'KG' }, offered, { 1: 12, 2: 21 }, 50);
    expect([Math.round(kg.usd * 100) / 100, kg.charges]).toEqual([0.35, ['Machined: USD 4.50 per piece, charged separately']]);
    expect(Math.round(productSpecs.adjustments({ reference_unit: 'SET' }, offered, { 3: 31 }, 50).usd * 100) / 100).toBe(2.25);
    const sqm = productSpecs.adjustments({ reference_unit: 'SQM' }, offered, { 1: 12 }, 50);
    expect([sqm.usd, sqm.charges]).toEqual([0, ['Machined: USD 4.50 per piece, charged separately']]);
  });

  it('prices an Engineering product per piece end to end', async () => {
    const eng = await engineering();
    const product = await productRepository.create({ name: `D257 Weld neck flange ${crypto.randomBytes(3).toString('hex')}`, specifications: 'ASTM A105', hs_code: '730791', reference_unit: 'PCS' }, userId);
    await specRepository.setProductFields(product, eng, null, null);
    const spec = await specRepository.createSpec({ category_id: eng, name: 'Machining', kind: 'normal', applies_to: null, sort_order: 1 }, userId);
    const base = await specRepository.createOption({ spec_id: spec, name: 'As forged', is_base: 1, change_type: 'none', value: null, thickness_mm: null, sort_order: 1 }, userId);
    const machined = await specRepository.createOption({ spec_id: spec, name: 'Machined', is_base: 0, change_type: 'usd_piece', value: '4.50', thickness_mm: null, sort_order: 2 }, userId);
    const s = await productSupplierRepository.create({ product_id: product, supplier_name: 'Rajkot Forge', location: 'Gujarat', fob_source: 'direct', fob_value: 1230, is_primary: 1 }, userId);
    await pricingRepository.setSupplierDates(s, new Date().toISOString().slice(0, 10), null);
    const p = await productRepository.find(product);
    const asForged = await pricingService.forProduct(p, null, null, null, { [spec]: base });
    const withMachining = await pricingService.forProduct(p, null, null, null, { [spec]: machined });
    // ₹1,230 ÷ 82 = USD 15.00 + 2.5 % other costs = 15.375; + 4.50 = 19.875; ÷ (1 − 20 %) = 24.84; + 3 % buffer = 25.59.
    expect([asForged.build.cost, asForged.build.minimum, asForged.build.staff_quote]).toEqual([15.38, 19.22, 19.8]);
    expect([withMachining.build.cost, withMachining.build.minimum, withMachining.build.staff_quote]).toEqual([19.88, 24.84, 25.59]);
  });

  it('shows the quality checklist only for categories that ask for documents', async () => {
    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
    expect(await orderQualityDocumentRepository.forOrder(orderId)).toEqual([]);
    expect((await agent.get(`/orders/${orderId}`)).text).not.toContain('id="quality-documents"');

    const eng = await engineering();
    await post(`/orders/${orderId}/products`, {
      description: 'Weld neck flange', quantity: '40', unit: 'PCS', unit_price: '18.50', line_type: 'goods', hs_code: '', category_id: String(eng), [`attr_${eng}`]: ['ASTM A105', '4 inch', '150#'],
    });
    expect((await orderQualityDocumentRepository.forOrder(orderId)).map((d) => [d.document_name, d.status]))
      .toEqual([['Mill test certificate 3.1', 'pending'], ['Drawing approval', 'pending']]);
    expect((await agent.get(`/orders/${orderId}`)).text).toContain('id="quality-documents"');

    const url = `/orders/${orderId}/quality-documents`;
    expect(await post(url, { category_id: String(eng), document_name: 'Heat number', status: 'received' })).toBe('This order does not ask for that quality document.');
    expect(await post(url, { category_id: String(eng), document_name: 'Drawing approval', status: 'received' })).toBe('Drawing approval: attach the document before marking it received.');
    expect(await post(url, { category_id: String(eng), document_name: 'drawing approval', status: 'pending', note: 'Sent to buyer 5 Nov' })).toBe('Drawing approval marked pending.');

    const token = await csrf(`/orders/${orderId}`);
    const res = await agent.post(url).field('_csrf', token).field('category_id', String(eng))
      .field('document_name', 'Mill test certificate 3.1').field('status', 'received')
      .attach('file', Buffer.from('%PDF-1.4 mill test certificate'), 'MTC-H2311.pdf');
    expect(await flashAfter(res)).toBe('Mill test certificate 3.1 marked received.');
    expect(await post(url, { category_id: String(eng), document_name: 'Mill test certificate 3.1', status: 'approved', note: 'Heat no. H2311' })).toBe('Mill test certificate 3.1 marked approved.');
    expect((await orderQualityDocumentRepository.forOrder(orderId)).map((d) => [d.status, d.original_filename, d.note]))
      .toEqual([['approved', 'MTC-H2311.pdf', 'Heat no. H2311'], ['pending', null, 'Sent to buyer 5 Nov']]);
    const first = await db.queryOne("SELECT old_value FROM audit_log WHERE action_type = 'QUALITY_DOCUMENT_UPDATED' AND entity_id = :o ORDER BY id LIMIT 1", { o: orderId });
    expect(first.old_value).toBe('pending');
  });

  it('leaves stone document data identical whatever the category settings', async () => {
    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
    const before = {};
    for (const type of ['QT', 'PI', 'CI', 'PL', 'SUPPO']) before[type] = await documentDataAssembler.assemble(orderId, type);
    const s = await stone();
    await post(`/products/categories/${s.id}/settings`, { default_unit: 'PCS', allowed_units: ['PCS', 'SQM'], default_hs_code: '730791', packing_type: 'Wooden Crates',
      quality_documents: 'Inspection report', tc_clause_set: 'general_goods' }, '/products/categories');
    await engineering();
    for (const type of ['QT', 'PI', 'CI', 'PL', 'SUPPO']) expect(await documentDataAssembler.assemble(orderId, type)).toEqual(before[type]);
  });
});
