'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const caFyLockRepository = require('../../src/repositories/caFyLockRepository');
const caFyLockGuard = require('../../src/services/caFyLockGuard');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * QA-4 P0.3 (docs/QA/TEST_PLAN.md Section 6): every CA write path listed
 * under CA/Accounts must refuse once the relevant financial year is
 * locked, except through ca_fy_lock_override, which must itself be
 * audit-logged. caFyLockGuard.allow() is the single choke point every
 * CA write route delegates to (caController.js/ordersController.js),
 * so pinning it here covers all of them at once; the last two tests
 * also drive one representative write path (recordAdvanceInrActual)
 * through a real HTTP request against the live Express app, proving
 * the guard is actually wired into the real route, not just correct
 * in isolation.
 */
describe('CA FY-lock enforcement (QA-4 P0.3)', () => {
  const LOCKED_FY = '2020-21';
  const LOCKED_DATE = '2020-06-15';
  const OPEN_DATE = '2030-06-15';

  const limitedEmail = `jest-fylock-limited-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
  const limitedPassword = 'JestFyLockLimited123!';
  let limitedUserId;

  beforeAll(async () => {
    await caFyLockRepository.lock(LOCKED_FY, 1);

    // A user who can record an INR actual (inr_actual_edit) but does NOT
    // hold ca_fy_lock_override — 'Export Executive' has neither by
    // default, so inr_actual_edit is granted as an individual override,
    // exactly like a real Admin & Settings per-user grant would.
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    const passwordHash = await bcrypt.hash(limitedPassword, 10);
    const userResult = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest FyLock Limited', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email: limitedEmail, hash: passwordHash, role_id: role.id }
    );
    limitedUserId = userResult.insertId;

    const permission = await db.queryOne("SELECT id FROM permissions WHERE permission_key = 'inr_actual_edit'");
    await db.execute(
      'INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:user_id, :permission_id, 1)',
      { user_id: limitedUserId, permission_id: permission.id }
    );
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function fakeReq(permissions) {
    return { user: { id: limitedUserId }, permissions, session: {} };
  }

  it('allows when the date is not in a locked year', async () => {
    const req = fakeReq({ ca_fy_lock_override: false });
    const result = await caFyLockGuard.allow(req, OPEN_DATE, 'order_payment_status', 1, 'advance_inr_actual');
    expect(result).toBe(true);
  });

  it('blocks when the date is in a locked year and the user has no override', async () => {
    const req = fakeReq({ ca_fy_lock_override: false });
    const result = await caFyLockGuard.allow(req, LOCKED_DATE, 'order_payment_status', 1, 'advance_inr_actual');
    expect(result).toBe(false);
    expect(req.session._flash[0].type).toBe('error');
    expect(req.session._flash[0].message).toContain(LOCKED_FY);
  });

  it('allows with an audit log entry and a warning when the user has the override', async () => {
    const before = (await db.query('SELECT COUNT(*) AS c FROM audit_log'))[0].c;
    const req = fakeReq({ ca_fy_lock_override: true });

    const result = await caFyLockGuard.allow(req, LOCKED_DATE, 'order_payment_status', 999, 'advance_inr_actual');

    expect(result).toBe(true);
    expect(req.session._flash[0].type).toBe('warning');
    const after = (await db.query('SELECT COUNT(*) AS c FROM audit_log'))[0].c;
    expect(after).toBe(before + 1);

    const [entry] = await db.query("SELECT * FROM audit_log WHERE action_type = 'CA_FY_LOCK_OVERRIDDEN' ORDER BY id DESC LIMIT 1");
    expect(entry.user_id).toBe(limitedUserId);
    expect(entry.entity_type).toBe('order_payment_status');
    expect(entry.entity_id).toBe(999);
  });

  it('a null date is never blocked', async () => {
    const req = fakeReq({ ca_fy_lock_override: false });
    const result = await caFyLockGuard.allow(req, null, 'order_payment_status', 1, 'advance_inr_actual');
    expect(result).toBe(true);
  });

  describe('end-to-end through the real route', () => {
    let agent;
    let orderLocked;
    let orderOpen;

    beforeAll(async () => {
      agent = request.agent(app);
      const loginPage = await agent.get('/login');
      const csrf = extractCsrf(loginPage.text);
      const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: limitedEmail, password: limitedPassword });
      expect(loginRes.status).toBe(302);
      expect(loginRes.headers.location).not.toBe('/login');

      orderLocked = await createTestOrder(await createTestClient());
      orderOpen = await createTestOrder(await createTestClient());
      await db.execute(
        'INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:order_id, 500.00, :cleared_at)',
        { order_id: orderLocked, cleared_at: LOCKED_DATE }
      );
      await db.execute(
        'INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:order_id, 500.00, :cleared_at)',
        { order_id: orderOpen, cleared_at: OPEN_DATE }
      );
    });

    it('refuses recordAdvanceInrActual when the leg cleared inside a locked FY', async () => {
      const orderPage = await agent.get(`/orders/${orderLocked}`);
      const csrf = extractCsrf(orderPage.text);
      const res = await agent
        .post(`/orders/${orderLocked}/payment/advance/inr-actual`)
        .type('form')
        .send({ _csrf: csrf, advance_inr_actual: '41000' });
      expect(res.status).toBe(302);

      const row = await db.queryOne('SELECT advance_inr_actual FROM order_payment_status WHERE order_id = :id', { id: orderLocked });
      expect(row.advance_inr_actual).toBeNull();
    });

    it('succeeds recordAdvanceInrActual when the leg cleared outside a locked FY', async () => {
      const orderPage = await agent.get(`/orders/${orderOpen}`);
      const csrf = extractCsrf(orderPage.text);
      const res = await agent
        .post(`/orders/${orderOpen}/payment/advance/inr-actual`)
        .type('form')
        .send({ _csrf: csrf, advance_inr_actual: '41000' });
      expect(res.status).toBe(302);

      const row = await db.queryOne('SELECT advance_inr_actual FROM order_payment_status WHERE order_id = :id', { id: orderOpen });
      expect(row.advance_inr_actual).toBe('41000.00');
    });
  });
});
