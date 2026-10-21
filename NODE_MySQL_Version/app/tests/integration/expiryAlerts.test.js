'use strict';

const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { run } = require('../../src/jobs/checkAlerts');
const notificationRepository = require('../../src/repositories/notificationRepository');
const userRepository = require('../../src/repositories/userRepository');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');

async function setOrderDates(orderId, fields) {
  const sets = Object.keys(fields).map((col) => `${col} = :${col}`).join(', ');
  await db.execute(`UPDATE orders SET ${sets} WHERE id = :id`, { ...fields, id: orderId });
}

async function setOrderStage(orderId, stageNumber) {
  const stage = await db.queryOne('SELECT id FROM stages_master WHERE stage_number = :n', { n: stageNumber });
  await db.execute('UPDATE orders SET current_stage_id = :stage_id WHERE id = :id', { stage_id: stage.id, id: orderId });
}

/**
 * Port of ExpiryAlertsTest.php. 90-item backlog "expiry alerts":
 * orders.quotation_valid_until and pi_valid_until had no cron alert at
 * all — unlike LUT/RCMC expiry and FDN/dispute overdue, which
 * checkAlerts.js already covered. A buyer accepting stale pricing/terms
 * after the window lapses, with nobody on staff aware the order is
 * sitting on an expired Quotation or PI, is exactly the kind of risk
 * this alert system exists to catch.
 */
describe('Expiry alerts (90-item backlog "expiry alerts")', () => {
  let adminId;

  beforeAll(async () => {
    const admin = await userRepository.findByEmail(TEST_ADMIN_EMAIL);
    adminId = admin.id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('alerts for an order with an expired quotation and no PI yet', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await setOrderDates(orderId, { quotation_valid_until: '2020-01-01' });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'quotation_expired', orderId)).toBe(true);
  });

  it('does not alert when the quotation is still valid', async () => {
    const orderId = await createTestOrder(await createTestClient());
    // createTestOrder() already sets quotation_valid_until 30 days out.

    await run();

    expect(await notificationRepository.existsToday(adminId, 'quotation_expired', orderId)).toBe(false);
  });

  it('does not alert on quotation expiry once a PI has been issued', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await setOrderDates(orderId, { quotation_valid_until: '2020-01-01', pi_date: '2024-01-01' });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'quotation_expired', orderId)).toBe(false);
  });

  it('alerts for an order with an expired PI still not confirmed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await setOrderDates(orderId, { pi_date: '2020-01-01', pi_valid_until: '2020-01-16' });
    await setOrderStage(orderId, 3);

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pi_expired', orderId)).toBe(true);
  });

  it('does not alert on PI expiry once Order Confirmation is reached', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await setOrderDates(orderId, { pi_date: '2020-01-01', pi_valid_until: '2020-01-16' });
    await setOrderStage(orderId, 4);

    await run();

    expect(await notificationRepository.existsToday(adminId, 'pi_expired', orderId)).toBe(false);
  });

  it('does not alert for a locked order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await setOrderDates(orderId, { quotation_valid_until: '2020-01-01' });
    await db.execute("UPDATE orders SET is_locked = 1, status = 'lost' WHERE id = :id", { id: orderId });

    await run();

    expect(await notificationRepository.existsToday(adminId, 'quotation_expired', orderId)).toBe(false);
  });
});
