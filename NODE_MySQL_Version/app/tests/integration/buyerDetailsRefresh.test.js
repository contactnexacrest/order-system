'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const requests = require('../../src/repositories/orderBuyerDetailRequestRepository');
const svc = require('../../src/services/buyerDetailsRefreshService');
const selfApprovalRules = require('../../src/services/selfApprovalRules');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Owner decision D-337 (12 Nov 2026): the buyer details an order keeps (R25)
 * follow the client — at once while nothing is issued, by an approved request
 * after. Covers case 1 (automatic, flash wording, audit, FYI notice), the
 * "issued" rule, the request / approve / refuse / lapse paths, the one open
 * request rule and the self-approval rule (default MD and ED).
 * Port of BuyerDetailsRefreshTest.php (same wording).
 */

const rnd = () => Math.random().toString(16).slice(2, 8);

async function createTestUser(roleName) {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-d337-${rnd()}${rnd()}@nexacrest.test`, role_id: role.id });
  return result.insertId;
}

const newClient = () => createTestClient(`d337-${rnd()}@buyer.test`);
const setClientEmail = (clientId, email) => db.execute('UPDATE clients SET email = :e WHERE id = :id', { e: email, id: clientId });
const orderEmail = async (orderId) => (await db.queryOne('SELECT client_email FROM orders WHERE id = :id', { id: orderId })).client_email;
const auditRows = (orderId, action) => db.query(
  "SELECT * FROM audit_log WHERE entity_type = 'orders' AND entity_id = :id AND action_type = :a ORDER BY id", { id: orderId, a: action });
const notifications = (userId, orderId) => db.query(
  'SELECT type, message FROM notifications WHERE user_id = :u AND related_order_id = :o ORDER BY id', { u: userId, o: orderId });
const refOf = async (orderId) => String((await orderRepository.find(orderId)).order_reference);

/** An order of the client with an issued (sent) quotation, the client's e-mail then changed. */
async function issuedOrderWithChangedEmail(clientId, newEmail) {
  const orderId = await createTestOrder(clientId);
  await createTestDocument(orderId, 'QT', 'sent');
  await setClientEmail(clientId, newEmail);
  return orderId;
}

describe('D-337 buyer details follow the client', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('case 1: a draft order takes the client\'s details, with flash, audit and notice', async () => {
    const md = await createTestUser('Managing Director');
    const exec = await createTestUser('Export Executive');
    const clientId = await newClient();
    const draft = await createTestOrder(clientId);
    await createTestDocument(draft, 'QT', 'draft');
    const issued = await createTestOrder(clientId);
    await createTestDocument(issued, 'QT', 'sent');
    const old = await orderEmail(draft);
    const next = `x${rnd()}@buyer.test`;
    await setClientEmail(clientId, next);

    const result = await svc.afterClientSaved(clientId, exec, 'client edit');
    const ref = await refOf(draft);
    expect(result.refreshed).toEqual([ref]);
    expect(await orderEmail(draft)).toBe(next);
    expect(await orderEmail(issued)).toBe(old); // an order with an issued document never changes on its own
    expect(svc.flashText(result)).toBe(` Buyer details also updated on draft order(s): ${ref}.`);

    const audit = await auditRows(draft, 'ORDER_BUYER_DETAILS_REFRESHED');
    expect(audit).toHaveLength(1);
    expect([audit[0].field_name, audit[0].old_value, audit[0].new_value, audit[0].reason]).toEqual(['client_email', old, next, 'Source: client edit']);

    const notes = await notifications(md, draft);
    expect(notes[0].type).toBe('buyer_details_refreshed');
    expect(notes[0].message).toBe(`Buyer details of Jest Test Buyer Ltd changed by Jest Test User — also updated on draft order ${ref}: Email: ${old} → ${next}`);
    expect(await notifications(exec, draft)).toEqual([]); // the person who saved the client is not told

    expect(await svc.afterClientSaved(clientId, exec, 'client edit')).toEqual({ refreshed: [], lapsed: [] });
    expect(await auditRows(draft, 'ORDER_BUYER_DETAILS_REFRESHED')).toHaveLength(1);
  });

  it('issued means approved, sent, or superseded after being issued', async () => {
    const clientId = await newClient();
    for (const [status, issued] of [['draft', false], ['in_review', false], ['approved', true], ['sent', true]]) {
      const orderId = await createTestOrder(clientId);
      await createTestDocument(orderId, 'QT', status);
      expect([status, await svc.hasIssuedDocument(orderId)]).toEqual([status, issued]);
    }
    const orderId = await createTestOrder(clientId);
    expect(await svc.hasIssuedDocument(orderId)).toBe(false); // no documents at all
    const fromDraft = await createTestDocument(orderId, 'QT', 'superseded');
    await auditLogRepository.log(null, 'DOCUMENT_SUPERSEDED', 'documents', fromDraft, 'status', 'draft', 'superseded');
    expect(await svc.hasIssuedDocument(orderId)).toBe(false);
    const fromSent = await createTestDocument(orderId, 'PI', 'superseded');
    await auditLogRepository.log(null, 'DOCUMENT_SUPERSEDED', 'documents', fromSent, 'status', 'sent', 'superseded');
    expect(await svc.hasIssuedDocument(orderId)).toBe(true);
  });

  it('cancelled, lost and complete orders are not changed', async () => {
    const clientId = await newClient();
    const ids = {};
    for (const status of ['cancelled', 'lost', 'complete']) {
      ids[status] = await createTestOrder(clientId);
      await db.execute('UPDATE orders SET status = :s WHERE id = :id', { s: status, id: ids[status] });
    }
    const before = await orderEmail(ids.lost);
    await setClientEmail(clientId, `closed${rnd()}@buyer.test`);
    expect((await svc.afterClientSaved(clientId, null, 'client edit')).refreshed).toEqual([]);
    expect(await orderEmail(ids.lost)).toBe(before);
  });

  it('a request needs a reason and only one may wait', async () => {
    const exec = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const clientId = await newClient();
    const orderId = await issuedOrderWithChangedEmail(clientId, `req${rnd()}@buyer.test`);

    expect(await svc.request(orderId, exec, 'too short')).toBe('Give the reason (10 to 500 characters).');
    expect(await svc.request(orderId, exec, 'a'.repeat(501))).toBe('Give the reason (10 to 500 characters).');
    expect(await svc.request(orderId, exec, 'The buyer changed their e-mail')).toBeNull();
    expect(await svc.request(orderId, exec, 'Asking a second time for this order'))
      .toBe('A request to update the buyer details is already waiting for approval.');

    const pending = await requests.pendingForOrder(orderId);
    expect(pending.status).toBe('PENDING');
    expect(JSON.parse(pending.changes_json)[0].field).toBe('client_email');
    expect(await auditRows(orderId, 'BUYER_DETAILS_CHANGE_REQUESTED')).toHaveLength(1);
    expect((await notifications(md, orderId))[0].type).toBe('buyer_details_change_requested');

    const notice = await svc.noticeFor(await orderRepository.find(orderId));
    expect(notice.issued).toBe(true);
    expect(Number(notice.pending.id)).toBe(Number(pending.id));
  });

  it('nothing issued: no request, but a direct update', async () => {
    const exec = await createTestUser('Export Executive');
    const clientId = await newClient();
    const orderId = await createTestOrder(clientId);
    const next = `direct${rnd()}@buyer.test`;
    await setClientEmail(clientId, next);
    expect(await svc.request(orderId, exec, 'The buyer changed their e-mail'))
      .toBe('Nothing has been issued on this order yet — use "Update buyer details from the client" (no approval needed).');
    expect((await svc.refreshOrder(orderId, exec)).error).toBeNull();
    expect(await orderEmail(orderId)).toBe(next);
    expect((await svc.refreshOrder(orderId, exec)).error).toBe('The order already shows the client\'s current details — nothing to update.');
  });

  it('approve applies the details and tells the requester', async () => {
    const exec = await createTestUser('Export Executive');
    const ed = await createTestUser('Executive Director');
    const clientId = await newClient();
    const next = `ok${rnd()}@buyer.test`;
    const orderId = await issuedOrderWithChangedEmail(clientId, next);
    await svc.request(orderId, exec, 'The buyer changed their e-mail');
    const req = await requests.pendingForOrder(orderId);

    expect(await svc.approve(Number(req.id), orderId, ed, 'Checked with the buyer')).toBeNull();
    expect(await orderEmail(orderId)).toBe(next);
    const after = await requests.find(Number(req.id));
    expect([after.status, Number(after.decided_by)]).toEqual(['APPROVED', Number(ed)]);
    expect((await auditRows(orderId, 'ORDER_BUYER_DETAILS_REFRESHED'))[0].reason).toBe(`Source: approved request #${req.id}`);
    expect(await auditRows(orderId, 'BUYER_DETAILS_CHANGE_APPROVED')).toHaveLength(1);
    const ref = await refOf(orderId);
    expect((await notifications(exec, orderId))[0].message)
      .toBe(`Your request to update the buyer details of order ${ref} was approved by Jest Test User — documents generated from now on show the new details.`);
    expect(await svc.approve(Number(req.id), orderId, ed, '')).toBe('This request is no longer waiting for approval.');
    expect(await svc.noticeFor(await orderRepository.find(orderId))).toBeNull();
  });

  it('refuse needs a note and keeps the order', async () => {
    const exec = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const clientId = await newClient();
    const orderId = await issuedOrderWithChangedEmail(clientId, `no${rnd()}@buyer.test`);
    const before = await orderEmail(orderId);
    await svc.request(orderId, exec, 'The buyer changed their e-mail');
    const req = await requests.pendingForOrder(orderId);

    expect(await svc.refuse(Number(req.id), orderId, md, 'no')).toBe('Give the note saying why it is refused (10 to 500 characters).');
    expect(await svc.refuse(Number(req.id), orderId, md, 'The buyer has not confirmed it')).toBeNull();
    expect((await requests.find(Number(req.id))).status).toBe('REFUSED');
    expect(await orderEmail(orderId)).toBe(before);
    const ref = await refOf(orderId);
    expect((await notifications(exec, orderId))[0].message)
      .toBe(`Your request to update the buyer details of order ${ref} was refused by Jest Test User: The buyer has not confirmed it`);
    expect(await auditRows(orderId, 'BUYER_DETAILS_CHANGE_REFUSED')).toHaveLength(1);
  });

  it('a request lapses when the client changes before approval', async () => {
    const exec = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const clientId = await newClient();
    const orderId = await issuedOrderWithChangedEmail(clientId, `first${rnd()}@buyer.test`);
    const before = await orderEmail(orderId);
    await svc.request(orderId, exec, 'The buyer changed their e-mail');
    const req = await requests.pendingForOrder(orderId);
    await setClientEmail(clientId, `second${rnd()}@buyer.test`);

    expect(await svc.approve(Number(req.id), orderId, md, ''))
      .toBe('The client\'s details changed again after this request — ask again so the approver sees the current details.');
    expect((await requests.find(Number(req.id))).status).toBe('LAPSED');
    expect(await orderEmail(orderId)).toBe(before); // nothing applied
    expect(await auditRows(orderId, 'BUYER_DETAILS_CHANGE_LAPSED')).toHaveLength(1);
  });

  it('saving the client again lapses a waiting request', async () => {
    const exec = await createTestUser('Export Executive');
    const clientId = await newClient();
    const orderId = await issuedOrderWithChangedEmail(clientId, `first${rnd()}@buyer.test`);
    await svc.request(orderId, exec, 'The buyer changed their e-mail');
    const req = await requests.pendingForOrder(orderId);
    await setClientEmail(clientId, `again${rnd()}@buyer.test`);

    const result = await svc.afterClientSaved(clientId, exec, 'client edit');
    const ref = await refOf(orderId);
    expect(result.lapsed).toEqual([ref]);
    expect(svc.flashText(result)).toBe(` A waiting request to update the buyer details lapsed on order(s) ${ref} — ask again so the approver sees the current details.`);
    const after = await requests.find(Number(req.id));
    expect([after.status, after.decision_note]).toEqual(['LAPSED', 'Lapsed — the client was saved again (client edit)']);
  });

  it('nobody decides their own request unless the rule allows it (default MD and ED)', async () => {
    expect(Object.keys(selfApprovalRules.RULES)).toContain('buyer_details_change');
    const ed = await createTestUser('Executive Director');
    const md = await createTestUser('Managing Director');
    // Owner, 12 Nov 2026: the default is the MD and the ED, like every other rule.
    expect(await selfApprovalRules.allowed('buyer_details_change', ed)).toBe(true);
    expect(await selfApprovalRules.allowed('buyer_details_change', md)).toBe(true);

    // Admin unticks the ED: the ED can no longer decide their own request.
    const [edRole] = await db.query("SELECT id FROM roles WHERE name = 'Executive Director'");
    await db.execute("DELETE FROM self_approval_rules WHERE rule_key = 'buyer_details_change' AND role_id = :r", { r: edRole.id });
    let clientId;
    try {
      expect(await selfApprovalRules.allowed('buyer_details_change', ed)).toBe(false);
      clientId = await newClient();
      const orderId = await issuedOrderWithChangedEmail(clientId, `self${rnd()}@buyer.test`);
      await svc.request(orderId, ed, 'The buyer changed their e-mail');
      const req = await requests.pendingForOrder(orderId);
      const msg = 'You asked for this change — someone else must approve it (Admin › Self-approval rules).';
      expect(await svc.approve(Number(req.id), orderId, ed, '')).toBe(msg);
      expect(await svc.refuse(Number(req.id), orderId, ed, 'Refusing my own request')).toBe(msg);
      expect((await requests.find(Number(req.id))).status).toBe('PENDING');
    } finally {
      await db.execute(`INSERT INTO self_approval_rules (rule_key, role_id) SELECT 'buyer_details_change', :r FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM self_approval_rules WHERE rule_key = 'buyer_details_change' AND role_id = :r2)`, { r: edRole.id, r2: edRole.id });
    }

    const orderId2 = await issuedOrderWithChangedEmail(clientId, `self2${rnd()}@buyer.test`);
    await svc.request(orderId2, md, 'The buyer changed their e-mail');
    const req2 = await requests.pendingForOrder(orderId2);
    expect(await svc.approve(Number(req2.id), orderId2, md, '')).toBeNull();
  });

  it('the permission is seeded for MD and ED and the client save paths call the refresh', async () => {
    const roles = await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE p.permission_key = 'approve_buyer_details_change' ORDER BY r.name`);
    expect(roles.map((r) => r.name)).toEqual(['Executive Director', 'Managing Director']);
    const dir = path.join(__dirname, '../../src/controllers');
    expect(fs.readFileSync(path.join(dir, 'clientsController.js'), 'utf8')).toContain("buyerDetails.afterClientSaved(clientId, Number(user.id), 'client edit')");
    expect(fs.readFileSync(path.join(dir, 'piIntakeReviewController.js'), 'utf8')).toContain('buyerDetails.afterClientSaved(');
  });
});
