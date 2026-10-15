'use strict';

const db = require('../../src/config/db');
const reportRepository = require('../../src/repositories/reportRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * 90-item backlog "sort order": productSalesReport()'s by_hs_code used to
 * be returned as `Object.fromEntries(sortedEntries)`. JS objects always
 * list integer-like keys in ascending numeric order regardless of
 * insertion order, and real HS codes are plain digits (schema.sql Section
 * AB) — so the FOB-descending sort immediately above that line was
 * silently thrown away the moment it was put back into a plain object.
 * PHP's associative array has no such reordering and was never affected.
 */
describe('productSalesReport by_hs_code stays ranked by FOB value, not reordered by HS code (90-item backlog "sort order")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('ranks a high-FOB, numerically-larger HS code ahead of a low-FOB, numerically-smaller one', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // Deliberately chosen so ascending-numeric-key order (the bug) and
    // FOB-descending order (the correct behavior) disagree: '11000001'
    // sorts first numerically but has the lower FOB value.
    await db.execute(
      `INSERT INTO order_products (order_id, line_no, description, quantity, unit_price, fob_value, hs_code, is_active)
       VALUES (:order_id, 1, 'Low FOB line', 1, 100.00, 100.00, '11000001', 1)`,
      { order_id: orderId }
    );
    await db.execute(
      `INSERT INTO order_products (order_id, line_no, description, quantity, unit_price, fob_value, hs_code, is_active)
       VALUES (:order_id, 2, 'High FOB line', 1, 9000.00, 9000.00, '99000002', 1)`,
      { order_id: orderId }
    );

    const report = await reportRepository.productSalesReport(null, null);

    expect(Array.isArray(report.by_hs_code)).toBe(true);
    const codes = report.by_hs_code.map((e) => e.hs_code);
    const highIndex = codes.indexOf('99000002');
    const lowIndex = codes.indexOf('11000001');
    expect(highIndex).toBeGreaterThanOrEqual(0);
    expect(lowIndex).toBeGreaterThanOrEqual(0);
    expect(highIndex).toBeLessThan(lowIndex);
  });
});
