'use strict';

const db = require('../../src/config/db');
const reportRepository = require('../../src/repositories/reportRepository');
const reportController = require('../../src/controllers/reportController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Sales Performance report — reuses funnelActivity()'s own sent/won/lost
 * counts (never a second, divergent definition), so these tests exercise
 * the parts that are genuinely new: FOB-won-by-currency, the individual
 * lost-order detail rows with their own free-text reason, the
 * auto-derived previous-period comparison (only computed when both dates
 * are given), and the calendar-based preset date math.
 *
 * Every scenario that inserts fixture rows uses its own far-future year
 * (2031+, but never past 2038 — the TIMESTAMP columns involved max out at
 * 2038-01-19) as a private date-range sandbox — the disposable test DB
 * isn't reset between tests in this file, and other fixtures elsewhere
 * default their created_at/sent_at to the real current date, so a
 * shared/real-world date range would silently pick up rows from
 * unrelated tests.
 */
describe('Sales Performance report', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function sendDocument(orderId, typeCode, sentAt) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const docResult = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, 'sent')`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}` }
    );
    const documentId = docResult.insertId;
    await db.execute(
      `INSERT INTO email_log (order_id, document_id, recipient_email, subject, body_snapshot, sent_at, status)
       VALUES (:order_id, :document_id, 'buyer@jest-test.test', 'Test', 'Test body', :sent_at, 'sent')`,
      { order_id: orderId, document_id: documentId, sent_at: sentAt }
    );
    return documentId;
  }

  async function markWon(orderId, piDate) {
    await sendDocument(orderId, 'PI', `${piDate} 10:00:00`);
    await db.execute('UPDATE orders SET pi_date = :pi_date WHERE id = :id', { pi_date: piDate, id: orderId });
  }

  async function markLost(orderId, lostAt, reason) {
    await db.execute(
      `UPDATE orders SET status = 'lost', lost_at = :lost_at, lost_reason = :reason WHERE id = :id`,
      { lost_at: lostAt, reason, id: orderId }
    );
  }

  async function addProduct(orderId, fobValue) {
    await db.execute(
      `INSERT INTO order_products (order_id, line_no, description, quantity, unit_price, fob_value, is_active)
       VALUES (:order_id, 1, :description, 1, :unit_price, :fob_value, 1)`,
      { order_id: orderId, description: 'Jest Test Product', unit_price: fobValue, fob_value: fobValue }
    );
  }

  /** A fresh order under its own fresh client, since a single client can't hold two orders with sequence_no 1. */
  async function newOrder(incotermCode = 'FOB') {
    return createTestOrder(await createTestClient(), incotermCode);
  }

  it('won order counts and sums FOB by currency', async () => {
    const orderId = await newOrder();
    await addProduct(orderId, 1500.0);
    await markWon(orderId, '2031-05-10');

    const report = await reportRepository.salesPerformanceReport('2031-05-01', '2031-05-31');

    expect(report.current.won).toBe(1);
    expect(report.current.fob_won_by_currency.USD).toBe(1500);
  });

  it('lost before PI is distinct from lost after PI', async () => {
    const beforePiOrder = await newOrder();
    await markLost(beforePiOrder, '2032-05-15 09:00:00', 'Price too high');

    const afterPiOrder = await newOrder();
    await markWon(afterPiOrder, '2032-05-05');
    await markLost(afterPiOrder, '2032-05-16 09:00:00', 'Buyer went silent after PI');

    const report = await reportRepository.salesPerformanceReport('2032-05-01', '2032-05-31');

    expect(report.current.lost_before_pi).toBe(1);
    expect(report.current.lost_after_pi).toBe(1);
    expect(report.current.lost_total).toBe(2);

    const byId = {};
    for (const row of report.lost_orders) byId[row.id] = row;
    expect(byId[beforePiOrder].reached_pi).toBe(false);
    expect(byId[beforePiOrder].lost_reason).toBe('Price too high');
    expect(byId[afterPiOrder].reached_pi).toBe(true);
    expect(byId[afterPiOrder].lost_reason).toBe('Buyer went silent after PI');
  });

  it('win rate pct is null without division by zero when nothing was sent', async () => {
    const report = await reportRepository.salesPerformanceReport('2099-01-01', '2099-01-02'); // empty window
    expect(report.current.quotations_sent).toBe(0);
    expect(report.current.win_rate_pct).toBeNull();
    expect(report.current.fob_won_by_currency).toEqual({});
  });

  it('win rate pct computes when quotations were sent', async () => {
    const wonOrder = await newOrder();
    await sendDocument(wonOrder, 'QT', '2033-06-05 09:00:00');
    await markWon(wonOrder, '2033-06-06');

    const lostOrder = await newOrder();
    await sendDocument(lostOrder, 'QT', '2033-06-07 09:00:00');
    await markLost(lostOrder, '2033-06-08 09:00:00', 'Went with a competitor');

    const report = await reportRepository.salesPerformanceReport('2033-06-01', '2033-06-30');

    expect(report.current.quotations_sent).toBe(2);
    expect(report.current.won).toBe(1);
    expect(report.current.win_rate_pct).toBe(50);
  });

  it('previous period is only computed when both dates are given', async () => {
    const reportOpenEnded = await reportRepository.salesPerformanceReport(null, '2034-06-30');
    expect(reportOpenEnded.previous).toBeNull();
    expect(reportOpenEnded.comparison).toBeNull();
    expect(reportOpenEnded.previous_range).toBeNull();

    const reportBounded = await reportRepository.salesPerformanceReport('2034-06-01', '2034-06-30');
    expect(reportBounded.previous).not.toBeNull();
    expect(reportBounded.comparison).not.toBeNull();
    expect(reportBounded.previous_range).not.toBeNull();
  });

  it('previous period is the immediately preceding range of equal length', async () => {
    // 2035-06-01..2035-06-30 is 30 days, so the previous period must be
    // the 30 days immediately before: 2035-05-02..2035-05-31.
    const report = await reportRepository.salesPerformanceReport('2035-06-01', '2035-06-30');
    expect(report.previous_range).toEqual(['2035-05-02', '2035-05-31']);
  });

  it('comparison pct change reflects growth between periods', async () => {
    const prevOrder = await newOrder();
    await markWon(prevOrder, '2036-05-10');

    const curOrder1 = await newOrder();
    await markWon(curOrder1, '2036-06-10');
    const curOrder2 = await newOrder();
    await markWon(curOrder2, '2036-06-11');

    const report = await reportRepository.salesPerformanceReport('2036-06-01', '2036-06-30');

    expect(report.previous.won).toBe(1);
    expect(report.current.won).toBe(2);
    expect(report.comparison.won).toBe(100);
  });

  it('comparison is null for a metric whose previous value was zero', async () => {
    const curOrder = await newOrder();
    await markWon(curOrder, '2037-06-10');

    const report = await reportRepository.salesPerformanceReport('2037-06-01', '2037-06-30');

    expect(report.previous.won).toBe(0);
    expect(report.current.won).toBe(1);
    expect(report.comparison.won).toBeNull();
  });

  it('FOB by currency never sums across different currencies', async () => {
    const usdOrder = await newOrder('FOB');
    await addProduct(usdOrder, 1000.0);
    await markWon(usdOrder, '2031-07-10');

    const eurCurrency = await db.queryOne("SELECT id FROM currencies WHERE code = 'EUR'");
    const eurOrder = await newOrder('FOB');
    await db.execute('UPDATE orders SET currency_id = :cid WHERE id = :id', { cid: eurCurrency.id, id: eurOrder });
    await addProduct(eurOrder, 2000.0);
    await markWon(eurOrder, '2031-07-11');

    const report = await reportRepository.salesPerformanceReport('2031-07-01', '2031-07-31');

    expect(report.current.fob_won_by_currency.USD).toBe(1000);
    expect(report.current.fob_won_by_currency.EUR).toBe(2000);
  });

  it('orders created count is scoped to the date range', async () => {
    const orderId = await newOrder();
    await db.execute('UPDATE orders SET created_at = :created_at WHERE id = :id', {
      created_at: '2032-08-15 12:00:00', id: orderId,
    });

    const inRange = await reportRepository.salesPerformanceReport('2032-08-01', '2032-08-31');
    expect(inRange.current.orders_created).toBe(1);

    const outOfRange = await reportRepository.salesPerformanceReport('2032-09-01', '2032-09-30');
    expect(outOfRange.current.orders_created).toBe(0);
  });

  // presetRange() computes off `new Date()` internally, which we can't
  // inject — so these tests only assert the *shape* and internal
  // *relationships* of the ranges (start <= end, correct span), never a
  // hardcoded date, so they stay valid on whatever day this runs.

  it('this_month preset spans the first to last day of the current month', () => {
    const [from, to] = reportController.presetRange('this_month');
    const fromDt = new Date(`${from}T00:00:00Z`);
    const toDt = new Date(`${to}T00:00:00Z`);

    expect(from.slice(8, 10)).toBe('01');
    expect(from.slice(0, 7)).toBe(to.slice(0, 7));
    const lastDayOfThatMonth = new Date(Date.UTC(toDt.getUTCFullYear(), toDt.getUTCMonth() + 1, 0)).getUTCDate();
    expect(toDt.getUTCDate()).toBe(lastDayOfThatMonth);
  });

  it('last_month preset ends the day before this_month starts', () => {
    const [, lastMonthTo] = reportController.presetRange('last_month');
    const [thisMonthFrom] = reportController.presetRange('this_month');

    const thisMonthFromDt = new Date(`${thisMonthFrom}T00:00:00Z`);
    const dayBefore = new Date(thisMonthFromDt.getTime() - 86400000).toISOString().slice(0, 10);
    expect(lastMonthTo).toBe(dayBefore);
  });

  it('this_quarter preset spans exactly three calendar months', () => {
    const [from, to] = reportController.presetRange('this_quarter');
    const fromDt = new Date(`${from}T00:00:00Z`);
    const toDt = new Date(`${to}T00:00:00Z`);

    expect(from.slice(8, 10)).toBe('01');
    expect([0, 3, 6, 9]).toContain(fromDt.getUTCMonth());
    const monthsApart = (toDt.getUTCFullYear() - fromDt.getUTCFullYear()) * 12 + (toDt.getUTCMonth() - fromDt.getUTCMonth());
    expect(monthsApart).toBe(2);
  });

  it('this_year preset is Jan 1 to Dec 31 of the current year', () => {
    const currentYear = new Date().getFullYear();
    const [from, to] = reportController.presetRange('this_year');

    expect(from).toBe(`${currentYear}-01-01`);
    expect(to).toBe(`${currentYear}-12-31`);
  });

  it('last_year preset is the full prior calendar year', () => {
    const lastYear = new Date().getFullYear() - 1;
    const [from, to] = reportController.presetRange('last_year');

    expect(from).toBe(`${lastYear}-01-01`);
    expect(to).toBe(`${lastYear}-12-31`);
  });

  it('unknown preset returns null', () => {
    expect(reportController.presetRange('not_a_real_preset')).toBeNull();
  });
});
