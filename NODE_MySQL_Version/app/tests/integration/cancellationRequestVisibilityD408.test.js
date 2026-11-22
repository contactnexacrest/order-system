'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderCancellationRepository = require('../../src/repositories/orderCancellationRepository');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CancellationRequestVisibilityD408Test.php — owner decision D-408 (22 Nov 2026): after
 * "Request cancellation" the person sees plainly that the request waits for approval — the order
 * page opens on a "waiting for approval" box (with the requester's real name, not "unknown"), the
 * order number carries a badge, and the Standard list and the Kanban board both show the badge and
 * a "Cancellation requested" filter. The order stays Active until approved (D-082).
 */
describe('D-408 a cancellation request shows as waiting for approval', () => {
  const PASSWORD = 'JestD408!1';

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d408-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    return { id: r.insertId, email };
  }

  async function signIn(user, kanban = false) {
    const t = kanban ? await db.queryOne("SELECT id FROM ui_templates WHERE template_key = 'kanban'") : null;
    await db.execute('UPDATE users SET ui_template_preference_id = :t WHERE id = :id', { t: t ? t.id : null, id: user.id });
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  test('the request says it was sent for approval and lands on the box', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createUser('Export Executive');
    const req = { params: { id: String(orderId) }, body: { reason: 'Buyer withdrew the order by e-mail.' }, user: { id: requester.id }, session: {}, permissions: new Proxy({}, { get: () => true }) };
    let redirectedTo = null;
    const res = { status() { return res; }, send() {}, redirect(u) { redirectedTo = u; } };

    await ordersController.requestCancellation(req, res);

    expect(redirectedTo).toBe(`/orders/${orderId}#cancellation-request`);
    const all = JSON.stringify(req.session);
    expect(all).toContain('Your cancellation request has been sent for approval. The order stays active until it is approved.');
    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  test('the pending lookup carries the requester\'s name', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createUser('Export Executive');
    await orderCancellationRepository.create(orderId, 'Buyer withdrew the order by e-mail.', requester.id);
    expect((await orderCancellationRepository.findPendingForOrder(orderId)).requested_by_name).toBe('Jest Test User');
  });

  test('the order page shows the waiting box and the badge', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const requester = await createUser('Export Executive');
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer withdrew the order by e-mail.', requester.id);
    await db.execute("UPDATE order_cancellations SET requested_at = '2026-11-22 17:40:02' WHERE id = :id", { id: cancellationId });
    const agent = await signIn(await createUser('Admin'));

    const html = (await agent.get(`/orders/${orderId}`)).text;

    expect(html).toContain('⏳ Cancellation requested — waiting for approval');
    expect(html).toContain('Requested by <strong>Jest Test User</strong> on 22 Nov 2026, 17:40 · Reason: Buyer withdrew the order by e-mail.');
    expect(html).toContain('The order stays <strong>Active</strong> until someone approves');
    expect(html).not.toContain('requested by unknown');
    expect(html).toContain('href="#cancellation-request" data-testid="cancellation-pending-badge">Cancellation requested</a>');
    expect(html).toContain('id="cancellation-request"');
  });

  test('no badge without a pending request', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const agent = await signIn(await createUser('Admin'));
    const html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).not.toContain('cancellation-pending-badge');
    expect(html).not.toContain('waiting for approval');
  });

  describe.each([['Standard list', false], ['Kanban board', true]])('%s', (_name, kanban) => {
    test('shows the badge and the filter', async () => {
      const orderId = await createTestOrder(await createTestClient());
      const other = await createTestOrder(await createTestClient());
      await orderCancellationRepository.create(orderId, 'Buyer withdrew the order by e-mail.', (await createUser('Export Executive')).id);
      const agent = await signIn(await createUser('Admin'), kanban);
      const ref = (await orderRepository.find(orderId)).order_reference;
      const otherRef = (await orderRepository.find(other)).order_reference;

      let html = (await agent.get('/orders?status=cancellation_requested')).text;
      expect(html).toMatch(/status=cancellation_requested[^"]*">Cancellation requested \(\d+\)</);
      expect(html).toContain(esc(ref));
      expect(html).not.toContain(esc(otherRef));
      expect(html).toContain('data-testid="cancellation-pending-badge">⏳ Cancellation requested — waiting for approval</span>');

      html = (await agent.get('/orders?status=active')).text;
      expect(html).toContain(esc(ref));
      expect(html).toContain('cancellation-pending-badge');
    });

    test('once approved the order leaves the filter and loses the badge', async () => {
      const orderId = await createTestOrder(await createTestClient());
      const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer withdrew the order by e-mail.', (await createUser('Export Executive')).id);
      await orderRepository.cancel(orderId);
      await orderCancellationRepository.approve(cancellationId, (await createUser('Admin')).id, 'ok', null, 'not_applicable');
      const agent = await signIn(await createUser('Admin'), kanban);
      const ref = (await orderRepository.find(orderId)).order_reference;

      expect((await agent.get('/orders?status=cancellation_requested')).text).not.toContain(esc(ref));
      const html = (await agent.get('/orders?status=cancelled')).text;
      expect(html).toContain(esc(ref));
      expect(html).not.toContain('cancellation-pending-badge');
    });
  });

  test('the controller source sends the person to the box (PHP twin checks the same)', () => {
    const src = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'controllers', 'ordersController.js'), 'utf8');
    expect(src).toContain('res.redirect(`/orders/${orderId}#cancellation-request`);');
  });
});
