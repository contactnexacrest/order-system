'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderLockGuard = require('../../src/middleware/orderLockGuard');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderLockGuardMiddlewareTest.php. External QA report L-28:
 * "Closed (complete + locked) order: production status can still be
 * edited — the new 'lock closed orders' does not cover every edit
 * route." orderEditGuard (covered by orderLockedEditGuard.test.js) only
 * ever protected the 5 routes that called it by hand; this middleware is
 * the central guard registered on every other order-mutating POST
 * /orders/:id/* route in src/server.js (see orderLockGuard's own
 * docblock for the exact exclusion list).
 */
describe('orderLockGuard middleware (External QA report L-28)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('blocks the action on a locked order without the override permission', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.markComplete(orderId);
    const req = { params: { id: String(orderId) }, permissions: { edit_locked_data: false }, session: {} };
    const res = fakeRes();
    let reached = false;

    await orderLockGuard.check()(req, res, () => { reached = true; });

    expect(reached).toBe(false);
    expect(res.redirectedTo).toBe(`/orders/${orderId}`);
  });

  it('allows the action on a locked order with the override permission', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderRepository.markComplete(orderId);
    const req = { params: { id: String(orderId) }, permissions: { edit_locked_data: true }, session: {} };
    const res = fakeRes();
    let reached = false;

    await orderLockGuard.check()(req, res, () => { reached = true; });

    expect(reached).toBe(true);
  });

  it('allows the action on an active order regardless', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = { params: { id: String(orderId) }, permissions: { edit_locked_data: false }, session: {} };
    const res = fakeRes();
    let reached = false;

    await orderLockGuard.check()(req, res, () => { reached = true; });

    expect(reached).toBe(true);
  });

  it('every non-excluded order mutation route is registered with checkOrderLock', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8');
    const excluded = new Set([
      '/orders/:id/override-status-lock', '/orders/:id/close', '/orders/:id/mark-fully-settled', '/orders/:id/mark-lost',
      '/orders/:id/archive', '/orders/:id/unarchive', '/orders/:id/duplicate', '/orders/:id/comments',
      '/orders/:id/documents/generate', '/orders/:id/documents/:documentId/delete',
      '/orders/:id/documents/:documentId/send', '/orders/:id/email/send', '/orders/:id/disputes',
      // docs/schema.sql Section BX — a Complaint, like a Dispute, is never
      // a routine edit gated behind the order's own lock state; it must
      // stay reachable on any order, locked or not.
      '/orders/:id/complaints',
      // External QA report M-09: the cancellation workflow is mark-lost's
      // sibling (a terminal action ending the order's active life, not a
      // routine edit), excluded for the same reason mark-lost is above.
      // completeCancellationRefund in particular MUST stay excluded: by
      // the time it runs, cancel() has already set is_locked=1, so
      // guarding it would make it permanently unreachable for the
      // close_orders-only tier it's deliberately gated on.
      '/orders/:id/cancellation/request', '/orders/:id/cancellation/:cancellationId/approve',
      '/orders/:id/cancellation/:cancellationId/reject', '/orders/:id/cancellation/:cancellationId/complete-refund',
      // Order ownership / assignment visibility (see
      // docs/migrations/2026-10-24c-order-multi-assignee.sql) —
      // visibility-only, never touches the order's own substantive data,
      // same tier as disputes/complaints above: adding or removing an
      // assignee on a completed order (e.g. a staff member leaves) must
      // stay reachable regardless of lock state. Already narrowly gated
      // to assign_order_owner (Admin/MD/ED), who hold edit_locked_data
      // too in practice.
      '/orders/:id/assignees', '/orders/:id/assignees/:userId/remove',
    ]);
    const lines = source.split('\n').filter((line) => /^app\.post\('\/orders\/:id/.test(line.trim()));
    expect(lines.length).toBeGreaterThan(0);

    for (const line of lines) {
      const m = line.trim().match(/^app\.post\('([^']+)'/);
      const routePath = m[1];
      if (excluded.has(routePath)) continue;
      expect(line).toContain('checkOrderLock');
    }
  });
});
