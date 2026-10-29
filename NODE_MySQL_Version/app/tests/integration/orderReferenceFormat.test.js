'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const ordersController = require('../../src/controllers/ordersController');
const referenceNumberService = require('../../src/services/referenceNumberService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderReferenceFormatTest.php. 90-item backlog "order-ID prefix
 * change to SC/ORD/{YYYY}/{NNN}": the seeded order_ref_format default
 * (and its hardcoded code-level fallback) used to read
 * 'SC/OC/{YYYY}/{NNN}' — changed to 'SC/ORD/{YYYY}/{NNN}' so a new
 * order's reference reads e.g. "SC/ORD/2026/004-7" instead of
 * "SC/OC/2026/004-7". Existing orders' own order_reference values are
 * never touched — this only governs orders created from here on.
 *
 * Owner decision 2026-10-29 (confirmed twice): every reference uses the
 * 2-digit year — SC/ORD/26/004-7, SC/CI/26/2910001 — because a GST tax
 * invoice number may have at most 16 characters.
 */
describe('Order reference format (90-item backlog "order-ID prefix change to SC/ORD/{YYYY}/{NNN}")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-orderref-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = {};
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('new order reference uses the ORD prefix', async () => {
    const clientId = await createTestClient();
    const userId = await createTestUser();
    const incoterm = await db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'");
    const currency = await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'");
    const preset = await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'");

    const req = {
      session: {},
      user: { id: userId },
      body: {
        client_id: String(clientId),
        incoterm_id: String(incoterm.id),
        currency_id: String(currency.id),
        payment_preset_id: String(preset.id),
        product_description: ['Order ref format test granite slab'],
        product_hs_code: ['680293'],
        product_quantity: ['10'],
        product_unit_price: ['5.00'],
      },
    };

    await ordersController.store(req, fakeRes());

    const row = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const order = await orderRepository.find(row.id);

    expect(order.order_reference).toMatch(/^SC\/ORD\/\d{2}\/\d{3}-\d+$/);
    expect(order.order_reference.startsWith(`SC/ORD/${String(new Date().getFullYear()).slice(2)}/`)).toBe(true);
  });

  it('a duplicated order reference also uses the ORD prefix', async () => {
    const clientId = await createTestClient();
    const userId = await createTestUser();
    const sourceOrderId = await createTestOrder(clientId);

    const newOrderId = await orderDuplicationService.duplicate(sourceOrderId, userId);
    const newOrder = await orderRepository.find(newOrderId);

    expect(newOrder.order_reference).toMatch(/^SC\/ORD\/\d{2}\/\d{3}-\d+$/);
  });

  it('every reference format uses the 2-digit year', async () => {
    expect((await db.queryOne("SELECT COUNT(*) AS n FROM document_types WHERE ref_format LIKE '%{YYYY}%'")).n).toBe(0);
    expect((await db.queryOne("SELECT COUNT(*) AS n FROM company_settings WHERE setting_key IN ('master_tracking_ref_format', 'order_ref_format') AND setting_value LIKE '%{YYYY}%'")).n).toBe(0);
  });

  it('a new Commercial Invoice and Debit Note number fit the GST 16-character limit', async () => {
    const now = new Date();
    const yy = String(now.getFullYear()).slice(2);
    const ddmm = String(now.getDate()).padStart(2, '0') + String(now.getMonth() + 1).padStart(2, '0');
    for (const [code, prefix] of [['CI', 'SC/CI/'], ['FDN', 'SC/DN/']]) {
      const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code });
      const reference = await referenceNumberService.generateDocumentRef(type.id);
      expect(reference).toMatch(new RegExp(`^${prefix.replace(/\//g, '\\/')}${yy}\\/${ddmm}\\d{3}$`));
      expect(reference.length).toBeLessThanOrEqual(16);
    }
  });
});
