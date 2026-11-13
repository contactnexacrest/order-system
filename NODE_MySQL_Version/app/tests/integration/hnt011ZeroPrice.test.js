'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderReorderRequestRepository = require('../../src/repositories/orderReorderRequestRepository');
const pricingService = require('../../src/services/pricingService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const zohoInvoiceData = require('../../src/services/zohoInvoiceData');
const { priceInput } = require('../../src/helpers/orderLineValidator');
const { createTestClient, createTestOrder, pinWorkedExamplePricing, configureProductForQuoting, baseSpecJson } = require('../support/fixtures');

/**
 * HNT-011 — owner decision 13 Nov 2026 (item 8, option A, confirm 1 + confirm 2):
 * a unit price typed as 0 is a price of 0.00 (free of charge) on both stacks; a blank
 * price still means "no price yet / To Be Confirmed". PHP stored NULL for "0", Node
 * stored 0.00 — this pins Node's behaviour. PHP twin: tests/Integration/Hnt011ZeroPriceTest.php.
 */
describe('HNT-011: a unit price of 0 is 0.00, a blank price is "no price yet"', () => {
  const PASSWORD = 'JestHnt011Zero!1';

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    await pinWorkedExamplePricing();
  });

  afterEach(async () => {
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-hnt011-${crypto.randomBytes(4).toString('hex')}@email.com`;
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

  async function post(agent, from, url, body) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  function today() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  }

  /** cost 1,000.00, minimum 1,250.00, staff floor 1,287.50 (the D-245 worked example). */
  async function product() {
    const admin = await user('Admin');
    const id = await productRepository.create({ name: `HNT011 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM' }, admin.id);
    const s = await productSupplierRepository.create({ product_id: id, supplier_name: 'TEST-Supplier', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin.id);
    await pricingRepository.setSupplierDates(s, today(), null);
    await configureProductForQuoting(id, admin.id);
    return id;
  }

  const stored = async (lineId) => {
    const r = await db.queryOne('SELECT unit_price, fob_value FROM order_products WHERE id = :id', { id: lineId });
    return { unit_price: r.unit_price, fob_value: r.fob_value };
  };

  it('HNT-011: a typed 0 is stored as 0.00 and a blank stays no price (add, edit, repository, duplicate)', async () => {
    const sales = await user('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales.id]);
    const agent = await login(sales);
    const charge = { description: 'TEST-QA charge line', quantity: '1', unit: 'LOT', line_type: 'charge' };

    expect(await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { ...charge, unit_price: '0' })).toContain('Product line added');
    const zero = (await orderProductRepository.forOrder(orderId))[0].id;
    expect(await stored(zero)).toEqual({ unit_price: '0.00', fob_value: '0.00' });
    const zeroRow = await orderProductRepository.find(zero);
    expect(zeroRow.price_approval_requested_at).toBeNull();
    expect(zeroRow.price_set_by).not.toBeNull();

    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { ...charge, description: 'TEST-QA charge line blank', unit_price: '' });
    const blank = (await orderProductRepository.forOrder(orderId))[1].id;
    expect(await stored(blank)).toEqual({ unit_price: null, fob_value: null });

    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products/${blank}`, { ...charge, description: 'TEST-QA charge line blank', unit_price: '0' });
    expect(await stored(blank)).toEqual({ unit_price: '0.00', fob_value: '0.00' });
    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products/${zero}`, { ...charge, unit_price: '' });
    expect(await stored(zero)).toEqual({ unit_price: null, fob_value: null });

    const direct = await orderProductRepository.add(orderId, 9, 'TEST-QA direct', null, null, '2', false, 'LOT', '0', '680293');
    expect(await stored(direct)).toEqual({ unit_price: '0.00', fob_value: '0.00' });
    const copy = await orderProductRepository.duplicate(direct);
    expect(await stored(copy)).toEqual({ unit_price: '0.00', fob_value: '0.00' });
    const numericZero = await orderProductRepository.add(orderId, 11, 'TEST-QA numeric zero', null, null, '2', false, 'LOT', 0, '680293');
    expect(await stored(numericZero)).toEqual({ unit_price: '0.00', fob_value: '0.00' });
    const empty = await orderProductRepository.add(orderId, 10, 'TEST-QA direct blank', null, null, '2', false, 'LOT', '', '680293');
    expect(await stored(empty)).toEqual({ unit_price: null, fob_value: null });
  });

  it('HNT-011: every line price input keeps a zero (helper and reorder lines)', async () => {
    expect([priceInput('0'), priceInput(' 0 '), priceInput(0), priceInput(''), priceInput(null), priceInput(undefined)]).toEqual(['0', '0', '0', null, null, null]);
    const client = await createTestClient();
    const source = await createTestOrder(client);
    const requestId = (await db.execute("INSERT INTO order_reorder_requests (client_id, source_order_id, status) VALUES (:c, :o, 'pending')", { c: client, o: source })).insertId;
    await orderReorderRequestRepository.addProductLine(requestId, 1, 'TEST-QA reorder free line', null, null, '1', false, 'LOT', '0', '680293');
    expect((await db.queryOne('SELECT unit_price FROM order_reorder_request_products WHERE reorder_request_id = :r', { r: requestId })).unit_price).toBe('0.00');
  });

  it('HNT-011: a goods line at 0 gets the price check, a charge line does not', async () => {
    const productId = await product();
    const sales = await user('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales.id]);
    const agent = await login(sales);

    const msg = await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, {
      catalog_product_id: String(productId), description: 'Black Galaxy block', quantity: '10', unit: 'CBM', line_type: 'goods',
      hs_code: '25161100', spec_json: await baseSpecJson(productId), unit_price: '0',
    });
    expect(msg).toContain('below what NexaCrest can accept');
    expect(await orderProductRepository.forOrder(orderId)).toEqual([]);

    const lineId = await orderProductRepository.add(orderId, 1, 'Black Galaxy block', null, null, '10', false, 'CBM', '0', '25161100');
    await orderProductRepository.setCatalogProduct(lineId, productId);
    expect((await orderLinePricing.forOrder(orderId)).lines[lineId].status).toBe(orderLinePricing.BELOW_MINIMUM);
    expect(await orderLinePricing.requestApproval(orderId, lineId, sales.id)).toContain('the MD cannot approve it either');
    await orderProductRepository.softDelete(lineId);

    const blank = await orderProductRepository.add(orderId, 2, 'Black Galaxy block', null, null, '10', false, 'CBM', '', '25161100');
    await orderProductRepository.setCatalogProduct(blank, productId);
    expect((await orderLinePricing.forOrder(orderId)).lines[blank].status).toBe(orderLinePricing.UNCHECKED);

    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { description: 'TEST-QA free pallets', quantity: '1', unit: 'LOT', line_type: 'charge', unit_price: '0' });
    const charge = (await orderProductRepository.forOrder(orderId)).filter((l) => l.line_type === 'charge')[0];
    expect(charge.unit_price).toBe('0.00');
    expect(charge.price_approval_requested_at).toBeNull();
  });

  it('HNT-011: documents and Zoho show a 0.00 line at 0.00; a blank price stays To Be Confirmed', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await orderProductRepository.add(orderId, 1, 'TEST-QA granite', null, null, '2', false, 'CBM', '100.00', '680293');
    const free = await orderProductRepository.add(orderId, 2, 'TEST-QA free sample', null, null, '1', false, 'LOT', '0', '680293');
    await orderProductRepository.setLineType(free, 'charge', null);

    let products = (await documentDataAssembler.assemble(orderId, 'PI')).products;
    expect([products[1].unit_price, products[1].amount]).toEqual(['0.00', '0.00']);

    const lines = await zohoInvoiceData.ciLines(orderId, 'Invoice');
    expect(lines).toHaveLength(2);
    expect([lines[1].rate, lines[1].quantity]).toEqual([0, 1]);

    await orderProductRepository.update(free, 'TEST-QA free sample', null, null, '1', false, 'LOT', null, '680293');
    products = (await documentDataAssembler.assemble(orderId, 'PI')).products;
    expect([products[1].unit_price, products[1].amount]).toEqual(['To Be Confirmed', 'To Be Confirmed']);
    await expect(zohoInvoiceData.ciLines(orderId, 'Invoice')).rejects.toThrow('line 2 has no confirmed quantity or unit price');
  });
});
