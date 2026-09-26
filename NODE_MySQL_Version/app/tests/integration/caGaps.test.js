'use strict';

const db = require('../../src/config/db');
const caExpenseRepository = require('../../src/repositories/caExpenseRepository');
const caRepository = require('../../src/repositories/caRepository');
const financialYear = require('../../src/helpers/financialYear');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Regression coverage for the CA/Reports gap-analysis additions specific
 * to the CA module: the TDS Payable Summary and the FY-Close Readiness
 * checklist.
 */
describe('CA gap-analysis additions', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('tdsSummary groups by month and quarter', async () => {
    const id = await caExpenseRepository.insert('ZOHO-JEST-1', 'Freight', 'Test expense', 'Test Vendor', 1000.0, 'INR', '2026-05-15');
    await caExpenseRepository.setTds(id, true, 100.0, 1);

    const summary = await caExpenseRepository.tdsSummary();
    const row = summary.find((r) => r.month === '2026-05');

    expect(row).toBeDefined();
    expect(row.expense_count).toBe(1);
    expect(row.total_expense_amount).toBe(1000);
    expect(row.total_tds_amount).toBe(100);
    expect(row.quarter).toBe('Q1 FY2026-27'); // May falls in Apr-Jun => Q1 of FY2026-27
  });

  it('tdsSummary excludes expenses not marked TDS-applicable', async () => {
    await caExpenseRepository.insert('ZOHO-JEST-2', 'Office', 'Not TDS', null, 500.0, 'INR', '2026-06-01');
    // Deliberately never call setTds() — is_tds_applicable defaults to 0.

    const summary = await caExpenseRepository.tdsSummary();
    expect(summary.some((r) => r.month === '2026-06')).toBe(false);
  });

  it('fyReadiness is not ready when a leg is missing an INR actual', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute(
      `INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at)
       VALUES (:order_id, 500.00, NOW())`,
      { order_id: orderId }
    );
    // advance_inr_actual deliberately left NULL.

    const fy = financialYear.current();
    const readiness = await caRepository.fyReadiness(fy);

    expect(readiness.isReady).toBe(false);
    expect(readiness.revenue.legsMissingInr).toBeGreaterThan(0);
  });
});
