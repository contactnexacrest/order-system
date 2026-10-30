'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const paymentTermsApprovalService = require('../../src/services/paymentTermsApprovalService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of QaRound6ConcurrencyTest.php. QA Round 6, Batch 3 (owner approved
 * the plan 2026-10-30): the same client email saved twice at once
 * (R6-N16), repeated clicks recorded several times (R6-N17), several
 * document revisions from one double click (R6-N18) and one person's save
 * silently undoing another's (R6-N27).
 */
describe('QA Round 6 — simultaneous and repeated saves', () => {
  let admin;
  let adminId;

  afterAll(async () => {
    await db.pool.end();
  });

  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  beforeEach(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-r6c-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    adminId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Concurrency Admin', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestRound6c!', 10), role_id: role.id }
    )).insertId;
    admin = request.agent(app);
    const csrf = csrfOf((await admin.get('/login')).text);
    await admin.post('/login').type('form').send({ _csrf: csrf, email, password: 'JestRound6c!' });
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  async function post(path, fields, page) {
    const csrf = csrfOf((await admin.get(page)).text);
    return admin.post(path).type('form').send({ _csrf: csrf, ...fields });
  }

  async function flashAfter(res) {
    const m = (await admin.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim() } : { type: null, message: '' };
  }

  async function auditCount(action, entityId) {
    return Number((await db.queryOne('SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :a AND entity_id = :e', { a: action, e: entityId })).n);
  }

  it('the same client email cannot be saved twice (R6-N16)', async () => {
    const email = `jest-r6-${Math.random().toString(16).slice(2, 10)}@example.test`;
    const first = await createTestClient(email);
    const second = await createTestClient();
    const page = `/clients/${second}/edit`;
    const csrf = csrfOf((await admin.get(page)).text);
    // What the slower of two simultaneous saves does once both passed the "already used" check.
    jest.spyOn(clientRepository, 'findOtherWithEmail').mockResolvedValue(null);
    const res = await admin.post(`/clients/${second}/update`).set('Referer', page).type('form')
      .send({ _csrf: csrf, company_legal_name: 'Jest Second Buyer', billing_address: '2 Test Street', email: email.toUpperCase() });
    jest.restoreAllMocks();
    expect((await flashAfter(res)).message).toBe('Another client already uses this email — each client needs its own (the client portal logs in by it). Nothing was saved.');
    expect((await db.queryOne('SELECT email FROM clients WHERE id = :id', { id: second })).email).not.toBe(email.toUpperCase());
    expect((await db.queryOne('SELECT email FROM clients WHERE id = :id', { id: first })).email).toBe(email);
  });

  it('clients without an email never clash (R6-N16)', async () => {
    const ids = [];
    for (const n of [1, 2]) {
      ids.push(await clientRepository.create({ company_legal_name: `Jest No Email ${n}`, billing_address: '1 Test Street', email: '  ' }, adminId, `NC/SC/TEST/${Math.random().toString(16).slice(2, 10)}`));
    }
    for (const id of ids) {
      expect((await db.queryOne('SELECT email FROM clients WHERE id = :id', { id })).email).toBeNull();
    }
    await clientRepository.update(ids[0], { company_legal_name: 'Jest No Email 1', billing_address: '1 Test Street', email: '' });
    expect((await db.queryOne('SELECT email FROM clients WHERE id = :id', { id: ids[0] })).email).toBeNull();
  });

  it('a repeated payment is recorded and logged once (R6-N17)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    // Every real order has its payment-status row from creation.
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    const fields = { advance_amount: '1020', advance_exchange_rate: '83.25', advance_received_at: todayIst() };
    const page = `/orders/${orderId}`;
    expect((await flashAfter(await post(`/orders/${orderId}/payment/advance`, fields, page))).type).toBe('success');
    expect((await flashAfter(await post(`/orders/${orderId}/payment/advance`, fields, page))).message).toContain('already recorded with these details');
    expect(await auditCount('ADVANCE_PAYMENT_RECORDED', orderId)).toBe(1);

    expect((await flashAfter(await post(`/orders/${orderId}/payment/advance`, { ...fields, advance_amount: '1030' }, page))).type).toBe('success');
    expect(await auditCount('ADVANCE_PAYMENT_RECORDED', orderId)).toBe(2);
  });

  it('a repeated payout payment and approval are logged once (R6-N17)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const month = todayIst().slice(0, 7);
    const { insertId: payoutId } = await db.execute(
      "INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status) VALUES (:o, 'staff', :u, 'sales', 5000, :m, 'due')",
      { o: orderId, u: adminId, m: month }
    );
    for (const click of [1, 2]) {
      await post(`/incentives/payouts/${payoutId}/paid`, { month, paid_on: todayIst(), paid_inr: '5000', click }, `/incentives/payouts?month=${month}`);
    }
    expect(await auditCount('INCENTIVE_PAID', payoutId)).toBe(1);

    expect(await paymentTermsApprovalService.approve(orderId, adminId)).toBe(true);
    expect(await paymentTermsApprovalService.approve(orderId, adminId)).toBe(false);
    expect(await auditCount('PAYMENT_TERMS_APPROVED', orderId)).toBe(1);
  });

  it('one generation of a document at a time (R6-N18)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const holder = await db.pool.getConnection();
    const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM documents WHERE order_id = :o', { o: orderId })).n);
    try {
      await holder.query('SELECT GET_LOCK(?, 0)', [`nx_docgen_${orderId}_QT`]);
      const before = await count();
      const res = await post(`/orders/${orderId}/documents/generate`, { document_type: 'QT' }, `/orders/${orderId}`);
      expect((await flashAfter(res)).message).toBe('This document is being generated right now — wait a moment, then refresh the page.');
      expect(await count()).toBe(before);
    } finally {
      await holder.query('SELECT RELEASE_LOCK(?)', [`nx_docgen_${orderId}_QT`]);
      holder.release();
    }
    expect(Number((await db.queryOne('SELECT IS_FREE_LOCK(:n) AS free', { n: `nx_docgen_${orderId}_QT` })).free)).toBe(1);
  });

  const fingerprintFrom = (html) => {
    const m = html.match(/name="edit_fingerprint" value="([0-9a-f]{40})"/);
    expect(m).not.toBeNull();
    return m[1];
  };

  it('a save on a stale order form is refused (R6-N27)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    const page = `/orders/${orderId}/edit`;
    const fingerprint = fingerprintFrom((await admin.get(page)).text);
    const form = (over) => ({ incoterm_id: String(order.incoterm_id), currency_id: String(order.currency_id), edit_fingerprint: fingerprint, ...over });

    expect((await flashAfter(await post(page, form({ port_of_discharge_text: 'Rotterdam, Netherlands' }), page))).type).toBe('success');
    const refused = await flashAfter(await post(page, form({ est_lead_time_text: '45 days' }), page));
    expect(refused.message).toContain('was changed by');
    expect(refused.message).toContain('Nothing was saved');
    const saved = await orderRepository.find(orderId);
    expect(saved.port_of_discharge_text).toBe('Rotterdam, Netherlands');
    expect(saved.est_lead_time_text).not.toBe('45 days');

    const fresh = fingerprintFrom((await admin.get(page)).text);
    const ok = await post(page, { incoterm_id: String(order.incoterm_id), currency_id: String(order.currency_id), port_of_discharge_text: 'Rotterdam, Netherlands', est_lead_time_text: '45 days', edit_fingerprint: fresh }, page);
    expect((await flashAfter(ok)).type).toBe('success');
  });

  it('a save on a stale client form is refused (R6-N27)', async () => {
    const clientId = await createTestClient();
    const page = `/clients/${clientId}/edit`;
    const fingerprint = fingerprintFrom((await admin.get(page)).text);
    const base = { company_legal_name: 'Jest Buyer Ltd', billing_address: '1 Test Street', edit_fingerprint: fingerprint };

    expect((await flashAfter(await post(`/clients/${clientId}/update`, { ...base, contact_person: 'Person A' }, page))).type).not.toBe('error');
    expect((await flashAfter(await post(`/clients/${clientId}/update`, { ...base, contact_person: 'Person B' }, page))).message).toContain('was changed by');
    expect((await db.queryOne('SELECT contact_person FROM clients WHERE id = :id', { id: clientId })).contact_person).toBe('Person A');
  });
});
