'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const clientPaymentReportRepository = require('../../src/repositories/clientPaymentReportRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PHP's OrderingTieBreakerTest. External QA report U-14: "Lists
 * with equal timestamps sort differently on PHP vs Node (orders,
 * payments, aggregate reports)." MySQL/MariaDB gives no ordering
 * guarantee among rows that tie on the ORDER BY column alone, and the
 * two stacks' storage engines/optimizers broke that tie differently in
 * practice. Adding `id DESC` as a second sort key removes the ambiguity.
 */
describe('Ordering tie-breaker (External QA report U-14)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setCreatedAt(table, id, timestamp) {
    await db.execute(`UPDATE ${table} SET created_at = :ts WHERE id = :id`, { ts: timestamp, id });
  }

  test('orders list breaks ties on id descending', async () => {
    const olderId = await createTestOrder(await createTestClient());
    const newerId = await createTestOrder(await createTestClient());
    const tiedTimestamp = '2026-01-01 12:00:00';
    await setCreatedAt('orders', olderId, tiedTimestamp);
    await setCreatedAt('orders', newerId, tiedTimestamp);

    const ids = (await orderRepository.all()).map((o) => o.id);
    const posOlder = ids.indexOf(olderId);
    const posNewer = ids.indexOf(newerId);

    expect(posOlder).toBeGreaterThanOrEqual(0);
    expect(posNewer).toBeGreaterThanOrEqual(0);
    expect(posNewer).toBeLessThan(posOlder);
  });

  test('client payment reports break ties on id descending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const firstId = await clientPaymentReportRepository.create(orderId, 'advance', 'UTR-A', null, null, null, null);
    const secondId = await clientPaymentReportRepository.create(orderId, 'advance', 'UTR-B', null, null, null, null);
    const tiedTimestamp = '2026-01-01 12:00:00';
    await db.execute('UPDATE client_payment_reports SET reported_at = :ts WHERE id = :id', { ts: tiedTimestamp, id: firstId });
    await db.execute('UPDATE client_payment_reports SET reported_at = :ts WHERE id = :id', { ts: tiedTimestamp, id: secondId });

    const ids = (await clientPaymentReportRepository.forOrder(orderId)).map((r) => r.id);

    expect(ids).toEqual([secondId, firstId]);
  });
});
