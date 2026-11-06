'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const mysql = require('mysql2/promise');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const caRepository = require('../../src/repositories/caRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
const associateCommission = require('../../src/services/associateCommission');
const associateTds = require('../../src/services/associateTds');
const caSyncService = require('../../src/services/caSyncService');
const incentiveService = require('../../src/services/incentiveService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const partPaymentService = require('../../src/services/partPaymentService');
const paymentActionService = require('../../src/services/paymentActionService');
const stageGateService = require('../../src/services/stageGateService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoSyncLock = require('../../src/services/zohoSyncLock');
const { round2 } = require('../../src/helpers/money');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of QaBatch4Test.php — QA fix batch 4 (5 Nov 2026): money, Zoho and the
 * two order findings the owner decided (ORD-09, ORD-14), with the same figures.
 */
describe('QA fix batch 4: money, Zoho, ORD-09, ORD-14', () => {
  let admin;

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest QA4 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-qa4-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode, status) {
    const docType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    await db.execute('INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :dt, :ref, :s)',
      { o: orderId, dt: docType.id, ref: `JEST-QA4-${Math.random().toString(16).slice(2, 10)}`, s: status });
  }

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  /** A TEST- USD order with one goods line worth fob, advance advPct % recorded and cleared at ₹83 (INR credited). */
  async function usdOrder(fob, advPct = 30) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute('UPDATE orders SET advance_pct = :p WHERE id = :o', { p: advPct, o: orderId });
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite', 1, 'SQM', :f, :f2, 'goods')",
      { o: orderId, f: fob, f2: fob });
    const advance = round2(fob * advPct / 100);
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(`UPDATE order_payment_status SET advance_amount = :a, advance_remittance_received_at = '2026-10-05', advance_cleared_at = '2026-10-06 10:00:00',
      advance_inr_actual = :ai, advance_exchange_rate = 83 WHERE order_id = :o`, { a: advance, ai: round2(advance * 83), o: orderId });
    return orderId;
  }

  async function milestone(orderId, pct, received = null, credited = null) {
    const id = Number((await db.execute(
      "INSERT INTO order_payment_milestones (order_id, seq, pct, due_text, received_amount, received_on, exchange_rate) VALUES (:o, 1, :p, 'on dispatch', :r, :d, :x)",
      { o: orderId, p: pct, r: received, d: received !== null ? '2026-10-20' : null, x: received !== null ? 83 : null }
    )).insertId);
    if (received !== null) {
      await db.execute("INSERT INTO order_payment_receipts (order_id, leg, milestone_id, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'milestone', :m, :a, '2026-10-20', 83, :i, 'UTR-QA4-M1')",
        { o: orderId, m: id, a: received, i: credited });
    }
    return id;
  }

  beforeAll(async () => {
    admin = await createTestUser('Admin');
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
  });

  afterAll(async () => {
    await require('../support/fixtures').clearDivisionTradeData(); // leave no division-trade records for later files
    await db.pool.end();
  });

  it('MON-1: TDS is capped at the payout and the rest carried to the next payout', async () => {
    const a = Number((await db.execute("INSERT INTO associates (name, status, based_in, pan) VALUES ('QA4 Associate no PAN', 'active', 'india', NULL)")).insertId);
    const orderId = await createTestOrder(await createTestClient());
    const paidOn = require('../../src/helpers/dbDateTime').todayIst();
    await db.execute(`INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_on, paid_inr, tds_inr)
      VALUES (:o, 'associate', :a, 'associate', 19800, :m, 'paid', :d, 19800, 0)`, { o: orderId, a, m: paidOn.slice(0, 7), d: paidOn });
    const c = await associateTds.compute({ id: 0, associate_id: a, amount_inr: '300.00' }, paidOn);
    expect([c.tds, c.net, c.shortfall, c.rate]).toEqual([300, 0, 3720, 20]);
    expect(c.reason).toContain('capped at the payout; ₹3,720.00 TDS carried to the next payout');

    await db.execute(`INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_on, paid_inr, tds_inr, tds_shortfall_inr)
      VALUES (:o, 'associate', :a, 'associate', 300, :m, 'paid', :d, 0, 300, 3720)`, { o: orderId, a, m: paidOn.slice(0, 7), d: paidOn });
    const next = await associateTds.compute({ id: 0, associate_id: a, amount_inr: '10000.00' }, paidOn);
    expect([next.tds, next.net, next.carried_in, next.shortfall]).toEqual([5720, 4280, 3720, 0]);
  });

  it('MON-2: milestone money counts in revenue, and a milestone not cleared means not fully paid', async () => {
    const orderId = await usdOrder(100000);
    const before = (await orderProfitabilityService.computeForOrder(orderId, false)).revenue_inr;
    await milestone(orderId, 40, 40000, 3320000);
    const after = (await orderProfitabilityService.computeForOrder(orderId, false)).revenue_inr;
    expect(round2(after - before)).toBe(3320000);

    await db.execute("UPDATE order_payment_status SET balance_amount = 30000, balance_cleared_at = '2026-10-25 10:00:00' WHERE order_id = :o", { o: orderId });
    expect(await incentiveService.fullyPaidOn(orderId)).toBeNull();
    await db.execute("UPDATE order_payment_milestones SET cleared_on = '2026-10-21' WHERE order_id = :o", { o: orderId });
    expect(await incentiveService.fullyPaidOn(orderId)).toBe('2026-10-25');
  });

  it("MON-3: a line's value is rounded once, half away from zero, as PHP", async () => {
    const orderId = await createTestOrder(await createTestClient());
    const id = await orderProductRepository.add(orderId, 1, 'Granite', null, null, '100.1', false, 'SQM', '18.45');
    expect(String((await orderProductRepository.find(id)).fob_value)).toBe('1846.85');
  });

  it('MON-4: bank charges deducted in foreign currency are not a forex loss', async () => {
    const orderId = await usdOrder(10000, 100);
    await db.execute('UPDATE order_payment_status SET advance_inr_actual = 827510 WHERE order_id = :o', { o: orderId });
    await db.execute(`INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference, bank_charges, bank_charges_inr)
      VALUES (:o, 'advance', 9970, '2026-10-05', 83, 827510, 'UTR-QA4-BC', 30, 2490)`, { o: orderId });
    const row = (await caRepository.settlementRegister()).find((r) => Number(r.order_id) === orderId && r.leg === 'advance');
    expect([Number(row.forex_gain_loss), Number(row.bank_charges_inr)]).toEqual([0, 2490]);
  });

  it('MON-6: LC bank charges count in the realised rate', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("UPDATE orders SET payment_method = 'LC' WHERE id = :o", { o: orderId });
    await db.execute(`INSERT INTO order_lc_details (order_id, lc_amount, lc_received_amount, lc_received_at, lc_inr_credited, lc_bank_charges_inr)
      VALUES (:o, 50000, 50000, '2026-10-20', 4162500, 12500)`, { o: orderId });
    const order = await orderRepository.find(orderId);
    expect(Math.round((await associateCommission.realisedRate(order)).rate * 10000) / 10000).toBe(83.5);
    expect(Math.round((await incentiveService.productRate(order)) * 10000) / 10000).toBe(83.5);
  });

  it('MON-8: the duplicate guard looks at the bank reference', async () => {
    const orderId = await usdOrder(10000);
    await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 5000, '2026-10-05', 83, 415000, 'UTR-QA4-A')", { o: orderId });
    expect(await partPaymentService.receiptExists(orderId, 'advance', 5000, '2026-10-05', 83, 415000, 'UTR-QA4-A')).toBe(true);
    expect(await partPaymentService.receiptExists(orderId, 'advance', 5000, '2026-10-05', 83, 415000, 'UTR-QA4-B')).toBe(false);
  });

  it('ORD-09: the balance is net of write-offs, the same on the buyer and staff sides', async () => {
    const orderId = await usdOrder(10000);
    const m = await milestone(orderId, 30, 2900, 240700);
    expect(await paymentMilestoneRepository.balanceDeduction(orderId, 10000, false)).toBe(2900);
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, milestone_id, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by)
      VALUES (:o, 'milestone', :m, 3000, 2900, 100, 'written_off', 'Agreed discount with the buyer', :u)`, { o: orderId, m, u: admin });
    expect(await paymentMilestoneRepository.balanceDeduction(orderId, 10000, false)).toBe(3000);
    expect(await paymentActionService.amountDue(await orderRepository.find(orderId), 'balance')).toBe(4000);
    expect(await partPaymentService.expected(orderId, 'balance')).toBe(4000);
  });

  it('ORD-14: Close Order needs the original BLs received and endorsed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    await orderShippingRepository.recordBl(orderId, 'MSCU7654321', '2026-06-15');
    for (let stage = 1; stage <= 8; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    await createTestDocument(orderId, 'BLI', 'approved');
    await createTestDocument(orderId, 'COOPREP', 'sent');
    const req = () => ({ params: { id: String(orderId) }, session: {}, user: { id: admin }, permissions: { close_orders: true }, body: { courier_tracking_number: 'DHL-QA4-1' } });
    await ordersController.closeOrder(req(), fakeRes());
    expect((await orderRepository.find(orderId)).status).toBe('active');

    await orderShippingRepository.recordBlOriginalsReceived(orderId, 3);
    await orderShippingRepository.recordBlEndorsed(orderId, admin);
    await ordersController.closeOrder(req(), fakeRes());
    expect((await orderRepository.find(orderId)).status).toBe('shipped_docs_sent');
  });

  it('ZOH-1: a second sync while one runs stops at once with one Sync Log row', async () => {
    expect(await zohoSyncLock.run(() => 'ran', () => 'busy', 'manual', null)).toBe('ran');
    const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM zoho_sync_log WHERE message = :m', { m: zohoSyncLock.BUSY })).n);
    const before = await count();
    const cfg = db.pool.pool.config.connectionConfig;
    const other = await mysql.createConnection({ host: cfg.host, port: cfg.port, user: cfg.user, password: cfg.password, database: cfg.database });
    try {
      const [rows] = await other.query("SELECT GET_LOCK(CONCAT('nx_zoho_sync_', DATABASE()), 0) AS got");
      expect(Number(rows[0].got)).toBe(1);
      const r = await caSyncService.runFullSync('manual', admin);
      expect(r.stopped).toBe(zohoSyncLock.BUSY);
      expect(await count()).toBe(before + 1);
    } finally {
      await other.query("SELECT RELEASE_LOCK(CONCAT('nx_zoho_sync_', DATABASE()))");
      await other.end();
    }
  });

  it('ZOH-2: a retry adopts the payment already in Books instead of posting it again', async () => {
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    for (const [k, v] of Object.entries({ zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret', zoho_books_refresh_token: 'fake-refresh',
      zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1' })) {
      await companySettingsRepository.set(k, v);
    }
    let posts = 0;
    zohoBooksService.setTransport(async (method, url) => {
      const path = new URL(url).pathname;
      const json = (b, s = 200) => ({ status: s, body: JSON.stringify(b) });
      if (path === '/oauth/v2/token') return json({ access_token: 'fake' });
      if (path === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: [{ contact_id: 'CT-1', contact_name: 'TEST-QA4 Buyer', currency_code: 'INR' }] });
      if (path.startsWith('/books/v3/contacts/')) return json({ code: 0, contact: { contact_id: 'CT-1', currency_code: 'INR' } });
      if (path === '/books/v3/customerpayments' && method === 'GET') {
        return json({ code: 0, customerpayments: [{ payment_id: 'PAY-EXISTING', reference_number: 'NX-QA4-advance', customer_id: 'CT-1', amount: 41500, date: '2026-10-05' }] });
      }
      if (path === '/books/v3/customerpayments' && method === 'POST') {
        posts++;
        return json({ code: 0, payment: { payment_id: 'PAY-NEW' } }, 201);
      }
      return json({ code: 0 });
    });
    try {
      const r = await zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-QA4 Buyer', 'INR', null, 41500, '2026-10-05', 'NX-QA4-advance');
      expect([r.payment_id, posts]).toEqual(['PAY-EXISTING', 0]);
    } finally {
      await companySettingsRepository.set('zoho_books_enabled', '0');
    }
  });
});
