'use strict';

// Capture the rendered HTML instead of launching a browser.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const productRepository = require('../../src/repositories/productRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const referencePrice = require('../../src/services/referencePrice');
const selfApprovalRules = require('../../src/services/selfApprovalRules');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoMasterDataSync = require('../../src/services/zohoMasterDataSync');
const zohoInvoiceData = require('../../src/services/zohoInvoiceData');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ReferencePriceTest.php. Owner decision 2 Nov 2026 ("reference price
 * yes with approval", D-240): a reference selling price on Products-menu
 * products, "Pick from Products" on order lines, the configurable note, the
 * MD/ED approval before the PI, and the Zoho item rate / invoice item link.
 */
describe('Reference selling price with discount approval (D-240)', () => {
  const PASSWORD = 'JestRefPrice!1';

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-ref-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
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

  async function product(price = '45.00', currency = 'USD', unit = 'SQFT', name = 'Ref Granite') {
    const admin = await user('Admin');
    return productRepository.create({
      name: `${name} ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite slab, polished, 20 mm', hs_code: '68022310',
      default_factory_cost: 1234.56, reference_price: price, reference_currency: currency, reference_unit: unit,
    }, admin.id);
  }

  async function orderLine(productId, price, setBy = null) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const lineId = await orderProductRepository.add(orderId, 1, 'Ref Granite', null, null, '100', false, 'SQFT', price, '68022310');
    await orderProductRepository.setCatalogProduct(lineId, productId);
    if (setBy !== null) await orderProductRepository.setPriceSetBy(lineId, setBy);
    return [orderId, lineId];
  }

  async function count(action, orderId) {
    return Number((await db.queryOne('SELECT COUNT(*) AS c FROM audit_log WHERE action_type = :a AND entity_id = :o', { a: action, o: orderId })).c);
  }

  it('only selling-visible people see or edit the reference price', async () => {
    const productId = await product('77.31', 'USD');
    const sales = await login(await user('Export Executive'));
    let page = (await sales.get(`/products/${productId}`)).text;
    expect(page).toContain('Reference Selling Price');
    expect(page).toContain('USD 77.31 per SQFT');

    const procurementUser = await user('Procurement Executive');
    const procurement = await login(procurementUser);
    page = (await procurement.get(`/products/${productId}`)).text;
    expect(page).not.toContain('77.31');
    expect(page).not.toContain('Reference Selling Price');

    // A Procurement manager saving the product keeps the reference price (nothing they post counts).
    await db.execute(`INSERT INTO role_permissions (role_id, permission_id)
      SELECT :r, p.id FROM permissions p WHERE p.permission_key = 'manage_product_catalog'
      AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = :r2 AND x.permission_id = p.id)`, { r: procurementUser.roleId, r2: procurementUser.roleId });
    const fresh = await login(procurementUser);
    const edit = (await fresh.get(`/products/${productId}/edit`)).text;
    expect(edit).not.toContain('reference_price');
    const csrf = edit.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await fresh.post(`/products/${productId}/update`).type('form').send({
      _csrf: csrf, name: 'Ref Granite', specifications: 'Granite slab', hs_code: '68022310', reference_price: '1.00', reference_currency: 'EUR', is_active: '1',
    });
    const row = await productRepository.find(productId);
    expect([row.name, String(row.reference_price), row.reference_currency]).toEqual(['Ref Granite', '77.31', 'USD']); // saved, reference kept
  });

  it('validates and audits the reference price', async () => {
    const productId = await product();
    const admin = await login(await user('Admin'));
    const body = { name: 'Ref Granite', specifications: 'Granite slab', hs_code: '68022310', reference_price: '50.5', reference_currency: '', is_active: '1' };
    expect(await post(admin, `/products/${productId}/edit`, `/products/${productId}/update`, body)).toBe('Choose the currency of the reference selling price.');
    expect(String((await productRepository.find(productId)).reference_price)).toBe('45.00');

    await post(admin, `/products/${productId}/edit`, `/products/${productId}/update`, { ...body, reference_currency: 'INR', reference_unit: 'SQM' });
    const row = await productRepository.find(productId);
    expect([String(row.reference_price), row.reference_currency, row.reference_unit]).toEqual(['50.50', 'INR', 'SQM']);
    const audit = await db.queryOne("SELECT old_value, new_value FROM audit_log WHERE action_type = 'PRODUCT_REFERENCE_PRICE_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: productId });
    expect([audit.old_value, audit.new_value]).toEqual(['USD 45.00 per SQFT', 'INR 50.50 per SQM']);
  });

  it('pick from Products stores the product and the note follows the settings', async () => {
    const productId = await product('45.00', 'USD');
    const adminUser = await user('Admin');
    const admin = await login(adminUser);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await post(admin, `/orders/${orderId}`, `/orders/${orderId}/products`, {
      catalog_product_id: String(productId), description: 'Ref Granite', quantity: '10', unit: 'SQFT', unit_price: '42.00', line_type: 'goods', hs_code: '68022310',
    });
    const line = (await orderProductRepository.forOrder(orderId))[0];
    expect([Number(line.catalog_product_id), Number(line.price_set_by)]).toEqual([productId, adminUser.id]);

    const p = await productRepository.find(productId);
    const note = async (price) => referencePrice.note(referencePrice.lineDifference({ unit_price: price }, p, 'USD'), await referencePrice.settings());
    expect(await note('43.00')).toBeNull(); // 4.4% below is under the 5% note level
    expect(await note('42.00')).toEqual({ kind: 'note', text: '6.7% below reference price (USD 45.00 per SQFT)' });
    expect(await note('36.00')).toEqual({ kind: 'warning', text: '⚠ 20% below reference price (USD 45.00 per SQFT)' });
    expect(await note('50.00')).toEqual({ kind: 'above', text: '11.1% above reference price (USD 45.00 per SQFT)' });
    expect(referencePrice.lineDifference({ unit_price: '36' }, p, 'EUR')).toBeNull(); // a different currency is not compared

    await companySettingsRepository.set('ref_price_note_pct', '8');
    expect(await note('42.00')).toBeNull();
    await companySettingsRepository.set('ref_price_note_enabled', '0');
    expect(await note('36.00')).toBeNull();
    await companySettingsRepository.set('ref_price_note_enabled', '1');
    await companySettingsRepository.set('ref_price_note_pct', '5');

    // The order page shows the note to Sales-side people, never to Procurement.
    await db.execute('UPDATE order_products SET unit_price = 36, fob_value = 360 WHERE order_id = :o', { o: orderId });
    const page = (await admin.get(`/orders/${orderId}`)).text;
    expect(page).toContain('⚠ 20% below reference price (USD 45.00 per SQFT)');
    expect(page).toContain('Pick from Products');
    expect(page).toContain('data-price="45.00"');
  });

  it('refuses the PI until approved, and the approval lapses on a further drop', async () => {
    const productId = await product('45.00', 'USD');
    const sales = await user('Export Executive');
    const [orderId, lineId] = await orderLine(productId, '36.00', sales.id);
    const md = await user('Managing Director');

    await expect(documentGenerationService.generate(orderId, 'PI', md.id, null, false, true))
      .rejects.toThrow('Line 1 is 20% below its reference price — an MD/ED must approve the discount first (order page → Price check).');

    expect(await referencePrice.approve(orderId, md.id, 'ok')).toBe('Give a reason of at least 10 characters for approving the discount.');
    expect(await referencePrice.approve(orderId, md.id, 'Volume order, agreed on call')).toBeNull();
    expect(await referencePrice.piBlockReason(orderId)).toBeNull();
    expect(await count('PRICE_DISCOUNT_APPROVED', orderId)).toBe(1);

    // Raising the price again keeps the approval; dropping it further lapses it.
    await db.execute('UPDATE order_products SET unit_price = 38 WHERE id = :id', { id: lineId });
    expect(await referencePrice.piBlockReason(orderId)).toBeNull();
    await db.execute('UPDATE order_products SET unit_price = 30 WHERE id = :id', { id: lineId });
    expect(await referencePrice.piBlockReason(orderId, md.id)).toContain('Line 1 is 33.3% below its reference price');
    expect(await referencePrice.currentApproval(orderId)).toBeNull();
    expect(await count('PRICE_DISCOUNT_LAPSED', orderId)).toBe(1);

    // A new line below the warning level also needs a fresh approval.
    expect(await referencePrice.approve(orderId, md.id, 'Second look, still agreed')).toBeNull();
    const second = await orderProductRepository.add(orderId, 2, 'Ref Granite B', null, null, '5', false, 'SQFT', '35.00', '68022310');
    await orderProductRepository.setCatalogProduct(second, productId);
    expect(await referencePrice.piBlockReason(orderId, md.id)).toContain('Line 2 is');

    // The controller refuses with the same words and points to Price check; the approval card posts the approval.
    const agent = await login(md);
    expect(await post(agent, `/orders/${orderId}`, `/orders/${orderId}/documents/generate`, { document_type: 'PI' })).toContain('must approve the discount first');
    expect(await post(agent, `/orders/${orderId}`, `/orders/${orderId}/price-approval`, { reason: 'Both lines agreed with the buyer' }))
      .toBe('Discount approved — the Proforma Invoice can now be generated.');
    expect(await referencePrice.piBlockReason(orderId)).toBeNull();
  });

  it('who set the discount approves only when the self-approval rule allows', async () => {
    const productId = await product('45.00', 'USD');
    const md = await user('Managing Director');
    const [orderId] = await orderLine(productId, '36.00', md.id);
    expect(Object.keys(selfApprovalRules.RULES)).toContain('price_discount');
    const saved = await selfApprovalRules.roleIds('price_discount');
    try {
      await selfApprovalRules.set('price_discount', []);
      expect(await referencePrice.approve(orderId, md.id, 'Agreed with the buyer')).toBe('You set a discounted price on this order — a different MD/ED must approve the discount.');
      await selfApprovalRules.set('price_discount', [md.roleId]);
      expect(await referencePrice.approve(orderId, md.id, 'Agreed with the buyer')).toBeNull();
      expect(await count('PRICE_DISCOUNT_SELF_APPROVED', orderId)).toBe(1);
    } finally {
      await selfApprovalRules.set('price_discount', saved);
    }
  });

  it('the Zoho item carries an INR rate or the reference in its description, and invoice lines link the item', async () => {
    const inr = await productRepository.find(await product('3500.00', 'INR', 'SQM', 'Ref INR'));
    const usd = await productRepository.find(await product('45.00', 'USD', 'SQFT', 'Ref USD'));
    const inrPayload = zohoBooksService.itemPayload(inr, true);
    expect(Number(inrPayload.rate)).toBe(3500);
    expect(inrPayload.description).not.toContain('Reference price');
    const usdPayload = zohoBooksService.itemPayload(usd, true);
    expect(usdPayload.rate).toBe(0);
    expect(usdPayload.description).toContain('Reference price: USD 45.00 per SQFT');
    expect(JSON.stringify(usdPayload)).not.toContain('1234.56'); // no cost is ever sent
    expect(zohoMasterDataSync.productHash(usd)).not.toBe(zohoMasterDataSync.productHash({ ...usd, reference_price: '46.00' }));

    await db.execute("UPDATE catalog_products SET zoho_item_id = 'ITEM-77' WHERE id = :id", { id: usd.id });
    const [orderId] = await orderLine(Number(usd.id), '45.00');
    const lines = await zohoInvoiceData.ciLines(orderId, 'Commercial Invoice');
    expect(lines[0].item_id).toBe('ITEM-77');
  });
});
