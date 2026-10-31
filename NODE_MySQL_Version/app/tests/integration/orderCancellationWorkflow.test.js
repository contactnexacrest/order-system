'use strict';

const { todayIst } = require('../../src/helpers/dbDateTime');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderCancellationRepository = require('../../src/repositories/orderCancellationRepository');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestUser(roleName = 'Export Executive') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-ocw-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

async function lastAuditLogAction() {
  return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
}

function fakeReq(orderId, userId, body = {}, extraParams = {}) {
  return {
    params: { id: String(orderId), ...extraParams },
    body,
    user: { id: userId },
    session: {},
    permissions: new Proxy({}, { get: () => true }),
  };
}

function fakeRes() {
  const res = { statusCode: 200 };
  res.status = (code) => { res.statusCode = code; return res; };
  res.send = () => {};
  res.redirect = () => {};
  res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
  return res;
}

/**
 * External QA report M-09: "Formal order cancellation workflow (reason,
 * approval, money/refund handling, audit) distinct from 'mark lost'."
 * Request/approve-or-reject, modeled on amendmentRepository's own
 * request/MD-approve/reject shape and makerCheckerGuard's self-approval
 * separation — see docs/migrations/2026-10-23c-order-cancellation-workflow.sql.
 * Port of PHP's OrderCancellationWorkflowTest.
 */
describe('Order cancellation workflow (External QA report M-09)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('requestCancellation creates a pending row', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const req = fakeReq(orderId, userId, { reason: 'Buyer has gone unresponsive for over a month.' });

    await ordersController.requestCancellation(req, fakeRes());

    const pending = await orderCancellationRepository.findPendingForOrder(orderId);
    expect(pending).not.toBeNull();
    expect(pending.status).toBe('pending');
    expect(pending.requested_by).toBe(userId);
    expect((await orderRepository.find(orderId)).status).toBe('active');

    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('ORDER_CANCELLATION_REQUESTED');
  });

  it('requestCancellation rejects a too-short reason', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const req = fakeReq(orderId, userId, { reason: 'short' });

    await ordersController.requestCancellation(req, fakeRes());

    expect(await orderCancellationRepository.findPendingForOrder(orderId)).toBeNull();
  });

  it('requestCancellation blocks a duplicate pending request', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.requestCancellation(fakeReq(orderId, userId, { reason: 'First cancellation request for this order.' }), fakeRes());
    await ordersController.requestCancellation(fakeReq(orderId, userId, { reason: 'A second, different cancellation reason entirely.' }), fakeRes());

    expect((await orderCancellationRepository.forOrder(orderId)).length).toBe(1);
  });

  it('requestCancellation is refused on an already-terminal order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: orderId });
    const userId = await createTestUser();

    await ordersController.requestCancellation(fakeReq(orderId, userId, { reason: 'Trying to cancel an already-complete order.' }), fakeRes());

    expect(await orderCancellationRepository.findPendingForOrder(orderId)).toBeNull();
  });

  it('approveCancellation cancels the order and records refund fields', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    const approver = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);

    await ordersController.approveCancellation(
      fakeReq(orderId, approver, { refund_status: 'pending', refund_amount: '1500.50', decision_notes: 'Advance will be refunded once reconciled.' }, { cancellationId: String(cancellationId) }),
      fakeRes()
    );

    const order = await orderRepository.find(orderId);
    expect(order.status).toBe('cancelled');
    expect(!!order.is_locked).toBe(true);

    const cancellation = await orderCancellationRepository.find(cancellationId);
    expect(cancellation.status).toBe('approved');
    expect(cancellation.decided_by).toBe(approver);
    expect(cancellation.refund_status).toBe('pending');
    expect(Number(cancellation.refund_amount)).toBe(1500.5);

    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('ORDER_CANCELLATION_APPROVED');
  });

  it('approveCancellation is refused when not pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    const approver = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);
    await orderCancellationRepository.reject(cancellationId, approver, 'Already resolved directly with the buyer.');

    await ordersController.approveCancellation(fakeReq(orderId, approver, {}, { cancellationId: String(cancellationId) }), fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  it('approveCancellation blocks self-approval for an ordinary user', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    const cancellationId = await orderCancellationRepository.create(orderId, 'Requesting and trying to approve my own cancellation.', requester);

    await ordersController.approveCancellation(
      fakeReq(orderId, requester, { refund_status: 'not_applicable' }, { cancellationId: String(cancellationId) }),
      fakeRes()
    );

    expect((await orderRepository.find(orderId)).status).toBe('active');
    expect((await orderCancellationRepository.find(cancellationId)).status).toBe('pending');
  });

  it('approveCancellation blocks self-approval even for a manage_permissions holder', async () => {
    // External QA report N-04: Admin holds manage_permissions via
    // seed.sql's wildcard grant (and is one of the only roles that can
    // approve a cancellation at all), but must still be blocked from
    // approving their own request — only Super Admin is exempt here.
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Requesting and trying to approve my own cancellation as Admin.', requester);

    await ordersController.approveCancellation(
      fakeReq(orderId, requester, { refund_status: 'not_applicable' }, { cancellationId: String(cancellationId) }),
      fakeRes()
    );

    expect((await orderRepository.find(orderId)).status).toBe('active');
    expect((await orderCancellationRepository.find(cancellationId)).status).toBe('pending');
  });

  it('approveCancellation allows self-approval for a Super Admin', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    await db.execute('UPDATE users SET is_super_admin = 1 WHERE id = :id', { id: requester });
    const cancellationId = await orderCancellationRepository.create(orderId, 'Requesting and approving my own cancellation as Super Admin.', requester);

    await ordersController.approveCancellation(
      fakeReq(orderId, requester, { refund_status: 'not_applicable' }, { cancellationId: String(cancellationId) }),
      fakeRes()
    );

    expect((await orderRepository.find(orderId)).status).toBe('cancelled');
  });

  it('rejectCancellation leaves the order unaffected and requires notes', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    const approver = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);

    await ordersController.rejectCancellation(fakeReq(orderId, approver, { decision_notes: 'short' }, { cancellationId: String(cancellationId) }), fakeRes());
    expect((await orderCancellationRepository.find(cancellationId)).status).toBe('pending');

    await ordersController.rejectCancellation(
      fakeReq(orderId, approver, { decision_notes: 'Buyer confirmed by phone they want to proceed after all.' }, { cancellationId: String(cancellationId) }),
      fakeRes()
    );

    expect((await orderCancellationRepository.find(cancellationId)).status).toBe('rejected');
    expect((await orderRepository.find(orderId)).status).toBe('active');

    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('ORDER_CANCELLATION_REJECTED');
  });

  it('completeCancellationRefund only works when approved and refund pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    const approver = await createTestUser('Admin');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);

    await ordersController.completeCancellationRefund(fakeReq(orderId, approver, { refund_notes: 'Trying too early.' }, { cancellationId: String(cancellationId) }), fakeRes());
    expect((await orderCancellationRepository.find(cancellationId)).refund_status).toBe('not_applicable');

    await orderCancellationRepository.approve(cancellationId, approver, null, 2000.0, 'pending');

    await ordersController.completeCancellationRefund(
      fakeReq(orderId, approver, { refund_notes: 'Wire transfer TXN123 sent back to buyer.', refund_paid_at: todayIst(), refund_reference: 'TXN123' }, { cancellationId: String(cancellationId) }),
      fakeRes()
    );

    const cancellation = await orderCancellationRepository.find(cancellationId);
    expect(cancellation.refund_status).toBe('completed');
    expect(cancellation.refund_recorded_by).toBe(approver);

    const audit = await lastAuditLogAction();
    expect(audit.action_type).toBe('ORDER_CANCELLATION_REFUND_COMPLETED');
  });

  it('orders index counts and filters a cancelled order correctly', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.cancel(orderId);
    const req = { query: { status: 'cancelled' }, user: { ui_template_preference_id: null }, permissions: new Proxy({}, { get: () => true }) };
    const res = fakeRes();

    await ordersController.index(req, res);

    expect(res.renderedData.counts.cancelled).toBeGreaterThanOrEqual(1);
    const match = res.renderedData.orders.find((o) => o.id === orderId);
    expect(match).toBeDefined();
    expect(match.status).toBe('cancelled');
  });

  it('order show page passes the pending cancellation to the view', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createTestUser();
    await orderCancellationRepository.create(orderId, 'Buyer requested cancellation before shipment.', requester);
    const userId = await createTestUser('Admin');

    const req = { params: { id: String(orderId) }, permissions: new Proxy({}, { get: () => true }), session: {}, user: { id: userId } };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.pendingCancellation).not.toBeNull();
    expect(res.renderedData.pendingCancellation.reason).toBe('Buyer requested cancellation before shipment.');
  });
});
