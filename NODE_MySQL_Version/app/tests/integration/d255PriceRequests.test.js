'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const crypto = require('crypto');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const specRepository = require('../../src/repositories/specRepository');
const orderLinePricing = require('../../src/services/orderLinePricing');
const priceRequestService = require('../../src/services/priceRequestService');
const productSpecs = require('../../src/services/productSpecs');
const associatePortalController = require('../../src/controllers/associatePortalController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of D255PriceRequestsTest.php — owner decision D-255 (5 Nov 2026): specifications that
 * change the price, CBM for 50 mm and above, and associate price requests. The same worked
 * figures the owner approved on the mockups (mock8/s3, mock9/t1).
 */
describe('D-255 specifications and associate price requests', () => {
  let admin;
  let product;
  let associate;
  let client;
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const SQM = { 'Grade / quality': 'Standard', Thickness: '30 mm', 'Surface finish': 'Leather', Quantity: '2–4 containers' };
  const CBM = { 'Grade / quality': 'Standard', Thickness: '80 mm', 'Surface finish': 'Leather' };

  async function supplier(inr, basis) {
    const s = await productSupplierRepository.create({ product_id: product, supplier_name: `Ongole Quarry ${basis}`, location: 'Andhra Pradesh', fob_source: 'direct', fob_value: inr, is_primary: basis === 'unit' ? 1 : 0 }, admin);
    await pricingRepository.setSupplierDates(s, ymd(new Date()), null);
    if (basis === 'cbm') {
      await pricingRepository.setSupplierBasis(s, 'cbm');
      await pricingRepository.setCbmPrimary(product, s);
    }
  }
  const p = async () => productRepository.find(product);
  const me = async () => associateRepository.find(associate);
  async function sel(byName) {
    const out = {};
    for (const s of await productSpecs.offered(await p())) {
      if (byName[s.name] !== undefined) for (const o of s.options) if (o.name === byName[s.name]) out[s.id] = o.id;
    }
    expect(Object.keys(out).length).toBe(Object.keys(byName).length);
    return out;
  }
  const opt = async (spec, option) => db.queryOne(
    'SELECT o.* FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id WHERE s.name = :s AND o.name = :o AND o.product_id IS NULL AND s.product_id IS NULL', { s: spec, o: option });
  const orderRow = async (clientId) => db.queryOne('SELECT * FROM orders WHERE id = :id', { id: await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD') });

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = Number((await db.execute(
      "INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled) VALUES ('Jest D255', :e, 'x', :r, 1, 0, 0)",
      { e: `jest-d255-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, r: role.id })).insertId);
  });

  beforeEach(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    product = await productRepository.create({ name: `D255 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022390', reference_unit: 'SQM' }, admin);
    await pricingRepository.saveProductOverrides(product, { pricing_margin_pct: null, pricing_other_cost_mode: 'pct', pricing_other_cost_value: '0', pricing_incentive_mode: null, pricing_incentive_value: null, pricing_buffer_pct: null });
    await specRepository.setProductFields(product, null, 'slab,tile', null);
    await supplier(3280, 'unit');
    await supplier(53300, 'cbm');
    const lvl = await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 2'");
    associate = Number((await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES (:n, 'active', 'fixed', :l)",
      { n: `D255 Associate ${crypto.randomBytes(3).toString('hex')}`, l: lvl.id })).insertId);
    client = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a: associate, c: client });
    await priceRequestService.saveAccess(associate, { [await specRepository.systemCategoryId()]: ['slab', 'tile'] });
  });

  afterAll(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
    await db.pool.end();
  });

  it('the approved worked examples per square metre and per CBM', async () => {
    const w = await priceRequestService.work(await me(), await p(), await sel(SQM));
    expect(w.ok).toBe(true);
    expect([w.unit, w.quote, w.lowest]).toEqual(['SQM', 73.43, 71.30]);
    const c = await priceRequestService.work(await me(), await p(), await sel(CBM));
    expect([c.unit, c.quote, c.lowest]).toEqual(['CBM', 904.56, 878.21]);
    expect(c.per_m2).toBeCloseTo(72.36, 2);
  });

  it('category changes flow to products unless the product changed that option', async () => {
    const standard = await opt('Grade / quality', 'Standard');
    try {
      await specRepository.updateOption(Number(standard.id), { name: 'Standard', is_base: 0, change_type: 'pct', value: '-10', thickness_mm: null }, admin);
      expect((await priceRequestService.work(await me(), await p(), await sel(SQM))).quote).not.toBe(73.43);
      await specRepository.setOptionOverride(product, Number(standard.id), false, '-8');
      expect((await priceRequestService.work(await me(), await p(), await sel(SQM))).quote).toBe(73.43);
      await specRepository.resetSpecForProduct(product, Number(standard.spec_id));
      expect((await priceRequestService.work(await me(), await p(), await sel(SQM))).quote).not.toBe(73.43);
    } finally {
      await specRepository.updateOption(Number(standard.id), { name: 'Standard', is_base: 0, change_type: 'pct', value: '-8', thickness_mm: null }, admin);
    }
  });

  it('hidden options are not offered and product-only options are', async () => {
    const leather = await opt('Surface finish', 'Leather');
    await specRepository.setOptionOverride(product, Number(leather.id), true, null);
    const [, errors] = productSpecs.normalize(await productSpecs.offered(await p()), { [leather.spec_id]: Number(leather.id) });
    expect(errors.length).toBeGreaterThan(0);
    const own = await specRepository.createOption({ spec_id: Number(leather.spec_id), product_id: product, name: 'Antique', is_base: 0, change_type: 'usd_m2', value: '5', thickness_mm: null, sort_order: 99 }, admin);
    const names = (await productSpecs.offered(await p())).find((s) => s.id === Number(leather.spec_id)).options.map((o) => o.name);
    expect(names).toContain('Antique');
    expect(names).not.toContain('Leather');
    const other = await productRepository.create({ name: `D255 other ${crypto.randomBytes(3).toString('hex')}`, specifications: 'x', hs_code: '68022390', reference_unit: 'SQM' }, admin);
    for (const s of await productSpecs.offered(await productRepository.find(other))) expect(s.options.map((o) => o.id)).not.toContain(own);
    await specRepository.setSpecHidden(product, Number(leather.spec_id), true);
    expect((await productSpecs.offered(await p())).map((s) => s.id)).not.toContain(Number(leather.spec_id));
  });

  it('only Admin and MD manage a product\'s specifications', async () => {
    const rows = await db.query(`SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
      WHERE p.permission_key = 'manage_product_specifications' ORDER BY r.name`);
    expect(rows.map((r) => r.name)).toEqual(['Admin', 'Managing Director']);
  });

  // Fresh-eyes review L4 (5 Nov 2026): the count and the insert run in one transaction that locks the associate's row.
  it('the daily limit holds when requests are fired at the same moment', async () => {
    await db.execute('UPDATE associates SET price_request_daily_limit = 2 WHERE id = :a', { a: associate });
    const product_ = await p();
    const selection = await sel(SQM);
    const results = await Promise.all([0, 1, 2, 3].map(async () => priceRequestService.create(await me(), product_, selection, false, null)));
    const errors = results.map((r) => r[1]);
    expect(errors.filter((e) => e === null)).toHaveLength(2);
    expect(errors.filter((e) => e !== null)).toEqual(Array(2).fill("You have used today's 2 price requests. Ask NexaCrest if you need more today."));
    expect(await priceRequestService.requestsToday(associate)).toBe(2);
  });

  it('an automatic request is numbered, valid for seven days and counts toward the daily limit', async () => {
    await db.execute('UPDATE associates SET price_request_daily_limit = 1 WHERE id = :a', { a: associate });
    const [id, err] = await priceRequestService.create(await me(), await p(), await sel(SQM), false, null);
    expect(err).toBeNull();
    const r = await priceRequestService.find(id);
    expect(r.number).toMatch(/^PR-\d{2}-\d{2}-\d{4}$/);
    const d = new Date();
    expect([r.kind, r.status, r.valid_until]).toEqual(['auto', 'valid', ymd(new Date(d.getFullYear(), d.getMonth(), d.getDate() + 7))]);
    expect(await priceRequestService.leftToday(await me())).toBe(0);
    expect((await priceRequestService.create(await me(), await p(), await sel(SQM), false, null))[1]).not.toBeNull();
    const [m, err3] = await priceRequestService.create(await me(), await p(), await sel(SQM), true, 'Buyer needs 25 mm for a hotel lobby');
    expect(err3).toBeNull();
    expect((await priceRequestService.find(m)).status).toBe('pending');
    await db.execute("UPDATE price_requests SET valid_until = '2026-01-01' WHERE id = :id", { id });
    expect(priceRequestService.displayStatus(await priceRequestService.find(id))).toBe('expired');
  });

  it('manual flow: staff are told and the associate is told when priced', async () => {
    expect((await priceRequestService.create(await me(), await p(), {}, true, 'short'))[1]).not.toBeNull();
    const before = Number((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE type = 'price_request'")).n);
    const [id] = await priceRequestService.create(await me(), await p(), {}, true, 'Buyer needs 25 mm leather, one container');
    let r = await priceRequestService.find(id);
    expect([r.kind, r.status, r.quote]).toEqual(['manual', 'pending', null]);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE type = 'price_request'")).n)).toBeGreaterThan(before);
    expect(await priceRequestService.priceManual(id, admin, '70', '75', 'SQM', '')).not.toBeNull();
    expect(await priceRequestService.priceManual(id, admin, '80.00', '77.50', 'SQM', 'priced for 25 mm')).toBeNull();
    r = await priceRequestService.find(id);
    expect([r.status, String(r.quote), String(r.lowest)]).toEqual(['priced', '80.00', '77.50']);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM associate_events WHERE associate_id = :a AND event = 'price_ready'", { a: associate })).n)).toBe(1);
  });

  it('access by category and form', async () => {
    expect(await priceRequestService.mayPrice(await me(), await p())).toBe(true);
    await priceRequestService.saveAccess(associate, { [await specRepository.systemCategoryId()]: ['block'] });
    expect(await priceRequestService.mayPrice(await me(), await p())).toBe(false);
    await priceRequestService.saveAccess(associate, {});
    expect(await priceRequestService.mayPrice(await me(), await p())).toBe(false);
    expect((await priceRequestService.create(await me(), await p(), await sel(SQM), false, null))[1]).not.toBeNull();
  });

  it('the portal never shows cost or supplier names', async () => {
    const [id] = await priceRequestService.create(await me(), await p(), await sel(SQM), false, null);
    const a = await me();
    const portalService = require('../../src/services/associatePortalService');
    const spy = jest.spyOn(portalService, 'current').mockResolvedValue(a);
    let html = '';
    const res = { renderView: (v, d) => { html = JSON.stringify(d); }, redirect: () => {}, status() { return this; } };
    const req = { session: {}, params: {}, body: {}, query: {} };
    for (const [fn, params] of [['catalogue', {}], ['getPriceForm', { id: product }], ['priceRequest', { id }]]) {
      await associatePortalController[fn]({ ...req, params }, res);
      const text = html.replace(/"[a-z_]+":/g, '');
      expect(text.toLowerCase()).not.toMatch(/\bcost\b/);
      expect(html).not.toMatch(/Ongole|Andhra|3280/);
    }
    expect(html).toContain('73.43');
    spy.mockRestore();
  });

  it('order line unit must be CBM from 50 mm and the floor uses CBM', async () => {
    const order = await orderRow(client);
    const cbm = await sel(CBM);
    expect(await orderLinePricing.saveRefusal(order, product, 'goods', '904.56', true, cbm, 'SQM')).toContain('set the unit to CBM');
    expect(await orderLinePricing.saveRefusal(order, product, 'goods', '904.56', true, cbm, 'CBM')).toBeNull();
    expect(await orderLinePricing.saveRefusal(order, product, 'goods', '500.00', true, cbm, 'CBM')).not.toBeNull();
    expect(await orderLinePricing.saveRefusal(order, product, 'goods', '73.43', true, await sel(SQM), 'SQM')).toBeNull();
  });

  it('an order line from a price request only for that associate\'s buyers', async () => {
    const [id] = await priceRequestService.create(await me(), await p(), await sel(SQM), false, null);
    const number = (await priceRequestService.find(id)).number;
    const mine = await orderRow(client);
    const [r, err] = await priceRequestService.usableForOrder(number.toLowerCase(), mine);
    expect(err).toBeNull();
    expect(Number(r.id)).toBe(id);
    const other = await orderRow(await createTestClient());
    expect((await priceRequestService.usableForOrder(number, other))[1]).not.toBeNull();
    await priceRequestService.markOrdered(id, Number(mine.id));
    expect((await priceRequestService.find(id)).status).toBe('ordered');
    expect((await priceRequestService.usableForOrder(number, mine))[1]).not.toBeNull();
  });

  it('the report warns on many requests without orders', async () => {
    for (let i = 0; i < priceRequestService.WARN_REQUESTS; i++) {
      await db.execute(`INSERT INTO price_requests (number, associate_id, product_id, selection, selection_text, kind, status, unit, requested_at)
        VALUES (:n, :a, :p, '{}', 'x', 'auto', 'valid', 'SQM', NOW())`, { n: `PR-99-${crypto.randomBytes(4).toString('hex')}`, a: associate, p: product });
    }
    const row = (await priceRequestService.report()).find((x) => x.associate_id === associate);
    expect([row.requests, row.orders, row.warn]).toEqual([priceRequestService.WARN_REQUESTS, 0, true]);
  });
});
