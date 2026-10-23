'use strict';

const db = require('../../src/config/db');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-osu-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

/**
 * QA Round 4, N-02: recordBl, attachBlCopy, recordBlOriginalsReceived
 * and recordBlEndorsed used to run a plain UPDATE against order_shipping.
 * That row only ever existed if staff had saved "Shipping / Vessel
 * Details" first (orderShippingRepository.upsert()) — if they skipped
 * straight to confirming the BL number, attaching the scan, recording
 * originals, or recording the endorsement, every one of those actions
 * reported success while silently updating zero rows. Fixed by making
 * all four upsert, same pattern attachDraftBl() already used.
 */
describe('OrderShippingRepository upsert (QA Round 4, N-02)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('recordBl creates the row when shipping details were never saved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect(await orderShippingRepository.find(orderId)).toBeNull();

    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-10-20');

    const row = await orderShippingRepository.find(orderId);
    expect(row).not.toBeNull();
    expect(row.bl_number).toBe('MSCU1234567');
  });

  it('attachBlCopy creates the row when shipping details were never saved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);

    await orderShippingRepository.attachBlCopy(orderId, fileId);

    const row = await orderShippingRepository.find(orderId);
    expect(row).not.toBeNull();
    expect(row.bl_copy_file_id).toBe(fileId);
  });

  it('recordBlOriginalsReceived creates the row when shipping details were never saved', async () => {
    const orderId = await createTestOrder(await createTestClient());

    await orderShippingRepository.recordBlOriginalsReceived(orderId, 3);

    const row = await orderShippingRepository.find(orderId);
    expect(row).not.toBeNull();
    expect(row.bl_originals_received_count).toBe(3);
    expect(row.bl_originals_received_at).not.toBeNull();
  });

  it('recordBlEndorsed creates the row when shipping details were never saved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await orderShippingRepository.recordBlEndorsed(orderId, userId);

    const row = await orderShippingRepository.find(orderId);
    expect(row).not.toBeNull();
    expect(row.bl_endorsed_by).toBe(userId);
    expect(row.bl_endorsed_at).not.toBeNull();
  });

  it('the full BL journey without ever saving shipping details persists everything together', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const fileId = await createTestFile(orderId, null);

    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-10-20');
    await orderShippingRepository.recordBlOriginalsReceived(orderId, 3);
    await orderShippingRepository.recordBlEndorsed(orderId, userId);
    await orderShippingRepository.attachBlCopy(orderId, fileId);

    const row = await orderShippingRepository.find(orderId);
    expect(row.bl_number).toBe('MSCU1234567');
    expect(row.bl_originals_received_count).toBe(3);
    expect(row.bl_endorsed_by).toBe(userId);
    expect(row.bl_copy_file_id).toBe(fileId);
  });

  it('recordBl does not clobber shipping details saved earlier', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MV Test' });

    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-10-20');

    const row = await orderShippingRepository.find(orderId);
    expect(row.shipping_line).toBe('Maersk');
    expect(row.vessel_name).toBe('MV Test');
    expect(row.bl_number).toBe('MSCU1234567');
  });
});
