'use strict';

const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const caRepository = require('../../src/repositories/caRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const caSyncService = require('../../src/services/caSyncService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const zohoGuard = require('../../src/services/zohoGuard');
const zohoMailService = require('../../src/services/zohoMailService');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of ZohoServerGuardTest.php. Owner decisions 2026-10-29 — the app
 * works with and without Zoho, and nothing from development or testing may
 * ever reach the GST portal or any other government portal. The
 * server-level lock lives in .env (ZOHO_INTEGRATION_ENABLED,
 * ZOHO_ENVIRONMENT, ZOHO_EINVOICE_ENABLED, always written out); Zoho Mail
 * is switched off in code (mail goes through Bluehost SMTP). Every request
 * goes to a fake Zoho that records it, so "nothing was sent" is checked,
 * not assumed.
 */
describe('Zoho server guard (.env) and Zoho Mail off', () => {
  let urls;
  const flagged = [];

  function server(integration, environment, einvoice) {
    process.env.ZOHO_INTEGRATION_ENABLED = integration;
    process.env.ZOHO_ENVIRONMENT = environment;
    process.env.ZOHO_EINVOICE_ENABLED = einvoice;
  }

  /** A cleared advance with its INR actual, waiting to go to Zoho. */
  async function clearedAdvance(buyerName, { test = false, sample = false } = {}) {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('UPDATE orders SET company_legal_name = :name, is_test_data = :t, is_sample_data = :s WHERE id = :id', { name: buyerName, t: test ? 1 : 0, s: sample ? 1 : 0, id: orderId });
    if (test || sample) flagged.push(orderId);
    await db.execute('INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at, advance_inr_actual) VALUES (:id, 100, NOW(), 8400)', { id: orderId });
    return orderId;
  }

  async function pendingOrderIds(orderIds) {
    const pending = (await caRepository.legsPendingZohoSync()).map((l) => Number(l.order_id));
    return orderIds.filter((id) => pending.includes(id));
  }

  beforeEach(async () => {
    urls = [];
    for (const [key, value] of Object.entries({ zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret', zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1' })) {
      await companySettingsRepository.set(key, value);
    }
    zohoBooksService.setTransport((method, url) => {
      urls.push(`${method} ${url}`);
      return { status: 200, body: JSON.stringify({ access_token: 'fake', organizations: [], code: 0 }) };
    });
  });

  afterEach(async () => {
    zohoBooksService.setTransport(null);
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    delete process.env.ZOHO_EINVOICE_ENABLED;
    await companySettingsRepository.set('zoho_books_enabled', '0');
    await companySettingsRepository.set('zoho_mail_enabled', '0');
    // Test/sample-flagged orders would keep Test Mode from being switched
    // off in every later test sharing this database.
    while (flagged.length) {
      await db.execute('UPDATE orders SET is_test_data = 0, is_sample_data = 0 WHERE id = :id', { id: flagged.pop() });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('.env.example writes out all three variables with the safe values', () => {
    const example = fs.readFileSync(path.join(__dirname, '../../.env.example'), 'utf8');
    expect(example).toMatch(/^ZOHO_INTEGRATION_ENABLED=false$/m);
    expect(example).toMatch(/^ZOHO_ENVIRONMENT=test$/m);
    expect(example).toMatch(/^ZOHO_EINVOICE_ENABLED=false$/m);
  });

  it('missing or unknown values are read as the safe choice', () => {
    delete process.env.ZOHO_INTEGRATION_ENABLED;
    delete process.env.ZOHO_ENVIRONMENT;
    delete process.env.ZOHO_EINVOICE_ENABLED;
    expect(zohoGuard.missingVariables()).toEqual(zohoGuard.VARIABLES);
    expect(zohoGuard.integrationEnabled()).toBe(false);
    expect(zohoGuard.environment()).toBe('test');
    expect(zohoGuard.governmentFeaturesAllowed()).toBe(false);
    server('yes', 'live', 'TRUE');
    expect(zohoGuard.integrationEnabled()).toBe(false);
    expect(zohoGuard.environment()).toBe('test');
  });

  it('with Zoho off nothing is sent and the sync says why', async () => {
    server('false', 'production', 'true');

    expect(await zohoBooksService.isEnabled()).toBe(false);
    await expect(zohoBooksService.testConnection()).rejects.toThrow('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED is not true in .env).');
    const result = await caSyncService.runFullSync('manual', null);
    expect(result.revenue.skipped).toBe(1);
    const log = await db.queryOne("SELECT message FROM zoho_sync_log WHERE sync_type = 'revenue_payment' ORDER BY id DESC LIMIT 1");
    expect(log.message).toBe('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED in .env) — nothing synced.');
    expect(urls).toEqual([]);
  });

  it('government requests are blocked unless production with e-invoice on', () => {
    for (const p of ['/books/v3/invoices/1/einvoice/push', '/books/v3/invoices/1/ewaybills', '/books/v3/irn/generate', '/books/v3/gstr1/file', '/books/v3/e-invoice', '/books/v3/gst-filing']) {
      expect([p, zohoGuard.isGovernmentUrl(`https://www.zohoapis.in${p}?organization_id=600`)]).toEqual([p, true]);
    }
    for (const p of ['/books/v3/contacts', '/books/v3/customerpayments', '/books/v3/invoices', '/books/v3/settings/currencies', '/oauth/v2/token']) {
      expect([p, zohoGuard.isGovernmentUrl(`https://www.zohoapis.in${p}`)]).toEqual([p, false]);
    }

    const url = 'https://www.zohoapis.in/books/v3/invoices/1/einvoice/push';
    for (const [on, environment, einvoice] of [['true', 'test', 'true'], ['true', 'production', 'false'], ['false', 'production', 'true']]) {
      server(on, environment, einvoice);
      expect(() => zohoGuard.assertRequestAllowed(url)).toThrow(on === 'true' ? 'Blocked: GST e-invoice' : 'switched off');
    }
    server('true', 'production', 'true');
    expect(() => zohoGuard.assertRequestAllowed(url)).not.toThrow();
    expect(zohoGuard.governmentFeaturesAllowed()).toBe(true);
  });

  it('in test only TEST- customers may be sent', async () => {
    server('true', 'test', 'false');

    await expect(zohoBooksService.pushRevenuePayment(await createTestClient(), 'Real Buyer GmbH', 'USD', 100, 8400, '2026-10-29', 'NX-1-advance'))
      .rejects.toThrow('Not sent: ZOHO_ENVIRONMENT is test, and only customers whose name starts with TEST- may go to Zoho (this one is "Real Buyer GmbH").');
    expect(urls).toEqual([]);

    const test = await clearedAdvance('TEST-Sync Buyer');
    const real = await clearedAdvance('Real Buyer GmbH');
    expect(await pendingOrderIds([test, real])).toEqual([test]);
  });

  it('in production test and sample orders are never sent', async () => {
    server('true', 'production', 'false');

    const real = await clearedAdvance('Real Buyer GmbH');
    const test = await clearedAdvance('Real Buyer GmbH', { test: true });
    const sample = await clearedAdvance('Real Buyer GmbH', { sample: true });
    expect(await pendingOrderIds([real, test, sample])).toEqual([real]);
  });

  it('Zoho Mail is not used and is hidden from Settings', async () => {
    await companySettingsRepository.set('zoho_mail_enabled', '1');
    expect(zohoMailService.IN_USE).toBe(false);
    expect(await zohoMailService.isEnabled()).toBe(false);

    server('false', 'test', 'false');
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const settingsPage = await agent.get('/settings');
    expect(settingsPage.text).not.toContain('Test Zoho Mail Connection');
    expect(settingsPage.text).not.toContain('name="zoho_client_secret"');
    expect(settingsPage.text).toContain('Zoho integration <strong>OFF</strong>');
    expect(settingsPage.text).toContain('GST e-invoice / e-way bill / GST filing <strong>blocked</strong>');

    const postRes = await agent.post('/settings/test-zoho-email').type('form').send({ _csrf: extractCsrf(settingsPage.text), test_to: 'someone@example.com' });
    const followUp = await agent.get(postRes.headers.location);
    expect(followUp.text).toContain('Zoho Mail is not used by this application — all mail goes through the SMTP server in .env (Bluehost).');
  });
});
