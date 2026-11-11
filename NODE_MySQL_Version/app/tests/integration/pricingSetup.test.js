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
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const pricingService = require('../../src/services/pricingService');

/**
 * Port of PricingSetupTest.php — owner decision D-245 (4 Nov 2026), phase P1:
 * pricing settings (MD only, audited), product overrides, Procurement's market
 * prices, the commission levels and ladder, an associate's commission level,
 * and who sees which price: MD/ED the whole build-up, selling-price staff only
 * the quote and their lowest price, Procurement no selling price at all.
 */
describe('Pricing setup (D-245, P1)', () => {
  const PASSWORD = 'JestPricing!1';

  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(async () => {
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-pricing-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
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

  const programOn = () => db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");

  /** A product with a ₹80,000 supplier price used for pricing, quoted today. */
  async function product(name = 'Black Galaxy Granite Block') {
    const admin = await user('Admin');
    const id = await productRepository.create({
      name: `${name} ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM',
    }, admin.id);
    const s = await productSupplierRepository.create({
      product_id: id, supplier_name: 'Sri Balaji Granites', fob_source: 'direct', fob_value: 80000, is_primary: 1,
    }, admin.id);
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    await pricingRepository.setSupplierDates(s, `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`, null);
    return id;
  }

  it('who sees which price', async () => {
    await programOn();
    const productId = await product();

    const md = await login(await user('Managing Director'));
    let page = (await md.get(`/products/${productId}`)).text;
    for (const s of ['Price build-up', 'USD 1,000.00', 'USD 1,250.00', 'USD 37.50', 'USD 1,287.50', 'USD 1,326.13', '1,355.26', '1,395.92', '1,430.56', "Save this product's settings"]) {
      expect([s, page.includes(s)]).toEqual([s, true]);
    }
    let list = (await md.get('/products')).text;
    expect(list).toContain('Staff lowest'); // D-304: plain names
    expect(list).toContain('1,313.78');

    const ed = await login(await user('Executive Director'));
    page = (await ed.get(`/products/${productId}`)).text;
    expect(page).toContain('USD 1,250.00');
    expect(page).not.toContain("Save this product's settings");

    const sales = await login(await user('Export Executive'));
    page = (await sales.get(`/products/${productId}`)).text;
    expect(page).toContain('USD 1,326.13');
    expect(page).toContain('USD 1,287.50');
    for (const s of ['Price build-up', '1,250.00', '1,000.00', '37.50', '80,000', '1,355.26', 'Market prices']) {
      expect([s, page.includes(s)]).toEqual([s, false]);
    }
    list = (await sales.get('/products')).text;
    expect(list).toContain('1,326.13');
    expect(list).not.toContain('Staff lowest');
    expect(list).not.toContain('1,250.00');

    const procurement = await login(await user('Procurement Executive'));
    const market = (await procurement.get(`/products/${productId}/market-prices`)).text;
    expect(market).toContain('80,000.00');
    for (const s of ['1,326.13', '1,287.50', '1,250.00', 'Staff floor', 'margin']) {
      expect([s, market.includes(s)]).toEqual([s, false]);
    }
    // Sales cannot open market prices or the pricing settings.
    expect((await sales.get(`/products/${productId}/market-prices`)).status).toBe(403);
    expect((await sales.get('/pricing/settings')).status).toBe(403);
  });

  it('settings are MD only, validated and audited', async () => {
    const md = await user('Managing Director');
    const agent = await login(md);
    let msg = await post(agent, '/pricing/settings', '/pricing/settings', {
      margin_pct: '95', other_cost_pct: '2.5', pricing_fx_rate: '82', buffer_pct: '3', incentive_mode: 'pct', incentive_value: '15', stale_days: '60',
    });
    expect(msg).toContain('Minimum margin (% of price): enter a number from 0 to 90');
    expect((await pricingRepository.settings()).margin_pct).toBe('20.00');

    msg = await post(agent, '/pricing/settings', '/pricing/settings', {
      margin_pct: '22.5', other_cost_pct: '2.5', pricing_fx_rate: '82', buffer_pct: '3', incentive_mode: 'pct', incentive_value: '15', stale_days: '60',
    });
    expect(msg).toContain('Pricing settings saved');
    expect((await pricingRepository.settings()).margin_pct).toBe('22.50');
    const log = await db.queryOne("SELECT user_id, field_name, old_value, new_value FROM audit_log WHERE action_type = 'PRICING_SETTING_CHANGED' ORDER BY id DESC LIMIT 1");
    expect([Number(log.user_id), log.field_name, log.old_value, log.new_value]).toEqual([md.id, 'margin_pct', '20.00', '22.50']);

    const ed = await login(await user('Executive Director'));
    expect((await ed.get('/pricing/settings')).text).toContain('Only the MD can change these settings.');
    const csrf = (await ed.get('/pricing/settings')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    expect((await ed.post('/pricing/settings').type('form').send({ _csrf: csrf, margin_pct: '30' })).status).toBe(403);
  });

  it('product overrides change only that product', async () => {
    await programOn();
    const productId = await product();
    const other = await product('Other Block');
    const agent = await login(await user('Managing Director'));
    const msg = await post(agent, `/products/${productId}`, `/products/${productId}/pricing`, {
      pricing_margin_pct: '25', pricing_buffer_pct: '', pricing_other_cost_mode: 'usd', pricing_other_cost_value: '24.39',
      pricing_incentive_mode: 'usd', pricing_incentive_value: '1',
    });
    expect(msg).toContain('saved');
    const p = await pricingService.forProduct(await productRepository.find(productId));
    expect([p.build.cost, p.build.minimum, p.build.reserve, p.build.staff_floor, p.build.staff_quote]).toEqual([1000, 1333.33, 1, 1334.33, 1374.36]);
    expect(p.overrides).toContain('margin 25%');
    expect((await pricingService.forProduct(await productRepository.find(other))).build.staff_quote).toBe(1326.13);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'PRODUCT_PRICING_CHANGED' AND entity_id = :id", { id: productId })).c)).toBe(5);

    expect(await post(agent, `/products/${productId}`, `/products/${productId}/pricing`, { pricing_margin_pct: '95' })).toContain('Margin %');
  });

  it('market prices: used for pricing, history and stale', async () => {
    const productId = await product();
    const agent = await login(await user('Procurement Executive'));
    const d = new Date();
    d.setDate(d.getDate() - 70);
    const p = (n) => String(n).padStart(2, '0');
    const old = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
    // D-303 (point 15): the supplier is a record of the Suppliers directory.
    const dirName = `Raghavendra Stones ${crypto.randomBytes(3).toString('hex')}`;
    const dir = await require('../../src/repositories/supplierRepository').create({ supplier_legal_name: dirName, types: [] }, null);
    let msg = await post(agent, `/products/${productId}/market-prices`, `/products/${productId}/market-prices`, {
      supplier_id: String(dir), supplier_name: dirName, price_inr: '82000', quoted_on: old, valid_until: '', notes: 'Ex-works', used_for_pricing: '1',
    });
    expect(msg).toBe('Supplier price recorded and used for pricing.');
    const pr = await pricingService.forProduct(await productRepository.find(productId));
    expect([pr.supplier.supplier_name, pr.supplier_inr, pr.staleness]).toEqual([dirName, 82000, 'stale']);
    expect(Number(pr.supplier.supplier_id)).toBe(Number(dir)); // linked to the directory supplier
    expect((await productSupplierRepository.forProduct(productId)).length).toBe(2);

    const t = new Date();
    t.setDate(t.getDate() + 1);
    msg = await post(agent, `/products/${productId}/market-prices`, `/products/${productId}/market-prices`, {
      supplier_id: String(dir), supplier_name: dirName, price_inr: '100', quoted_on: `${t.getFullYear()}-${p(t.getMonth() + 1)}-${p(t.getDate())}`,
    });
    expect(msg).toBe('The quoted date cannot be in the future.');

    const first = (await productSupplierRepository.forProduct(productId)).find((s) => s.supplier_name === 'Sri Balaji Granites');
    await post(agent, `/products/${productId}/market-prices`, `/products/suppliers/${first.id}/use-for-pricing`, {});
    expect((await pricingService.forProduct(await productRepository.find(productId))).supplier_inr).toBe(80000);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'MARKET_PRICE_USED_FOR_PRICING' AND entity_id = :id", { id: first.id })).c)).toBe(1);
  });

  it('commission levels and ladder', async () => {
    const agent = await login(await user('Managing Director'));
    const from = '/pricing/commission-levels';
    expect(await post(agent, from, '/pricing/commission-levels', { name: 'Level 5', kind: 'pct', value: '12' })).toBe('Level "Level 5" added.');
    const id = Number((await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 5'")).id);
    expect(await post(agent, from, '/pricing/commission-levels', { name: 'Level 5', kind: 'pct', value: '3' })).toBe('A level called "Level 5" already exists.');
    expect(await post(agent, from, '/pricing/commission-levels', { name: 'Level 6', kind: 'pct', value: '60' })).toContain('up to 50');

    const level1 = Number((await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 1'")).id);
    expect(await post(agent, from, `/pricing/commission-levels/${level1}/delete`, {})).toContain('is in use');
    expect(await post(agent, from, `/pricing/commission-levels/${level1}/active`, {})).toContain('deactivated');
    await post(agent, from, `/pricing/commission-levels/${level1}/active`, {});
    expect(await post(agent, from, `/pricing/commission-levels/${id}/delete`, {})).toBe('Level "Level 5" deleted.');

    const ids = {};
    for (const r of await db.query('SELECT name, id FROM commission_levels')) ids[r.name] = String(r.id);
    const ladder = (rows) => ({ from: rows.map((r) => r[0]), to: rows.map((r) => r[1]), level_id: rows.map((r) => r[2]) });
    expect(await post(agent, from, '/pricing/commission-ladder', ladder([['1', '5', ids['Level 1']], ['7', '', ids['Level 2']]]))).toBe('The ladder has a gap: no step covers order 6.');
    expect(await post(agent, from, '/pricing/commission-ladder', ladder([['1', '5', ids['Level 1']], ['5', '', ids['Level 2']]]))).toBe('Steps overlap at order 5.');
    expect(await post(agent, from, '/pricing/commission-ladder', ladder([['1', '5', ids['Level 1']], ['6', '10', ids['Level 2']]]))).toContain('Leave the last step');
    expect(await post(agent, from, '/pricing/commission-ladder', ladder([['1', '3', ids['Level 1']], ['4', '', ids['Level 4']], ['', '', '']]))).toContain('Ladder saved');
    expect((await pricingRepository.ladder()).map((s) => [Number(s.from_order), s.to_order === null ? null : Number(s.to_order), s.level_name]))
      .toEqual([[1, 3, 'Level 1'], [4, null, 'Level 4']]);

    // Put the seeded ladder back for other tests.
    await post(agent, from, '/pricing/commission-ladder', ladder([['1', '5', ids['Level 1']], ['6', '10', ids['Level 2']], ['11', '20', ids['Level 3']], ['21', '', ids['Level 4']]]));
  });

  it("an associate's commission level is MD only", async () => {
    const md = await login(await user('Managing Director'));
    const level2 = Number((await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 2'")).id);
    const name = `Jest Associate ${crypto.randomBytes(3).toString('hex')}`;
    const base = { name, based_in: 'india', currency_code: 'INR' };
    expect(await post(md, '/associates/new', '/associates', { ...base, commission_mode: 'fixed', commission_level_id: '' })).toContain('Choose an active commission level');
    await post(md, '/associates/new', '/associates', { ...base, commission_mode: 'fixed', commission_level_id: String(level2) });
    const a = await db.queryOne('SELECT id, commission_mode, commission_level_id FROM associates WHERE name = :n ORDER BY id DESC LIMIT 1', { n: name });
    expect([a.commission_mode, Number(a.commission_level_id)]).toEqual(['fixed', level2]);
    expect((await db.queryOne("SELECT new_value FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_LEVEL_CHANGED' AND entity_id = :id", { id: a.id })).new_value).toBe('Fixed: Level 2 — 5%');

    const ed = await login(await user('Executive Director'));
    await post(ed, `/associates/${a.id}/edit`, `/associates/${a.id}/update`, { ...base, commission_mode: 'ladder' });
    const after = await db.queryOne('SELECT commission_mode, commission_level_id FROM associates WHERE id = :id', { id: a.id });
    expect([after.commission_mode, Number(after.commission_level_id)]).toEqual(['fixed', level2]);

    expect(await post(md, '/pricing/commission-levels', `/pricing/commission-levels/${level2}/delete`, {})).toMatch(/is in use \(\d+ associate\(s\)/); // other tests may also use the level (shared test DB)
  });

  it('the pages open', async () => {
    const md = await login(await user('Managing Director'));
    const settings = (await md.get('/pricing/settings')).text;
    expect(settings).toContain('Pricing Settings');
    expect(settings).toContain('name="margin_pct"');
    const levels = (await md.get('/pricing/commission-levels')).text;
    expect(levels).toContain('Associate Commission Levels');
    expect(levels).toContain('Level 4');
    expect(levels).toContain('id="ladder"');
  });
});
