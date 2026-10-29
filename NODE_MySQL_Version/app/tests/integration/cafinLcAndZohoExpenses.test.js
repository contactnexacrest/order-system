'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const caBankStatementRepository = require('../../src/repositories/caBankStatementRepository');
const caExpenseRepository = require('../../src/repositories/caExpenseRepository');
const caRepository = require('../../src/repositories/caRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const caController = require('../../src/controllers/caController');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const orderLedgerService = require('../../src/services/orderLedgerService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const zohoRestoreService = require('../../src/services/zohoRestoreService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CafinLcAndZohoExpensesTest.php. Owner decision 2026-10-29 (CAFIN
 * change confirmed twice): the money the bank pays under an LC counts as
 * money received from the buyer (CAFIN receipts, revenue, cash position,
 * INR Settlement Register with its own FIRC / eBRC and bank match), and
 * expenses booked in Zoho Books and linked to the order count in its cost —
 * except a possible duplicate of a cost entered in the app. Also: the
 * scheduled sync checks the records already in Books once a day; Sync Now
 * always does.
 */
describe('CAFIN: the LC payment and the expenses booked in Zoho Books', () => {
  let userId;

  const res = () => ({ redirect() {}, status() { return this; }, send() {} });
  const req = (params, body) => ({ params, body, query: {}, session: {}, user: { id: userId, name: 'Jest Admin' }, permissions: { ca_fy_lock_override: true } });

  /** An LC order in EUR: LC paid EUR 1,800 (₹160,650 credited, ₹450 charges), balance EUR 2,700 at ₹241,650. */
  async function lcOrder() {
    const orderId = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
    await db.execute("UPDATE orders SET payment_method = 'LC' WHERE id = :id", { id: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:id)', { id: orderId });
    await db.execute(`UPDATE order_payment_status SET balance_amount = 2700, balance_remittance_received_at = '2026-10-28 10:00:00', balance_cleared_at = '2026-10-29 10:00:00',
      balance_inr_actual = 241650, balance_exchange_rate = 89.5 WHERE order_id = :id`, { id: orderId });
    await db.execute("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'LC-T-1', 1800, '2026-10-15 10:00:00')", { id: orderId });
    await orderLcDetailRepository.recordReceived(orderId, '2026-10-28', 1800, 160650, 450, userId);
    return orderId;
  }

  async function zohoExpense(orderId, zohoId, category, amount, date) {
    const id = await caExpenseRepository.insert(zohoId, category, `${category} booked in Books`, 'Vendor', amount, 'INR', date);
    await caExpenseRepository.linkToOrder(id, orderId);
    return id;
  }

  beforeEach(async () => {
    await db.execute('UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE unlocked_at IS NULL');
    userId = (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('the LC payment is money received from the buyer', async () => {
    const orderId = await lcOrder();

    const profit = await orderProfitabilityService.computeForOrder(orderId);
    expect(profit.revenue_inr).toBe(402300); // LC ₹160,650 credited + balance ₹241,650
    expect(profit.revenue_is_estimated).toBe(false);

    const s = await caFinancialSnapshotService.build(orderId);
    const r = s.receipts[0];
    expect([r.leg, r.amount, r.rate, r.inr, r.basis])
      .toEqual(['LC payment (paid by the bank under the LC)', '1,800.00', '89.2500', '160,650.00', 'rupees credited (after ₹450.00 bank charges)']);
    expect(s.receipts_inr).toBe('402,300.00');

    const ledger = (await orderLedgerService.forOrder(orderId, 'EUR', true)).totals;
    expect(ledger.buyer_inr).toBe(402300);

    const register = (await caRepository.settlementRegister()).filter((row) => row.order_id === orderId && row.leg === 'lc');
    expect(register).toHaveLength(1);
    expect([register[0].foreign_amount, register[0].inr_actual, register[0].cleared_at]).toEqual([1800, 160650, '2026-10-28']);
    expect(caRepository.legLabel('lc')).toBe('LC payment');
    // the LC payment has its own Zoho step — never sent twice
    expect((await caRepository.legsPendingZohoSync()).filter((row) => row.leg === 'lc')).toEqual([]);
  });

  it('the LC payment takes its FIRC and can be matched to a bank line', async () => {
    const orderId = await lcOrder();

    const firc = req({ id: String(orderId) }, { lc_firc_reference: 'EBRC-LC-1', lc_firc_received_at: '2026-10-29' });
    await orderFinancialsController.recordLcFirc(firc, res());
    expect(flash.pull(firc)[0].type).toBe('success');
    const row = (await caRepository.settlementRegister()).filter((r) => r.order_id === orderId && r.leg === 'lc')[0];
    expect([row.firc_reference, String(row.firc_received_at)]).toEqual(['EBRC-LC-1', '2026-10-29']);

    await caBankStatementRepository.insertLine(`lc-line-${orderId}`, '2026-10-28', 'LC proceeds', 'REF', 160650, null, userId);
    const lineId = (await db.queryOne('SELECT id FROM ca_bank_statement_lines WHERE line_hash = :h', { h: `lc-line-${orderId}` })).id;
    const match = req({ id: String(lineId) }, { order_id: String(orderId), leg: 'lc' });
    await caController.matchBankLineToRevenue(match, res());
    expect(flash.pull(match)[0].type).toBe('success');
    expect(await caBankStatementRepository.matchedRevenueKeys()).toContain(`${orderId}:lc`);
  });

  it('expenses booked in Zoho count in the cost, but not a possible duplicate', async () => {
    const orderId = await lcOrder();
    await orderCostEntryRepository.create(orderId, 'ecgc_insurance', 'ECGC cover', 4200, '2026-10-29', userId, 'company_account');
    await zohoExpense(orderId, `Z-CNT-${orderId}`, 'Postage', 2350, '2026-10-27');
    await zohoExpense(orderId, `Z-DUP-${orderId}`, 'Insurance Expense', 4200, '2026-10-29');

    const profit = await orderProfitabilityService.computeForOrder(orderId);
    expect(profit.zoho_expenses_inr).toBe(2350); // the ECGC premium matches the ECGC cost already in the app: not counted
    expect(profit.total_cost_inr).toBe(6550); // ECGC 4,200 + courier 2,350

    const s = await caFinancialSnapshotService.build(orderId);
    expect(s.zoho_total).toBe('2,350.00');
    expect(s.zoho.map((z) => z.tag)).toContain('possible duplicate of a cost entered in the app — not counted');
    expect(s.summary).toContainEqual(['C5 Expenses booked in Zoho Books', '2,350.00']);
    expect(s.pl).toContainEqual(['line', '− Expenses booked in Zoho Books (C5)', null, '2,350.00']);

    const ledger = (await orderLedgerService.forOrder(orderId, 'EUR', true)).totals;
    expect(ledger.expenses_inr).toBe(6550);
  });

  it('the scheduled sync checks the records in Books once a day, Sync Now always', async () => {
    await companySettingsRepository.set('zoho_books_restore_checked_at', '');
    await zohoRestoreService.markScheduledCheckDone();
    expect(await zohoRestoreService.due('manual')).toBe(true); // Sync Now always checks
    expect(await zohoRestoreService.due('scheduled')).toBe(false); // checked just now

    const pad = (n) => String(n).padStart(2, '0');
    const d = new Date(Date.now() - 24 * 3600 * 1000);
    await companySettingsRepository.set('zoho_books_restore_checked_at', `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`);
    zohoRestoreService.resetDailyDue();
    expect(await zohoRestoreService.due('scheduled')).toBe(true); // a day later the scheduled run checks again
    await companySettingsRepository.set('zoho_books_restore_checked_at', '');
    zohoRestoreService.resetDailyDue();
  });
});
