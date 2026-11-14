'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const orderLinePricing = require('../../src/services/orderLinePricing');
const { createTestClient, createTestOrder, pinWorkedExamplePricing, resetPricingSettings, configureProductForQuoting } = require('../support/fixtures');

/**
 * Owner decision D-374 (14 Nov 2026): each order line remembers the pricing rate (₹ per USD) and the day its
 * price was set, until the approved Quotation fixes the prices; the order page shows it under the price and
 * offers "Use today's price". PHP twin: tests/Integration/OrderLinePricedRateTest.php.
 */
describe('D-374: the rate a line was priced at', () => {
  const PASSWORD = 'JestPricedRate!1';
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    await pinWorkedExamplePricing({ fx_mode: 'manual', pricing_fx_rate: '90.0000' });
  });

  afterEach(async () => {
    await resetPricingSettings();
  });

  const rate = (r) => db.execute("UPDATE pricing_settings SET setting_value = :r WHERE setting_key = 'pricing_fx_rate'", { r });
  const today = () => {
    const d = new Date();
    return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
  };
  const isoToday = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  };

  async function admin() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-pricedrate-${crypto.randomBytes(4).toString('hex')}@email.com`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function login(u) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  test('the rate is recorded when the price is set, kept on a copy, and not after the prices are fixed', async () => {
    const u = await admin();
    const orderId = await createTestOrder(await createTestClient());
    const line = await orderProductRepository.add(orderId, await orderProductRepository.nextLineNo(orderId), 'Black Galaxy block', null, null, '10', false, 'CBM', '1500.00', '25161100');
    await orderLinePricing.afterSave(line, null, u.id);
    let row = await orderProductRepository.find(line);
    expect(String(row.priced_fx_rate)).toBe('90.0000');
    expect(row.priced_at).toBeTruthy();
    expect(orderLinePricing.pricedAtText(row)).toBe(`Priced at ₹90.00 per USD on ${today()}`);

    await rate('91.5000');
    const before = await orderProductRepository.find(line);
    await orderLinePricing.afterSave(line, before, u.id);
    expect(String((await orderProductRepository.find(line)).priced_fx_rate)).toBe('90.0000');

    await orderProductRepository.setUnitPrice(line, '1490.00');
    await orderLinePricing.afterSave(line, before, u.id);
    row = await orderProductRepository.find(line);
    expect(String(row.priced_fx_rate)).toBe('91.5000');
    expect(String(row.fob_value)).toBe('14900.00');

    await rate('93.0000');
    const copy = await orderProductRepository.duplicate(line);
    await orderLinePricing.afterSave(copy, null, u.id);
    expect(String((await orderProductRepository.find(copy)).priced_fx_rate)).toBe('91.5000');

    await db.execute('UPDATE orders SET prices_fixed_at = NOW(), pricing_fx_rate_at_qt = 91.5 WHERE id = :o', { o: orderId });
    const before2 = await orderProductRepository.find(line);
    await orderProductRepository.setUnitPrice(line, '1480.00');
    await orderLinePricing.afterSave(line, before2, u.id);
    expect(String((await orderProductRepository.find(line)).priced_fx_rate)).toBe('91.5000');

    expect(orderLinePricing.pricedAtText({ unit_price: '10.00', priced_fx_rate: null, priced_at: null })).toBe('Rate not recorded');
    expect(orderLinePricing.pricedAtText({ unit_price: null, priced_fx_rate: null, priced_at: null })).toBe('');
  });

  test('"Use today\'s price" takes the quote and records today\'s rate; refused once the prices are fixed', async () => {
    const u = await admin();
    const productId = await productRepository.create({ name: `D374 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM' }, u.id);
    const s = await productSupplierRepository.create({ product_id: productId, supplier_name: 'TEST-Supplier', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, u.id);
    await pricingRepository.setSupplierDates(s, isoToday(), null);
    await configureProductForQuoting(productId, u.id);
    const orderId = await createTestOrder(await createTestClient());
    const line = await orderProductRepository.add(orderId, 1, 'Black Galaxy block', null, null, '10', false, 'CBM', '1500.00', '25161100');
    await orderProductRepository.setCatalogProduct(line, productId);
    await rate('92.0000');

    const quote = (await orderLinePricing.forOrder(orderId)).lines[line].figures.quote;
    const agent = await login(u);
    const page = await agent.get(`/orders/${orderId}`);
    expect(page.text).toContain("Use today's price");
    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post(`/orders/${orderId}/products/${line}/todays-price`).type('form').send({ _csrf: csrf, reason: '' });

    const row = await orderProductRepository.find(line);
    expect(String(row.unit_price)).toBe(require('../../src/helpers/money').round2(Number(quote)).toFixed(2));
    expect(String(row.priced_fx_rate)).toBe('92.0000');
    const log = await db.queryOne("SELECT old_value, new_value FROM audit_log WHERE action_type = 'ORDER_LINE_TODAYS_PRICE' AND entity_id = :id", { id: line });
    expect([log.old_value, log.new_value]).toEqual(['1500.00', String(row.unit_price)]);
    expect((await agent.get(`/orders/${orderId}`)).text).toContain(`Priced at ₹92.00 per USD on ${today()}`);

    await db.execute('UPDATE orders SET prices_fixed_at = NOW(), pricing_fx_rate_at_qt = 92 WHERE id = :o', { o: orderId });
    await orderProductRepository.setUnitPrice(line, '1500.00');
    await agent.post(`/orders/${orderId}/products/${line}/todays-price`).type('form').send({ _csrf: csrf, reason: '' });
    expect(String((await orderProductRepository.find(line)).unit_price)).toBe('1500.00');
  });
});
