'use strict';

// documentGenerationService.generate() renders a real PDF through headless Chrome; stubbed here.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderPageOwnerPointsNov2Test.php. Owner points of 2 Nov 2026 on the order page:
 *  10 — nothing associate-related when the client has no associate;
 *  13 — Step 7c: the Supplier Link first, then the supplier's acknowledgment;
 *  14 — the COO Prep Sheet can be prepared once Stage 8 opens (before the balance);
 *  16 — "Sync this order to Zoho now" (POST /orders/:id/zoho-sync) and the Zoho Sync menu link.
 */
describe('order page — owner points of 2 Nov 2026', () => {
  const PASSWORD = 'JestOwnerNov2!1';
  let admin;

  async function signedInAs(roleName, superAdmin = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-nov2-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0, :sa)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id, sa: superAdmin ? 1 : 0 }
    )).insertId;
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return { id, agent };
  }

  async function passUpTo(orderId, passedUpTo) {
    for (let n = 1; n <= passedUpTo + 1; n += 1) {
      await db.execute('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)', { s: n <= passedUpTo ? 'gate_passed' : 'in_progress', id: orderId, n });
    }
  }

  async function postForm(agent, path) {
    const page = await agent.get('/orders');
    const csrf = page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    return agent.post(path).type('form').send({ _csrf: csrf });
  }

  beforeAll(async () => {
    admin = await signedInAs('Admin', true);
  });

  afterAll(async () => { await db.pool.end(); });

  it('10: no associate select when the client has no associate', async () => {
    const plain = await createTestOrder(await createTestClient());
    let html = (await admin.agent.get(`/orders/${plain}`)).text;
    expect(html).toContain('Step 12d &middot; Team Incentive');
    expect(html).not.toContain('Associate commission on this order');
    expect(html).toContain('<input type="hidden" name="associate_mode" value="auto">');

    const associateId = (await db.execute("INSERT INTO associates (name) VALUES ('TEST-Associate Nov2')")).insertId;
    const client = await createTestClient();
    await db.execute('UPDATE clients SET associate_id = :a WHERE id = :c', { a: associateId, c: client });
    html = (await admin.agent.get(`/orders/${await createTestOrder(client)}`)).text;
    expect(html).toContain('Associate commission on this order');
  });

  it('13: the Supplier Link comes before the acknowledgment', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await passUpTo(orderId, 4);
    const supplierId = (await db.execute("INSERT INTO suppliers (supplier_legal_name) VALUES ('TEST-Supplier Nov2')")).insertId;
    await db.execute("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference) VALUES (:o, :s, 'TEST-Supplier Nov2', 'TEST-SPO-N2')", { o: orderId, s: supplierId });
    const html = (await admin.agent.get(`/orders/${orderId}`)).text;
    const link = html.indexOf('1. Send the Supplier Link first');
    const ack = html.indexOf('2. When the supplier has acknowledged');
    const upload = html.indexOf('/supplier-po/documents"');
    expect(link).toBeGreaterThan(-1);
    expect(ack).toBeGreaterThan(link);
    expect(upload).toBeGreaterThan(ack);
  });

  it('14: the COO Prep Sheet can be prepared once Stage 8 opens, before the balance', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await passUpTo(orderId, 7);
    const html = (await admin.agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain('document_type" value="COOPREP"');
    expect(html).toContain('Prepared early — the Certificate of Origin can be applied for now');
    expect(html).toMatch(/<details class="step-card" id="step-11a-coo-prep"(?![^>]*data-locked)[^>]*>/); // not held by the step-by-step lock
    const result = await documentGenerationService.generate(orderId, 'COOPREP', admin.id);
    expect(result.document_id || result.documentId).toBeTruthy();

    const early = await createTestOrder(await createTestClient());
    expect((await admin.agent.get(`/orders/${early}`)).text).toContain('Opens once the BL is issued (Step 10)');
    await expect(documentGenerationService.generate(early, 'COOPREP', admin.id)).rejects.toThrow();
  });

  it('16: "Sync this order to Zoho now" and the Zoho Sync menu link', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const html = (await admin.agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain(`action="/orders/${orderId}/zoho-sync"`);
    expect(html).toContain('Sync this order to Zoho now');
    expect(html).toContain('href="/ca/zoho-sync"');

    const r = await postForm(admin.agent, `/orders/${orderId}/zoho-sync`);
    expect(r.status).toBe(302);
    expect(r.headers.location).toBe(`/orders/${orderId}#order-financials`);
    const after = (await admin.agent.get(`/orders/${orderId}`)).text;
    expect(after).toContain('Zoho is not connected — Settings → Zoho Books. Nothing was synced.');

    expect((await postForm(admin.agent, '/orders/99999999/zoho-sync')).status).toBe(404);

    const sales = await signedInAs('Export Executive');
    expect((await postForm(sales.agent, `/orders/${orderId}/zoho-sync`)).status).toBe(403);
    expect((await sales.agent.get('/orders')).text).not.toContain('href="/ca/zoho-sync"');
  });

  // Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N" — only while Zoho is on, only for Zoho-status holders.
  it('D-233: Waiting for Zoho badge on the order page', async () => {
    const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
    const orderId = await createTestOrder(await createTestClient());
    const accounts = await signedInAs('Accounts Executive');
    await companySettingsRepository.set('zoho_books_enabled', '0');
    expect((await accounts.agent.get(`/orders/${orderId}`)).text).not.toContain('Waiting for Zoho'); // nothing while Zoho is off

    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    await companySettingsRepository.set('zoho_books_enabled', '1');
    try {
      let html = (await accounts.agent.get(`/orders/${orderId}`)).text;
      expect(html).toMatch(new RegExp(`data-zoho-waiting="0"[^>]*><a href="/orders/${orderId}/zoho-check">Waiting for Zoho: 0 — everything on this order is in Zoho Books</a>`));
      expect(html.split('class="badge badge-success zoho-waiting"').length - 1).toBe(1); // At a Glance (Accounts has no Step 12)
      html = (await admin.agent.get(`/orders/${orderId}`)).text;
      expect(html.split('class="badge badge-success zoho-waiting"').length - 1).toBe(2); // Step 12 and At a Glance

      const ca = await signedInAs('CA / Chartered Accountant'); // ca_module_view: sees the status, no link
      html = (await ca.agent.get(`/orders/${orderId}`)).text;
      expect(html).toContain('Waiting for Zoho: 0');
      expect(html).not.toContain('/zoho-check">Waiting');

      const sales = await signedInAs('Export Executive');
      expect((await sales.agent.get(`/orders/${orderId}`)).text).not.toContain('Waiting for Zoho');
    } finally {
      await companySettingsRepository.set('zoho_books_enabled', '0');
      delete process.env.ZOHO_INTEGRATION_ENABLED;
      delete process.env.ZOHO_ENVIRONMENT;
    }
  });
});
