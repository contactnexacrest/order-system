'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const notificationRepository = require('../../src/repositories/notificationRepository');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const orderLcAmendmentRepository = require('../../src/repositories/orderLcAmendmentRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const userRepository = require('../../src/repositories/userRepository');
const balanceDueService = require('../../src/services/balanceDueService');
const permissionService = require('../../src/services/permissionService');
const dates = require('../../src/helpers/dates');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { run } = require('../../src/jobs/checkAlerts');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of GapDueDatesLcTest.php. Gap list answers (owner, 1 Nov 2026,
 * register D-218), part B:
 * G-2 — a balance paid after the BL (option "against BL", and LC orders)
 * has no due date until the scanned BL is sent to the buyer, then the
 * order's balance days; an overdue balance is reminded daily.
 * G-4 — the LC's dates (issue, expiry, last shipment, presentation) with
 * reminders 7 days before each, and the LC amendments.
 */
describe('Gap list G-2 and G-4: balance due dates and LC dates', () => {
  jest.setTimeout(90000);
  const PASSWORD = 'JestGapB2026!';
  let admin;
  let session;

  function addDays(days) {
    const d = new Date(`${todayIst()}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email: `jest-gapb-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    return result.insertId;
  }

  async function call(fn, { params = {}, body = {} } = {}) {
    const req = { params, body, query: {}, session, method: 'POST', path: '/', ip: '127.0.0.1' };
    req.user = await userRepository.findById(admin);
    req.permissions = await permissionService.load(admin, req.user.role_id);
    const res = {
      statusCode: 200,
      status(c) { this.statusCode = c; return this; },
      redirect(l) { this.location = l; },
      send(b) { this.body = b; },
      setHeader() {},
    };
    await fn(req, res);
    return res;
  }

  function flash() {
    const f = (session._flash || [])[0] || { type: '', message: '' };
    delete session._flash;
    return f;
  }

  async function order(preset, assignees = []) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', preset, 'USD', null, null, assignees);
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute('UPDATE order_payment_status SET balance_amount = 2700, balance_due_date = NULL WHERE order_id = :o', { o: orderId });
    await db.execute('INSERT IGNORE INTO order_shipping (order_id) VALUES (:o)', { o: orderId });
    return orderId;
  }

  async function due(orderId) {
    const row = await db.queryOne('SELECT balance_due_date FROM order_payment_status WHERE order_id = :o', { o: orderId });
    return row && row.balance_due_date ? String(row.balance_due_date).slice(0, 10) : null;
  }

  async function ref(orderId) {
    return (await db.queryOne('SELECT order_reference FROM orders WHERE id = :o', { o: orderId })).order_reference;
  }

  beforeAll(async () => {
    admin = await createTestUser('Admin');
  });

  beforeEach(() => {
    session = {};
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('a balance against the BL is due from the day the scanned BL is sent', async () => {
    const orderId = await order('Established Buyer — Post-BL');
    expect(await due(orderId)).toBeNull(); // no due date before the BL is sent
    await call(ordersController.recordScannedBlSent, { params: { id: String(orderId) } });
    const expected = addDays(7);
    expect(await due(orderId)).toBe(expected);
    expect(flash().message).toBe(`Scanned BL copy marked as sent to buyer. Balance payment due on ${dates.short(expected)}.`);
  });

  it('an LC balance too, but a before-shipment balance gets no fixed date', async () => {
    const lc = await order('Irrevocable Letter of Credit at Sight');
    expect(balanceDueService.dueAfterBl(await orderRepository.find(lc))).toBe(true);
    expect(await balanceDueService.startFromScannedBl(lc, '2026-11-10')).toBe('2026-11-17');

    const before = await order('Standard — New Buyer');
    await call(ordersController.recordScannedBlSent, { params: { id: String(before) } });
    expect(await due(before)).toBeNull();
    expect(flash().message).toBe('Scanned BL copy marked as sent to buyer.');

    const cleared = await order('Established Buyer — Post-BL');
    await db.execute('UPDATE order_payment_status SET balance_cleared_at = NOW() WHERE order_id = :o', { o: cleared });
    expect(await balanceDueService.startFromScannedBl(cleared, '2026-11-10')).toBeNull(); // a cleared balance is left alone
  });

  it('an overdue balance is reminded daily to Admin and the assignees', async () => {
    const assignee = await createTestUser('Export Executive');
    const orderId = await order('Established Buyer — Post-BL', [assignee]);
    const yesterday = addDays(-1);
    await db.execute('UPDATE order_payment_status SET balance_due_date = :d WHERE order_id = :o', { d: yesterday, o: orderId });

    await run();
    for (const u of [admin, assignee]) {
      expect(await notificationRepository.existsToday(u, 'balance_overdue', orderId)).toBe(true);
    }
    const msg = await db.queryOne("SELECT message FROM notifications WHERE user_id = :u AND type = 'balance_overdue' AND related_order_id = :o", { u: assignee, o: orderId });
    expect(msg.message).toBe(`Balance payment overdue on order ${await ref(orderId)} — was due ${yesterday}, still not cleared.`);
    await run();
    const count = await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'balance_overdue' AND related_order_id = :o", { u: assignee, o: orderId });
    expect(Number(count.n)).toBe(1); // once a day

    const today = await order('Established Buyer — Post-BL', [assignee]);
    await db.execute('UPDATE order_payment_status SET balance_due_date = CURDATE() WHERE order_id = :o', { o: today });
    await run();
    expect(await notificationRepository.existsToday(assignee, 'balance_overdue', today)).toBe(false); // due today is not overdue yet
  });

  it('the LC dates are checked and saved', async () => {
    let target = await order('Irrevocable Letter of Credit at Sight');
    const save = async (body) => {
      await call(ordersController.saveLcDates, { params: { id: String(target) }, body });
      return flash();
    };
    expect((await save({ lc_expiry_date: '2026-02-30' })).message).toBe('Enter each LC date as a date, or leave it empty.');
    expect((await save({ lc_issue_date: addDays(1) })).message).toBe('The LC issue date cannot be in the future.');
    expect((await save({ lc_expiry_date: '2026-12-31', lc_last_shipment_date: '2027-01-05' })).message).toBe('The last shipment date cannot be after the LC expiry date.');
    expect((await save({ lc_expiry_date: '2026-12-31', lc_presentation_deadline: '2027-01-05' })).message).toBe('The deadline to present documents cannot be after the LC expiry date.');
    expect((await save({ lc_issue_date: '2026-10-01', lc_expiry_date: '2026-12-31', lc_last_shipment_date: '2026-09-30' })).message)
      .toBe('The expiry, last shipment and presentation dates must be after the LC issue date (01 Oct 2026).');
    expect((await save({ lc_issue_date: '2026-10-01', lc_expiry_date: '2026-12-31', lc_last_shipment_date: '2026-12-10', lc_presentation_deadline: '2026-12-21' })).message)
      .toBe('LC dates saved. Reminders go out 7 days before the last shipment date, the deadline to present documents and the expiry.');
    const lc = await orderLcDetailRepository.find(target);
    expect([lc.lc_issue_date, lc.lc_expiry_date, lc.lc_last_shipment_date, lc.lc_presentation_deadline].map((v) => String(v).slice(0, 10)))
      .toEqual(['2026-10-01', '2026-12-31', '2026-12-10', '2026-12-21']);
    const audits = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'LC_DATE_SAVED' AND entity_id = :o", { o: target });
    expect(Number(audits.n)).toBe(4);

    target = await order('Standard — New Buyer');
    expect((await save({ lc_expiry_date: '2026-12-31' })).message).toBe('This order is not paid by Letter of Credit.');
  });

  it('LC date warnings, and reminders 7 days before, once each', async () => {
    const assignee = await createTestUser('Export Executive');
    const orderId = await order('Irrevocable Letter of Credit at Sight', [assignee]);
    const in5 = addDays(5);
    const in20 = addDays(20);
    const past = addDays(-2);
    await orderLcDetailRepository.saveDates(orderId, null, in20, in5, past, admin);
    await db.execute("UPDATE order_lc_details SET lc_number = 'LC-777' WHERE order_id = :o", { o: orderId });
    expect(orderLcDetailRepository.dateWarnings(await orderLcDetailRepository.find(orderId)))
      .toEqual([{ label: 'Last shipment date', date: in5, days: 5 }, { label: 'Deadline to present documents', date: past, days: -2 }]);

    await run();
    const rows = await db.query("SELECT type, message FROM notifications WHERE user_id = :u AND related_order_id = :o AND type LIKE 'lc_%'", { u: assignee, o: orderId });
    // only the date within 7 days; not the past one, not the one 20 days away
    expect(rows.map((r) => [r.type, r.message])).toEqual([['lc_last_shipment_soon', `Order ${await ref(orderId)}: LC LC-777 — last shipment date is ${in5} (5 day(s) away).`]]);
    expect(await notificationRepository.existsToday(admin, 'lc_last_shipment_soon', orderId)).toBe(true);

    await db.execute('UPDATE notifications SET created_at = DATE_SUB(created_at, INTERVAL 1 DAY) WHERE related_order_id = :o', { o: orderId });
    await run();
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND related_order_id = :o AND type = 'lc_last_shipment_soon'", { u: assignee, o: orderId });
    expect(Number(n.n)).toBe(1); // once, not every day

    await db.execute('UPDATE order_lc_details SET lc_received_at = CURDATE() WHERE order_id = :o', { o: orderId });
    expect(orderLcDetailRepository.dateWarnings(await orderLcDetailRepository.find(orderId))).toEqual([]); // no warning once the bank has paid
  });

  it('LC amendments are recorded', async () => {
    const orderId = await order('Irrevocable Letter of Credit at Sight');
    const add = async (body, id = orderId) => {
      await call(ordersController.addLcAmendment, { params: { id: String(id) }, body });
      return flash();
    };
    expect((await add({ amended_on: addDays(1), reference: 'AMD-1', change_text: 'Expiry extended' })).message).toBe('Enter the date of the amendment (today or earlier).');
    expect((await add({ amended_on: '2026-10-20', reference: '', change_text: 'Expiry extended' })).message).toBe('Enter the amendment number or reference (up to 100 characters).');
    expect((await add({ amended_on: '2026-10-20', reference: 'AMD-1', change_text: 'abc' })).message).toBe('Say what the amendment changed (5 to 500 characters).');
    expect((await add({ amended_on: '2026-10-20', reference: 'AMD-1', change_text: 'Last shipment date extended to 30 Nov 2026' })).message)
      .toBe('LC amendment AMD-1 recorded. If it changed a date, update the LC dates too.');
    const rows = await orderLcAmendmentRepository.forOrder(orderId);
    expect([String(rows[0].amended_on).slice(0, 10), rows[0].reference, rows[0].change_text]).toEqual(['2026-10-20', 'AMD-1', 'Last shipment date extended to 30 Nov 2026']);
    const audits = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'LC_AMENDMENT_RECORDED' AND entity_id = :id", { id: rows[0].id });
    expect(Number(audits.n)).toBe(1);

    const cash = await order('Standard — New Buyer');
    expect((await add({ amended_on: '2026-10-20', reference: 'AMD-2', change_text: 'Expiry extended' }, cash)).message).toBe('This order is not paid by Letter of Credit.');
  });

  it('the order page shows the LC dates, amendments and warning', async () => {
    const orderId = await order('Irrevocable Letter of Credit at Sight');
    await orderLcDetailRepository.saveDates(orderId, '2026-10-01', addDays(30), addDays(3), null, admin);
    await orderLcAmendmentRepository.add(orderId, '2026-10-20', 'AMD-9', 'Partial shipment allowed', null, admin);
    const user = await userRepository.findById(admin);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    const html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain('<span class="badge badge-lost">LC date within 7 days</span>');
    expect(html).toContain(`Last shipment date: ${dates.short(addDays(3))} — in 3 day(s)`);
    expect(html).toContain('<td>AMD-9</td><td>Partial shipment allowed</td>');
    expect(html).toContain(`action="/orders/${orderId}/lc-dates"`);
    expect(html).toContain('set when the scanned BL is sent to the buyer (Step 11b)');
  });
});
