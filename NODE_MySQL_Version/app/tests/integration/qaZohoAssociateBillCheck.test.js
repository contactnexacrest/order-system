'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const zohoPurchaseRepository = require('../../src/repositories/zohoPurchaseRepository');
const orderZohoMatchService = require('../../src/services/orderZohoMatchService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoRestoreService = require('../../src/services/zohoRestoreService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA ZOH-002: an associate's paid commission goes to Books as the associate's
 * own BILL (AS-12) and its id is kept in order_cost_entries.zoho_expense_id.
 * The App vs Zoho check, the daily restore and the test clean-up list read it
 * as a bill — not as an expense, which Books does not have. Fake Zoho only.
 * Port of QaZohoAssociateBillCheckTest.php.
 */
describe('QA ZOH-002: associate commission bill in the App vs Zoho check', () => {
  let requests;
  let bills;

  function fakeZoho(method, url) {
    requests.push({ method, url });
    const p = new URL(url).pathname;
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;
    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/bills\/(.+)$/))) return bills[m[1]] ? json({ code: 0, bill: bills[m[1]] }) : json({ code: 1002, message: 'Record does not exist.' }, 404);
    if (/^\/books\/v3\/expenses\/.+$/.test(p)) return json({ code: 1002, message: 'Record does not exist.' }, 404);
    if (p === '/books/v3/expenses') return json({ code: 0, expenses: [], page_context: { has_more_page: false } });
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  async function orderWithSentCommission() {
    const clientId = await createTestClient();
    await db.execute("UPDATE clients SET company_legal_name = 'TEST-ZOH002 Buyer' WHERE id = :id", { id: clientId });
    const orderId = await createTestOrder(clientId, 'FOB');
    await db.execute("UPDATE orders SET buyer_inquiry_ref = :ref, company_legal_name = 'TEST-ZOH002 Buyer' WHERE id = :id", { ref: `TQZ-${orderId}`, id: orderId });
    const userId = (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id;
    const r = await db.execute(
      `INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by, paid_from, incentive_kind, zoho_expense_id, zoho_synced_at)
       VALUES (:o, 'commission', 'Associate commission', 104312.50, CURDATE(), :u, 'company_account', 'associate', 'BILL-77', NOW())`,
      { o: orderId, u: userId }
    );
    return [orderId, r.insertId];
  }

  beforeEach(async () => {
    requests = [];
    bills = {};
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    for (const [k, v] of Object.entries({
      zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret',
      zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1',
    })) {
      await companySettingsRepository.set(k, v);
    }
    zohoBooksService.setTransport(fakeZoho);
    // Earlier tests' TEST- records must not reach this fake Zoho.
    await db.execute("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    await companySettingsRepository.set('zoho_books_enabled', '0');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const expenseRows = async (orderId) => (await orderZohoMatchService.check(orderId)).rows.filter((r) => r.group === 'Expenses');

  test('ZOH-002: the check reads the associate commission as its bill', async () => {
    const [orderId] = await orderWithSentCommission();
    bills['BILL-77'] = { bill_id: 'BILL-77', bill_number: 'COMM-1', sub_total: 104312.50, total: 123088.75 };
    let rows = await expenseRows(orderId);
    expect(rows).toHaveLength(1);
    expect(rows[0].status).toBe('match');
    expect(rows[0].books).toBe('INR 104,312.50');
    expect(requests.filter((r) => r.url.includes('/expenses/BILL-77'))).toHaveLength(0);

    delete bills['BILL-77'];
    rows = await expenseRows(orderId);
    expect(rows[0].status).toBe('missing');
  });

  test('ZOH-002: the restore leaves the bill alone and puts back only a deleted one', async () => {
    const [, costId] = await orderWithSentCommission();
    bills['BILL-77'] = { bill_id: 'BILL-77', bill_number: 'COMM-1', sub_total: 104312.50, total: 104312.50 };
    const zohoId = async () => (await db.queryOne('SELECT zoho_expense_id FROM order_cost_entries WHERE id = :id', { id: costId })).zoho_expense_id;

    await zohoRestoreService.purchases(null, 'manual', null);
    expect(await zohoId()).toBe('BILL-77');

    delete bills['BILL-77'];
    await zohoRestoreService.purchases(null, 'manual', null);
    expect(await zohoId()).toBeNull();
  });

  test('ZOH-002: the clean-up list names the commission as a bill', async () => {
    await orderWithSentCommission();
    const mine = (await zohoPurchaseRepository.pushedRecords()).filter((r) => r.id === 'BILL-77');
    expect(mine[0].kind).toBe('bill');
    expect(mine[0].label.startsWith('associate commission bill INR 104312.50')).toBe(true);
  });
});
