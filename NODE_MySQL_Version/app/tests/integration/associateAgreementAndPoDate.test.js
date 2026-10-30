'use strict';

const fs = require('fs');
const request = require('supertest');
const bcrypt = require('bcrypt');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const incentiveService = require('../../src/services/incentiveService');
const dates = require('../../src/helpers/dates');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of AssociateAgreementAndPoDateTest.php. Owner decision 2026-10-30
 * (incentive follow-ups 10 and 11): the signed associate agreement is kept
 * in the app with its date, and Step 4d records the day the buyer's PO
 * arrived — the agreement's Day 1, so the 10-day rule counts from it.
 */
describe('Associate agreement on file and the buyer PO received date', () => {
  let mailBefore;

  beforeEach(async () => {
    mailBefore = await companySettingsRepository.get('mail_sending_enabled');
  });

  afterEach(async () => {
    await companySettingsRepository.set('mail_sending_enabled', String(mailBefore ?? '0'), null);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function loginAs(email, password) {
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email, password });
    return agent;
  }

  async function csrfFrom(agent, path) {
    return (await agent.get(path)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  function shiftDays(ymd, days) {
    const d = new Date(`${ymd}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }

  const associateFields = (overrides = {}) => ({
    name: 'Jest Associate', based_in: 'india', country: '', currency_code: 'INR', email: '', phone: '', tax_id: '', agreement_signed_on: '',
    rate_1_5: '0.70', rate_6_10: '1.00', rate_11_20: '1.50', rate_21_40: '1.75', rate_41_plus: '2.00', status: 'active', notes: '', ...overrides,
  });

  async function associateCount() {
    return Number((await db.queryOne("SELECT COUNT(*) AS n FROM associates WHERE name = 'Jest Associate'")).n);
  }

  async function flashOf(agent) {
    return (await agent.get('/associates')).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/) || [];
  }

  it('Step 4d records the day the buyer\'s PO arrived', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await db.execute(
      "UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'in_progress', os.unlocked_at = NOW() WHERE os.order_id = :o AND sm.stage_number = 2",
      { o: orderId }
    );
    await db.execute('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:o, :f)', { o: orderId, f: await createTestFile(orderId, clientId) });
    await companySettingsRepository.set('mail_sending_enabled', '0', null);
    await createTestDocument(orderId, 'BUYERPO', 'approved');
    const created = String((await orderRepository.find(orderId)).created_at).slice(0, 10);

    const agent = await loginAs(TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD);
    let html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toMatch(new RegExp(`<input type="date" name="buyer_po_received_on" required min="${created}" max="${todayIst()}"`));

    for (const [date, why] of [['', 'no date'], [shiftDays(todayIst(), 1), 'a future date'], [shiftDays(created, -1), 'before the order']]) {
      const csrf = html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
      await agent.post(`/orders/${orderId}/buyer-po`).type('form').send({ _csrf: csrf, buyers_po_ref: 'PO-77', buyer_po_received_on: date });
      html = (await agent.get(`/orders/${orderId}`)).text;
      expect([why, html.includes('Enter the date the buyer&#39;s PO was received') || html.includes("Enter the date the buyer's PO was received")]).toEqual([why, true]);
      expect((await orderRepository.find(orderId)).buyer_po_received_on).toBeNull();
    }

    const csrf = html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post(`/orders/${orderId}/buyer-po`).type('form').send({ _csrf: csrf, buyers_po_ref: 'PO-77', buyer_po_received_on: created });
    const order = await orderRepository.find(orderId);
    expect([order.buyers_po_ref, order.buyer_po_received_on]).toEqual(['PO-77', created]);
    expect((await agent.get(`/orders/${orderId}`)).text).toContain(`&middot; received on ${dates.short(created)}`);

    await orderRepository.setBuyersPoRef(orderId, 'PO-77A');
    expect((await orderRepository.find(orderId)).buyer_po_received_on).toBe(created); // changing only the reference keeps the date
  });

  it('the ten days count from the client\'s first PO received', async () => {
    const clientId = await createTestClient();
    const first = await createTestOrder(clientId);
    const second = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET client_id = :c, sequence_no = 2 WHERE id = :o', { c: clientId, o: second });
    const set = (orderId, created, po) => db.execute('UPDATE orders SET created_at = :c, buyer_po_received_on = :p WHERE id = :o', { c: `${created} 10:00:00`, p: po, o: orderId });
    const within = async (orderId) => incentiveService.withinFirstTenDays(await orderRepository.find(orderId));

    // The illustration in clause 4.6: first PO received 4 March (Day 1), a later one on 13 March (Day 10).
    await set(first, '2026-02-25', '2026-03-04');
    await set(second, '2026-03-12', '2026-03-13');
    expect(await within(second)).toBe(true); // Day 10 from the first PO received is the same order
    await set(second, '2026-03-12', '2026-03-14');
    expect(await within(second)).toBe(false); // Day 11 is a repeat order

    await set(second, '2026-03-10', null);
    expect(await within(second)).toBe(true); // no PO date recorded: counts from the order date
    await set(first, '2026-02-25', null);
    expect(await within(second)).toBe(false); // and so does the first order
  });

  it('the signed agreement goes with its signing date', async () => {
    const before = await associateCount();
    const agent = await loginAs(TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD);

    let csrf = await csrfFrom(agent, '/associates');
    await agent.post('/associates').type('form').send({ _csrf: csrf, ...associateFields({ agreement_signed_on: '2026-10-01' }) });
    expect((await flashOf(agent))[1]).toBe('error'); // a signing date needs the signed copy
    expect(await associateCount()).toBe(before);

    csrf = await csrfFrom(agent, '/associates');
    let req = agent.post('/associates').field('_csrf', csrf);
    for (const [k, v] of Object.entries(associateFields())) req = req.field(k, v);
    await req.attach('agreement_file', Buffer.from('%PDF-1.4 signed agreement'), 'agreement.pdf');
    expect((await flashOf(agent))[2]).toContain('date the agreement was signed'); // a signed copy needs its signing date
    expect(await associateCount()).toBe(before);

    csrf = await csrfFrom(agent, '/associates');
    req = agent.post('/associates').field('_csrf', csrf);
    for (const [k, v] of Object.entries(associateFields({ agreement_signed_on: '2026-10-01' }))) req = req.field(k, v);
    await req.attach('agreement_file', Buffer.from('%PDF-1.4 signed agreement'), 'agreement.pdf');
    expect((await flashOf(agent))[1]).toBe('success');
    const saved = await db.queryOne("SELECT id, agreement_file_id FROM associates WHERE name = 'Jest Associate' ORDER BY id DESC LIMIT 1");
    expect(saved.agreement_file_id).not.toBeNull();
    const audit = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_AGREEMENT_UPLOADED' AND entity_id = :id", { id: saved.id });
    expect(Number(audit.n)).toBe(1);

    const html = (await agent.get('/associates')).text;
    expect(html).toContain('enctype="multipart/form-data"');
    expect(html).toContain('<input type="file" name="agreement_file" accept=".pdf,application/pdf" data-required-if="agreement_signed_on"');
    expect(html).toContain(`<a href="/associates/${saved.id}/agreement" class="small">Signed copy</a>`);

    const download = await agent.get(`/associates/${saved.id}/agreement`);
    expect(download.status).toBe(200);
    expect(download.headers['content-type']).toBe('application/pdf');
    expect(Buffer.from(download.body).toString()).toBe('%PDF-1.4 signed agreement');
    const downloads = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_AGREEMENT_DOWNLOADED' AND entity_id = :id", { id: saved.id });
    expect(Number(downloads.n)).toBe(1); // every download is logged

    const stored = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: saved.agreement_file_id });
    fs.rmSync(stored.server_path, { force: true });
  });

  it('only those who manage incentives can download the signed agreement', async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    const email = `jest-agreement-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestAgreement1!', 4), role_id: role.id }
    );
    const id = await associateRepository.create({ ...associateFields({ agreement_signed_on: '2026-10-01' }), country: null, email: null, phone: null, tax_id: null, notes: null }, null);
    const fileId = await createTestFile(null, null);
    const { server_path: path } = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
    fs.writeFileSync(path, '%PDF-1.4 signed agreement');
    await associateRepository.setAgreementFile(id, fileId);
    try {
      const agent = await loginAs(email, 'JestAgreement1!');
      expect((await agent.get(`/associates/${id}/agreement`)).status).toBe(403);
      const general = await agent.get(`/file-store/${fileId}/download`);
      expect([general.status, general.text]).toEqual([403, 'You do not have permission to view this document.']); // the general file link does not serve it either
    } finally {
      fs.rmSync(path, { force: true });
    }
  });
});
