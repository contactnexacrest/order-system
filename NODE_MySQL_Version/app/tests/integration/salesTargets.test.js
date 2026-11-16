'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const salesTargetRepository = require('../../src/repositories/salesTargetRepository');
const salesTargetService = require('../../src/services/salesTargetService');
const { performanceCsvRows } = require('../../src/controllers/reportController');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — spec section 5
 * (docs/decisions/D-378-sales-targets-spec.md).
 *
 * The disposable test database is shared by every test file and never reset between
 * them, so every scenario that confirms orders uses its own future dates (2033-2037; TIMESTAMP columns stop at 2038) as a
 * private sandbox, and compares only its own credit rows. Rows this file adds to the
 * feature's own tables (company target, targets, settings) are removed after each test.
 *
 * PHP twin: tests/Integration/SalesTargetsTest.php.
 */
describe('D-378: sales targets', () => {
  const PASSWORD = 'JestSalesTargets!1';
  let companyRows = [];
  // What this file leaves behind is removed at the end: sync() credits every confirmed order of the
  // shared database, and runAlerts() records its "once" guards and notices.
  let started = null;

  beforeAll(async () => {
    started = {
      at: Number((await db.queryOne('SELECT UNIX_TIMESTAMP() AS t')).t), // seconds: the same in every session time zone
      alertId: Number((await db.queryOne('SELECT COALESCE(MAX(id), 0) AS m FROM compliance_alerts_sent')).m),
      noticeId: Number((await db.queryOne('SELECT COALESCE(MAX(id), 0) AS m FROM notifications')).m),
    };
  });

  afterAll(async () => {
    await db.execute('DELETE FROM sales_target_credits WHERE UNIX_TIMESTAMP(created_at) >= :t', { t: started.at });
    await db.execute("DELETE FROM compliance_alerts_sent WHERE id > :i AND alert_kind LIKE 'sales_target_%'", { i: started.alertId });
    await db.execute("DELETE FROM notifications WHERE id > :i AND type LIKE 'sales_target_%'", { i: started.noticeId });
    await db.pool.end();
  });

  beforeEach(() => {
    salesTargetService.resetCache();
  });

  afterEach(async () => {
    for (const id of companyRows) await db.execute('DELETE FROM sales_target_company WHERE id = :id', { id });
    companyRows = [];
    await db.execute("DELETE FROM sales_targets WHERE reason LIKE 'Jest D-378%'");
    await db.execute("UPDATE sales_target_settings SET setting_value = NULL WHERE setting_key IN ('alert_behind_recipients', 'alert_review_recipients', 'alert_no_owner_recipients')");
  });

  // ---------------------------------------------------------------- helpers

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-d378-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Sales Target User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    return Number(result.insertId);
  }

  async function signIn(userId) {
    const u = await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId });
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  async function csrfOf(agent) {
    return (await agent.get('/')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  const order = async (clientId = null) => Number(await createTestOrder(clientId ?? await createTestClient()));

  const receipt = (orderId, on) => db.execute(
    "INSERT INTO order_payment_receipts (order_id, leg, amount, received_on) VALUES (:o, 'advance', 1000.00, :d)", { o: orderId, d: on }
  );

  const salesDepartmentId = async () => Number((await db.queryOne("SELECT id FROM departments WHERE code = 'SALES'")).id);

  const salesOwner = async (orderId, userId) => db.execute(
    'INSERT INTO order_department_owners (order_id, department_id, user_id, assigned_by) VALUES (:o, :d, :u, :u2)',
    { o: orderId, d: await salesDepartmentId(), u: userId, u2: userId }
  );

  async function salesDesignationId(title = 'Sales/Export Executive') {
    const r = await db.queryOne(
      "SELECT dd.id FROM department_designations dd JOIN departments d ON d.id = dd.department_id WHERE d.code = 'SALES' AND dd.title = :t", { t: title }
    );
    return Number(r.id);
  }

  const member = (userId, designationId) => db.execute(
    'INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default) VALUES (:u, :d, CURDATE(), 1)',
    { u: userId, d: designationId }
  );

  /** This test's own credit rows (order id => row). */
  async function credits(orderIds) {
    const all = await salesTargetRepository.credits();
    const out = new Map();
    for (const [oid, c] of all) if (orderIds.includes(oid)) out.set(oid, c);
    return out;
  }

  async function sendQt(orderId, sentAt) {
    const doc = await createTestDocument(orderId, 'QT', 'sent');
    await db.execute(
      `INSERT INTO email_log (order_id, document_id, recipient_email, subject, body_snapshot, sent_at, status)
       VALUES (:o, :d, 'buyer@jest-test.test', 'Quotation', 'Body', :s, 'sent')`,
      { o: orderId, d: doc, s: sentAt }
    );
  }

  const ymd = (v) => (v === null || v === undefined ? null : String(v).slice(0, 10));

  // ---------------------------------------------------------------- periods

  test('periods follow the Indian financial year', () => {
    const q = salesTargetService.period('quarter', '2026-11-15');
    expect([q.start, q.end, q.months, q.label]).toEqual(['2026-10-01', '2026-12-31', 3, 'Oct–Dec 2026']);
    const m = salesTargetService.period('month', '2026-02-10');
    expect([m.start, m.end, m.months, m.label]).toEqual(['2026-02-01', '2026-02-28', 1, 'Feb 2026']);
    const h1 = salesTargetService.period('half', '2026-06-30');
    expect([h1.start, h1.end, h1.label]).toEqual(['2026-04-01', '2026-09-30', 'Apr–Sep 2026']);
    const h2 = salesTargetService.period('half', '2027-02-01');
    expect([h2.start, h2.end, h2.label]).toEqual(['2026-10-01', '2027-03-31', 'Oct 2026–Mar 2027']);
    const y = salesTargetService.period('year', '2026-03-31');
    expect([y.start, y.end, y.months, y.label]).toEqual(['2025-04-01', '2026-03-31', 12, 'Apr 2025–Mar 2026']);
    expect(salesTargetService.previous(q).start).toBe('2026-07-01');
    expect(salesTargetService.recentPeriods('quarter', '2026-11-15').map((p) => p.key)).toEqual(['2026-10-01', '2026-07-01', '2026-04-01', '2026-01-01']);
    expect(salesTargetService.monthsOf(q)).toEqual(['2026-10-01', '2026-11-01', '2026-12-01']);
    expect(salesTargetService.elapsedPct(salesTargetService.period('month', '2026-11-15'), '2026-11-15')).toBe(50);
  });

  // ---------------------------------------------------------------- confirmation

  test('confirmation rules as pure facts', () => {
    expect(salesTargetService.confirmation({ advance_receipt_on: '2034-01-05', advance_status_on: '2034-01-01', advance_pct: '40' })).toEqual(['2034-01-05', 'advance']);
    expect(salesTargetService.confirmation({ advance_receipt_on: null, advance_status_on: '2034-01-01', advance_pct: '40' })).toEqual(['2034-01-01', 'advance']);
    expect(salesTargetService.confirmation({ advance_receipt_on: '2034-01-09', lc_received_on: '2034-01-03', advance_pct: '40' })).toEqual(['2034-01-03', 'lc']);
    // PO + PI: only with no advance, the later of the two.
    const poPi = { advance_pct: '0.00', buyer_po_received_on: '2034-01-10', frozen_on: '2034-01-07' };
    expect(salesTargetService.confirmation(poPi)).toEqual(['2034-01-10', 'po_pi']);
    expect(salesTargetService.confirmation({ ...poPi, advance_pct: '40.00' })).toEqual([null, null]);
    expect(salesTargetService.confirmation({ ...poPi, advance_pct: null })).toEqual(['2034-01-10', 'po_pi']); // no advance % counts as 0
    expect(salesTargetService.confirmation({ advance_pct: '0', buyer_po_received_on: '2034-01-10', frozen_on: null })).toEqual([null, null]);
  });

  test('sync confirms by advance, LC and PO + PI, and skips test data', async () => {
    const advance = await order();
    await receipt(advance, '2034-02-10');
    const fallback = await order();
    await require('../../src/repositories/orderPaymentStatusRepository').initializeForOrder(fallback);
    await db.execute("UPDATE order_payment_status SET advance_remittance_received_at = '2034-02-18 09:00:00' WHERE order_id = :o", { o: fallback });
    const lc = await order();
    await db.execute("INSERT INTO order_lc_details (order_id, lc_number, lc_received_at) VALUES (:o, 'LC-D378', '2034-02-12')", { o: lc });
    const poPi = await order();
    await db.execute("UPDATE orders SET advance_pct = 0, buyer_po_received_on = '2034-02-14', frozen_at = '2034-02-16 10:00:00' WHERE id = :o", { o: poPi });
    const withAdvanceTerms = await order();
    await db.execute("UPDATE orders SET advance_pct = 40, buyer_po_received_on = '2034-02-14', frozen_at = '2034-02-16 10:00:00' WHERE id = :o", { o: withAdvanceTerms });
    const testData = await order();
    await db.execute('UPDATE orders SET is_test_data = 1 WHERE id = :o', { o: testData });
    await receipt(testData, '2034-02-10');
    const lostFirst = await order();
    await db.execute("UPDATE orders SET status = 'lost', lost_at = '2034-02-01 10:00:00' WHERE id = :o", { o: lostFirst });
    await receipt(lostFirst, '2034-02-03');

    await salesTargetService.sync('2034-03-01');
    const c = await credits([advance, fallback, lc, poPi, withAdvanceTerms, testData, lostFirst]);

    expect([ymd(c.get(advance).confirmed_on), c.get(advance).how]).toEqual(['2034-02-10', 'advance']);
    expect([ymd(c.get(fallback).confirmed_on), c.get(fallback).how]).toEqual(['2034-02-18', 'advance']);
    expect([ymd(c.get(lc).confirmed_on), c.get(lc).how]).toEqual(['2034-02-12', 'lc']);
    expect([ymd(c.get(poPi).confirmed_on), c.get(poPi).how]).toEqual(['2034-02-16', 'po_pi']);
    expect(c.has(withAdvanceTerms)).toBe(false); // PO + PI counts only on no-advance terms
    expect(c.has(testData)).toBe(false); // test data never counts
    // Leave no test data behind: Test Mode cannot be switched off while any exists (later suites rely on it).
    await db.execute('UPDATE orders SET is_test_data = 0 WHERE id = :o', { o: testData });
    expect(c.has(lostFirst)).toBe(false); // lost before it was confirmed never counts
    // Containers: the estimate from the goods lines, at least 1 (these orders have none).
    expect(Number(c.get(advance).containers_confirmed)).toBe(1);
    expect(await salesTargetService.estimatedContainers(advance)).toBe(1);

    // A second run changes nothing (dates are kept).
    await db.execute("UPDATE order_payment_receipts SET received_on = '2034-01-01' WHERE order_id = :o", { o: advance });
    await salesTargetService.sync('2034-03-02');
    expect(ymd((await credits([advance])).get(advance).confirmed_on)).toBe('2034-02-10');
  });

  // ---------------------------------------------------------------- corrections

  test('corrections are dated events', () => {
    const row = (id, conf, cc, shipped = null, cs = null, rev = null) => ({
      order_id: id, client_id: id, confirmed_on: conf, how: 'advance', containers_confirmed: cc,
      shipped_on: shipped, containers_shipped: cs, reversed_on: rev, reversed_why: rev ? 'cancelled' : null, is_new_buyer: 0,
    });
    // Cancelled in the same period: 0.
    expect(salesTargetService.periodCounts([row(1, '2035-04-05', 2, null, null, '2035-05-01')], '2035-04-01', '2035-06-30').counted).toBe(0);
    // Cancelled in a later period: +2 stays in Apr–Jun, −2 in Jul–Sep.
    const later = [row(1, '2035-04-05', 2, null, null, '2035-07-20')];
    expect(salesTargetService.periodCounts(later, '2035-04-01', '2035-06-30').counted).toBe(2);
    const q3 = salesTargetService.periodCounts(later, '2035-07-01', '2035-09-30');
    expect([q3.counted, q3.corrections]).toEqual([-2, -2]);
    // Shipped fewer containers than confirmed: the difference on the shipping day.
    const less = [row(1, '2035-04-05', 3, '2035-08-02', 2)];
    expect(salesTargetService.periodCounts(less, '2035-04-01', '2035-06-30').counted).toBe(3);
    expect(salesTargetService.periodCounts(less, '2035-07-01', '2035-09-30').counted).toBe(-1);
    const same = salesTargetService.periodCounts([row(1, '2035-04-05', 3, '2035-05-02', 2)], '2035-04-01', '2035-06-30');
    expect([same.counted, same.confirmed, same.corrections, same.shipped, same.awaiting]).toEqual([2, 3, -1, 2, 0]);
  });

  test('sync records a cancellation, a loss and a shipment', async () => {
    const requester = await createTestUser('Export Executive');
    const cancelledLater = await order();
    await receipt(cancelledLater, '2036-04-05');
    const lostSame = await order();
    await receipt(lostSame, '2036-04-06');
    const shipped = await order();
    await receipt(shipped, '2036-04-07');
    await salesTargetService.sync('2036-04-10');

    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :o", { o: cancelledLater });
    await db.execute(
      "INSERT INTO order_cancellations (order_id, reason, requested_by, status, decided_at) VALUES (:o, 'Jest D-378 cancellation', :u, 'approved', '2036-07-20 10:00:00')",
      { o: cancelledLater, u: requester }
    );
    await db.execute("UPDATE orders SET status = 'lost', lost_at = '2036-05-01 10:00:00' WHERE id = :o", { o: lostSame });
    const ci = await createTestDocument(shipped, 'CI', 'approved');
    await db.execute("UPDATE documents SET document_date = '2036-05-10' WHERE id = :id", { id: ci });
    await db.execute('INSERT INTO order_shipping (order_id, container_count) VALUES (:o, 3)', { o: shipped });
    await salesTargetService.sync('2036-08-01');

    const c = await credits([cancelledLater, lostSame, shipped]);
    expect([ymd(c.get(cancelledLater).reversed_on), c.get(cancelledLater).reversed_why]).toEqual(['2036-07-20', 'cancelled']);
    expect([ymd(c.get(lostSame).reversed_on), c.get(lostSame).reversed_why]).toEqual(['2036-05-01', 'lost']);
    expect([ymd(c.get(shipped).shipped_on), Number(c.get(shipped).containers_shipped)]).toEqual(['2036-05-10', 3]);

    const q2 = salesTargetService.periodCounts(c, '2036-04-01', '2036-06-30');
    // +1 +1 +1 confirmed, −1 lost the same quarter, +2 shipped more than confirmed.
    expect([q2.counted, q2.confirmed, q2.corrections]).toEqual([4, 3, 1]);
    // The later cancellation is a correction in its own quarter.
    expect(salesTargetService.periodCounts(c, '2036-07-01', '2036-09-30').counted).toBe(-1);
  });

  // ---------------------------------------------------------------- new buyer and credit

  test('the first confirmed order of a client is the new buyer', async () => {
    const client = await createTestClient();
    const second = await order(client);
    await receipt(second, '2034-08-02');
    const first = await order(client);
    await receipt(first, '2034-08-01');
    await salesTargetService.sync('2034-08-10');
    const c = await credits([first, second]);
    expect(Number(c.get(first).is_new_buyer)).toBe(1);
    expect(Number(c.get(second).is_new_buyer)).toBe(0);
    expect(salesTargetService.periodCounts(c, '2034-07-01', '2034-09-30').new_buyers).toBe(1);
  });

  test('the credit goes to the Sales & Export owner unless the MD moved it', async () => {
    const owner = await createTestUser('Export Executive');
    const other = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const orderId = await order();
    expect((await salesTargetRepository.creditUsers([orderId])).get(orderId)).toBeNull(); // nobody: Unassigned
    await salesOwner(orderId, owner);
    expect((await salesTargetRepository.creditUsers([orderId])).get(orderId)).toBe(owner);

    expect(await salesTargetService.moveCredit(orderId, String(other), 'short', md)).not.toBeNull(); // a reason is required
    expect(await salesTargetService.moveCredit(orderId, String(other), 'Jest D-378 closed while the owner was away', md)).toBeNull();
    expect((await salesTargetRepository.creditUsers([orderId])).get(orderId)).toBe(other);
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ORDER_SALES_CREDIT_MOVED' AND entity_id = :o", { o: orderId });
    expect(Number(n.n)).toBe(1);
    expect(await salesTargetService.moveCredit(orderId, 'auto', 'Jest D-378 back to the department owner', md)).toBeNull();
    expect((await salesTargetRepository.creditUsers([orderId])).get(orderId)).toBe(owner);
  });

  // ---------------------------------------------------------------- company target

  test('company target: off, break-even and profit', async () => {
    const p = salesTargetService.period('quarter', '2037-11-15');
    expect((await salesTargetService.companyTarget(p, '2037-11-15', [])).on).toBe(false); // no row: off
    companyRows.push(await salesTargetRepository.insertCompanyRow('2037-10-01', 0.0, 0.0, 'Jest D-378 zero', 1));
    expect((await salesTargetService.companyTarget(p, '2037-11-15', [])).on).toBe(false); // costs and profit 0: off
    companyRows.push(await salesTargetRepository.insertCompanyRow('2037-10-01', 100000.0, 50000.0, 'Jest D-378 target', 1));
    const on = await salesTargetService.companyTarget(p, '2037-11-15', []);
    expect(on.on).toBe(true);
    expect(on.needed).toBe(450000); // (costs + profit) × 3 months
    expect(on.avg_margin).toBeNull(); // no shipment in the last 3 months: not enough history yet
    expect(on.containers_needed).toBeNull();

    const math = salesTargetService.companyMath(100000.0, 50000.0, 3, 30000.0);
    expect([math.needed, math.containers_needed, math.breakeven_containers]).toEqual([450000, 15, 10]);
    const m29 = salesTargetService.companyMath(100000.0, 50000.0, 3, 29000.0);
    expect([m29.containers_needed, m29.breakeven_containers]).toEqual([16, 11]); // rounded up
    expect(salesTargetService.companyMath(100000.0, 0.0, 3, null).breakeven_containers).toBeNull();
    // The row that applies is the latest one on or before the period start.
    expect((await salesTargetService.companyTarget(salesTargetService.period('quarter', '2037-08-15'), '2037-08-15', [])).on).toBe(false);
  });

  // ---------------------------------------------------------------- build-up and personal targets

  test('build-up factors', () => {
    expect([0, 1, 2, 3, 4].map((k) => salesTargetService.buildupFactor(k, 3))).toEqual([0, 0, 0.5, 1, 1]);
    expect([1, 2, 3, 4].map((k) => salesTargetService.buildupFactor(k, 4))).toEqual([0, 0.5, 0.5, 1]);
    expect(salesTargetService.buildupFactor(1, 1)).toBe(1);
    expect(salesTargetService.buildupFactor(1, 0)).toBe(1);
    expect(salesTargetService.buildupFactor(0, 0)).toBe(0); // a month before joining
    expect(salesTargetService.monthsSinceJoining('2034-11-20', '2034-11-01')).toBe(1);
    expect(salesTargetService.monthsSinceJoining('2034-11-20', '2035-01-01')).toBe(3);
  });

  test('a personal target uses the designation default and the build-up', async () => {
    const person = await createTestUser('Export Executive');
    const designation = await salesDesignationId();
    await member(person, designation);
    await salesTargetRepository.saveTarget('designation', designation, { containers: 6, quotations: 10, reason: 'Jest D-378 default' }, 1);
    await db.execute("UPDATE users SET joined_on = '2034-11-10' WHERE id = :id", { id: person });
    const settings = { ...(await salesTargetService.settings()), buildup_months: '3' };

    // Oct (before joining) 0, Nov (month 1) 0, Dec (month 2) 0.5 → 6 × 0.5 / 3 = 1.
    const t = await salesTargetService.personalTarget(person, salesTargetService.period('quarter', '2034-11-15'), settings);
    expect([t.containers, t.source, t.in_buildup, t.quotations]).toEqual([1, 'designation', true, 10]);
    const full = await salesTargetService.personalTarget(person, salesTargetService.period('quarter', '2035-05-15'), settings);
    expect([full.containers, full.in_buildup]).toEqual([6, false]);

    // Own target wins; build-up switched off for the person.
    await salesTargetRepository.saveTarget('user', person, { containers: 9, buildup_enabled: 0, multiple: 2.5, reason: 'Jest D-378 own' }, 1);
    const own = await salesTargetService.personalTarget(person, salesTargetService.period('quarter', '2034-11-15'), settings);
    expect([own.containers, own.source, own.in_buildup, own.multiple]).toEqual([9, 'own', false, 2.5]);
  });

  test("saving a person's target needs a reason and marks it reviewed", async () => {
    const person = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    expect(await salesTargetService.savePerson(person, { containers: '5', reason: '' }, md)).not.toBeNull();
    expect(await salesTargetService.savePerson(person, { containers: 'five', reason: 'Jest D-378 quarterly review' }, md)).not.toBeNull();
    expect(await salesTargetService.savePerson(person, { containers: '5', new_buyers: '2', multiple: '', buildup_present: '1', reason: 'Jest D-378 quarterly review' }, md)).toBeNull();
    const row = await salesTargetRepository.target('user', person);
    expect([String(row.containers), String(row.new_buyers), row.multiple, String(row.buildup_enabled)]).toEqual(['5.0', '2', null, '0']);
    expect(row.reviewed_at).not.toBeNull();
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'SALES_TARGET_CHANGED' AND entity_id = :p", { p: person });
    expect(Number(n.n)).toBe(1);
  });

  // ---------------------------------------------------------------- who sees what

  test('only holders open the Targets page', async () => {
    let agent = await signIn(await createTestUser('Export Executive'));
    expect((await agent.get('/sales-targets')).status).toBe(403);
    expect((await agent.get('/sales-targets/settings')).status).toBe(403);

    agent = await signIn(await createTestUser('Managing Director'));
    let res = await agent.get('/sales-targets');
    expect(res.status).toBe(200);
    expect(res.text).toContain('Sales targets —');
    expect(res.text).toContain('id="target-team"');
    res = await agent.get('/sales-targets/settings');
    expect(res.status).toBe(200);
    expect(res.text).toContain('name="costs_monthly"'); // the MD also sees the price build-up: the money fields
  });

  test('money figures need view_price_buildup too', async () => {
    await db.execute("INSERT IGNORE INTO roles (name, description, is_system_role) VALUES ('Test Role — Targets Only', 'Test only', 0)");
    await db.execute(
      `INSERT IGNORE INTO role_permissions (role_id, permission_id)
       SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_sales_targets' WHERE r.name = 'Test Role — Targets Only'`
    );
    // D-379: the full Targets page is the All level of the Sales targets report.
    await db.execute(
      `INSERT IGNORE INTO role_report_access (role_id, report_key, level)
       SELECT r.id, 'sales_targets', 'all' FROM roles r WHERE r.name = 'Test Role — Targets Only'`
    );
    companyRows.push(await salesTargetRepository.insertCompanyRow('2000-01-01', 100000.0, 50000.0, 'Jest D-378 money', 1));

    let agent = await signIn(await createTestUser('Test Role — Targets Only'));
    const res = await agent.get('/sales-targets');
    expect(res.status).toBe(200);
    expect(res.text).toContain('Containers needed');
    expect(res.text).not.toContain('Needed (₹)');
    expect(res.text).not.toContain('Average margin per container, last 3 months');
    expect((await agent.get('/sales-targets/settings')).text).not.toContain('name="costs_monthly"');

    agent = await signIn(await createTestUser('Managing Director'));
    expect((await agent.get('/sales-targets')).text).toContain('Needed (₹)');
    expect((await agent.get('/cockpit')).text).toContain('id="cockpit-sales-target"'); // the cockpit box while the company target is on
  });

  test('the salesperson sees only their own target, in containers', async () => {
    const person = await createTestUser('Export Executive');
    const colleague = await createTestUser('Export Executive');
    await salesTargetRepository.saveTarget('user', person, { containers: 4, reason: 'Jest D-378 own' }, 1);
    const mine = await order();
    await salesOwner(mine, person);
    await receipt(mine, todayIst());
    const theirs = await order();
    await salesOwner(theirs, colleague);
    await receipt(theirs, todayIst());
    await salesTargetService.sync();
    const ref = async (id) => String((await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id })).order_reference);

    let page = (await (await signIn(person)).get('/')).text;
    const start = page.indexOf('id="your-target"');
    expect(start).toBeGreaterThan(-1);
    const next = page.indexOf('<div class="card', start);
    const box = page.slice(start, next > -1 ? next : page.length);
    expect(box).toContain('Your target —');
    expect(box).toContain('4 containers');
    expect(box).toContain(await ref(mine));
    expect(box).not.toContain(await ref(theirs)); // a colleague's order is never shown
    expect(box).not.toContain('₹'); // no money
    expect((await salesTargetService.dashboardFor(person, todayIst(), false)).counts.counted).toBe(1);

    page = (await (await signIn(colleague)).get('/')).text;
    expect(page).not.toContain('id="your-target"'); // no target, no box
  });

  test('associates see nothing of the targets', () => {
    const dir = path.join(__dirname, '../../views/associate_portal');
    for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.njk'))) {
      const text = fs.readFileSync(path.join(dir, file), 'utf8');
      expect([file, text.includes('sales-target')]).toEqual([file, false]);
      expect([file, text.includes('salesTarget')]).toEqual([file, false]);
    }
    const routes = fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8');
    const lines = routes.split('\n').filter((l) => /^app\.(?:get|post)\('\/sales-targets[^']*',/.test(l));
    expect(lines).toHaveLength(6);
    for (const line of lines) {
      expect(line).not.toContain('associateAuth');
      if (line.startsWith("app.get('/sales-targets',")) {
        // D-379: the page opens at the Department level of the Sales targets report (read-only team view).
        expect(line).toContain("requireReport('sales_targets', 'department')");
        continue;
      }
      expect(line).toContain("requirePermission('manage_sales_targets')");
      expect(line).toContain("requireReport('sales_targets', 'all')");
    }
  });

  test('the calculator inputs have no name and sit outside every form', async () => {
    const agent = await signIn(await createTestUser('Managing Director'));
    const body = (await agent.get('/sales-targets')).text;
    const at = body.indexOf('id="target-calculator"');
    expect(at).toBeGreaterThan(-1);
    const end = body.indexOf('</script>', at);
    const calc = body.slice(at, end);
    expect(calc).toContain('id="calc-cost"');
    expect(calc).not.toContain('name='); // nothing typed in the calculator can be posted
    expect(calc).not.toContain('<form');
    const before = body.slice(0, at);
    expect(before.split('<form').length).toBe(before.split('</form>').length); // not inside a form
  });

  // ---------------------------------------------------------------- Sales Performance

  test('Sales Performance: the default page is unchanged and By salesperson is optional', async () => {
    const agent = await signIn(await createTestUser('Managing Director'));
    const plain = (await agent.get('/reports/performance?date_from=2033-03-01&date_to=2033-03-31')).text;
    const empty = (await agent.get('/reports/performance?date_from=2033-03-01&date_to=2033-03-31&salesperson=')).text;
    expect(empty).toBe(plain); // salesperson '' renders exactly the default page
    expect(plain).not.toContain('id="performance-by-salesperson"');

    const seller = await createTestUser('Export Executive');
    const won = await order();
    await salesOwner(won, seller);
    await sendQt(won, '2033-03-05 10:00:00');
    await createTestDocument(won, 'PI', 'sent');
    await db.execute("UPDATE orders SET pi_date = '2033-03-20' WHERE id = :o", { o: won });
    const lost = await order();
    await salesOwner(lost, seller);
    await db.execute("UPDATE orders SET status = 'lost', lost_at = '2033-03-10 10:00:00', lost_category = 'price' WHERE id = :o", { o: lost });

    const rows = await salesTargetService.performanceBySalesperson('2033-03-01', '2033-03-31', String(seller));
    expect(rows).toHaveLength(1);
    expect([rows[0].quotes, rows[0].won, rows[0].lost_before_pi, rows[0].lost_after_pi, rows[0].win_rate_pct, rows[0].avg_days_qt_to_pi, rows[0].top_lost_category])
      .toEqual([1, 1, 1, 0, 100, 15, 'Price']);
    const all = (await agent.get('/reports/performance?date_from=2033-03-01&date_to=2033-03-31&salesperson=all')).text;
    expect(all).toContain('id="performance-by-salesperson"');
    // The CSV export is unchanged (the lost-orders table only).
    expect(performanceCsvRows({ lost_orders: [] }).headers).toEqual(['Order', 'Client', 'Lost On', 'Stage Reached', 'Reason']);
  });

  // ---------------------------------------------------------------- Mark Lost, joined_on

  test('Mark Lost saves the optional category only when listed', async () => {
    const agent = await signIn(await createTestUser('Managing Director'));
    for (const [posted, expected] of [['price', 'price'], ['bogus', null], ['', null]]) {
      const orderId = await order();
      const csrf = await csrfOf(agent); // fetched before agent.post(): a request started in between refuses the connection
      await agent.post(`/orders/${orderId}/mark-lost`).type('form')
        .send({ _csrf: csrf, reason: 'Jest D-378 buyer chose another supplier', lost_category: posted });
      const row = await db.queryOne('SELECT status, lost_category FROM orders WHERE id = :id', { id: orderId });
      expect([posted, row.status, row.lost_category]).toEqual([posted, 'lost', expected]);
    }
    const orderId = await order();
    const csrf = await csrfOf(agent);
    await agent.post(`/orders/${orderId}/mark-lost`).type('form').send({ _csrf: csrf, reason: '', lost_category: 'price' });
    expect((await db.queryOne('SELECT status FROM orders WHERE id = :id', { id: orderId })).status).toBe('active'); // the reason stays required
  });

  test('joined_on is saved only when the form carries it', async () => {
    const agent = await signIn(await createTestUser('Admin'));
    const person = await createTestUser('Export Executive');
    const u = await db.queryOne('SELECT name, email, role_id FROM users WHERE id = :id', { id: person });
    const base = { name: u.name, email: u.email, phone: '', role_id: String(u.role_id) };
    const joined = async () => {
      const r = await db.queryOne('SELECT joined_on FROM users WHERE id = :id', { id: person });
      return r.joined_on === null ? null : String(r.joined_on).slice(0, 10);
    };
    const post = async (extra) => {
      const csrf = await csrfOf(agent); // fetched before agent.post(), as above
      return agent.post(`/users/${person}/update`).type('form').send({ _csrf: csrf, ...base, ...extra });
    };

    await post({ joined_on_present: '1', joined_on: '2026-03-01' });
    expect(await joined()).toBe('2026-03-01');

    await post({ joined_on: '' });
    expect(await joined()).toBe('2026-03-01'); // not on the form: unchanged

    await post({ joined_on_present: '1', joined_on: '2026-02-31' });
    expect(await joined()).toBe('2026-03-01'); // an invalid date is refused

    await post({ joined_on_present: '1', joined_on: '' });
    expect(await joined()).toBeNull(); // cleared
  });

  // ---------------------------------------------------------------- alerts

  test('alert recipients: defaults and save', async () => {
    const seededMd = Number((await db.queryOne("SELECT id FROM users WHERE email = 'md@email.com'")).id);
    const salesManager = await createTestUser('Sales/Export Manager');
    const behind = await salesTargetService.alertRecipients('behind');
    expect(behind).toContain(seededMd); // default: the MD and the ED
    expect(behind).not.toContain(salesManager);
    expect(await salesTargetService.alertRecipients('no_owner')).toContain(salesManager); // no salesperson: the MD and the Sales/Export Manager
    expect(await salesTargetService.alertRecipients('no_owner')).toContain(seededMd);

    const admin = await createTestUser('Admin');
    // Saving the defaults unchanged keeps them as defaults (NULL).
    const form = await salesTargetService.alertRecipientsForForm();
    expect(await salesTargetService.saveAlertRecipients(form, admin, 'Jest D-378 no change')).toEqual([]);
    expect((await salesTargetService.settings()).alert_behind_recipients).toBeNull();

    const changes = await salesTargetService.saveAlertRecipients({ behind: [String(salesManager)], review: [], no_owner: form.no_owner }, admin, 'Jest D-378 recipients');
    expect(changes).toHaveLength(2);
    expect(await salesTargetService.alertRecipients('behind')).toEqual([salesManager]);
    expect((await salesTargetService.settings()).alert_review_recipients).toBe(''); // nobody ticked is stored as empty
    expect(await salesTargetService.alertRecipients('review')).toContain(seededMd); // nobody ticked: the MD
    expect((await salesTargetService.settings()).alert_no_owner_recipients).toBeNull();
  });

  test('an order with no salesperson is alerted once', async () => {
    // One person ticked for this alert (Admin › E-mail settings): sync() credits every confirmed order of the
    // shared test database, and the defaults (every MD and Sales/Export Manager the other files made) would
    // multiply the notices by hundreds. The defaults themselves are checked in "alert recipients" above.
    const recipient = await createTestUser('Managing Director');
    await db.execute("UPDATE sales_target_settings SET setting_value = :v WHERE setting_key = 'alert_no_owner_recipients'", { v: String(recipient) });
    const orderId = await order();
    await receipt(orderId, '2035-04-02');
    await salesTargetService.sync('2035-04-05');
    const count = async () => Number((await db.queryOne(
      "SELECT COUNT(*) AS n FROM notifications WHERE type = 'sales_target_no_owner' AND related_order_id = :o", { o: orderId }
    )).n);
    await salesTargetService.runAlerts('2035-04-05');
    expect(await count()).toBe(1); // the one person ticked
    await salesTargetService.runAlerts('2035-04-06');
    expect(await count()).toBe(1); // once per order
  });

  // ---------------------------------------------------------------- seed

  test('the permission and the default settings are seeded', async () => {
    expect((await db.queryOne("SELECT category FROM permissions WHERE permission_key = 'manage_sales_targets'")).category).toBe('reports');
    const roles = (await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE p.permission_key = 'manage_sales_targets' ORDER BY r.name`
    )).map((r) => r.name);
    expect(roles).toContain('Managing Director');
    expect(roles).toContain('Executive Director');
    expect(roles).not.toContain('Export Executive');
    const s = await salesTargetService.settings();
    expect([s.period, s.multiple, s.buildup_months, s.review_months, s.alert_pct]).toEqual(['quarter', '3', '3', '12', '60']);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM sales_target_settings')).n)).toBe(8);
  });
});
