'use strict';

// Owner decision (2 Nov 2026, gap-list Q-6 "go with your recommendation"):
// (a) a missing eBRC / FIRC never blocks settling — it is listed (13h warning,
//     monthly report + reminder); (b) ECGC buyer limit, exposure warning and the
//     monthly declaration; (c) incentive claims get sanctioned amount, deadline and
//     status, with reminders 30 / 7 days before. PHP twin: ComplianceTrackingTest.php.

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const svc = require('../../src/services/complianceTrackingService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const SETTINGS = ['ecgc_policy_no', 'ecgc_declaration_day', 'benefit_deadline_days_rodtep', 'benefit_deadline_days_duty_drawback'];
const TEST_DAYS = ['2031-05-05', '2031-05-19'];

describe('Compliance tracking — eBRC, ECGC, incentive claims (gap-list Q-6)', () => {
  const saved = {};
  beforeAll(async () => {
    for (const k of SETTINGS) saved[k] = (await db.queryOne('SELECT setting_value AS v FROM company_settings WHERE setting_key = :k', { k })).v;
    await db.execute('DELETE FROM compliance_alerts_sent WHERE sent_on IN (:a, :b)', { a: TEST_DAYS[0], b: TEST_DAYS[1] });
  });
  afterAll(async () => {
    for (const k of SETTINGS) await db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v: saved[k], k });
    await db.execute('DELETE FROM compliance_alerts_sent WHERE sent_on IN (:a, :b)', { a: TEST_DAYS[0], b: TEST_DAYS[1] });
    await db.pool.end();
  });

  const setting = (k, v) => db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { v, k });

  async function orderWithFob(clientId, fob, ccy = 'USD') {
    const orderId = await createTestOrder(clientId);
    await db.execute("UPDATE orders SET currency_code = :c, status = 'active', is_test_data = 0 WHERE id = :id", { c: ccy, id: orderId });
    await orderProductRepository.add(orderId, 1, 'ECGC test line', null, null, '1', false, 'PCS', Number(fob).toFixed(2));
    return orderId;
  }

  async function receiveAdvance(orderId, amount, firc = null, on = '2026-01-15 10:00:00') {
    await db.execute('INSERT INTO order_payment_status (order_id) SELECT :o FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM order_payment_status WHERE order_id = :o2)', { o: orderId, o2: orderId });
    await db.execute('UPDATE order_payment_status SET advance_amount = :a, advance_remittance_received_at = :d, advance_firc_reference = :f WHERE order_id = :o', { a: amount, d: on, f: firc, o: orderId });
  }

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-ct-${crypto.randomBytes(3).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES (:name, :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { name: `Jest ${roleName}`, email, hash: await bcrypt.hash('JestCt5!x', 10), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function page(u, url) {
    const agent = request.agent(app);
    const csrf = ((await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/) || [])[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: 'JestCt5!x' });
    return (await agent.get(url)).text;
  }

  it('lists a missing FIRC without blocking', async () => {
    const orderId = await orderWithFob(await createTestClient(), 10000);
    await receiveAdvance(orderId, 3000);
    const legs = await svc.missingFircLegs(orderId);
    expect(legs).toHaveLength(1);
    expect(legs[0].label).toBe('Advance');
    expect(legs[0].amount).toBe(3000);
    expect((await svc.missingFircReport()).map((r) => r.order_id)).toContain(orderId);
    await db.execute('UPDATE order_payment_status SET advance_firc_reference = :f WHERE order_id = :o', { f: 'FIRC/HDFC/1', o: orderId });
    expect(await svc.missingFircLegs(orderId)).toEqual([]);
  });

  it('report hides amounts without view_buyer_payments', async () => {
    const orderId = await orderWithFob(await createTestClient(), 10000);
    await receiveAdvance(orderId, 3456.78);
    const compliance = await page(await user('Export Compliance Executive'), '/reports/missing-ebrc');
    expect(compliance).toContain('Missing eBRC / FIRC');
    expect(compliance).not.toContain('3,456.78');
    const accounts = await page(await user('Accounts Executive'), '/reports/missing-ebrc');
    expect(accounts).toContain('3,456.78');
  });

  it('ECGC exposure against the limit', async () => {
    await setting('ecgc_policy_no', 'ECGC/SCR/2026/77');
    const clientId = await createTestClient();
    const orderId = await orderWithFob(clientId, 15000);
    let p = await svc.ecgcPosition(clientId);
    expect(p.limit).toBeNull();
    expect(p.warning).toBe('No ECGC credit limit is recorded for this buyer — shipments to this buyer are not covered until ECGC approves a limit.');
    await db.execute("UPDATE clients SET ecgc_limit_amount = 10000, ecgc_limit_currency = 'USD', ecgc_limit_valid_to = '2099-12-31' WHERE id = :id", { id: clientId });
    p = await svc.ecgcPosition(clientId);
    expect(p.over_limit).toBe(true);
    expect(p.over_by).toBe(5000);
    expect(p.warning).toBe('Open orders for this buyer (USD 15,000.00) are above the ECGC-approved credit limit (USD 10,000.00) by USD 5,000.00 — the amount above the limit is not covered.');
    await receiveAdvance(orderId, 6000, 'FIRC/1');
    p = await svc.ecgcPosition(clientId);
    expect(p.over_limit).toBe(false);
    expect(p.warning).toBeNull();
    await db.execute("UPDATE clients SET ecgc_limit_valid_to = '2020-01-01' WHERE id = :id", { id: clientId });
    expect((await svc.ecgcPosition(clientId)).warning).toBe('The ECGC credit limit for this buyer expired on 2020-01-01 — ask ECGC to renew it before shipping.');
  });

  it('declaration due by the 10th of the next month', async () => {
    await setting('ecgc_declaration_day', '10');
    expect(await svc.declarationDue('2026-10-15')).toBe('2026-11-10');
    expect(await svc.declarationDue('2026-12-31')).toBe('2027-01-10');
    const orderId = await createTestOrder(await createTestClient());
    expect((await svc.declarationStatus(orderId)).state).toBe('not_due');
    await db.execute('INSERT INTO order_shipping (order_id, bl_date) VALUES (:o, :d) ON DUPLICATE KEY UPDATE bl_date = :d2', { o: orderId, d: '2026-10-15', d2: '2026-10-15' });
    expect((await svc.declarationStatus(orderId, '2026-11-05')).state).toBe('due');
    const over = await svc.declarationStatus(orderId, '2026-11-11');
    expect(over.state).toBe('overdue');
    expect(over.text).toBe('Not declared to ECGC — it was due by 2026-11-10. A shipment not declared on time is not covered.');
    await db.execute("UPDATE orders SET ecgc_declared_on = '2026-11-03', ecgc_declaration_ref = 'D-1' WHERE id = :id", { id: orderId });
    expect((await svc.declarationStatus(orderId, '2026-11-11')).text).toBe('Declared to ECGC on 2026-11-03 (D-1).');
  });

  it('claim deadline, sanction and status', async () => {
    await setting('benefit_deadline_days_rodtep', '365');
    await setting('benefit_deadline_days_duty_drawback', '0');
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('INSERT INTO order_shipping (order_id, shipping_bill_date, bl_date) VALUES (:o, :s, :b) ON DUPLICATE KEY UPDATE shipping_bill_date = :s2', { o: orderId, s: '2026-03-01', b: '2026-03-05', s2: '2026-03-01' });
    expect(await svc.defaultDeadline(orderId, 'RODTEP')).toBe('2027-03-01');
    expect(await svc.defaultDeadline(orderId, 'Duty Drawback')).toBeNull();
    const u = await user('Admin');
    const id = (await db.execute("INSERT INTO ca_export_benefits (order_id, scheme_name, claimed_amount, claimed_at, currency_code, recorded_by) VALUES (:o, 'RODTEP', 1000, '2026-04-01', 'INR', :u)", { o: orderId, u: u.id })).insertId;
    const claim = await db.queryOne('SELECT * FROM ca_export_benefits WHERE id = :id', { id });
    let v = await svc.claimView(claim);
    expect(v.effective_status).toBe('filed');
    expect(v.effective_deadline).toBe('2027-03-01');
    expect(v.deadline_is_default).toBe(true);
    expect(await svc.saveClaimTracking(claim, { claim_status: 'sanctioned' })).toBe('Enter the amount sanctioned.');
    expect(await svc.saveClaimTracking(claim, { claim_status: 'rejected', rejected_reason: 'no' })).toBe('Say why the claim was rejected (at least 10 characters).');
    expect(await svc.saveClaimTracking(claim, { claim_status: 'sanctioned', sanctioned_amount: '950', sanctioned_at: '2026-05-01', claim_deadline: '2026-12-31' })).toBeNull();
    const saved2 = await db.queryOne('SELECT * FROM ca_export_benefits WHERE id = :id', { id });
    expect(saved2.claim_status).toBe('sanctioned');
    expect(saved2.sanctioned_amount).toBe('950.00');
    v = await svc.claimView(saved2);
    expect(v.effective_deadline).toBe('2026-12-31');
    expect(v.deadline_is_default).toBe(false);
    await db.execute("UPDATE ca_export_benefits SET received_amount = 950, received_at = '2026-06-01' WHERE id = :id", { id });
    expect((await svc.claimView(await db.queryOne('SELECT * FROM ca_export_benefits WHERE id = :id', { id }))).effective_status).toBe('received');
  });

  it('reminders go out once', async () => {
    await setting('ecgc_policy_no', 'ECGC/SCR/2026/77');
    await setting('ecgc_declaration_day', '10');
    const finance = await user('Accounts Executive');
    const orderId = await orderWithFob(await createTestClient(), 1000);
    await receiveAdvance(orderId, 300);
    await db.execute('INSERT INTO order_shipping (order_id, bl_date, shipping_bill_date) VALUES (:o, :d, :d2) ON DUPLICATE KEY UPDATE bl_date = :d3', { o: orderId, d: '2031-04-15', d2: '2031-04-14', d3: '2031-04-15' });
    await db.execute("INSERT INTO ca_export_benefits (order_id, scheme_name, claimed_amount, claimed_at, currency_code, recorded_by, claim_deadline) VALUES (:o, 'RODTEP', 500, '2031-04-20', 'INR', :u, '2031-05-25')", { o: orderId, u: finance.id });

    expect(await svc.runReminders(TEST_DAYS[0])).toBeGreaterThan(0);
    const types = (await db.query('SELECT DISTINCT type FROM notifications WHERE user_id = :u', { u: finance.id })).map((r) => r.type);
    expect(types).toContain('ebrc_missing_monthly');
    expect(types).toContain('benefit_claim_deadline');
    const msg = (await db.queryOne("SELECT message FROM notifications WHERE user_id = :u AND type = 'benefit_claim_deadline' ORDER BY id DESC LIMIT 1", { u: finance.id })).message;
    expect(msg).toContain('the RODTEP claim must be completed by 2031-05-25 (20 day(s) left).');
    const before = Number((await db.queryOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u', { u: finance.id })).n);
    await svc.runReminders(TEST_DAYS[0]);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u', { u: finance.id })).n)).toBe(before);
    await svc.runReminders(TEST_DAYS[1]); // 7 days before is a new threshold
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'benefit_claim_deadline'", { u: finance.id })).n)).toBe(2);
  // Three reminder runs over every order in the shared test database — late in the full
  // suite (and on the slower GitHub runner) that takes longer than a minute.
  }, 240000);
});
