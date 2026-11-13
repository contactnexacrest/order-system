'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderLineValidator = require('../../src/helpers/orderLineValidator');
const settingValueValidator = require('../../src/helpers/settingValueValidator');
const settingLabels = require('../../src/helpers/settingLabels');
const quantityLimits = require('../../src/services/quantityLimits');
const { createTestClient, createTestOrder, allowHandTypedGoodsLines } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

/**
 * Owner decision item 10a (13 Nov 2026, "go for the two-level version"), replacing the
 * HNT-011 single cap of 10,000,000: an order line's quantity is (1) refused above
 * 1,000,000 in any unit, and (2) above about 20 containers of its unit (SQM 30,000 ·
 * SQFT 300,000 · CBM 500 · TON 600 · PCS 600,000 · RMT 60,000 — settings) saved only with
 * "I confirm this quantity" ticked, which is audited. PHP: QuantityLimitsItem10aTest.php.
 * Domestic Trade: domesticTrade.test.js.
 */
describe('Order line quantity limits (owner decision item 10a)', () => {
  const MESSAGE_SQM = "This is unusually large for one line (more than about 20 containers of SQM). Check the quantity, or tick 'I confirm this quantity' to save it.";
  let agent;
  let adminId;
  let auditFrom;

  beforeAll(async () => {
    await allowHandTypedGoodsLines(); // D-245 P2: these lines are typed by hand; the test is about the quantity
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    adminId = (await db.queryOne('SELECT id FROM users WHERE email = :e', { e: TEST_ADMIN_EMAIL })).id;
  });

  beforeEach(async () => {
    auditFrom = Number((await db.queryOne('SELECT COALESCE(MAX(id), 0) AS m FROM audit_log')).m);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const auditRows = async () => db.query("SELECT user_id, entity_type, entity_id, new_value, reason FROM audit_log WHERE action_type = 'LARGE_QUANTITY_CONFIRMED' AND id > :f ORDER BY id", { f: auditFrom });
  const csrfOf = async (url) => (await agent.get(url)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const alertOf = async (res) => {
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&#039;/g, "'").replace(/&amp;/g, '&') : '';
  };
  async function order() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    return orderId;
  }
  async function saveLine(orderId, body, productId = null) {
    const csrf = await csrfOf(`/orders/${orderId}`);
    const res = await agent.post(`/orders/${orderId}/products${productId ? `/${productId}` : ''}`).type('form')
      .send({ _csrf: csrf, description: 'Granite slab item 10a', hs_code: '68022310', unit_price: '5.00', ...body });
    return alertOf(res);
  }
  const lines = async (orderId) => db.query('SELECT * FROM order_products WHERE order_id = :o ORDER BY id', { o: orderId });

  it('item 10a: the hard block is 1,000,000 in any unit', () => {
    expect(orderLineValidator.checkLine('1000000', false, '1')).toBeNull();
    expect(orderLineValidator.checkLine('1000000.001', false, '1')).toBe('quantity can be at most 1,000,000 (got "1000000.001")');
    expect(orderLineValidator.checkLine('99999999999', false, '10')).toContain('quantity can be at most 1,000,000');
    expect(orderLineValidator.checkLine('10000000', false, '1')).toContain('quantity can be at most 1,000,000');
    expect(orderLineValidator.checkLine('', true, '10')).toBeNull();
  });

  it('item 10a: above the unit level the tick is needed', async () => {
    const cases = [['SQM', 30000], ['M2', 30000], ['SQFT', 300000], ['CBM', 500], ['TON', 600], ['MT', 600], ['PCS', 600000], ['NOS', 600000], ['RMT', 60000]];
    for (const [unit, level] of cases) {
      expect(await quantityLimits.check(String(level), unit, false, false)).toBeNull();
      expect(await quantityLimits.check(String(level + 0.001), unit, false, false)).toBe(quantityLimits.message(unit));
      expect(await quantityLimits.check(String(level + 1), unit, false, true)).toBeNull();
    }
    expect(await quantityLimits.check('40000', 'SQM', false, false)).toBe(MESSAGE_SQM);
    expect(await quantityLimits.check('999999', 'BOX', false, false)).toBeNull();
    expect(await quantityLimits.check('999999', 'SET', false, false)).toBeNull();
    expect(await quantityLimits.check('40000', 'SQM', true, false)).toBeNull();
    expect(await quantityLimits.check('', 'SQM', false, false)).toBeNull();
  });

  it('item 10a: a setting change moves the level; the six settings are seeded with labels and ranges', async () => {
    try {
      expect(await quantityLimits.check('150', 'CBM', false, false)).toBeNull();
      await db.execute("UPDATE company_settings SET setting_value = '100' WHERE setting_key = 'quantity_warning_level_cbm'");
      expect(await quantityLimits.check('150', 'CBM', false, false)).toBe(quantityLimits.message('CBM'));
      expect(await quantityLimits.check('100', 'CBM', false, false)).toBeNull();
      expect((await quantityLimits.browserLevels()).CBM).toBe(100);
    } finally {
      await db.execute("UPDATE company_settings SET setting_value = '500' WHERE setting_key = 'quantity_warning_level_cbm'");
    }
    const keys = await db.query("SELECT setting_key, setting_value, category, value_type FROM company_settings WHERE setting_key LIKE 'quantity_warning_level_%' ORDER BY setting_key");
    expect(keys.map((k) => k.setting_key)).toEqual(['quantity_warning_level_cbm', 'quantity_warning_level_pcs', 'quantity_warning_level_rmt', 'quantity_warning_level_sqft', 'quantity_warning_level_sqm', 'quantity_warning_level_ton']);
    expect(keys.map((k) => k.setting_value)).toEqual(['500', '600000', '60000', '300000', '30000', '600']);
    for (const k of keys) {
      expect([k.category, k.value_type]).toEqual(['orders', 'number']);
      expect(settingValueValidator.check('number', '25000', k.setting_key)).toBeNull();
      expect(settingValueValidator.check('number', '0', k.setting_key)).not.toBeNull();
      expect(settingValueValidator.check('number', '1000001', k.setting_key)).not.toBeNull();
      expect(settingLabels.label(k.setting_key)).toMatch(/^Large quantity: confirm a line above/);
      expect(settingLabels.placeholder(k.setting_key, 'number')).toMatch(/^e\.g\. \d+$/);
    }
    // Settings page: the levels' inputs carry min 1 / max 1,000,000.
    const page = (await agent.get('/settings')).text;
    expect(page).toMatch(/min="1" max="1000000" step="1"\s+name="settings\[quantity_warning_level_sqm\]"/);
  });

  it('item 10a: add and edit line need the tick and are audited', async () => {
    const orderId = await order();
    const before = (await lines(orderId)).length;

    expect(await saveLine(orderId, { quantity: '40000', unit: 'SQM' })).toBe(`Product line: ${MESSAGE_SQM}`);
    expect(await lines(orderId)).toHaveLength(before);
    await saveLine(orderId, { quantity: '1000001', unit: 'BOX', confirm_large_quantity: '1' });
    expect(await lines(orderId)).toHaveLength(before);
    await saveLine(orderId, { quantity: '29999', unit: 'SQM' });
    expect(await lines(orderId)).toHaveLength(before + 1);
    expect(await auditRows()).toHaveLength(0);

    await saveLine(orderId, { quantity: '40000', unit: 'SQM', confirm_large_quantity: '1' });
    const all = await lines(orderId);
    expect(all).toHaveLength(before + 2);
    const big = all[all.length - 1];
    const [audit] = await auditRows();
    expect([Number(audit.user_id), audit.entity_type, Number(audit.entity_id), audit.new_value]).toEqual([Number(adminId), 'order_products', Number(big.id), '40000 SQM']);
    expect(audit.reason).toBe('Large quantity confirmed ("I confirm this quantity"): 40000 SQM on line "Granite slab item 10a" — above the 30,000 SQM level (about 20 containers).');

    const page = (await agent.get(`/orders/${orderId}`)).text;
    expect(page).toMatch(/name="confirm_large_quantity" value="1" data-confirm-tick="" checked>/);
    await saveLine(orderId, { quantity: '45000', unit: 'SQM' }, big.id);
    expect(String((await db.queryOne('SELECT quantity FROM order_products WHERE id = :id', { id: big.id })).quantity)).toBe('40000.000');
    await saveLine(orderId, { quantity: '40000', unit: 'SQM', confirm_large_quantity: '1' }, big.id);
    expect(await auditRows()).toHaveLength(1);
    await saveLine(orderId, { quantity: '45000', unit: 'SQM', confirm_large_quantity: '1' }, big.id);
    expect(String((await db.queryOne('SELECT quantity FROM order_products WHERE id = :id', { id: big.id })).quantity)).toBe('45000.000');
    expect(await auditRows()).toHaveLength(2);
  });

  it('item 10a: create order needs the tick per line', async () => {
    const clientId = await createTestClient();
    const [incoterm, currency, preset] = await Promise.all([
      db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'"),
      db.queryOne("SELECT id FROM currencies WHERE code = 'USD'"),
      db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"),
    ]);
    const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS c FROM orders')).c);
    const create = async (confirm) => {
      const p = new URLSearchParams();
      p.append('_csrf', await csrfOf(`/orders/create?client_id=${clientId}`));
      p.append('client_id', String(clientId));
      p.append('incoterm_id', String(incoterm.id));
      p.append('currency_id', String(currency.id));
      p.append('payment_preset_id', String(preset.id));
      for (const [d, q, u, c] of [['Small line item 10a', '10', 'SQM', confirm[0]], ['Large line item 10a', '600', 'CBM', confirm[1]]]) {
        p.append('product_description[]', d);
        p.append('product_hs_code[]', '680293');
        p.append('product_quantity[]', q);
        p.append('product_unit[]', u);
        p.append('product_unit_price[]', '5.00');
        p.append('product_confirm_large_quantity[]', c);
      }
      return agent.post('/orders').type('form').send(p.toString());
    };
    const before = await count();
    const res = await create(['0', '0']);
    expect(await count()).toBe(before);
    expect(await alertOf(res)).toBe(`Product line 2: ${quantityLimits.message('CBM')}`);
    await create(['0', '1']);
    expect(await count()).toBe(before + 1);
    expect(await auditRows()).toHaveLength(1);
  });

  it('item 10a: browser attributes on every line form', async () => {
    const orderId = await order();
    const create = (await agent.get('/orders/create')).text;
    const show = (await agent.get(`/orders/${orderId}`)).text;
    const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    for (const [html, name, unit] of [[create, 'product_quantity[]', 'product_unit[]'], [show, 'quantity', 'unit']]) {
      expect(html).toMatch(new RegExp(`name="${esc(name)}"[^>]*data-max-value="1000000"[^>]*data-confirm-above="\\{[^"]*&quot;SQM&quot;:30000[^"]*&quot;CBM&quot;:500[^"]*\\}" data-confirm-unit="${esc(unit)}"`));
      expect(html).toContain('data-confirm-message="This is unusually large for one line (more than about 20 containers of {unit}).');
      expect(html).toContain('I confirm this quantity</label>');
      expect(html).not.toContain('data-max-value="10000000"');
    }
    expect(create).toContain('<input type="hidden" name="product_confirm_large_quantity[]" value="0">');
    expect(create).toContain('<input type="checkbox" data-confirm-tick="product_confirm_large_quantity[]" >');
    for (const js of [path.join(__dirname, '../../public/js/app.js'), path.join(__dirname, '../../../../PHP_MySQL_Version/public_html/assets/js/app.js')]) {
      const src = fs.readFileSync(js, 'utf8');
      expect(src).toContain('var confirmState = confirmAboveState(el);');
      expect(src).toContain('initConfirmAbove(); // item 10a');
    }
  });
});
