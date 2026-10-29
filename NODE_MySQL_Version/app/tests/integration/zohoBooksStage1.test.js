'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

const SETTINGS = {
  zoho_books_enabled: '1',
  zoho_books_client_id: 'fake-client',
  zoho_books_client_secret: 'fake-secret',
  zoho_books_refresh_token: 'fake-refresh',
  zoho_books_organization_id: '600',
  zoho_books_deposit_account_id: 'SBI-1',
};

/**
 * Port of ZohoBooksStage1Test.php. Zoho integration Stage 1 (owner
 * decisions 2026-10-29): zoho.in by default, buyers created in Books as
 * "overseas" customers in the order's currency, payments in the foreign
 * amount at the actual rate, the "Test Books connection" button, and the
 * cleanup of TEST- records. Every request goes to a fake Zoho
 * (zohoBooksService.setTransport) — the tests never reach the real one.
 */
describe('Zoho Books Stage 1', () => {
  let requests;
  let contacts;
  let currencies;
  let organizationId;
  let gstRegistered;

  function fakeZoho(method, url, payload) {
    requests.push({ method, url, payload });
    const u = new URL(url);
    const p = u.pathname;
    const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });

    if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
    if (p === '/books/v3/organizations') return json({ code: 0, organizations: [{ organization_id: organizationId, name: 'NexaCrest TEST Org' }] });
    const orgMatch = p.match(/^\/books\/v3\/organizations\/(\d+)$/);
    if (orgMatch) return json({ code: 0, organization: { organization_id: orgMatch[1], is_registered_for_gst: gstRegistered } });
    if (p === '/books/v3/invoices' && method === 'GET') return json({ code: 0, invoices: [] });
    if (p === '/books/v3/bankaccounts') return json({ code: 0, bankaccounts: [{ account_id: 'SBI-1', account_name: 'SBI Bank - Current Account' }] });
    if (p === '/books/v3/settings/currencies') return json({ code: 0, currencies });
    if (p === '/books/v3/contacts' && method === 'GET') {
      const name = u.searchParams.get('contact_name');
      const prefix = u.searchParams.get('contact_name_startswith') || '';
      return json({ code: 0, contacts: Object.values(contacts).filter((c) => (name !== null ? c.contact_name === name : c.contact_name.startsWith(prefix))) });
    }
    if (p === '/books/v3/contacts' && method === 'POST') {
      const id = `CT-${Object.keys(contacts).length + 1}`;
      const currency = currencies.find((c) => c.currency_id === payload.currency_id).currency_code;
      contacts[id] = { contact_id: id, contact_name: payload.contact_name, currency_code: currency };
      return json({ code: 0, contact: contacts[id] }, 201);
    }
    const contactMatch = p.match(/^\/books\/v3\/contacts\/(.+)$/);
    if (contactMatch) {
      if (method === 'DELETE') return contacts[contactMatch[1]] ? json({ code: 0 }) : json({ code: 1002, message: 'not found' }, 404);
      return json({ code: 0, contact: contacts[contactMatch[1]] });
    }
    if (p === '/books/v3/customerpayments' && method === 'POST') return json({ code: 0, payment: { payment_id: 'PAY-1' } }, 201);
    if (p === '/books/v3/customerpayments' && method === 'GET') {
      return json({ code: 0, customerpayments: [{ payment_id: 'PAY-9', reference_number: 'TEST-REF', currency_code: 'USD', amount: 100, date: '2026-10-29' }] });
    }
    if (p.startsWith('/books/v3/customerpayments/')) return json({ code: 0 });
    return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
  }

  const requestsTo = (method, prefix) => requests.filter((r) => r.method === method && new URL(r.url).pathname.startsWith(prefix));

  beforeEach(async () => {
    requests = [];
    contacts = {};
    currencies = [{ currency_id: 'C-USD', currency_code: 'USD' }, { currency_id: 'C-EUR', currency_code: 'EUR' }, { currency_id: 'C-INR', currency_code: 'INR' }];
    organizationId = '600';
    gstRegistered = true;
    // The server-level lock (.env, zohoGuard): on, in test — so only TEST-
    // customers may be sent.
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    process.env.ZOHO_EINVOICE_ENABLED = 'false';
    for (const [key, value] of Object.entries(SETTINGS)) {
      await companySettingsRepository.set(key, value);
    }
    zohoBooksService.setTransport(fakeZoho);
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    delete process.env.ZOHO_EINVOICE_ENABLED;
    // The credential settings are protected (cannot be blanked once
    // filled), so the fake values stay; switching Books off is enough for
    // every other test.
    await companySettingsRepository.set('zoho_books_enabled', '0');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('the seeded domains are Zoho India', async () => {
    await zohoBooksService.testConnection();

    expect(new URL(requestsTo('POST', '/oauth/v2/token')[0].url).host).toBe('accounts.zoho.in');
    expect(new URL(requestsTo('GET', '/books/v3/organizations')[0].url).host).toBe('www.zohoapis.in');
  });

  it('the seed defaults are Zoho India, with no e-invoice setting', () => {
    const seed = fs.readFileSync(path.join(__dirname, '../../../docs/seed.sql'), 'utf8');
    expect(seed).toContain("('zoho_books_accounts_domain',    'accounts.zoho.in'");
    expect(seed).toContain("('zoho_books_api_domain',         'www.zohoapis.in'");
    expect(seed).toContain("('zoho_accounts_domain', 'accounts.zoho.in'");
    expect(seed).toContain("('zoho_api_domain',      'mail.zoho.in'");
    // The e-invoice switch lives in .env (ZOHO_EINVOICE_ENABLED), never in Admin -> Settings.
    expect(seed).not.toContain('einvoice');
  });

  it('the connection test reports the organisation, the deposit account and missing currencies', async () => {
    const result = await zohoBooksService.testConnection();

    expect(result).toEqual({ organization: 'NexaCrest TEST Org', deposit_account: 'SBI Bank - Current Account', missing_currencies: ['GBP'] });
    expect(requestsTo('POST', '/books/')).toEqual([]);
  });

  it('the connection test names an unknown organisation', async () => {
    organizationId = '999';

    await expect(zohoBooksService.testConnection()).rejects.toThrow('Organization ID 600 was not found. This Zoho login can see: NexaCrest TEST Org (999).');
  });

  it('the Settings button shows the result and is audit-logged', async () => {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    const settingsPage = await agent.get('/settings');
    expect(settingsPage.text).toContain('Test Books Connection');
    const postRes = await agent.post('/settings/test-zoho-books').type('form').send({ _csrf: extractCsrf(settingsPage.text) });
    expect(postRes.status).toBe(302);

    const followUp = await agent.get(postRes.headers.location);
    expect(followUp.text).toContain('Connected to Zoho Books: NexaCrest TEST Org. Payments are deposited into SBI Bank - Current Account. Turn on these currencies in Zoho Books -&gt; Settings -&gt; Currencies: GBP.');
    const log = await db.queryOne("SELECT a.*, u.email FROM audit_log a JOIN users u ON u.id = a.user_id WHERE a.action_type = 'SETTINGS_TEST_ZOHO_BOOKS_CONNECTED' ORDER BY a.id DESC LIMIT 1");
    expect(log.email).toBe(TEST_ADMIN_EMAIL);
  });

  it('the Settings button shows the real error when not configured', async () => {
    await db.execute("UPDATE company_settings SET is_protected = 0 WHERE setting_key = 'zoho_books_refresh_token'");
    await db.execute("UPDATE company_settings SET setting_value = '' WHERE setting_key = 'zoho_books_refresh_token'");
    try {
      const agent = request.agent(app);
      const loginPage = await agent.get('/login');
      await agent.post('/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
      const settingsPage = await agent.get('/settings');
      const postRes = await agent.post('/settings/test-zoho-books').type('form').send({ _csrf: extractCsrf(settingsPage.text) });

      const followUp = await agent.get(postRes.headers.location);
      expect(followUp.text).toContain('Zoho Books connection failed: Zoho Books is enabled but not fully configured');
    } finally {
      await db.execute("UPDATE company_settings SET setting_value = 'fake-refresh', is_protected = 1 WHERE setting_key = 'zoho_books_refresh_token'");
    }
  });

  it('a foreign-currency payment creates an overseas customer and books the foreign amount at the actual rate', async () => {
    const clientId = await createTestClient();

    const result = await zohoBooksService.pushRevenuePayment(clientId, 'TEST-Overseas Buyer', 'USD', 1800, 151020, '2026-10-29', 'NX-1-advance');

    expect(result).toEqual({ payment_id: 'PAY-1', applied: 0 });
    expect(requestsTo('POST', '/books/v3/contacts')[0].payload).toEqual({ contact_name: 'TEST-Overseas Buyer', company_name: 'TEST-Overseas Buyer', contact_type: 'customer', currency_id: 'C-USD', gst_treatment: 'overseas' });
    const payment = requestsTo('POST', '/books/v3/customerpayments')[0].payload;
    expect(payment.amount).toBe(1800);
    expect(payment.exchange_rate).toBe(83.9);
    expect(payment.account_id).toBe('SBI-1');
    expect((await clientRepository.find(clientId)).zoho_contact_id).toBe('CT-1');
  });

  it('a Books organisation not registered for GST gets the customer without GST treatment', async () => {
    // Seen in the test organisation (change log Z-025): an organisation
    // that is not GST-registered rejects gst_treatment.
    gstRegistered = false;

    await zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-Plain Buyer', 'USD', 100, 8400, '2026-10-29', 'NX-7-advance');

    expect(requestsTo('POST', '/books/v3/contacts')[0].payload).toEqual({ contact_name: 'TEST-Plain Buyer', company_name: 'TEST-Plain Buyer', contact_type: 'customer', currency_id: 'C-USD' });
  });

  it('one access token serves a run', async () => {
    await zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-Token Buyer A', 'USD', 100, 8400, '2026-10-29', 'NX-8-advance');
    await zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-Token Buyer B', 'USD', 100, 8400, '2026-10-29', 'NX-9-advance');

    expect(requestsTo('POST', '/oauth/v2/token')).toHaveLength(1); // Zoho limits how many access tokens are issued
  });

  it('an INR payment goes in as rupees without a rate', async () => {
    await zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-Rupee Buyer', 'INR', null, 50000, '2026-10-29', 'NX-2-advance');

    const payment = requestsTo('POST', '/books/v3/customerpayments')[0].payload;
    expect(payment.amount).toBe(50000);
    expect(payment).not.toHaveProperty('exchange_rate');
  });

  it('a currency Books has not turned on is refused with where to add it', async () => {
    await expect(zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-Pound Buyer', 'GBP', 100, 11000, '2026-10-29', 'NX-3-advance'))
      .rejects.toThrow('Currency GBP is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.');
  });

  it('a customer already in Books in another currency is refused', async () => {
    const clientId = await createTestClient();
    await zohoBooksService.pushRevenuePayment(clientId, 'TEST-Dollar Buyer', 'USD', 100, 8400, '2026-10-29', 'NX-4-advance');

    await expect(zohoBooksService.pushRevenuePayment(clientId, 'TEST-Dollar Buyer', 'EUR', 100, 9100, '2026-10-29', 'NX-5-advance'))
      .rejects.toThrow('TEST-Dollar Buyer is set up in Zoho Books in USD, but this payment is in EUR. A Zoho Books customer has one currency — ask the CA how to book it.');
    expect(requestsTo('POST', '/books/v3/customerpayments')).toHaveLength(1);
  });

  it('a foreign payment without its foreign amount is refused', async () => {
    await expect(zohoBooksService.pushRevenuePayment(await createTestClient(), 'TEST-No Amount Buyer', 'USD', null, 8400, '2026-10-29', 'NX-6-balance'))
      .rejects.toThrow('Payment NX-6-balance has no USD amount recorded, so its rate cannot be worked out.');
  });

  it('cleanup lists TEST- customers with their payments and treats a missing record as gone', async () => {
    contacts['CT-7'] = { contact_id: 'CT-7', contact_name: 'TEST-Cleanup Buyer', currency_code: 'USD' };
    contacts['CT-8'] = { contact_id: 'CT-8', contact_name: 'Real Buyer', currency_code: 'USD' };

    expect(await zohoBooksService.listTestContacts()).toEqual([{ contact_id: 'CT-7', name: 'TEST-Cleanup Buyer', payments: [{ payment_id: 'PAY-9', label: 'TEST-REF USD 100 on 2026-10-29' }], invoices: [] }]);
    await zohoBooksService.deleteRecord('customerpayment', 'PAY-9');
    await zohoBooksService.deleteRecord('contact', 'CT-7');
    await zohoBooksService.deleteRecord('contact', 'CT-404');
    expect(requestsTo('DELETE', '/books/').map((r) => new URL(r.url).pathname)).toEqual(['/books/v3/customerpayments/PAY-9', '/books/v3/contacts/CT-7', '/books/v3/contacts/CT-404']);
  });
});
