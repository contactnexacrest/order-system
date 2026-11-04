'use strict';

// Owner decision 4 Nov 2026 (D-245, CAFIN change confirmed twice): under E. PROFIT the totals stay
// locked & paid figures only; while the order is not locked, the estimated associate commission and
// team incentive are shown in their own box, never added to the totals. PHP twin: CafinEstimatedBoxTest.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const incentiveService = require('../../src/services/incentiveService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

describe('CAFIN E. PROFIT — estimated box (owner, 4 Nov 2026)', () => {
  let admin;
  let sales;

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest CAFIN User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-cafin-est-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return r.insertId;
  }

  async function paidOrder() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [sales]);
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
        balance_amount = 2700, balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000, balance_exchange_rate = 92.5926 WHERE order_id = :o`,
      { o: orderId }
    );
    return orderId;
  }

  const grand = (s) => s.pl.filter((r) => r[1] === 'ORDER PROFIT')[0];
  const htmlText = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#39;|&#x27;/g, "'").replace(/\s+/g, ' ');
  async function pdfText(orderId) {
    await documentGenerationService.generateCaInternalAnnexure(orderId, admin);
    const calls = renderPdfFromHtml.mock.calls;
    return htmlText(calls[calls.length - 1][0]);
  }

  beforeEach(async () => {
    admin = await createUser('Admin');
    sales = await createUser('Export Executive');
    await incentiveRepository.replaceUserRoles({ [sales]: ['sales'] });
    await incentiveRepository.setSetting('program_enabled', '1', admin);
  });

  afterEach(async () => {
    await incentiveRepository.replaceUserRoles({});
    await incentiveRepository.setSetting('program_enabled', '0', admin);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('shows the estimated incentive apart; the totals do not change; locking moves it into the totals', async () => {
    const orderId = await paidOrder();
    await incentiveRepository.saveOrder(orderId, { incentive_mode: 'fixed', incentive_value: 20000 }, admin);

    const s = await caFinancialSnapshotService.build(orderId);
    const profit = await orderProfitabilityService.computeForOrder(orderId, false);
    expect([grand(s)[3], grand(s)[2]]).toEqual([money(profit.profit_inr), 'locked & paid figures only']);
    expect(s.pl_estimated).not.toBeNull();
    expect(s.pl_estimated.commission).toBeNull();
    expect(s.pl_estimated.incentive).toBe('20,000.00');
    expect(s.pl_estimated.after).toBe(money(profit.profit_inr - 20000));
    const layer = await orderProfitabilityService.computeForOrder(orderId);
    expect(s.pl_estimated.after).toBe(money(layer.profit_after_incentive_inr));

    const text = await pdfText(orderId);
    expect(text).toContain('locked & paid figures only');
    expect(text).toContain('Not yet locked — estimated, NOT included in the totals above');
    expect(text).toMatch(/Team incentive Estimated 20,000\.00/);
    expect(text).not.toContain('Associate commission Estimated');
    expect(text).toContain(`Profit if these lock as estimated ${s.pl_estimated.after}`);
    expect(text).toContain('this box disappears');

    await incentiveService.lock(orderId, '2026-10-25', admin);
    const locked = await caFinancialSnapshotService.build(orderId);
    expect(locked.pl_estimated).toBeNull();
    expect(grand(locked)[3]).toBe(money(profit.profit_inr - 20000));
  });

  it('shows no box when nothing is estimated', async () => {
    await incentiveRepository.setSetting('program_enabled', '0', admin);
    const orderId = await paidOrder();
    const s = await caFinancialSnapshotService.build(orderId);
    expect(s.pl_estimated).toBeNull();
    const text = await pdfText(orderId);
    expect(text).not.toContain('Not yet locked');
    expect(text).toContain('locked & paid figures only');
  });
});
