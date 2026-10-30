'use strict';

const request = require('supertest');
const bcrypt = require('bcrypt');
const app = require('../../src/server');
const db = require('../../src/config/db');
const financialYear = require('../../src/helpers/financialYear');
const permissionRepository = require('../../src/repositories/permissionRepository');
const cafinExportService = require('../../src/services/cafinExportService');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CafinExportTest.php. Owner request 2026-10-30: CAFIN as CSV for
 * every order of a financial year — summary (one row per order) and detail
 * (every CAFIN line), from the same calculation as the CAFIN PDF; test
 * orders never included.
 */
describe('CAFIN CSV export', () => {
  async function createTestUser(roleName, password = null) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-cafin-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: password ? await bcrypt.hash(password, 4) : 'x', role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id, email };
  }

  async function order(status = 'active') {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(`UPDATE order_payment_status SET advance_amount = 1800, advance_remittance_received_at = '2026-10-09 10:00:00', advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = 150000,
      balance_amount = 2700, balance_remittance_received_at = '2026-10-24 10:00:00', balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000 WHERE order_id = :o`, { o: orderId });
    await db.execute('UPDATE orders SET status = :s WHERE id = :o', { s: status, o: orderId });
    return orderId;
  }

  afterAll(async () => {
    await db.pool.end();
  });

  it('the summary has one row per order with the same figures as CAFIN', async () => {
    const orderId = await order();
    expect(await cafinExportService.orderIds(financialYear.current(), 'all')).toContain(orderId);

    const values = (await cafinExportService.summaryRows([orderId]))[0];
    const row = Object.fromEntries(cafinExportService.SUMMARY_HEADERS.map((h, i) => [h, values[i]]));
    const p = await orderProfitabilityService.computeForOrder(orderId);
    expect([row['FOB value'], row['Revenue (INR)'], row['Revenue estimated']]).toEqual(['4500.00', '400000.00', 'No']);
    expect(row['Order profit (INR)']).toBe(p.profit_inr.toFixed(2));
    expect(row['Total cost (INR)']).toBe(p.total_cost_inr.toFixed(2));
    expect(row['Cash: received from buyer (INR)']).toBe('400000.00');

    const snapshot = await caFinancialSnapshotService.build(orderId);
    const profitLine = snapshot.pl.filter((l) => l[1] === 'ORDER PROFIT')[0];
    expect(row['Order profit (INR)']).toBe(String(profitLine[3]).replace(/,/g, '')); // the summary agrees with the CAFIN PDF
  });

  it('the detail has every CAFIN section as plain numbers', async () => {
    const orderId = await order();
    const rows = await cafinExportService.detailRows([orderId]);
    const sections = [...new Set(rows.map((r) => r[2]))];
    for (const section of ['A. Order details', 'A. Products', 'A. Money received from the buyer', 'C1. Freight & insurance', 'C. Expenses summary', 'E. Profit', 'E. Cash position so far', 'F. Open items for the CA']) {
      expect(sections).toContain(section);
    }
    const receiptTotal = rows.filter((r) => r[2] === 'A. Money received from the buyer' && r[3] === 'TOTAL')[0];
    expect([receiptTotal[6], receiptTotal[7], receiptTotal[8]]).toEqual(['4500.00', 'USD', '400000.00']);
  });

  it('the status filter, and test orders left out', async () => {
    const settled = await order('complete');
    const lost = await order('lost');
    const test = await order();
    await db.execute('UPDATE orders SET is_test_data = 1 WHERE id = :o', { o: test });
    try {
      const fy = financialYear.current();
      expect(await cafinExportService.orderIds(fy, 'complete')).toContain(settled);
      expect(await cafinExportService.orderIds(fy, 'complete')).not.toContain(lost);
      expect(await cafinExportService.orderIds(fy, 'lost')).toContain(lost);
      expect(await cafinExportService.orderIds(fy, 'all')).not.toContain(test); // test orders are never exported
      expect(await cafinExportService.orderIds('2020-21', 'all')).not.toContain(settled);
    } finally {
      // Leftover test data would keep Test Mode from being switched off in later tests.
      await db.execute('UPDATE orders SET is_test_data = 0 WHERE id = :o', { o: test });
    }
  });

  it('the page opens for whoever may see CAFIN only', async () => {
    const accounts = await createTestUser('Accounts Executive', 'JestCafin1!');
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: accounts.email, password: 'JestCafin1!' });
    const html = (await agent.get('/ca/cafin-export')).text;
    expect(html).toContain('Internal only.');
    expect(html).toContain('Download summary (one row per order)');

    const can = async (roleName, perm) => {
      const u = await createTestUser(roleName);
      return !!(await permissionRepository.effectivePermissions(u.id, u.roleId))[perm];
    };
    expect(await can('CA / Chartered Accountant', 'view_cafin')).toBe(true);
    expect(await can('Export Executive', 'view_cafin')).toBe(false);
    expect(await can('Logistics Executive', 'view_cafin')).toBe(false);
  });
});
