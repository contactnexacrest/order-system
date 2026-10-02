'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const emailService = require('../../src/services/emailService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of SmtpTestEmailTest.php (docs/schema.sql Section AS) — the "Test
 * SMTP Mail Connection" button (mirrors the existing Zoho test-send
 * button). The test environment's app/.env deliberately ships with
 * SMTP_HOST blank (globalSetup.js loads the real .env, only swapping the
 * database name — a real credential here would mean every test run
 * actually tries to send mail), so these tests only exercise the "not
 * configured" failure path; the actual send-success path is verified by
 * hand against the user's real SMTP credentials from the Settings screen,
 * never by an automated test.
 */
describe('SMTP test-send (docs/schema.sql Section AS)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('sendTestEmail throws when SMTP_HOST is not configured', async () => {
    await expect(emailService.sendTestEmail('someone@example.com', 'subject', 'body')).rejects.toThrow('SMTP_HOST is not configured');
  });

  it('the controller surfaces the real SMTP error for a valid address', async () => {
    const settingsPage = await agent.get('/settings');
    const csrfToken = extractCsrf(settingsPage.text);

    const postRes = await agent.post('/settings/test-smtp-email').type('form').send({
      _csrf: csrfToken,
      test_to: 'someone@example.com',
    });
    expect(postRes.status).toBe(302);

    const followUp = await agent.get(postRes.headers.location);
    expect(followUp.text).toContain('SMTP test send failed');
  });

  it('rejects an invalid destination address without attempting a send', async () => {
    const settingsPage = await agent.get('/settings');
    const csrfToken = extractCsrf(settingsPage.text);

    const postRes = await agent.post('/settings/test-smtp-email').type('form').send({
      _csrf: csrfToken,
      test_to: 'not-an-email',
    });
    expect(postRes.status).toBe(302);

    const followUp = await agent.get(postRes.headers.location);
    expect(followUp.text).toContain('Enter a valid email address');
  });
});
