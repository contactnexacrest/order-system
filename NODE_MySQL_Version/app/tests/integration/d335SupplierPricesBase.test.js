'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const pricingService = require('../../src/services/pricingService');
const productService = require('../../src/services/productService');
const thicknessPrices = require('../../src/services/thicknessPrices');

/**
 * Port of D335SupplierPricesBaseTest.php — owner decision D-335 (12 Nov 2026, mockups approved "OK build"),
 * THE ONE RULE: (1) a price the user TYPED always wins; (2) each supplier price has a BASE thickness the user
 * chose, used by the product-level price and order lines without a thickness; (3) the price rules are only a
 * CALCULATOR — a ticked thickness is stored as CALCULATED ("from 20 mm"), an unticked one keeps its price.
 * Rows saved before D-335 (auto_fill 1) keep the on-the-fly completion until the first new price; migration
 * 2026-11-12b turns "add up the cost parts" rows into typed prices with the same total.
 * Owner example: 50 mm+ typed ₹1,80,000 / CBM, CHA ₹80,000 per container of 8.2 CBM, rate 95, margin 8 %,
 * other 0 %, buffer 3 %.
 */
describe('D-335 supplier prices: typed wins, a base, the rules only a calculator', () => {
  const PASSWORD = 'JestD335!1';
  let admin;

  const rules = (extra = {}) => ({
    grid: true, base_mm: 20, k: 40, cbm_from: 50,
    rows: [{ mm: 15, pct: -20, option: '15 mm' }, { mm: 18, pct: -5, option: '18 mm' }, { mm: 20, pct: 0, option: '20 mm' },
      { mm: 30, pct: 35, option: '30 mm' }, { mm: 40, pct: 70, option: '40 mm' }],
    container_cbm: 8.2, container_max_t: 27, t_per_cbm: 2.7, own: [], category_id: 1, category_name: 'Natural Stone', unit: 'CBM',
    ...extra,
  });

  beforeAll(async () => {
    admin = await user('Admin');
  });

  beforeEach(async () => {
    for (const [k, v] of Object.entries({ fx_mode: 'manual', pricing_fx_rate: '95' })) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  afterEach(async () => {
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d335-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id },
    )).insertId;
    return { id, email, roleId: role.id };
  }

  async function login(u) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  async function post(agent, from, url, body) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  async function product() {
    const id = await productRepository.create({ name: `D335 Absolute Black ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022310', reference_unit: 'CBM' }, admin.id);
    await pricingRepository.saveProductOverrides(id, { pricing_margin_pct: '8', pricing_other_cost_mode: 'pct', pricing_other_cost_value: '0',
      pricing_incentive_mode: 'pct', pricing_incentive_value: '0', pricing_buffer_pct: '3' });
    await productRepository.setPriceRuleFields(id, { cha_transport_per_container: '80000.00', container_cbm: '8.20' });
    return id;
  }

  async function directorySupplier(name) {
    const id = await supplierRepository.create({ supplier_legal_name: `${name} ${crypto.randomBytes(3).toString('hex')}`, city: 'Test town', types: [] }, admin.id);
    return supplierRepository.find(id);
  }

  const gridRow = async (productId) => (await productSupplierRepository.forProduct(productId)).find((s) => s.price_basis === 'grid');
  const stored = async (rowId) => {
    const out = {};
    for (const [mm, s] of Object.entries(await thicknessPrices.storedFor(rowId))) out[mm] = [s.price, s.how, s.calc_from_mm];
    return out;
  };
  const todayYmd = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  };

  it('the calculator shows the result and how it is worked out', () => {
    let c = thicknessPrices.calcFrom(rules(), 20, 4600);
    expect(c.ok).toBe(true);
    expect([c.values[15].price, c.values[15].how, c.values[15].change]).toEqual([3680, '= 4,600 − 20 %', '−20 %']);
    expect([c.values[18].price, c.values[30].price, c.values[40].price]).toEqual([4370, 6210, 7820]);
    expect([c.values[0].price, c.values[0].how, c.values[0].change]).toEqual([184000, '= 20 mm price ₹4,600 per m² × 40', '× 40']);
    expect(c.values[20]).toBeUndefined();
    c = thicknessPrices.calcFrom(rules(), 0, 180000);
    expect([c.values[20].price, c.values[15].price]).toEqual([4500, 3600]);
    expect(c.values[20].how).toBe('= 20 mm price ₹4,500 (= 180,000 per CBM ÷ 40)');
    expect(c.values[15].how).toBe('= 20 mm price ₹4,500 (= 180,000 per CBM ÷ 40) − 20 %');
    expect(thicknessPrices.calcFrom(rules(), 25, 100).error).toBe('Choose a thickness from the list.');
  });

  it('the base is used and a thickness without a stored price has no price', () => {
    const r = rules();
    const row = { id: 1, cha_transport_per_container: '80000', includes_packing_loading: 1, auto_fill: 0, base_thickness_mm: 0 };
    const st = { 0: { price: 180000, how: 'typed' }, 20: { price: 4600, how: 'typed' }, 30: { price: 6210, how: 'calculated', calc_from_mm: 20 } };
    const c = thicknessPrices.cost(r, st, row, {}, 'unit', null);
    expect(c.total).toBeCloseTo(189756.10, 2);
    expect(c.how).toBe('typed');
    expect(thicknessPrices.cost(r, st, row, {}, 'cbm', 60).total).toBeCloseTo(189756.10, 2);
    expect(thicknessPrices.cost(r, st, row, {}, 'unit', 30).total).toBeCloseTo(6210 / 0.03 + 9756.0976, 2);
    expect(thicknessPrices.cost(r, st, row, {}, 'unit', 25)).toBeNull();
    expect(thicknessPrices.cost(r, { 20: 4600 }, row, {}, 'unit', 40)).toBeNull();
    expect(thicknessPrices.cost(r, st, { ...row, base_thickness_mm: 20 }, {}, 'unit', null).total).toBeCloseTo(4600 / 0.02 + 9756.0976, 2);
    expect(thicknessPrices.cost(r, { 20: 4600 }, { ...row, auto_fill: 1, base_thickness_mm: 20 }, {}, 'unit', 40).total).toBeCloseTo(7820 / 0.04 + 9756.0976, 2);
  });

  it('owner example: the typed CBM price is the base and a 20 mm price does not change it', async () => {
    const productId = await product();
    const sup = await directorySupplier('D335 Granites');
    const agent = await login(admin);
    const url = `/products/${productId}/market-prices`;
    let msg = await post(agent, url, url, {
      supplier_id: String(sup.id), supplier_name: sup.supplier_legal_name, location: 'Test town', quoted_on: '2026-11-11', valid_until: '',
      price_basis: 'grid', thickness_mm: '0', thickness_price: '180000', includes_packing_loading: '1', cha_transport_per_container: '',
      used_for_pricing: '1', notes: '',
    });
    expect(msg).toBe('Supplier price recorded and used for pricing.');
    let row = await gridRow(productId);
    expect([Number(row.base_thickness_mm), Number(row.auto_fill)]).toEqual([0, 0]);
    expect(await stored(Number(row.id))).toEqual({ 0: [180000, 'typed', null] });

    let p = await pricingService.forProduct(await productRepository.find(productId));
    expect(p.supplier_inr).toBeCloseTo(189756.10, 2);
    expect([p.build.supplier_usd, p.build.cost, p.build.minimum]).toEqual([1997.43, 1997.43, 2171.12]);
    expect(Math.abs(p.build.staff_quote - 2236.25)).toBeLessThanOrEqual(0.011);

    msg = await post(agent, url, `/products/suppliers/${row.id}/thickness-price`, {
      thickness_mm: '20', thickness_price: '4600.00', price_quoted_on: '2026-11-11', 'also[]': ['15', '18', '30', '40'], base: 'keep',
    });
    expect(msg).toBe('Price saved and 4 worked out from it.');
    expect(await stored(Number(row.id))).toEqual({
      15: [3680, 'calculated', 20], 18: [4370, 'calculated', 20], 20: [4600, 'typed', null],
      30: [6210, 'calculated', 20], 40: [7820, 'calculated', 20], 0: [180000, 'typed', null],
    });
    row = await productSupplierRepository.find(Number(row.id));
    expect(Number(row.base_thickness_mm)).toBe(0);
    p = await pricingService.forProduct(await productRepository.find(productId));
    expect(p.supplier_inr).toBeCloseTo(189756.10, 2);

    const history = (await thicknessPrices.historyFor(Number(row.id))).map((h) => `${h.what}: ${h.old_value} → ${h.new_value}`);
    expect(history).toContain('20 mm per m²: — → 4,600.00 typed');
    expect(history).toContain('15 mm per m²: — → 3,680.00 calculated from 20 mm');
    expect(history).toContain('Base: 20 mm (category) → 50 mm and above');

    const page = (await agent.get(url)).text.replace(/&#39;/g, "'");
    for (const s of ['4,795.12', '4,600.00 + CHA 195.12', '189,756.10', 'BASE', 'TYPED', 'CALCULATED', 'from 20 mm − 20 %',
      'Product price (from the base):', '₹189,756.10 per CBM · 50 mm and above', 'USD 1,997.43 at ₹95', 'minimum (8 % margin) USD 2,171.12',
      'you typed ₹180,000.00 on 11 Nov 2026 — kept unless you tick']) expect(page).toContain(s);

    await post(agent, url, `/products/suppliers/${row.id}/thickness-price`, {
      thickness_mm: '20', thickness_price: '5000', price_quoted_on: '2026-11-11', 'also[]': ['0'], base: 'keep',
    });
    const s = await stored(Number(row.id));
    expect(s[0]).toEqual([200000, 'calculated', 20]);
    expect(s[15]).toEqual([3680, 'calculated', 20]);
    expect(s[20]).toEqual([5000, 'typed', null]);

    msg = await post(agent, url, `/products/suppliers/${row.id}/base`, { mm: '20' });
    expect(msg).toBe('The base for orders is now 20 mm.');
    expect(Number((await productSupplierRepository.find(Number(row.id))).base_thickness_mm)).toBe(20);
  });

  it('owner bug: "Try a price" starts on the supplier\'s base; an explicit thickness prices that thickness', async () => {
    const productId = await product();
    const sup = await directorySupplier('D335 Try');
    const agent = await login(admin);
    const url = `/products/${productId}/market-prices`;
    await post(agent, url, url, {
      supplier_id: String(sup.id), supplier_name: sup.supplier_legal_name, location: 'Test town', quoted_on: '2026-11-11', valid_until: '',
      price_basis: 'grid', thickness_mm: '0', thickness_price: '180000', includes_packing_loading: '1', cha_transport_per_container: '',
      used_for_pricing: '1', notes: '',
    });
    const row = await gridRow(productId);
    await post(agent, url, `/products/suppliers/${row.id}/thickness-price`, {
      thickness_mm: '20', thickness_price: '4600.00', price_quoted_on: '2026-11-11', base: 'keep',
    });
    const productSpecs = require('../../src/services/productSpecs');
    const thick = (await productSpecs.offered(await productRepository.find(productId))).find((sp) => sp.kind === 'thickness');
    expect(thick).toBeTruthy();
    const mm20 = thick.options.find((o) => Number(o.thickness_mm) === 20).id;

    const tryPart = (html) => html.replace(/&#39;/g, "'").slice(html.replace(/&#39;/g, "'").indexOf('id="try-price"'));
    let t = tryPart((await agent.get(`/products/${productId}`)).text);
    expect(t).toContain('<option value="" selected>Supplier\'s base price (50 mm and above)</option>');
    expect(t).not.toContain(`<option value="${mm20}" selected>`);
    expect(t).toContain('Supplier price &#8377;189,756.10');
    expect(t).toContain('cost USD 1,997.43');
    expect(t).toContain('Per CBM, FOB, USD.');

    t = tryPart((await agent.get(`/products/${productId}?try[${thick.id}]=${mm20}`)).text);
    expect(t).toContain(`<option value="${mm20}" selected>`);
    expect(t).not.toContain('<option value="" selected>');
    expect(t).toContain('Supplier price &#8377;239,756.10');
  });

  it('a row saved before D-335 keeps what it showed when switched to stored prices', async () => {
    const productId = await product();
    const sup = await directorySupplier('D335 Old');
    const agent = await login(admin);
    const url = `/products/${productId}/market-prices`;
    await post(agent, url, url, {
      supplier_id: String(sup.id), supplier_name: sup.supplier_legal_name, quoted_on: todayYmd(), valid_until: '', price_basis: 'grid',
      'price[20]': '1600', 'how[20]': 'typed', includes_packing_loading: '1', cha_transport_per_container: '', used_for_pricing: '1', notes: '',
    });
    const row = await gridRow(productId);
    expect(Number(row.auto_fill)).toBe(1);
    await post(agent, url, `/products/suppliers/${row.id}/thickness-price`, { thickness_mm: '0', thickness_price: '70000', price_quoted_on: todayYmd(), base: 'keep' });
    const s = await stored(Number(row.id));
    expect(s[30]).toEqual([2160, 'calculated', 20]);
    expect(s[20]).toEqual([1600, 'typed', null]);
    expect(s[0]).toEqual([70000, 'typed', null]);
    expect(Number((await productSupplierRepository.find(Number(row.id))).auto_fill)).toBe(0);
  });

  it('the migration turns "add up the cost parts" into a typed price with the same total', async () => {
    const productId = await product();
    await db.execute('UPDATE catalog_products SET default_factory_cost = 900, default_transportation_cost = 60, default_packing_cost = 25, default_loading_cost = NULL, default_cha_cost = 20 WHERE id = :id', { id: productId });
    const id = (await db.execute(`INSERT INTO catalog_product_suppliers (product_id, supplier_name, fob_source, fob_value, factory_cost, transportation_cost, packing_cost, loading_cost, cha_cost)
      VALUES (:p, 'D335 computed', 'computed', NULL, 1000, NULL, NULL, 0, NULL)`, { p: productId })).insertId;
    const before = productService.effectiveFobForSupplier(await productSupplierRepository.find(id), await productRepository.find(productId));
    expect(before).toBe(1105);
    const sql = fs.readFileSync(path.join(__dirname, '../../../../docs/migrations/2026-11-12b-supplier-prices-base-and-calculated.sql'), 'utf8');
    const update = sql.slice(sql.lastIndexOf('UPDATE catalog_product_suppliers s')).trim().replace(/;\s*$/, '');
    await db.execute(update);
    await db.execute(update);
    const row = await productSupplierRepository.find(id);
    expect([row.fob_source, String(row.fob_value)]).toEqual(['direct', '1105.00']);
    expect(productService.effectiveFobForSupplier(row, await productRepository.find(productId))).toBe(before);
  });
});
