'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const associatePortalRepository = require('../../src/repositories/associatePortalRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const associateCommission = require('../../src/services/associateCommission');
const associatePayoutService = require('../../src/services/associatePayoutService');
const incentiveService = require('../../src/services/incentiveService');
const { short } = require('../../src/helpers/dates');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AssociatePaymentsD264Test.php — owner decision D-264 (7 Nov 2026): the associate's
 * commission is fixed by itself when the buyer has paid in full — in rupees at the rate of ALL
 * the buyer's payments — and never changes; the order currency is the figure associates see
 * (rupees only for India, once fixed); India is paid in INR, abroad in USD/EUR/GBP; OUR charges
 * by default; small amounts abroad carry to the next run; paying records what it really cost
 * (a company cost, never the order's). Same figures and wording as the PHP test: advance
 * USD 5,000 credited ₹4,15,500.00, balance USD 12,857.14 credited ₹10,74,678.33 → ₹83.45;
 * 7% → USD 1,250.00 = ₹104,312.50.
 */
describe('Associate payments (D-264)', () => {
  const PASSWORD = 'JestD264!1';
  let admin;

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-d264-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    admin = { id: (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId, email };
  });

  beforeEach(async () => {
    for (const [k, v] of Object.entries({ associate_commission_due_days: '30', associate_claim_hold: '1', associate_payout_run: 'monthly', associate_payout_day: '5',
      associate_counting_threshold_inr: '500000', associate_abroad_charges: 'OUR', associate_abroad_min_payout_usd: '100' })) {
      await incentiveRepository.setSetting(k, v, admin.id);
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** [associate id, client id] */
  async function associate(basedIn = 'india', currency = 'INR') {
    const level = Number((await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 3'")).id);
    const a = (await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id, based_in, currency_code) VALUES (:n, 'active', 'fixed', :l, :b, :c)",
      { n: `Demo Associate ${crypto.randomBytes(3).toString('hex')}`, l: level, b: basedIn, c: currency })).insertId;
    const client = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a, c: client });
    return [Number(a), client];
  }

  /** The mockup order: one goods line USD 17,857.14; advance and balance receipts; balance cleared on `cleared` (null = not yet). */
  async function order(client, cleared = '2026-11-12', balanceRupees = true) {
    const orderId = await createTestOrder(client, 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite', 100, 'CBM', 178.5714, 17857.14, 'goods')", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute('UPDATE order_payment_status SET advance_amount = 5000.00, advance_cleared_at = :ac, balance_amount = 12857.14, balance_cleared_at = :bc WHERE order_id = :o',
      { ac: '2026-10-15 10:00:00', bc: cleared === null ? null : `${cleared} 11:00:00`, o: orderId });
    await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 5000.00, '2026-10-15', 83.10, 415500.00, 'FIRC2610150003')", { o: orderId });
    await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'balance', 12857.14, '2026-11-12', 83.59, :i, 'FIRC2611120007')",
      { o: orderId, i: balanceRupees ? 1074678.33 : null });
    return orderId;
  }

  const payout = (orderId) => db.queryOne("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL", { o: orderId });
  const lastNotice = async (a) => String((await db.queryOne('SELECT message FROM associate_events WHERE associate_id = :a ORDER BY id DESC LIMIT 1', { a })).message);
  const todayIst = () => new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);

  it('fixes the commission by itself at full payment, at the rate of all the buyer\'s payments', async () => {
    const [a, client] = await associate();
    const orderId = await order(client);
    expect(await associatePayoutService.autoFix(orderId)).toBe(true);
    const p = await payout(orderId);
    expect([p.amount_fc, p.amount_inr, p.fixed_rate, String(p.due_on).slice(0, 10), String(p.payout_run_on).slice(0, 10), p.status])
      .toEqual(['1250.00', '104312.50', '83.450000', '2026-12-12', '2027-01-05', 'due']);
    const row = await incentiveRepository.forOrder(orderId);
    expect(row.associate_fixed_at).toBeTruthy();
    expect(row.locked_at).toBeNull();
    const entry = (await incentiveRepository.costEntries(orderId)).associate;
    expect(Number(entry.amount_inr)).toBe(104312.5);
    expect(String(entry.description).endsWith(`; fixed on ${short(todayIst())} at ₹83.45 per USD`)).toBe(true);
    const o = await orderRepository.find(orderId);
    expect(await lastNotice(a)).toBe(`Your client ${o.company_legal_name} has paid order ${o.order_reference} in full. Your commission is now fixed at ₹104,312.50 (USD 1,250.00, 7% of the invoiced goods value, at ₹83.45 per USD — the rate at which the buyer's payments were converted by the bank). This amount will not change, whatever the exchange rate does from now on. It is due on 12 Dec 2026 and will be paid in the 05 Jan 2027 payout run to your verified bank account, after TDS as the law requires. Please upload your invoice for this commission under My commission. NexaCrest pays all bank charges.`);

    // Once only; the MD's later team lock adds the team's part without a second associate payout.
    expect(await associatePayoutService.autoFix(orderId)).toBe(false);
    expect(await incentiveService.lockIfReady(orderId, admin.id)).toBeNull();
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate'", { o: orderId })).n)).toBe(1);
    expect((await payout(orderId)).amount_inr).toBe('104312.50');
    // Unlocking the team incentive keeps the associate's fixed commission.
    expect(await incentiveService.unlock(orderId, admin.id, 'Team shares were wrong, fixing them')).toBeNull();
    expect(await payout(orderId)).toBeTruthy();
    expect((await incentiveRepository.forOrder(orderId)).associate_fixed_at).toBeTruthy();
  });

  it('is not fixed while a receipt\'s rupees are missing; the daily job fixes it once they are recorded', async () => {
    const [, client] = await associate();
    const orderId = await order(client, '2026-11-12', false);
    expect(await associatePayoutService.autoFix(orderId)).toBe(false);
    await db.execute("UPDATE order_payment_receipts SET inr_credited = 1074678.33 WHERE order_id = :o AND leg = 'balance'", { o: orderId });
    expect((await associatePayoutService.daily('2026-11-13')).fixed).toBeGreaterThanOrEqual(1);
    expect((await payout(orderId)).amount_inr).toBe('104312.50');
    expect(await associatePayoutService.autoFix(await order(client, null))).toBe(false);
  });

  it('words the display for India and abroad', async () => {
    expect(associatePayoutService.fixedRupeeLine(104312.5, 83.45, 'USD', '2026-11-12 10:00:00')).toBe('You will be paid ₹104,312.50 (fixed at ₹83.45 per USD on 12 Nov 2026; will not change)');
    expect((await associatePayoutService.howFixedBox({ based_in: 'india' })).title).toBe('How your commission is fixed — it never changes afterwards');
    expect((await associatePayoutService.howFixedBox({ based_in: 'india' })).text).toBe("Your commission is a percentage of the goods value (FOB) in the order's currency. On the day the buyer completes payment, it is fixed in rupees at the exchange rate the buyer's payment was actually converted at. That rupee amount is what you are paid. Later changes in the exchange rate do not raise or lower it. NexaCrest pays all bank charges.");
    expect((await associatePayoutService.howFixedBox({ based_in: 'abroad', currency_code: 'USD' })).text).toBe("Your commission is a percentage of the goods value (FOB). It is fixed in US dollars on the day the buyer completes payment, and you are paid exactly that amount in US dollars to your verified account. NexaCrest pays its own and the intermediary bank's charges. Any conversion into your local currency is done by your own bank.");
    await incentiveRepository.setSetting('associate_abroad_charges', 'SHA', admin.id);
    expect((await associatePayoutService.howFixedBox({ based_in: 'abroad', currency_code: 'USD' })).text).toContain("NexaCrest pays its own bank's charges; your own bank may charge you on receipt.");
    await incentiveRepository.setSetting('associate_abroad_charges', 'OUR', admin.id);

    const [a, client] = await associate('abroad', 'USD');
    const orderId = await order(client);
    expect(await associatePayoutService.autoFix(orderId)).toBe(true);
    const msg = await lastNotice(a);
    expect(msg).toContain('Your commission is now fixed at USD 1,250.00 (7% of the invoiced goods value). This amount will not change. It is due on 12 Dec 2026 and will be paid in the 05 Jan 2027 payout run to your verified bank account, paid in US dollars.');
    expect(msg).not.toContain('₹');

    const t = await associatePortalRepository.totals(a, 24, '2027-01-01');
    expect(associatePortalRepository.fcText(t.fc.waiting)).toBe('USD 1,250.00');
    expect(associatePortalRepository.fcText(t.fc.paid)).toBe('USD 0.00');
    const p = await payout(orderId);
    expect(associatePortalRepository.payoutLabel(p, false, '', '2026-11-20')).toBe('Fixed — due 12 Dec 2026, paid in the 05 Jan 2027 payout run');
    expect(associatePortalRepository.payoutLabel(p, false, '', '2026-12-20')).toBe('Due — paid in the 05 Jan 2027 payout run');
  });

  it('pays an associate in India in INR always, one abroad in an active currency, never INR', async () => {
    const codes = await require('../../src/controllers/associateAdminController').abroadCurrencyCodes();
    expect(codes).not.toContain('INR');
    expect(codes).toContain('USD');
    const agent = request.agent(app);
    let csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: admin.email, password: PASSWORD });
    for (const [basedIn, posted, saved] of [['india', 'USD', 'INR'], ['abroad', 'EUR', 'EUR'], ['abroad', 'INR', null]]) {
      const email = `d264-${basedIn}-${crypto.randomBytes(3).toString('hex')}@email.com`;
      csrf = (await agent.get('/associates/new')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
      await agent.post('/associates').type('form').send({ _csrf: csrf, name: `D264 Paid In ${basedIn}`, based_in: basedIn, currency_code: posted, commission_mode: 'ladder', email });
      const row = await db.queryOne('SELECT currency_code FROM associates WHERE email = :e', { e: email });
      expect(row ? row.currency_code : null).toBe(saved);
    }
  });

  it('books the payout difference abroad as a company cost, with its description', async () => {
    const [a, client] = await associate('abroad', 'USD');
    const orderId = await order(client);
    await associatePayoutService.autoFix(orderId);
    let p = await payout(orderId);
    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = 104312.50, tds_inr = 0, paid_reference = 'FT2612050001' WHERE id = :id", { id: p.id });
    await associatePayoutService.recordPaymentCosts(Number(p.id), 105125.0, 1850.0);
    p = await payout(orderId);
    expect([p.bank_debited_inr, p.bank_charges_inr, p.payout_difference_inr]).toEqual(['105125.00', '1850.00', '2662.50']);
    const o = await orderRepository.find(orderId);
    expect(await associatePayoutService.payoutCostDescription(p)).toBe(`Associate commission payout — ${o.order_reference} — ${(await associateRepository.find(a)).name} — USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer charges ₹1,850.00`);
    expect(Number((await incentiveRepository.costEntries(orderId)).associate.amount_inr)).toBe(104312.5);
    expect(associatePayoutService.payoutDifference({ amount_inr: 104312.5, tds_inr: 2086.25 }, null, 15, true)).toBe(15);
  });

  it('holds and releases for a buyer claim and works a settlement out again at the same fixed rate', async () => {
    const [a, client] = await associate();
    const orderId = await order(client);
    await associatePayoutService.autoFix(orderId);
    await db.execute("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Two slabs cracked', 'open', '2026-11-20 10:00:00')", { o: orderId, c: client });
    expect((await associatePayoutService.daily('2026-11-21')).held).toBe(1);
    expect([(await payout(orderId)).status, (await payout(orderId)).hold_reason]).toEqual(['held', associatePayoutService.CLAIM_HOLD_REASON]);
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                      VALUES (:o, 'balance', 12857.14, 12500.00, 357.14, 'written_off', 'Claim settled for two cracked slabs', :u, 'claim_settled')`, { o: orderId, u: admin.id });
    await db.execute("UPDATE order_complaints SET status = 'resolved', resolved_at = NOW() WHERE order_id = :o", { o: orderId });
    const out = await associatePayoutService.daily('2026-11-25');
    expect([out.recomputed, out.released]).toEqual([1, 1]);
    const p = await payout(orderId);
    expect([p.amount_fc, p.amount_inr, p.fixed_rate, p.status]).toEqual(['1225.00', '102226.25', '83.450000', 'due']);
    expect(Number((await incentiveRepository.costEntries(orderId)).associate.amount_inr)).toBe(102226.25);
    const o = await orderRepository.find(orderId);
    const all = (await db.query('SELECT message FROM associate_events WHERE associate_id = :a', { a })).map((r) => r.message).join('\n');
    expect(all).toContain(`Order ${o.order_reference}: after an agreed discount or a settled buyer claim, your commission is now USD 1,225.00 = ₹102,226.25, at the same fixed rate of ₹83.45 per USD.`);
  });

  it('lets the MD change a fixed commission with a reason, at the same rate, until it is paid', async () => {
    const [, client] = await associate();
    const orderId = await order(client);
    await associatePayoutService.autoFix(orderId);
    const md = { id: admin.id };
    expect(await associatePayoutService.changeCommission(orderId, '1000', 'short', md)).toBe('Say why the commission is changed (10 to 500 characters).');
    expect(await associatePayoutService.changeCommission(orderId, '1000', 'Agreed with the associate after the late claim', md)).toBeNull();
    const p = await payout(orderId);
    expect([p.amount_fc, p.amount_inr]).toEqual(['1000.00', '83450.00']);
    const c = await associateCommission.forOrder(orderId);
    expect([c.amount_fc, c.amount_inr, c.override]).toEqual([1000, 83450, true]);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_CHANGED' AND entity_id = :id", { id: p.id })).n)).toBe(1);
    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2027-01-05', paid_inr = 81781.00 WHERE id = :id", { id: p.id });
    expect(await associatePayoutService.changeCommission(orderId, '900', 'Second thoughts after the payment', md)).toBe('This commission is already paid, so it can no longer be changed.');
  });

  it('carries a small amount abroad to the next run', async () => {
    const [, client] = await associate('abroad', 'USD');
    const orderId = await order(client);
    await associatePayoutService.autoFix(orderId);
    let p = await payout(orderId);
    await db.execute("UPDATE incentive_payouts SET amount_fc = 60.00, amount_inr = 5007.00, payout_run_on = '2027-01-05' WHERE id = :id", { id: p.id });
    expect(await associatePayoutService.carrySmallAbroad('2027-01-05')).toBeGreaterThanOrEqual(1); // this payout at least (the shared test database may hold others on that run)
    p = await payout(orderId);
    expect([String(p.payout_run_on).slice(0, 10), p.incentive_month, p.note]).toEqual(['2027-02-05', '2027-02', 'Carried to the next payout run: the amount due is below the USD 100 minimum for a transfer abroad.']);
  });
});
