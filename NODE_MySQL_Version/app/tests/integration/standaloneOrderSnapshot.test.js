'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const portRepository = require('../../src/repositories/portRepository');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of StandaloneOrderSnapshotTest.php. 90-item backlog "standalone
 * order": an order's incoterm/currency/port/payment-preset terms are
 * resolved ONCE at creation (orderRepository's resolveSnapshot(), called
 * from insertOrderRow()) and snapshotted onto the order's own columns —
 * never re-read from incoterms/currencies/ports/payment_presets again.
 * Before this, every one of these was a live JOIN (orderRepository.find()),
 * so editing or retiring a lookup row later silently rewrote what every
 * past order referencing it displayed and printed, even an order closed
 * years ago. This proves the isolation actually holds: editing each of the
 * four lookup tables after an order is created must never change that
 * order's own resolved values. Duplicating an order is the one deliberate
 * exception — it re-resolves TODAY's current lookup-table values (a
 * duplicate is a new commercial decision made today, not a continuation of
 * the source's possibly years-old terms — see orderDuplicationService /
 * insertOrderRow()'s own docblock).
 */
describe('Standalone order snapshot (90-item backlog "standalone order")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-standalone-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('snapshots incoterm/currency/preset at creation', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
    const order = await orderRepository.find(orderId);

    expect(order.incoterm_code).toBe('CIF');
    expect(order.currency_code).toBe('EUR');
    expect(order.preset_name).toBe('Standard — New Buyer');
    expect(Number(order.advance_pct)).toBe(40);
    expect(Number(order.balance_pct)).toBe(60);
  });

  it('snapshots port name and city at creation', async () => {
    const portId = await portRepository.create('Nhava Sheva (JNPT)', 'Mumbai', 'India', 'loading', 97);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', portId);
    const order = await orderRepository.find(orderId);

    expect(order.port_of_loading_name).toBe('Nhava Sheva (JNPT)');
    expect(order.port_of_loading_city).toBe('Mumbai');
  });

  it('editing the incoterms table does not change an existing order', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    expect((await orderRepository.find(orderId)).incoterm_code).toBe('FOB');

    await db.execute("UPDATE incoterms SET code = 'ZZZ' WHERE code = 'FOB'");

    expect((await orderRepository.find(orderId)).incoterm_code).toBe('FOB');

    // restore for other tests in this process
    await db.execute("UPDATE incoterms SET code = 'FOB' WHERE code = 'ZZZ'");
  });

  it('editing the currencies table does not change an existing order', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    expect((await orderRepository.find(orderId)).currency_code).toBe('USD');

    await db.execute("UPDATE currencies SET code = 'ZZZ' WHERE code = 'USD'");

    expect((await orderRepository.find(orderId)).currency_code).toBe('USD');

    await db.execute("UPDATE currencies SET code = 'USD' WHERE code = 'ZZZ'");
  });

  it("editing a port's name and city does not change an existing order", async () => {
    const portId = await portRepository.create('Original Terminal Name', 'Original City', 'India', 'loading', 96);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', portId);
    expect((await orderRepository.find(orderId)).port_of_loading_name).toBe('Original Terminal Name');

    await db.execute('UPDATE ports SET name = :name, city = :city WHERE id = :id', {
      name: 'Renamed Terminal', city: 'Renamed City', id: portId,
    });

    const order = await orderRepository.find(orderId);
    expect(order.port_of_loading_name).toBe('Original Terminal Name');
    expect(order.port_of_loading_city).toBe('Original City');
  });

  it("editing a payment preset's terms does not change an existing order", async () => {
    const preset = await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'");

    const orderId = await createTestOrder(await createTestClient());
    const before = await orderRepository.find(orderId);
    expect(Number(before.advance_pct)).toBe(40);
    expect(Number(before.balance_pct)).toBe(60);

    await db.execute(
      'UPDATE payment_presets SET advance_pct = 55.00, balance_pct = 45.00, show_bl_instructions = 0 WHERE id = :id',
      { id: preset.id }
    );

    const after = await orderRepository.find(orderId);
    expect(Number(after.advance_pct)).toBe(40);
    expect(Number(after.balance_pct)).toBe(60);
    expect(Number(after.show_bl_instructions)).toBe(1);

    // restore for other tests in this process
    await db.execute(
      'UPDATE payment_presets SET advance_pct = 40.00, balance_pct = 60.00, show_bl_instructions = 1 WHERE id = :id',
      { id: preset.id }
    );
  });

  it("duplicating an order re-resolves today's current preset values", async () => {
    const preset = await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'");

    const clientId = await createTestClient();
    const sourceOrderId = await createTestOrder(clientId);
    expect(Number((await orderRepository.find(sourceOrderId)).advance_pct)).toBe(40);

    // "6 months later" the preset's own terms change.
    await db.execute('UPDATE payment_presets SET advance_pct = 25.00, balance_pct = 75.00 WHERE id = :id', { id: preset.id });

    const adminUserId = await createTestUser('Admin');
    const duplicateOrderId = await orderDuplicationService.duplicate(sourceOrderId, adminUserId);

    const source = await orderRepository.find(sourceOrderId);
    const duplicate = await orderRepository.find(duplicateOrderId);

    expect(Number(source.advance_pct)).toBe(40);
    expect(Number(duplicate.advance_pct)).toBe(25);
    expect(Number(duplicate.balance_pct)).toBe(75);

    // restore for other tests in this process
    await db.execute('UPDATE payment_presets SET advance_pct = 40.00, balance_pct = 60.00 WHERE id = :id', { id: preset.id });
  });
});
