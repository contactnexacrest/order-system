'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productCategoryRepository = require('../../src/repositories/productCategoryRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const specRepository = require('../../src/repositories/specRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const catalogueMasterController = require('../../src/controllers/catalogueMasterController');
const fxRateService = require('../../src/services/fxRateService');
const priceRules = require('../../src/services/priceRules');
const pricingEngine = require('../../src/services/pricingEngine');
const pricingService = require('../../src/services/pricingService');
const productSpecs = require('../../src/services/productSpecs');
const thicknessPrices = require('../../src/services/thicknessPrices');

/**
 * Port of D300ProductsPricingTest.php — owner decisions of 11 Nov 2026, double-confirmed (products and pricing):
 *  - D-300 (point 12): one supplier price for every thickness, TYPED / CALCULATED, CHA + transport per container
 *    → per unit, packing and loading, price history; the category's Price rules (MD only, reason, audited).
 *  - D-301 (point 4): the daily exchange rate — main source, backup, failure keeps the last rate, a move over
 *    2 % waits for the MD; pricing rate = market × (1 − cushion %); Manual mode.
 *  - D-302 / D-303 (points 11 and 15): HS code only from the master list; the supplier of a price from the
 *    Suppliers directory (quick add "Details pending", Link suppliers).
 *  - D-304 / D-305 (points 14 and 19): catalogue labels and the rate line; "for this order".
 *  - D-306 (point 20): the category page with tabs.
 * The network is never used: the exchange-rate fetcher is replaced.
 */
describe('D-300 to D-306 products and pricing', () => {
  const PASSWORD = 'JestD300!1';
  let admin;

  beforeAll(async () => {
    admin = await user('Admin');
  });

  beforeEach(async () => {
    await db.execute('DELETE FROM fx_rates');
  });

  afterEach(async () => {
    fxRateService.setHttp(null);
    await db.execute('DELETE FROM fx_rates');
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d300-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email, roleId: role.id };
  }

  async function login(u) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  /** Posts a form and returns the flash message shown after the redirect. */
  async function post(agent, from, url, body) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  async function product(unit = 'SQM') {
    const id = await productRepository.create({ name: `D300 Absolute Black ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022310', reference_unit: unit }, admin.id);
    await pricingRepository.saveProductOverrides(id, { pricing_margin_pct: null, pricing_other_cost_mode: 'pct', pricing_other_cost_value: '0',
      pricing_incentive_mode: null, pricing_incentive_value: null, pricing_buffer_pct: null });
    return id;
  }

  async function directorySupplier(name = 'D300 Granites') {
    const id = await supplierRepository.create({ supplier_legal_name: `${name} ${crypto.randomBytes(3).toString('hex')}`, city: 'Test town', types: [] }, admin.id);
    return supplierRepository.find(id);
  }

  async function sel(productId, byName) {
    const out = {};
    for (const s of await productSpecs.offered(await productRepository.find(productId))) {
      for (const o of s.options) if (byName[s.name] === o.name) out[s.id] = o.id;
    }
    return out;
  }

  const gridRow = async (productId) => (await productSupplierRepository.forProduct(productId)).find((s) => s.price_basis === 'grid');
  const todayYmd = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  };

  it('one supplier price for every thickness, with CHA and transport', async () => {
    const productId = await product();
    const sup = await directorySupplier();
    const agent = await login(await user('Procurement Executive'));
    const url = `/products/${productId}/market-prices`;
    const msg = await post(agent, url, url, {
      supplier_id: String(sup.id), supplier_name: sup.supplier_legal_name, location: 'Test town', quoted_on: todayYmd(), valid_until: '',
      price_basis: 'grid', 'price[20]': '1600', 'how[20]': 'typed', 'price[30]': '2160.00', 'how[30]': 'calculated',
      includes_packing_loading: '1', cha_transport_per_container: '60000', used_for_pricing: '1', notes: '',
    });
    expect(msg).toBe('Supplier price recorded and used for pricing.');
    const row = await gridRow(productId);
    expect([Number(row.supplier_id), Number(row.is_primary), String(row.cha_transport_per_container), Number(row.includes_packing_loading)]).toEqual([Number(sup.id), 1, '60000.00', 1]);
    expect(await thicknessPrices.typedFor(Number(row.id))).toEqual({ 20: 1600 });

    let p = await pricingService.forProduct(await productRepository.find(productId));
    expect(p.supplier_inr).toBeCloseTo(1750, 6);
    expect(p.inr_parts.cha).toBeCloseTo(150, 6);
    expect(p.build.cost).toBe(pricingEngine.round2(1750 / 82));
    p = await pricingService.forProduct(await productRepository.find(productId), [], null, null, await sel(productId, { Thickness: '30 mm' }));
    expect(p.supplier_inr).toBeCloseTo(2385, 6);
    expect(p.spec.adj.pct).toEqual([]);
    expect(p.build.cost).toBe(pricingEngine.round2(2385 / 82));
    p = await pricingService.forProduct(await productRepository.find(productId), [], null, null, await sel(productId, { Thickness: '60 mm' }));
    expect(p.unit).toBe('CBM');
    expect(p.supplier_inr).toBeCloseTo(71500, 6);

    const page = (await agent.get(url)).text;
    for (const s of ['every thickness', '1,600.00', '2,160.00', '64,000.00', 'typed', 'calc.', '1,750.00', 'Price includes packing and loading', 'CHA + transport per container']) expect(page).toContain(s);
    expect(page).not.toContain('margin');
  });

  it('changing a typed price moves the calculated ones and keeps history', async () => {
    const productId = await product();
    const sup = await directorySupplier();
    const agent = await login(await user('Procurement Executive'));
    const url = `/products/${productId}/market-prices`;
    const base = { supplier_id: String(sup.id), supplier_name: sup.supplier_legal_name, quoted_on: todayYmd(), valid_until: '', price_basis: 'grid',
      includes_packing_loading: '1', cha_transport_per_container: '', used_for_pricing: '1', notes: '' };
    await post(agent, url, url, { ...base, 'price[20]': '1600', 'how[20]': 'typed' });
    const row = await gridRow(productId);
    const edit = `/products/suppliers/${row.id}/price`;
    expect(await post(agent, edit, edit, { ...base, 'price[20]': '1700', 'how[20]': 'typed', 'price[30]': '2100', 'how[30]': 'typed', 'price[40]': '2890.00', 'how[40]': 'calculated' }))
      .toBe('Supplier price saved.');
    const done = thicknessPrices.complete(await thicknessPrices.rules(await productRepository.find(productId)), await thicknessPrices.typedFor(Number(row.id)));
    expect([done.values[20].price, done.values[20].how]).toEqual([1700, 'typed']);
    expect([done.values[30].price, done.values[30].how, done.values[30].calc]).toEqual([2100, 'typed', 2295]);
    expect([done.values[40].price, done.values[40].how]).toEqual([2890, 'calculated']);
    expect(done.values[0].price).toBe(68000);
    const history = (await thicknessPrices.historyFor(Number(row.id))).map((h) => `${h.what}: ${h.old_value} → ${h.new_value}`);
    expect(history).toContain('20 mm per m²: 1600.00 typed → 1700.00 typed');
    expect(history).toContain('40 mm per m²: 2720.00 calculated → 2890.00 calculated');
    expect(await post(agent, edit, edit, { ...base, 'price[20]': '', 'how[20]': 'typed', 'price[30]': '0', 'how[30]': 'typed' })).toBe('Enter at least one price.');
    const page = (await agent.get(edit)).text;
    expect(page).toContain('Price history');
    expect(page).toContain('calculated would be 2,295.00');
    expect(page).not.toContain('Change these rules');
  });

  it('a price needs a directory supplier; quick add marks "Details pending"', async () => {
    const productId = await product();
    const agent = await login(await user('Procurement Executive'));
    const url = `/products/${productId}/market-prices`;
    expect(await post(agent, url, url, { supplier_name: `Not In The Directory ${crypto.randomBytes(3).toString('hex')}`, quoted_on: todayYmd(), price_basis: 'grid', 'price[20]': '1600' }))
      .toMatch(/^Pick the supplier from the Suppliers directory/);
    expect(await productSupplierRepository.forProduct(productId)).toEqual([]);

    const name = `D300 Quick Quarry ${crypto.randomBytes(3).toString('hex')}`;
    const form = { quick_name: name, quick_location: 'Kota, Rajasthan', quick_phone: '+91-0000000000', quick_type: '', product_id: String(productId) };
    expect(await post(agent, url, '/suppliers/quick-add', form)).toContain('(details pending)');
    const s = await supplierRepository.findByExactName(name);
    expect(Number(s.details_pending)).toBe(1);
    expect((await supplierRepository.pickList()).find((x) => x.id === Number(s.id)).locations).toEqual(['Kota, Rajasthan']);
    expect(await post(agent, url, '/suppliers/quick-add', form)).toContain('is already in the Suppliers directory');
    expect(await post(agent, url, '/suppliers/quick-add', { ...form, quick_name: `Another ${name}`, quick_phone: 'call me' })).toMatch(/^Phone:/);
    await post(agent, url, url, { supplier_id: String(s.id), supplier_name: name, quoted_on: todayYmd(), price_basis: 'grid', 'price[20]': '1600', 'how[20]': 'typed' });
    expect((await productSupplierRepository.forDirectorySupplier(Number(s.id))).length).toBe(1);
  });

  it('Link suppliers matches typed names; Fix HS codes', async () => {
    const productId = await product();
    const sup = await directorySupplier('D300 Linked Stones');
    const legacy = await productSupplierRepository.create({ product_id: productId, supplier_name: String(sup.supplier_legal_name).toUpperCase(), fob_source: 'direct', fob_value: 80000, is_primary: 0 }, admin.id);
    const agent = await login(await user('Procurement Manager'));
    expect((await agent.get('/products/link-suppliers')).text).toContain('Match by name');
    expect(await post(agent, '/products/link-suppliers', '/products/link-suppliers', { action: 'match_all' })).toContain('linked to their supplier by name');
    const row = await productSupplierRepository.find(legacy);
    expect([Number(row.supplier_id), String(row.supplier_name)]).toEqual([Number(sup.id), String(sup.supplier_legal_name)]);

    const bad = await productRepository.create({ name: `D300 Odd HS ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '12345678' }, admin.id);
    const ids = (await catalogueMasterController.productsToFix()).map((x) => Number(x.id));
    expect(ids).toContain(Number(bad));
    expect(ids).not.toContain(Number(productId));
    const a = await login(admin);
    expect(await post(a, '/products/fix-hs-codes', `/products/${bad}/hs-code`, { hs_code: '99999999' })).toContain('choose an HS code from the HS Code Master List');
    await post(a, '/products/fix-hs-codes', `/products/${bad}/hs-code`, { hs_code: '68022310' });
    expect(String((await productRepository.find(bad)).hs_code)).toBe('68022310');
  });

  it('a product HS code comes from the master list', async () => {
    const agent = await login(admin);
    const name = `D300 HS Product ${crypto.randomBytes(3).toString('hex')}`;
    const form = { name, specifications: 'Granite', hs_code: '99999999', origin: 'India', is_active: '1' };
    expect(await post(agent, '/products/create', '/products/create', form)).toContain('is not on the HS Code Master List');
    expect(await post(agent, '/products/create', '/products/create', { ...form, hs_code: '68022310', cha_transport_per_container: '55000' })).toBe('Product added to the catalog.');
    const p = await db.queryOne('SELECT * FROM catalog_products WHERE name = :n', { n: name });
    expect(String(p.cha_transport_per_container)).toBe('55000.00');
    const page = (await agent.get('/products/create')).text;
    expect(page).toContain('data-in-list="product_hs_codes"');
    expect(page).toContain('<datalist id="product_hs_codes">');
  });

  it('legacy prices get CHA and transport from the product default', async () => {
    const productId = await product('CBM');
    const s = await productSupplierRepository.create({ product_id: productId, supplier_name: 'D300 Legacy', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin.id);
    await pricingRepository.setSupplierDates(s, todayYmd(), null);
    expect((await pricingService.forProduct(await productRepository.find(productId))).supplier_inr).toBeCloseTo(80000, 6);
    await productRepository.setPriceRuleFields(productId, { cha_transport_per_container: '60000.00' });
    expect((await pricingService.forProduct(await productRepository.find(productId))).supplier_inr).toBeCloseTo(87500, 6);
    await productRepository.setPriceRuleFields(productId, { container_cbm: '6.00' });
    expect((await pricingService.forProduct(await productRepository.find(productId))).supplier_inr).toBeCloseTo(90000, 6);
    expect((await thicknessPrices.rules(await productRepository.find(productId))).own).toContain('container');
  });

  it('the Price rules page: MD only, with a reason, audited; the category page tabs', async () => {
    const cat = await productCategoryRepository.create(`D300 Rules ${crypto.randomBytes(3).toString('hex')}`, ['Size'], admin.id);
    const spec = await specRepository.createSpec({ category_id: cat, name: 'Thickness', kind: 'thickness', applies_to: null, sort_order: 1 }, admin.id);
    await specRepository.createOption({ spec_id: spec, name: '20 mm', is_base: 1, change_type: 'none', value: null, thickness_mm: 20, sort_order: 1 }, admin.id);
    const o30 = await specRepository.createOption({ spec_id: spec, name: '30 mm', is_base: 0, change_type: 'pct', value: '35.0000', thickness_mm: 30, sort_order: 2 }, admin.id);
    const md = await user('Managing Director');
    let [n, e] = await priceRules.save(cat, { pct: { [o30]: '40' }, thick_k_factor: '40' }, md.id);
    expect(n).toBe(0);
    expect(e).toMatch(/^Reason for the change:/);
    [n, e] = await priceRules.save(cat, { pct: { [o30]: '40' }, thick_k_factor: '42', container_cbm: '8', container_max_t: '27', weight_t_per_cbm: '2.7',
      new_mm: '18', new_change: '-5', reason: 'Supplier rates for 30 mm went up' }, md.id);
    expect(e).toBeNull();
    expect(n).toBe(6);
    const r = await priceRules.forCategory(cat);
    expect(r.thickness.map((t) => [t.mm, t.pct])).toEqual([[18, -5], [20, 0], [30, 40]]);
    expect([r.k, r.container_cbm, r.container_max_t, r.weight]).toEqual([42, 8, 27, 2.7]);
    const audit = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type IN ('PRICE_RULE_CHANGED', 'PRICE_RULE_ADDED') AND reason = 'Supplier rates for 30 mm went up'");
    expect(Number(audit.n)).toBe(6);
    expect((await priceRules.save(cat, { new_mm: '30', new_change: '10', reason: 'Trying a duplicate thickness' }, md.id))[1]).toContain('already in the list');
    expect((await priceRules.save(cat, { new_mm: '60', new_change: '10', reason: 'Trying a thick thickness' }, md.id))[1]).toContain('from 50 mm the price is per CBM');

    const agent = await login(md);
    let page = (await agent.get(`/products/categories/${cat}?tab=rules`)).text;
    for (const s of ['General (name, units, HS code, packing, documents)', 'Options and their price effect', 'Price rules (thickness %, container)', 'Products in this category',
      'Container and weight', 'Save rules', 'data-rules-example']) expect(page).toContain(s);
    page = (await agent.get(`/products/categories/${cat}?tab=options`)).text;
    expect(page).toContain('What changes the price in this category');
    const old = await agent.get(`/pricing/specifications?category=${cat}`);
    expect(old.headers.location).toBe(`/products/categories/${cat}?tab=options`);
  });

  it('the daily exchange rate: failure, backup, a move over 2 % and the MD\'s OK', async () => {
    const md = await user('Managing Director');
    const calls = [];
    let answers = {};
    fxRateService.setHttp(async (url) => {
      calls.push(url);
      return answers[url.includes('frankfurter') ? 'f' : 'e'] ?? null;
    });
    expect((await pricingService.settings()).pricing_fx_rate).toBe(82);
    expect((await fxRateService.current()).warnings[0]).toContain('No market rate has been fetched yet');
    let r = await fxRateService.fetchDaily('2026-11-08');
    expect(r.status).toBe('failed');
    expect(calls).toEqual(['https://api.frankfurter.app/latest?from=USD&to=INR', 'https://open.er-api.com/v6/latest/USD']);
    expect(await fxRateService.rate(await pricingService.settingsRaw())).toBe(82);
    answers = { f: '{"amount":1.0,"base":"USD","date":"2026-11-09","rates":{"INR":88.40}}' };
    r = await fxRateService.fetchDaily('2026-11-09');
    expect([r.status, r.source]).toEqual(['in_use', 'Frankfurter (European Central Bank)']);
    expect((await pricingService.settings()).pricing_fx_rate).toBe(86.632);
    answers = { e: '{"result":"success","rates":{"INR":88.50}}' };
    r = await fxRateService.fetchDaily('2026-11-10');
    expect([r.status, r.source]).toEqual(['in_use', 'open.er-api.com']);
    expect(await fxRateService.rate(await pricingService.settingsRaw())).toBe(86.73);
    answers = { f: '{"rates":{"INR":91.00}}' };
    r = await fxRateService.fetchDaily('2026-11-11');
    expect(r.status).toBe('waiting_md');
    expect(await fxRateService.rate(await pricingService.settingsRaw())).toBe(86.73);
    const waiting = await fxRateService.forDate('2026-11-11');
    expect(Number(waiting.change_pct)).toBeCloseTo(2.825, 3);
    expect(await fxRateService.decide(Number(waiting.id), true, 'ok', md.id)).not.toBeNull();
    expect(await fxRateService.decide(Number(waiting.id), true, 'Checked the move with the bank', md.id)).toBeNull();
    expect(await fxRateService.rate(await pricingService.settingsRaw())).toBe(89.18);
    expect(await fxRateService.decide(Number(waiting.id), true, 'Checked the move with the bank', md.id)).not.toBeNull();
    await pricingRepository.setSetting('fx_cushion_pct', '2.5', md.id);
    expect(await fxRateService.rate(await pricingService.settingsRaw())).toBe(88.725);
    await pricingRepository.setSetting('fx_mode', 'manual', md.id);
    expect((await pricingService.settings()).pricing_fx_rate).toBe(82);
    await pricingRepository.setSetting('fx_mode', 'auto', md.id);
    const page = (await (await login(md)).get('/pricing/settings')).text;
    for (const s of ['Pricing rate ₹', "below today's market rate (₹91.00)", 'Why?', 'History (last 30 fetches)', 'Fetch failed — last rate kept', 'Frankfurter (European Central Bank)', 'open.er-api.com']) {
      expect(page.replace(/&#39;/g, "'")).toContain(s);
    }
  });

  it('catalogue labels and who sees which price', async () => {
    const productId = await product('CBM');
    const s = await productSupplierRepository.create({ product_id: productId, supplier_name: 'D300 Labels', fob_source: 'direct', fob_value: 180000, is_primary: 1 }, admin.id);
    await pricingRepository.setSupplierDates(s, todayYmd(), null);
    const q = encodeURIComponent((await productRepository.find(productId)).name);
    let list = (await (await login(await user('Managing Director'))).get(`/products?q=${q}`)).text;
    for (const x of ['Supplier price<br>₹ / CBM', 'Cost<br>USD / CBM', 'Staff lowest', 'Associate lowest', 'Pricing rate ₹82.00 per USD', 'Why?']) expect(list).toContain(x);
    expect(list).not.toContain('Headline FOB');
    list = (await (await login(await user('Export Executive'))).get(`/products?q=${q}`)).text;
    for (const x of ['Quote price<br>USD / CBM', 'Your lowest<br>USD / CBM']) expect(list).toContain(x);
    for (const x of ['Pricing rate', 'Staff lowest', 'Associate lowest', 'Cost<br>', '180,000']) expect(list).not.toContain(x);
  });

  it('an associate order shows this order\'s figures without the associate', async () => {
    const nunjucks = require('nunjucks');
    const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(require('path').join(__dirname, '../../views')));
    env.addFilter('money', (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    env.addFilter('unitLabel', (u) => require('../../src/services/lineUnits').label(u));
    env.addFilter('factor4', (v) => String(Math.round(Number(v) * 10000) / 10000));
    const lc = { status: 'ok', figures: { quote: 2881.88, floor: 2797.95, minimum: 2662.12, sold_by: 'L2' }, conversion: null, reference: null, fixed: false, requested: false, note: null };
    let html = env.render('orders/_line_price.njk', { lc, seesPriceBuildup: false });
    expect(html).toContain('Quote 2,881.88 · lowest 2,797.95 for this order');
    expect(html).not.toContain('minimum');
    html = env.render('orders/_line_price.njk', { lc: { ...lc, figures: { ...lc.figures, sold_by: 'staff' } }, seesPriceBuildup: false });
    expect(html).not.toContain('for this order');
  });
});
