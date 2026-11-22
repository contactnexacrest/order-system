'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const marineInsurance = require('../../src/services/marineInsurance');
const fieldValidator = require('../../src/helpers/fieldValidator');
const sopAccess = require('../../src/services/sopAccessService');
const requestContext = require('../../src/helpers/requestContext');
const divisionService = require('../../src/services/divisionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of QaRound10Test.php. QA Round 10 (22 Nov 2026) — the fixes for R10-N01…N10 and the Round-9 defects still
 * open (R9-N01…N05), and owner decision D-405 (CIF insurance recalculated whenever the products or the freight change).
 */
describe('QA Round 10 fixes and D-405', () => {
  afterAll(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.pool.end();
  });

  async function createUser(roleName, divisionId = 1) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-r10-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestRound10Pass!';
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest Round 10 User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id, d: divisionId }
    );
    return { id: result.insertId, email, password };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  async function csrfOf(agent, path) {
    return (await agent.get(path)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  /** A CIF order with one 100 × 45.00 goods line (FOB 4,500), freight 950 and the calculated insurance. */
  async function cifOrder() {
    const order = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
    await orderProductRepository.add(order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
    await db.execute('UPDATE orders SET indicative_freight_amount = 950, indicative_insurance_amount = NULL WHERE id = :id', { id: order });
    await marineInsurance.fillIfEmpty(order);
    await marineInsurance.rememberBasis(order);
    return order;
  }

  async function insurance(orderId) {
    const r = await db.queryOne('SELECT indicative_insurance_amount AS v FROM orders WHERE id = :id', { id: orderId });
    return r.v === null ? null : String(r.v);
  }

  async function editPost(agent, orderId, over) {
    const o = await db.queryOne('SELECT incoterm_id, currency_id, indicative_freight_amount, indicative_insurance_amount FROM orders WHERE id = :id', { id: orderId });
    const csrf = await csrfOf(agent, `/orders/${orderId}/edit`);
    return agent.post(`/orders/${orderId}/edit`).type('form').send({
      _csrf: csrf, incoterm_id: String(o.incoterm_id), currency_id: String(o.currency_id), container_type: '20ft Standard',
      indicative_freight_amount: String(o.indicative_freight_amount ?? ''), indicative_insurance_amount: String(o.indicative_insurance_amount ?? ''),
      ...over,
    });
  }

  it('R10-N02: a typed 0 for the freight or the insurance is kept as 0', async () => {
    const order = await cifOrder();
    const agent = await loginAs(await createUser('Admin'));
    await editPost(agent, order, { indicative_insurance_amount: '0' });
    expect(await insurance(order)).toBe('0.00');
    await editPost(agent, order, { indicative_freight_amount: '0', indicative_insurance_amount: '0' });
    expect(String((await db.queryOne('SELECT indicative_freight_amount AS v FROM orders WHERE id = :id', { id: order })).v)).toBe('0.00');
  });

  it('D-405: a change of the products recalculates the insurance, even a typed figure, until the actual premium is billed', async () => {
    const order = await cifOrder();
    expect(await insurance(order)).toBe('19.00');
    await db.execute('UPDATE orders SET indicative_insurance_amount = 0 WHERE id = :id', { id: order }); // staff typed 0
    await marineInsurance.rememberBasis(order);
    expect(await marineInsurance.sync(order, null)).toBeNull();
    // The quantity is tripled: FOB 13,500 + freight 950 at 0.30 % × 110 % → 47.83 → 48.
    await db.execute('UPDATE order_products SET quantity = 300, fob_value = 13500 WHERE order_id = :id', { id: order });
    expect(await marineInsurance.sync(order, null)).toEqual({ from: '0.00', to: '48.00' });
    expect(await insurance(order)).toBe('48.00');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ORDER_INSURANCE_RECALCULATED' AND entity_id = :id", { id: order })).n)).toBe(1);
    expect(marineInsurance.recalcMessage({ from: '0.00', to: '48.00' }, 'EUR'))
      .toBe('Insurance recalculated from EUR 0.00 to EUR 48.00 because the products / freight changed. Change it again on Edit order if needed.');
    await db.execute('INSERT INTO order_freight (order_id, insurance_amount) VALUES (:id, 55.00) ON DUPLICATE KEY UPDATE insurance_amount = 55.00', { id: order });
    await db.execute('UPDATE order_products SET quantity = 10, fob_value = 450 WHERE order_id = :id', { id: order });
    expect(await marineInsurance.sync(order, null)).toBeNull();
    expect(await insurance(order)).toBe('48.00');
  });

  it('D-405: Edit order recalculates on a freight change unless the insurance was changed in the same save', async () => {
    const order = await cifOrder();
    const agent = await loginAs(await createUser('Admin'));
    await editPost(agent, order, { indicative_insurance_amount: '5' });
    expect(await insurance(order)).toBe('5.00');
    await editPost(agent, order, { indicative_freight_amount: '2950' });
    expect(await insurance(order)).toBe('25.00');
    expect((await agent.get(`/orders/${order}`)).text).toContain('Insurance recalculated from EUR 5.00 to EUR 25.00');
    const cfr = await createTestOrder(await createTestClient(), 'CFR', 'Standard — New Buyer', 'EUR');
    await orderProductRepository.add(cfr, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
    expect(await marineInsurance.sync(cfr, null, true)).toBeNull();
  });

  it('R10-N03: very large numbers get a message instead of a server error', () => {
    expect(fieldValidator.orderNumbers({ indicative_insurance_amount: '99999999999999' })).toBe('Indicative insurance must be a number from 0 to 10000000.');
    expect(fieldValidator.orderNumbers({ indicative_freight_amount: '99999999999999' })).toBe('Indicative freight must be a number from 0 to 10000000.');
    expect(fieldValidator.orderNumbers({ lead_time_min_days: '99999999999999' })).toBe('Production lead time (min days) must be a whole number from 0 to 365.');
    expect(fieldValidator.orderNumbers({ sailing_lead_time_max_days: '400' })).toBe('Sailing time (max days) must be a whole number from 0 to 365.');
    expect(fieldValidator.orderNumbers({ estimated_total_cbm: '12.3456' })).toBe('Total CBM: use at most 3 decimals.');
    expect(fieldValidator.orderNumbers({ estimated_total_cbm: '99999999999' })).toBe('Total CBM must be a number from 0 to 10000.');
    expect(fieldValidator.orderNumbers({ indicative_freight_amount: '950.00', lead_time_min_days: '30', estimated_total_cbm: '28.500' })).toBeNull();
  });

  it('R10-N06: the product supplier form checks the phone, the e-mail and the prices on the server', async () => {
    const md = await loginAs(await createUser('Managing Director'));
    const product = await db.queryOne('SELECT id FROM catalog_products ORDER BY id LIMIT 1');
    if (!product) return;
    const csrf = await csrfOf(md, `/products/${product.id}`);
    const before = Number((await db.queryOne('SELECT COUNT(*) AS n FROM catalog_product_suppliers')).n);
    for (const bad of [{ contact_phone: 'call me' }, { contact_email: 'not-an-email' }, { fob_value: '-1' }]) {
      await md.post(`/products/${product.id}/suppliers/add`).type('form').send({ _csrf: csrf, supplier_name: 'TEST Quarry R10', ...bad });
    }
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM catalog_product_suppliers')).n)).toBe(before);
    const page = (await md.get(`/products/${product.id}`)).text;
    expect(page).toContain('FOB value: a number of 0 or more, e.g. 4500.00.');
  });

  it('R10-N08: a document generation in another database on the same MySQL server does not block this one', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const holder = await db.pool.getConnection();
    try {
      await holder.query('SELECT GET_LOCK(?, 0)', [`nx_docgen_${orderId}_QT`]); // the old, server-wide name
      await holder.query("SELECT GET_LOCK(CONCAT('nx_docgen_', LEFT(MD5('another_database'), 12), ?), 0)", [`_${orderId}_QT`]);
      const { claimGeneration } = require('../../src/controllers/documentController');
      const claim = await claimGeneration(orderId, 'QT');
      expect(claim).not.toBeNull();
      await claim.release();
    } finally {
      await holder.query('SELECT RELEASE_ALL_LOCKS()');
      holder.release();
    }
  });

  it('R10-N04: with the SOP library switched off, Getting Started does not link to the SOP', async () => {
    const md = await createUser('Managing Director');
    const agent = await loginAs(await createUser('Accounts Executive'));
    await sopAccess.setLibraryEnabled(false, md.id);
    try {
      const html = (await agent.get('/getting-started')).text;
      expect(html).not.toContain('<a href="/sop">');
      expect(html).toContain('SOP / Operations Guide (switched off)');
    } finally {
      await sopAccess.setLibraryEnabled(true, md.id);
    }
    expect((await agent.get('/getting-started')).text).toContain('<a href="/sop">SOP / Operations Guide</a>');
  });

  it('R10-N05: someone working only outside Export does not see "Quotation requests"', async () => {
    const dom = (await db.queryOne("SELECT id FROM divisions WHERE code = 'DOM'")).id;
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: dom });
    try {
      const user = await createUser('Export Executive', dom);
      expect((await (await loginAs(user)).get('/orders')).text).not.toContain('href="/client-intake"');
      await db.execute('UPDATE users SET division_id = 1 WHERE id = :id', { id: user.id });
      expect((await (await loginAs(user)).get('/orders')).text).toContain('href="/client-intake"');
    } finally {
      await db.execute("UPDATE divisions SET status = 'planned' WHERE id = :d", { d: dom });
    }
  });

  it('R10-N09 (b): the "Forms of stone" link only for people who can open it', async () => {
    const forms = await db.queryOne('SELECT COUNT(*) AS n FROM product_forms');
    if (Number(forms.n) === 0) return;
    expect((await (await loginAs(await createUser('System Administrator'))).get('/admin/dropdown-options')).text).not.toContain('href="/pricing/forms"');
    expect((await (await loginAs(await createUser('Managing Director'))).get('/admin/dropdown-options')).text).toContain('href="/pricing/forms"');
  });

  it('R9-N01: the rupees the bank credited and the rate only with inr_actual_view', async () => {
    const order = await createTestOrder(await createTestClient());
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: order });
    await db.execute("UPDATE order_payment_status SET advance_amount = 1000, advance_remittance_received_at = '2026-11-20 10:00:00', advance_inr_actual = 90000, advance_exchange_rate = 90 WHERE order_id = :o", { o: order });
    await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 1000, '2026-11-20', 90, 90000, 'UTR-R10')", { o: order });
    const exec = await createUser('Export Executive');
    await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: order, u: exec.id });
    let html = (await (await loginAs(exec)).get(`/orders/${order}`)).text;
    expect(html).toContain('UTR-R10');
    expect(html).not.toContain('INR credited</th>');
    expect(html).not.toContain('credited by the bank (exchange rate');
    expect(html).not.toContain('90,000.00');
    html = (await (await loginAs(await createUser('Accounts Executive'))).get(`/orders/${order}`)).text;
    expect(html).toContain('INR credited</th>');
    expect(html).toContain('90,000.00');
  });

  it('R9-N02: removing an extra SOP chapter says what really happened', async () => {
    const md = await createUser('Managing Director');
    const user = await createUser('Export Executive');
    const agent = await loginAs(md);
    const slug = (await sopAccess.allSlugs())[0];
    await sopAccess.addUserExtra(user.id, slug, md.id);
    const post = async (s) => {
      const csrf = await csrfOf(agent, `/users/${user.id}/edit`);
      await agent.post(`/users/${user.id}/sop-chapters/remove`).type('form').send({ _csrf: csrf, slug: s });
      return (await agent.get(`/users/${user.id}/edit`)).text;
    };
    expect(await post('')).toContain('Choose the extra SOP chapter to remove.');
    expect(await post(slug)).toContain('Extra SOP chapter removed.');
    expect(await post(slug)).toContain('That extra SOP chapter was already removed.');
  });

  it('R9-N04: outside an active division the refusal says so, and how to get in', async () => {
    const mtt = (await db.queryOne("SELECT id FROM divisions WHERE code = 'MTT'")).id;
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = :d", { d: mtt });
    await db.execute("INSERT INTO division_modules (division_id, module_key, is_enabled) VALUES (:d, 'merchant_trade', 1) ON DUPLICATE KEY UPDATE is_enabled = 1", { d: mtt });
    try {
      const refusal = await requestContext.run({ userId: 1, divisionIds: [1], divisionSeesAll: false }, () => divisionService.refusalForPath('/merchant'));
      expect(refusal).toMatch(/^You do not work in the /);
      expect(refusal).toContain('division — ask an administrator to add you (Admin › Divisions).');
    } finally {
      await db.execute("UPDATE divisions SET status = 'planned' WHERE id = :d", { d: mtt });
    }
    const off = await requestContext.run({ userId: 1, divisionIds: [1], divisionSeesAll: false }, () => divisionService.refusalForPath('/merchant'));
    expect(off).toBe(divisionService.MODULE_OFF);
  });

  it('R9-N05: downloading the Import PO with view rights only does not mark it generated', () => {
    const src = require('fs').readFileSync(require.resolve('../../src/controllers/importController'), 'utf8');
    expect(src).toContain('if (req.permissions && req.permissions.manage_purchasing) {\n    await I.markPoGenerated');
  });
});
