'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoApiBudget = require('../../src/services/zohoApiBudget');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA 2026 Zoho batch — the Books client itself, against a fake Zoho (never
 * the real one). Port of QaZohoClientFixesTest.php.
 *  - ZOH-001: an empty billing address goes as a JSON object ({}), never [].
 *  - ZOH-003: after a 401 and a new token, every later request of the
 *    operation uses the new token.
 *  - ZOH-004: a refused refresh token stops at the first refusal with a
 *    sign-in message — never one token request per record, never "rate limit".
 */
describe('QA Zoho client fixes (ZOH-001, ZOH-003, ZOH-004)', () => {
  let requests;
  let override;

  function fakeZoho(method, url, payload, headers) {
    requests.push({ method, url, payload, headers: headers || {}, body: JSON.stringify(payload) });
    const special = override(method, url, payload, headers || {});
    if (special) return special;
    const p = new URL(url).pathname;
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
    let m;
    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if ((m = p.match(/^\/books\/v3\/organizations\/(\d+)$/))) return json({ code: 0, organization: { organization_id: m[1], is_registered_for_gst: true, tax_settings: { tax_reg_no: '29AAAAA0000A1ZY' } } });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies: [{ currency_id: 'CUR-USD', currency_code: 'USD' }] });
    if (p === '/books/v3/contacts' && method === 'GET') return json({ code: 0, contacts: [] });
    if (p === '/books/v3/contacts' && method === 'POST') return json({ code: 0, contact: { contact_id: 'CT-1', contact_name: payload.contact_name } }, 201);
    return json({ code: 0, message: `not faked: ${method} ${p}` });
  }

  const tokenRequests = () => requests.filter((r) => r.url.includes('/oauth/v2/token'));

  const client = (name) => ({
    company_legal_name: name, billing_address: 'Free-text address only',
    billing_address_line1: null, billing_address_line2: null, billing_city: null, billing_postcode: null, billing_country: null,
    contact_person: 'Project Buyer', email: 'buyer@email.com', phone: '+91-0000000000', vat_eori_tax_no: null,
  });

  beforeEach(async () => {
    requests = [];
    override = () => null;
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    for (const [k, v] of Object.entries({
      zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret',
      zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1',
      zoho_sync_clients: '0', zoho_sync_products: '0',
    })) {
      await companySettingsRepository.set(k, v);
    }
    zohoBooksService.setTransport(fakeZoho);
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    for (const k of ['zoho_books_enabled', 'zoho_sync_clients', 'zoho_sync_products']) await companySettingsRepository.set(k, '0');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  test('ZOH-001: an empty billing address is sent as a JSON object', async () => {
    expect(JSON.stringify(zohoBooksService.customerPayload(client('TEST-ZOH001 Buyer'), true).billing_address)).toBe('{}');
    await zohoBooksService.pushCustomerContact(client('TEST-ZOH001 Buyer'), null, 'USD');
    await zohoBooksService.pushCustomerContact(client('TEST-ZOH001 Buyer'), 'CT-1', 'USD');
    const writes = requests.filter((r) => ['POST', 'PUT'].includes(r.method) && /\/books\/v3\/contacts(\/CT-1)?$/.test(new URL(r.url).pathname));
    expect(writes).toHaveLength(2);
    for (const w of writes) {
      expect(w.body).toContain('"billing_address":{}');
      expect(w.body).not.toContain('"billing_address":[]');
    }
    const full = { ...client('TEST-ZOH001 Full'), billing_address_line1: '1 Harbour Road', billing_city: 'Rotterdam' };
    expect(JSON.stringify(zohoBooksService.customerPayload(full, true).billing_address)).toBe('{"address":"1 Harbour Road","city":"Rotterdam"}');
  });

  test('ZOH-003: after a 401 every later request of the operation uses the new token', async () => {
    let issued = 0;
    override = (method, url, payload, headers) => {
      if (url.includes('/oauth/v2/token')) {
        issued += 1;
        return { status: 200, body: JSON.stringify({ access_token: issued === 1 ? 'tok-old' : 'tok-new' }) };
      }
      if (headers.Authorization === 'Zoho-oauthtoken tok-old') {
        return { status: 401, body: JSON.stringify({ code: 57, message: 'You are not authorized to perform this operation' }) };
      }
      return null;
    };
    const r = await zohoBooksService.pushCustomerContact(client('TEST-ZOH003 Buyer'), null, 'USD');
    expect(r.action).toBe('created');
    expect(issued).toBe(2);
    const api = requests.filter((q) => !q.url.includes('/oauth/v2/token'));
    expect(api.filter((q) => q.headers.Authorization === 'Zoho-oauthtoken tok-old')).toHaveLength(1);
  });

  test('ZOH-004: a refused refresh token stops at the first refusal with a sign-in message', async () => {
    override = (method, url) => (url.includes('/oauth/v2/token') ? { status: 200, body: '{"error":"invalid_code"}' } : null);
    let err = null;
    try {
      await zohoBooksService.pushCustomerContact(client('TEST-ZOH004 Buyer'), null, 'USD');
    } catch (e) {
      err = e;
    }
    expect(err).toBeInstanceOf(zohoApiBudget.ZohoSignInFailed);
    expect(err.message).toContain('Zoho sign-in failed');
    expect(err.message).toContain('invalid_code');
    expect(err.message).not.toContain('rate limit');
    await expect(zohoBooksService.pushCustomerContact(client('TEST-ZOH004 Buyer'), null, 'USD')).rejects.toBeInstanceOf(zohoApiBudget.ZohoSignInFailed);
    expect(tokenRequests()).toHaveLength(1);

    await companySettingsRepository.set('zoho_sync_clients', '1');
    const clientId = await createTestClient();
    await db.execute("UPDATE clients SET company_legal_name = 'TEST-ZOH004 Pending Client' WHERE id = :id", { id: clientId });
    await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD');
    const before = Number((await db.queryOne('SELECT COALESCE(MAX(id), 0) AS m FROM zoho_sync_log')).m);
    const result = await caSyncService.runFullSync('manual', null);
    expect(String(result.stopped)).toContain('Zoho sign-in failed');
    expect(tokenRequests()).toHaveLength(1);
    const row = await db.queryOne("SELECT message FROM zoho_sync_log WHERE id > :id AND sync_type = 'sync_run'", { id: before });
    expect(String(row.message)).toContain('Zoho sign-in failed');
  });
});
