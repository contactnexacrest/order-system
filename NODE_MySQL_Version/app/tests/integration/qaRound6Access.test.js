'use strict';

const fs = require('fs');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderAccessService = require('../../src/services/orderAccessService');
const permissionService = require('../../src/services/permissionService');
const reportRepository = require('../../src/repositories/reportRepository');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of QaRound6AccessTest.php. QA Round 6, Batch 1 (owner approved the
 * plan 2026-10-30): the order assignment rule on child-record routes
 * (R6-N08), reports and the client page scoped to the orders the user may
 * see (R6-N01 / Q-R6-4), the Disputes list open to respond_to_disputes and
 * scoped (R6-N09 / Q-R6-3), CAFIN download needing the order too (Q-R6-2),
 * and an advance with an oversized rate saving nothing (R6-N22).
 */
describe('QA Round 6 — order access and atomic payments', () => {
  let exporter;
  let mine;
  let other;
  let clientId;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-r6-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestRound6123!';
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    return { id: result.insertId, email, password, roleId: role.id };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  async function reference(orderId) {
    return (await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id: orderId })).order_reference;
  }

  async function permissionsOf(user) {
    return permissionService.load(user.id, user.roleId);
  }

  beforeEach(async () => {
    exporter = await createUser('Export Executive');
    clientId = await createTestClient();
    mine = await createTestOrder(clientId, 'CIF', 'Standard — New Buyer', 'USD', null, null, exporter.id);
    other = await createTestOrder(await createTestClient());
    // Both orders under one client, as on the QA site (clients 9 and 10).
    await db.execute('UPDATE orders SET client_id = :c, sequence_no = 2 WHERE id = :o', { c: clientId, o: other });
  });

  it('child-record routes follow the order\'s assignment rule (R6-N08)', async () => {
    const paths = {};
    for (const orderId of [mine, other]) {
      const dispute = await db.execute("INSERT INTO disputes (order_id, notice_date, description) VALUES (:o, CURDATE(), 'Jest dispute')", { o: orderId });
      const complaint = await db.execute("INSERT INTO order_complaints (order_id, client_id, description) SELECT id, client_id, 'Jest complaint' FROM orders WHERE id = :o", { o: orderId });
      const amendment = await db.execute(
        "INSERT INTO amendments (amendment_reference, order_id, reason, requested_by, original_terms_snapshot) VALUES (:r, :o, 'Jest amendment', 'importer', '{}')",
        { r: `JEST-AMD-${orderId}`, o: orderId }
      );
      paths[orderId] = [`/disputes/${dispute.insertId}/replies`, `/complaints/${complaint.insertId}/replies`, `/amendments/${amendment.insertId}/generate-document`];
    }
    const perms = await permissionsOf(exporter);
    for (const p of paths[other]) {
      expect([p, await orderAccessService.orderIdForPath(p)]).toEqual([p, other]);
      expect([p, await orderAccessService.denialReasonForPath(p, exporter.id, perms)]).not.toEqual([p, null]);
    }
    for (const p of paths[mine]) {
      expect([p, await orderAccessService.denialReasonForPath(p, exporter.id, perms)]).toEqual([p, null]);
    }
  });

  it('reports and the client page show only visible orders (R6-N01 / Q-R6-4)', async () => {
    for (const orderId of [mine, other]) {
      await db.execute('UPDATE order_payment_status SET advance_amount = 100, advance_remittance_received_at = NOW() WHERE order_id = :o', { o: orderId });
    }
    const perms = await permissionsOf(exporter);
    const visibility = orderAccessService.visibilitySql(exporter.id, perms);
    const ids = (await reportRepository.paymentsReport(null, null, visibility)).rows.map((r) => Number(r.id));
    expect(ids).toContain(mine);
    expect(ids).not.toContain(other);
    expect((await reportRepository.perClient(clientId, visibility)).orders.map((o) => Number(o.id))).toEqual([mine]);

    const page = (await (await loginAs(exporter)).get(`/clients/${clientId}`)).text;
    expect(page).toContain(await reference(mine));
    expect(page).not.toContain(await reference(other));

    const admin = await createUser('Admin');
    const all = (await reportRepository.paymentsReport(null, null, orderAccessService.visibilitySql(admin.id, await permissionsOf(admin)))).rows.map((r) => Number(r.id));
    expect(all).toContain(other);
  });

  it('the Disputes list opens to responders and is scoped (R6-N09 / Q-R6-3)', async () => {
    const source = fs.readFileSync(require.resolve('../../src/server.js'), 'utf8');
    expect(source).toMatch(/app\.get\('\/disputes', requireAuth, requirePermissionAny\(\[[^\]]*'respond_to_disputes'/);
    expect(source).toMatch(/app\.get\('\/complaints', requireAuth, requirePermissionAny\(\[[^\]]*'respond_to_complaints'/);

    for (const [orderId, text] of [[mine, 'Jest mine dispute'], [other, 'Jest other dispute']]) {
      await db.execute('INSERT INTO disputes (order_id, notice_date, description) VALUES (:o, CURDATE(), :d)', { o: orderId, d: text });
    }
    const res = await (await loginAs(exporter)).get('/disputes');
    expect(res.status).toBe(200);
    expect(res.text).toContain(await reference(mine));
    expect(res.text).not.toContain(await reference(other));
  });

  it('CAFIN download also needs the order (Q-R6-2, D-118)', async () => {
    const fileId = await createTestFile(other, clientId);
    const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    fs.writeFileSync(file.server_path, '%PDF-1.4 CAFIN');
    await db.execute('UPDATE file_store SET file_size_bytes = 14 WHERE id = :id', { id: fileId });
    const documentId = await createTestDocument(other, 'CAFIN', 'approved');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :d', { f: fileId, d: documentId });
    try {
      // Holds view_cafin but neither manage_orders nor view_orders: the order page answers 403.
      const viewer = await createUser('Viewer / Auditor');
      for (const [key, enabled] of [['view_cafin', 1], ['view_orders', 0]]) {
        const permission = await db.queryOne('SELECT id FROM permissions WHERE permission_key = :k', { k: key });
        await db.execute('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, :e)', { u: viewer.id, p: permission.id, e: enabled });
      }
      const refused = await (await loginAs(viewer)).get(`/documents/${documentId}/download`);
      expect([refused.status, refused.text]).toEqual([403, 'You do not have permission to view this document.']);

      const accounts = await (await loginAs(await createUser('Accounts Executive'))).get(`/documents/${documentId}/download`);
      expect(accounts.status).toBe(200);
    } finally {
      fs.rmSync(file.server_path, { force: true });
    }
  });

  it('an oversized advance rate saves nothing (R6-N22)', async () => {
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: mine });
    const agent = await loginAs(await createUser('Admin'));
    const csrf = (await agent.get(`/orders/${mine}`)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const posted = await agent.post(`/orders/${mine}/payment/advance`).type('form')
      .send({ _csrf: csrf, advance_amount: '1020', advance_exchange_rate: '100000000000000000000', advance_received_at: new Date().toISOString().slice(0, 10) });
    expect(posted.status).toBe(302);
    expect((await agent.get(`/orders/${mine}`)).text).toContain('The exchange rate is too large: it can be at most 999999.9999.');
    const row = (await db.queryOne('SELECT advance_amount, advance_remittance_received_at, advance_exchange_rate FROM order_payment_status WHERE order_id = :o', { o: mine })) || {};
    expect([row.advance_amount ?? null, row.advance_remittance_received_at ?? null, row.advance_exchange_rate ?? null]).toEqual([null, null, null]);
  });

  it('what db.transaction() wraps is all saved or none of it (R6-N22)', async () => {
    await expect(db.transaction(async (conn) => {
      await conn.execute('UPDATE orders SET buyers_po_ref = :r WHERE id = :o', { r: 'JEST-TX', o: mine });
      throw new Error('second write failed');
    })).rejects.toThrow('second write failed');
    expect((await db.queryOne('SELECT buyers_po_ref FROM orders WHERE id = :o', { o: mine })).buyers_po_ref).not.toBe('JEST-TX');
  });
});
