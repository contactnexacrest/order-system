'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingService = require('../../src/services/pricingService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const documentGenerationService = require('../../src/services/documentGenerationService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, pinWorkedExamplePricing, configureProductForQuoting, baseSpecJson } = require('../support/fixtures');

/**
 * Port of OrderLinePricingTest.php — owner decision D-245, phase P2 (4 Nov 2026):
 * prices on order lines. Picking a product fills the quote price of whoever sells
 * the order; below that seller's floor the line goes to the MD, who may approve it
 * down to the minimum price and never below; the PI waits for it; a changed price
 * voids the approval; the PI fixes the figures on the line. D-240's reference
 * price is gone.
 */
describe('Order line pricing (D-245, P2)', () => {
  const PASSWORD = 'JestLinePricing!1';

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    // QA 13 Nov 2026 (D-347 stale tests): the figures below are the D-245 worked example
    // (margin 20 %, other costs 2.5 %) — every pricing setting is pinned, whatever ran before.
    await pinWorkedExamplePricing();
    // Team incentive program on: staff floor USD 1,287.50 sits above the minimum USD 1,250.00.
    await db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
  });

  afterEach(async () => {
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
    }
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-lineprice-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
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

  function today() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  }

  /** A product whose ₹80,000 supplier price is used for pricing: cost 1,000.00, minimum 1,250.00, staff floor 1,287.50, quote 1,326.13. */
  async function product() {
    const admin = await user('Admin');
    const id = await productRepository.create({ name: `P2 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM' }, admin.id);
    const s = await productSupplierRepository.create({ product_id: id, supplier_name: 'Sri Balaji Granites', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin.id);
    await pricingRepository.setSupplierDates(s, today(), null);
    // D-361: only a configured product with an active supplier offer can be picked on an order.
    await configureProductForQuoting(id, admin.id);
    return id;
  }

  /** A client tied to an associate on a fixed level (5% unless given). */
  async function associateClient(levelName = 'Level 2') {
    const level = await db.queryOne('SELECT id FROM commission_levels WHERE name = :n', { n: levelName });
    const associate = (await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES ('P2 Associate B', 'active', 'fixed', :l)", { l: level.id })).insertId;
    const client = await createTestClient();
    await db.execute('UPDATE clients SET associate_id = :a WHERE id = :c', { a: associate, c: client });
    return client;
  }

  async function line(productId, price, client = null, currency = 'USD', assignees = null) {
    const orderId = await createTestOrder(client || (await createTestClient()), 'FOB', 'Standard — New Buyer', currency, null, null, assignees);
    const lineId = await orderProductRepository.add(orderId, 1, 'Black Galaxy block', null, null, '10', false, 'CBM', price, '25161100');
    await orderProductRepository.setCatalogProduct(lineId, productId);
    return [orderId, lineId];
  }

  const lineStatus = async (orderId, lineId) => (await orderLinePricing.forOrder(orderId)).lines[lineId].status;

  it('the picker carries each seller\'s quote and floor', async () => {
    const productId = await product();
    const prices = (await orderLinePricing.pickerPrices([{ id: productId }]))[productId];
    expect(prices.staff).toEqual([1326.13, 1287.5]);
    const level2 = (await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 2'")).id;
    expect(prices[`L${level2}`]).toEqual([1395.92, 1355.26]);

    const client = await associateClient();
    const associateId = (await db.queryOne('SELECT associate_id FROM clients WHERE id = :c', { c: client })).associate_id;
    const keys = await orderLinePricing.clientKeys([{ id: client, associate_id: associateId }, { id: 0, associate_id: null }]);
    expect([keys[client], keys[0]]).toEqual([`L${level2}`, 'staff']);
  });

  it('each line is checked against the floor of whoever sells', async () => {
    const productId = await product();
    const [staffOrder, staffLine] = await line(productId, '1300.00');
    expect(await lineStatus(staffOrder, staffLine)).toBe(orderLinePricing.OK);
    const f = (await orderLinePricing.forOrder(staffOrder)).lines[staffLine].figures;
    expect([f.cost, f.minimum, f.floor, f.quote, f.sold_by]).toEqual([1000, 1250, 1287.5, 1326.13, 'staff']);

    const [assocOrder, assocLine] = await line(productId, '1300.00', await associateClient());
    expect(await lineStatus(assocOrder, assocLine)).toBe(orderLinePricing.BELOW);
    expect((await orderLinePricing.forOrder(assocOrder)).lines[assocLine].figures.floor).toBe(1355.26);

    const [o3, l3] = await line(productId, '1200.00');
    expect(await lineStatus(o3, l3)).toBe(orderLinePricing.BELOW_MINIMUM);
    const bare = await productRepository.create({ name: `P2 unpriced ${crypto.randomBytes(3).toString('hex')}`, specifications: 'x', hs_code: '25161100' }, (await user('Admin')).id);
    const [o4, l4] = await line(bare, '1300.00');
    expect(await lineStatus(o4, l4)).toBe(orderLinePricing.NO_PRICE);
    const o5 = await createTestOrder(await createTestClient());
    const l5 = await orderProductRepository.add(o5, 1, 'Typed by hand', null, null, '1', false, 'CBM', '1.00', '25161100');
    expect(await lineStatus(o5, l5)).toBe(orderLinePricing.UNCHECKED);
    const [o6, l6] = await line(productId, '1.00', null, 'EUR');
    expect(await lineStatus(o6, l6)).toBe(orderLinePricing.UNCHECKED);
  });

  it('the server refuses a price below the minimum and sends a below-floor line to the MD', async () => {
    const productId = await product();
    const sales = await user('Export Executive');
    const md = await user('Managing Director');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales.id]);
    const agent = await login(sales);
    const base = { catalog_product_id: String(productId), description: 'Black Galaxy block', quantity: '10', unit: 'CBM', line_type: 'goods', hs_code: '25161100',
      spec_json: await baseSpecJson(productId) };

    let msg = await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { ...base, unit_price: '1240.00' });
    expect(msg).toContain('below what NexaCrest can accept');
    expect(msg).not.toContain('1,250');
    expect(await orderProductRepository.forOrder(orderId)).toEqual([]);

    msg = await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { ...base, unit_price: '1270.00' });
    expect(msg).toContain('sent to the MD for approval');
    const saved = (await orderProductRepository.forOrder(orderId))[0];
    expect(saved.price_approval_requested_at).not.toBeNull();
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'line_price_approval' AND related_order_id = :o", { u: md.id, o: orderId })).n)).toBe(1);

    expect(await orderLinePricing.piBlockReason(orderId)).toContain('below the lowest price');
    await expect(documentGenerationService.generate(orderId, 'PI', md.id, null, false, true)).rejects.toThrow('Line 1');
  });

  it('the MD approves down to the minimum only, and a price change voids it', async () => {
    const productId = await product();
    const md = await user('Managing Director');
    const [orderId, lineId] = await line(productId, '1270.00');

    expect(await orderLinePricing.approve(orderId, lineId, md.id, 'short')).toBe('Give a reason of at least 10 characters for approving this price.');
    const view = await orderLinePricing.approvalView(orderId, lineId);
    expect([view.effect.margin_pct, view.effect.short_per_unit, view.effect.reserve_line, view.effect.after_line]).toEqual([21.3, 17.5, 375, 200]);
    expect(await orderLinePricing.approve(orderId, lineId, md.id, 'Repeat buyer, matched a competitor offer')).toBeNull();
    expect(await lineStatus(orderId, lineId)).toBe(orderLinePricing.APPROVED);
    const row = await orderProductRepository.find(lineId);
    expect([String(row.price_approved_unit_price), String(row.price_approval_incentive_cut), Number(row.price_approval_commission_zero)]).toEqual(['1270.00', '175.00', 0]);
    expect(await orderLinePricing.piBlockReason(orderId)).toBeNull();

    await db.execute('UPDATE order_products SET unit_price = 1260.00 WHERE id = :id', { id: lineId });
    await orderLinePricing.afterSave(lineId, row, md.id);
    expect(await lineStatus(orderId, lineId)).toBe(orderLinePricing.BELOW);
    expect((await orderProductRepository.find(lineId)).price_approved_at).toBeNull();
    expect(await orderLinePricing.piBlockReason(orderId)).not.toBeNull();

    await db.execute('UPDATE order_products SET unit_price = 1249.99 WHERE id = :id', { id: lineId });
    expect(await orderLinePricing.approve(orderId, lineId, md.id, 'Trying to approve below the minimum')).toContain('nobody can approve it, not even the MD');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'LINE_PRICE_APPROVAL_REFUSED' AND entity_id = :id", { id: lineId })).n)).toBe(1);

    const [o2, l2] = await line(productId, '1300.00', await associateClient());
    expect(await orderLinePricing.approve(o2, l2, md.id, 'Trial container for a new distributor')).toBeNull();
    expect(Number((await orderProductRepository.find(l2)).price_approval_commission_zero)).toBe(1);
  });

  it('only the MD may approve, and the MD may refuse', async () => {
    const productId = await product();
    const [orderId, lineId] = await line(productId, '1270.00');
    const ed = await user('Executive Director');
    const sales = await user('Export Executive');
    const edPerms = await require('../../src/repositories/permissionRepository').effectivePermissions(ed.id, ed.roleId);
    expect(!!edPerms.manage_pricing).toBe(false);
    await orderLinePricing.requestApproval(orderId, lineId, sales.id);
    const md = await user('Managing Director');
    expect(await orderLinePricing.refuse(orderId, lineId, md.id, 'Too low for this buyer')).toBeNull();
    expect((await orderProductRepository.find(lineId)).price_approval_requested_at).toBeNull();
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'line_price_refused'", { u: sales.id })).n)).toBe(1);
  });

  it('the PI fixes the figures on the line', async () => {
    const productId = await product();
    const [orderId, lineId] = await line(productId, '1300.00');
    const admin = await user('Admin');
    await documentGenerationService.generate(orderId, 'PI', admin.id, null, false, true);
    const snapshot = JSON.parse((await orderProductRepository.find(lineId)).price_snapshot);
    expect([snapshot.floor, snapshot.minimum, snapshot.sold_by]).toEqual([1287.5, 1250, 'staff']);

    await db.execute("UPDATE pricing_settings SET setting_value = '30.00' WHERE setting_key = 'margin_pct'");
    const check = (await orderLinePricing.forOrder(orderId)).lines[lineId];
    expect([check.status, check.fixed, check.figures.floor]).toEqual([orderLinePricing.OK, true, 1287.5]);
    await db.execute('UPDATE order_products SET unit_price = 1280.00 WHERE id = :id', { id: lineId });
    expect(await lineStatus(orderId, lineId)).toBe(orderLinePricing.BELOW);
  });

  it('the order page shows each person only what they may see', async () => {
    const productId = await product();
    const sales = await user('Export Executive');
    const md = await user('Managing Director');
    const logistics = await user('Logistics Executive');
    const [orderId] = await line(productId, '1270.00', null, 'USD', [sales.id, md.id, logistics.id]);

    let page = (await (await login(sales)).get(`/orders/${orderId}`)).text;
    expect(page).toContain('Quote 1,326.13 · lowest 1,287.50');
    expect(page).toContain('data-floor="1287.50"');
    expect(page).toContain('Below your lowest price');
    expect(page).toContain('Ask MD to approve line 1');
    expect(page).not.toContain('minimum 1,250.00');
    expect(page).not.toContain('1,000.00');

    page = (await (await login(md)).get(`/orders/${orderId}`)).text;
    expect(page).toContain('minimum 1,250.00');
    expect(page).toContain('Review and approve line 1');

    page = (await (await login(logistics)).get(`/orders/${orderId}`)).text;
    expect(page).not.toContain('1,287.50');
    expect(page).not.toContain('data-floor=');
  });

  it('goods lines on a USD order must be picked from Products while the setting is on', async () => {
    const productId = await product();
    const sales = await user('Export Executive');
    const agent = await login(sales);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales.id]);
    const hand = { description: 'Granite typed by hand', quantity: '10', unit: 'CBM', unit_price: '1300.00', line_type: 'goods', hs_code: '25161100' };

    expect((await pricingService.settings()).require_product_pick).toBe(true);
    expect(await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, hand)).toContain('Pick this goods line from Products');
    expect(await orderProductRepository.forOrder(orderId)).toEqual([]);
    expect((await agent.get(`/orders/${orderId}`)).text).toContain('data-required-when="line_type=goods" data-required-when-message="Pick this goods line from Products');
    const usd = await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'");
    expect((await agent.get('/orders/create')).text).toContain(`data-required-when="currency_id=${usd.id}"`);

    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { description: 'Fumigation', quantity: '1', unit: 'CBM', unit_price: '40.00', line_type: 'service', sac_code: '998719' });
    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { description: 'Pallets', quantity: '1', unit: 'CBM', unit_price: '25.00', line_type: 'charge' });
    await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { catalog_product_id: String(productId), spec_json: await baseSpecJson(productId), ...hand });
    expect((await orderProductRepository.forOrder(orderId)).map((l) => l.line_type)).toEqual(['service', 'charge', 'goods']);

    const eur = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'EUR', null, null, [sales.id]);
    await post(agent, `/orders/${eur}`, `/orders/${eur}/products`, hand);
    expect((await orderProductRepository.forOrder(eur)).length).toBe(1);

    await db.execute("UPDATE pricing_settings SET setting_value = '0' WHERE setting_key = 'require_product_pick'");
    try {
      await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, hand);
      expect((await orderProductRepository.forOrder(orderId)).length).toBe(4);
      expect((await agent.get(`/orders/${orderId}`)).text).not.toContain('data-required-when-message="Pick this goods line from Products');
    } finally {
      await db.execute("UPDATE pricing_settings SET setting_value = '1' WHERE setting_key = 'require_product_pick'");
    }
  });

  // SUI-005: a picked product that cannot be quoted is refused with the real reason, not "typed by hand".
  it('SUI-005: a picked product that is not quotable is refused with the real reason', async () => {
    const productId = await product();
    const name = (await productRepository.find(productId)).name;
    const sales = await user('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales.id]);
    const agent = await login(sales);
    const body = { catalog_product_id: String(productId), description: 'Black Galaxy block', quantity: '10', unit: 'CBM', unit_price: '1300.00',
      line_type: 'goods', hs_code: '25161100', spec_json: await baseSpecJson(productId) };

    // The base price is no longer fixed (not configured) — e.g. changed after the page was opened.
    await db.execute('UPDATE catalog_products SET configured_base_follows_supplier = 1, configured_base_price_inr = NULL WHERE id = :id', { id: productId });
    let msg = await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, body);
    expect(msg).toContain(`"${name}" cannot be quoted yet: Not configured yet — ask the product manager.`);
    expect(msg).not.toContain('typed by hand');
    expect(await orderProductRepository.forOrder(orderId)).toEqual([]);

    // Configured again but switched Inactive.
    await configureProductForQuoting(productId, (await user('Admin')).id);
    await db.execute('UPDATE catalog_products SET is_active = 0 WHERE id = :id', { id: productId });
    msg = await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, body);
    expect(msg).toContain(`"${name}" cannot be quoted yet: it is Inactive — ask the product manager to switch it Active.`);

    // Typed by hand (no product posted): the D-245 P2 message as before.
    msg = await post(agent, `/orders/${orderId}`, `/orders/${orderId}/products`, { ...body, catalog_product_id: '' });
    expect(msg).toContain('Pick this goods line from Products');
    expect(await orderProductRepository.forOrder(orderId)).toEqual([]);
  });

  it('only the MD changes the setting, and a form without it keeps it', async () => {
    const md = await login(await user('Managing Director'));
    const base = { margin_pct: '20.00', other_cost_pct: '2.50', pricing_fx_rate: '82.0000', buffer_pct: '3.00', incentive_mode: 'pct', incentive_value: '15', stale_days: '60' };
    try {
      await post(md, '/pricing/settings', '/pricing/settings', base);
      expect((await pricingService.settings()).require_product_pick).toBe(true);
      await post(md, '/pricing/settings', '/pricing/settings', { ...base, require_product_pick: '0' });
      expect((await pricingService.settings()).require_product_pick).toBe(false);
      expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'PRICING_SETTING_CHANGED' AND field_name = 'require_product_pick' AND new_value = '0'")).n)).toBeGreaterThanOrEqual(1);
      expect((await md.get('/pricing/settings')).text).toContain('Goods lines must be picked from Products');
    } finally {
      await db.execute("UPDATE pricing_settings SET setting_value = '1' WHERE setting_key = 'require_product_pick'");
    }
  });

  it('the Quotation waits like the PI', async () => {
    const productId = await product();
    const md = await user('Managing Director');
    const [orderId, lineId] = await line(productId, '1270.00');
    expect(await orderLinePricing.documentBlockReason(orderId, 'QT')).toContain('before the Quotation');
    await expect(documentGenerationService.generate(orderId, 'QT', md.id, null, false, true))
      .rejects.toThrow('Line 1 is below the lowest price for this order — raise it, or ask the MD to approve it, before the Quotation');
    expect(await orderLinePricing.approve(orderId, lineId, md.id, 'Agreed with the buyer on the call')).toBeNull();
    const r = await documentGenerationService.generate(orderId, 'QT', md.id, null, false, true);
    expect(r.document_id).toBeTruthy();
    const bare = await productRepository.create({ name: `P2 unpriced ${crypto.randomBytes(3).toString('hex')}`, specifications: 'x', hs_code: '25161100' }, md.id);
    const [o2] = await line(bare, '1300.00');
    expect(await orderLinePricing.documentBlockReason(o2, 'QT')).toContain('has no price set for its product — the MD must approve it before the Quotation');
  });

  it('the reference price of D-240 is gone', async () => {
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME IN ('reference_price','reference_currency')")).n)).toBe(0);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_price_approvals'")).n)).toBe(0);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM permissions WHERE permission_key = 'approve_price_discount'")).n)).toBe(0);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM company_settings WHERE setting_key LIKE 'ref_price%'")).n)).toBe(0);
    expect(fs.existsSync(path.join(__dirname, '../../src/services/referencePrice.js'))).toBe(false);
    expect(fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8')).not.toContain("'/orders/:id/price-approval'");
    expect(zohoBooksService.itemPayload({ name: 'Granite', specifications: 'Slab', hs_code: '68022310' }, true).rate).toBe(0);
  });
});
