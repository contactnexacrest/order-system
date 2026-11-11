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
const associateRepository = require('../../src/repositories/associateRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const specRepository = require('../../src/repositories/specRepository');
const orderLinePricing = require('../../src/services/orderLinePricing');
const priceRequestService = require('../../src/services/priceRequestService');
const pricingService = require('../../src/services/pricingService');
const productSpecs = require('../../src/services/productSpecs');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of D256PricingUsabilityTest.php — owner decision D-256 (5 Nov 2026): an option worth 0
 * reads "Included in base price", "Pricing ready?" with fix links, "Try a price", help text
 * with examples, exact supplier prices for a specific combination, and the GST tax on an
 * associate's commission bill in Zoho. The same figures: ₹3,200 ÷ 82 + 2.5% = USD 40.00.
 */
describe('D-256 pricing usability', () => {
  const PASSWORD = 'JestD256!1';
  let admin;
  let product;
  let associate;
  let unitSupplier;
  let cbmSupplier;
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return ymd(d); };
  const today = () => ymd(new Date());
  const SQM = { 'Grade / quality': 'Standard', Thickness: '30 mm', 'Surface finish': 'Leather', Quantity: '2–4 containers' };
  const PREMIUM = { 'Grade / quality': 'Premium', Thickness: '30 mm', 'Surface finish': 'Leather', Quantity: '2–4 containers' };

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d256-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = Number((await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D256', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id })).insertId);
    return { id, email };
  }

  async function login(roleName) {
    const u = await user(roleName);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  async function supplier(inr, basis, name = 'Ongole Quarry') {
    const s = await productSupplierRepository.create({ product_id: product, supplier_name: `${name} ${basis}`, location: 'Andhra Pradesh', fob_source: 'direct', fob_value: inr, is_primary: basis === 'unit' ? 1 : 0 }, admin);
    await pricingRepository.setSupplierDates(s, today(), null);
    if (basis === 'cbm') {
      await pricingRepository.setSupplierBasis(s, 'cbm');
      await pricingRepository.setCbmPrimary(product, s);
    }
    return s;
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
  /** An exact supplier price for the named options, per unit, in use. */
  async function exact(inr, byName, quotedOn, validUntil = null) {
    const s = await supplier(inr, 'unit', 'Exact quarry');
    await pricingRepository.setCombo(s, await sel(byName), 'unit', true);
    await pricingRepository.setSupplierDates(s, quotedOn, validUntil);
    return s;
  }
  const opt = async (spec, option) => db.queryOne(
    'SELECT o.* FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id WHERE s.name = :s AND o.name = :o AND o.product_id IS NULL AND s.product_id IS NULL', { s: spec, o: option });
  const price = async (byName) => pricingService.forProduct(await p(), null, null, null, await sel(byName));
  const level = (pr, name) => pr.levels.find((l) => l.name === name);
  const readyItem = (r, key) => r.items.find((i) => i.key === key) || null;

  beforeAll(async () => {
    admin = (await user('Admin')).id;
  });

  beforeEach(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    product = await productRepository.create({ name: `D256 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022390', reference_unit: 'SQM' }, admin);
    await specRepository.setProductFields(product, null, 'slab,tile', null);
    unitSupplier = await supplier(3200, 'unit');
    cbmSupplier = await supplier(53300, 'cbm');
    const lvl = await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 2'");
    associate = Number((await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES (:n, 'active', 'fixed', :l)",
      { n: `D256 Associate ${crypto.randomBytes(3).toString('hex')}`, l: lvl.id })).insertId);
    await priceRequestService.saveAccess(associate, { [await specRepository.systemCategoryId()]: ['slab', 'tile'] });
  });

  afterEach(async () => {
    await incentiveRepository.setSetting('zoho_gst_commission_tax_id', '', admin);
  });

  afterAll(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
    await db.pool.end();
  });

  it('an option worth 0 reads "Included in base price"', async () => {
    expect(productSpecs.changeLabel('pct', 0)).toBe('Included in base price');
    expect(productSpecs.changeLabel('usd_m2', 0)).toBe('Included in base price');
    expect(productSpecs.changeLabel('pct', 35)).toBe('+35%');
    const standard = await opt('Grade / quality', 'Standard');
    await specRepository.setOptionOverride(product, Number(standard.id), false, '0');
    const w = await priceRequestService.work(await me(), await p(), await sel(SQM));
    expect(w.breakdown).toContainEqual({ label: 'Standard (grade / quality)', change: 'Included in base price' });
    expect(w.quote).toBe((await priceRequestService.work(await me(), await p(), await sel(PREMIUM))).quote);
  });

  it('Try a price gives the same figures as Get price and the order page', async () => {
    const pr = await price(SQM);
    expect(pr.build.staff_quote).toBe(69.76);
    expect([level(pr, 'Level 2').floor, level(pr, 'Level 2').quote]).toEqual([71.30, 73.43]);
    const w = await priceRequestService.work(await me(), await p(), await sel(SQM));
    expect([w.quote, w.lowest]).toEqual([73.43, 71.30]);
    const staffOrder = await db.queryOne('SELECT * FROM orders WHERE id = :id', { id: await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD') });
    expect(pr.build.minimum).toBe(65.76);
    expect(String(await orderLinePricing.saveRefusal(staffOrder, product, 'goods', '65.75', true, await sel(SQM), 'SQM'))).toContain('Minimum price: USD 65.76');
    expect(await orderLinePricing.saveRefusal(staffOrder, product, 'goods', '65.76', true, await sel(SQM), 'SQM')).toBeNull();

    const md = await login('Managing Director');
    const query = Object.entries(await sel(SQM)).map(([k, v]) => `try[${k}]=${v}`).join('&');
    const page = (await md.get(`/products/${product}?${query}`)).text;
    for (const s of ['Try a price', 'Pricing ready?', 'USD 69.76', '71.30 · <strong>73.43</strong>', 'Leather +USD 4.00']) {
      expect([s, page.includes(s)]).toEqual([s, true]);
    }
  });

  it('the exact price for a combination is used', async () => {
    await exact(4900, { Thickness: '30 mm', 'Surface finish': 'Leather' }, today());
    const pr = await price(PREMIUM);
    expect(pr.exact).toBe('30 mm · Leather');
    expect(Number(pr.supplier_inr)).toBe(4900);
    expect(pr.build.raw.cost).toBeCloseTo(60.025, 4);
    expect([pr.build.staff_floor, pr.build.staff_quote]).toEqual([77.28, 79.60]);
    expect([level(pr, 'Level 2').floor, level(pr, 'Level 2').quote]).toEqual([81.35, 83.79]);
    const w = await priceRequestService.work(await me(), await p(), await sel(PREMIUM));
    expect([w.quote, w.lowest]).toEqual([83.79, 81.35]);
    expect(w.breakdown).toContainEqual({ label: 'Exact price for 30 mm · Leather', change: 'used' });
    expect(w.breakdown).toContainEqual({ label: 'Leather (surface finish)', change: 'Included (in the exact price)' });
    expect((await price(SQM)).build.raw.cost).toBeCloseTo(61.25 * 0.92 * 0.98, 4);
    const polished = await price({ 'Grade / quality': 'Premium', Thickness: '30 mm', 'Surface finish': 'Polished' });
    expect(polished.exact).toBeNull();
    expect(Number(polished.supplier_inr)).toBe(3200);
  });

  it('the most specific, then the newest wins; stale or expired ones are ignored', async () => {
    const thirty = await exact(4000, { Thickness: '30 mm' }, today());
    const both = await exact(4900, { Thickness: '30 mm', 'Surface finish': 'Leather' }, daysAgo(10));
    expect(Number((await price(PREMIUM)).supplier_inr)).toBe(4900);
    const newer = await exact(5100, { Thickness: '30 mm', 'Surface finish': 'Leather' }, daysAgo(2));
    expect(Number((await price(PREMIUM)).supplier_inr)).toBe(5100);
    await pricingRepository.setSupplierDates(newer, daysAgo(2), daysAgo(1));
    expect(Number((await price(PREMIUM)).supplier_inr)).toBe(4900);
    await pricingRepository.setSupplierDates(both, daysAgo(200), null);
    const pr = await price(PREMIUM);
    expect([pr.exact, Number(pr.supplier_inr)]).toEqual(['30 mm', 4000]);
    expect(pr.build.raw.cost).toBeCloseTo((50 + 4) * 0.98, 4);
    await pricingRepository.setComboInUse(thirty, false);
    const plain = await price(PREMIUM);
    expect([plain.exact, Number(plain.supplier_inr)]).toEqual([null, 3200]);
    const cbm = await price({ 'Grade / quality': 'Premium', Thickness: '80 mm', 'Surface finish': 'Leather' });
    expect([cbm.exact, Number(cbm.supplier_inr)]).toEqual([null, 53300]);
  });

  it('the "Pricing ready?" checklist, the list badge, and who sees it', async () => {
    let r = await pricingService.readiness(await p());
    expect(r.to_fix).toBe(0);
    expect(r.items.map((i) => i.key)).toEqual(['unit', 'cbm', 'specs', 'current', 'associates']);
    await db.execute('DELETE FROM catalog_product_suppliers WHERE id = :id', { id: cbmSupplier });
    await pricingRepository.setSupplierDates(unitSupplier, daysAgo(90), null);
    await db.execute('DELETE FROM associate_price_access WHERE category_id = :c', { c: await specRepository.systemCategoryId() });
    r = await pricingService.readiness(await p());
    expect(readyItem(r, 'cbm').ok).toBe(false);
    expect(readyItem(r, 'cbm').link).toBe(`/products/${product}/market-prices`);
    expect(readyItem(r, 'current').ok).toBe(false);
    expect(readyItem(r, 'current').detail).toContain('stale');
    expect(readyItem(r, 'associates').ok).toBe(false);
    expect(r.to_fix).toBe(3);
    await exact(4900, { Thickness: '30 mm' }, daysAgo(5), daysAgo(1));
    expect(readyItem(await pricingService.readiness(await p()), 'exact').ok).toBe(false);

    const md = await login('Managing Director');
    let list = (await md.get('/products')).text;
    expect(list).toContain(`/products/${product}#pricing-ready`);
    expect(list).toContain('Pricing: 4 to fix');
    const procurement = await login('Procurement Executive');
    let page = (await procurement.get(`/products/${product}`)).text;
    expect(page).toContain('Pricing ready?');
    expect(page).toContain('Supplier price per CBM');
    for (const s of ['Product open to associates', 'Specifications checked', 'Try a price', 'Staff floor', 'Minimum price']) {
      expect([s, page.includes(s)]).toEqual([s, false]);
    }
    const sales = await login('Export Executive');
    page = (await sales.get(`/products/${product}`)).text;
    for (const s of ['Pricing ready?', 'Try a price', '3,200', 'Ongole', 'Exact quarry']) {
      expect([s, page.includes(s)]).toEqual([s, false]);
    }
    list = (await sales.get('/products')).text;
    expect(list).not.toContain('to fix');
  });

  it('exact prices in Market prices for Procurement, without selling prices', async () => {
    const agent = await login('Procurement Executive');
    const url = `/products/${product}/market-prices`;
    // D-303 (point 15): the supplier is a record of the Suppliers directory (matched by its exact name).
    const quarry = `Exact quarry ${require('crypto').randomBytes(3).toString('hex')}`;
    await require('../../src/repositories/supplierRepository').create({ supplier_legal_name: quarry, types: [] }, null);
    const csrf = (await agent.get(url)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const combo = Object.fromEntries(Object.entries(await sel({ Thickness: '30 mm', 'Surface finish': 'Leather' })).map(([k, v]) => [`combo[${k}]`, String(v)]));
    await agent.post(url).type('form').send({ _csrf: csrf, supplier_name: quarry, price_inr: '4900', quoted_on: today(), valid_until: '', price_basis: 'combo', used_for_pricing: '1', location: '', notes: '', ...combo });
    const rows = (await productSupplierRepository.forProduct(product)).filter((s) => s.price_basis === 'combo');
    expect(rows.length).toBe(1);
    expect([String(rows[0].combo_per), Number(rows[0].combo_in_use)]).toEqual(['unit', 1]);
    const page = (await agent.get(url)).text;
    expect(page).toContain('Exact');
    expect(page).toContain('30 mm · Leather');
    for (const s of ['79.60', '77.28', '83.79', 'Staff floor', 'margin']) {
      expect([s, page.includes(s)]).toEqual([s, false]);
    }
    await agent.post(url).type('form').send({ _csrf: csrf, supplier_name: quarry, price_inr: '4900', quoted_on: today(), valid_until: '', price_basis: 'combo', location: '', notes: '' });
    expect((await productSupplierRepository.forProduct(product)).filter((s) => s.price_basis === 'combo').length).toBe(1);
  });

  it('help text with an example beside every setting', async () => {
    const md = await login('Managing Director');
    const settings = (await md.get('/pricing/settings')).text;
    expect((settings.match(/class="help-line"/g) || []).length).toBeGreaterThanOrEqual(11);
    expect(settings).toContain('Example:');
    const ladder = (await md.get('/associates/ladder')).text;
    expect((ladder.match(/class="help-line"/g) || []).length).toBeGreaterThanOrEqual(7);
    expect(ladder).toContain('Zoho GST tax for commission');
  });

  it('the Zoho commission bill carries the GST tax, and is refused without one', async () => {
    await db.execute('UPDATE associates SET name = :n WHERE id = :id', { n: `TEST-D256 Associate ${crypto.randomBytes(2).toString('hex')}`, id: associate });
    const orderId = await createTestOrder(await createTestClient());
    await db.execute(`INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_inr, gst_inr, paid_on, paid_reference)
                      VALUES (:o, 'associate', :a, 'associate', 10000, '2026-10', 'paid', 10000, 1800, '2026-11-02', 'UTR778899')`, { o: orderId, a: associate });
    const ref = String((await db.queryOne('SELECT buyer_inquiry_ref FROM orders WHERE id = :id', { id: orderId })).buyer_inquiry_ref);
    const cost = { order_id: orderId, buyer_inquiry_ref: ref, buyer_name: 'TEST-Buyer' };
    let c = await zohoBooksService.associateCommission(cost);
    expect([c.gst_inr, c.gst_tax_id]).toEqual([1800, null]);
    await expect(zohoBooksService.pushAssociateCommission(cost)).rejects.toThrow(
      `Associate commission on order ${ref} not sent: GST of INR 1800.00 was paid on the associate's tax invoice, but no Zoho GST tax is chosen for commission (Associates → Commission settings → Zoho GST tax for commission). Choose it, then sync again.`);

    await incentiveRepository.setSetting('zoho_gst_commission_tax_id', '460000000067890', admin);
    c = await zohoBooksService.associateCommission(cost);
    expect(c.gst_tax_id).toBe('460000000067890');
    const bill = zohoBooksService.associateBillPayload(c, cost, 'V1', 'ACC1');
    expect(bill.line_items[0]).toEqual({ account_id: 'ACC1', name: 'Associate commission', description: c.bill_description, rate: 10000, quantity: 1, tax_id: '460000000067890' });
    expect(bill).not.toHaveProperty('tds_tax_id');
    const pay = zohoBooksService.associatePaymentPayload(c, 'V1', 'B1', 'BANK1');
    expect([pay.amount, pay.bills]).toEqual([11800, [{ bill_id: 'B1', amount_applied: 11800 }]]);
    c.gst_inr = 0;
    expect(zohoBooksService.associateBillPayload(c, cost, 'V1', 'ACC1').line_items[0]).not.toHaveProperty('tax_id');
    expect(zohoBooksService.associatePaymentPayload(c, 'V1', 'B1', 'BANK1').amount).toBe(10000);
  });
});
