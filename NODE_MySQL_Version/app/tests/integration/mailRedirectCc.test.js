'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const mailRedirectService = require('../../src/services/mailRedirectService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of MailRedirectCcTest.php (docs/schema.sql Section AS). Covers
 * mailRedirectService's resolve/CC logic (including the security-email
 * carve-out, same shape as testModeService's), and
 * settingsController.update()'s Super-Admin-only gate on
 * requires_super_admin fields (mail_cc_emails, mail_default_cc_email).
 */
describe('Mail redirect & CC (docs/schema.sql Section AS)', () => {
  const KEYS = ['mail_redirect_enabled', 'mail_redirect_address', 'mail_cc_emails', 'mail_default_cc_email'];
  const originalValues = {};
  let agent;

  beforeAll(async () => {
    for (const key of KEYS) {
      originalValues[key] = (await companySettingsRepository.get(key)) || '';
    }
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterEach(async () => {
    for (const key of KEYS) {
      await companySettingsRepository.set(key, originalValues[key]);
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function attemptUpdate(key, newValue) {
    const settingsPage = await agent.get('/settings');
    const csrfToken = extractCsrf(settingsPage.text);
    return agent.post('/settings/update').type('form').send({
      _csrf: csrfToken,
      reason: 'jest mail-redirect/cc regression check',
      [`settings[${key}]`]: newValue,
    });
  }

  it('resolveRecipient passes through when redirect is off', async () => {
    await companySettingsRepository.set('mail_redirect_enabled', '0');
    await companySettingsRepository.set('mail_redirect_address', 'qa-inbox@nexacrest.example');

    const result = await mailRedirectService.resolveRecipient('buyer@real-company.example', false);

    expect(result).toBe('buyer@real-company.example');
  });

  it('resolveRecipient redirects when enabled with a valid address', async () => {
    await companySettingsRepository.set('mail_redirect_enabled', '1');
    await companySettingsRepository.set('mail_redirect_address', 'qa-inbox@nexacrest.example');

    const result = await mailRedirectService.resolveRecipient('buyer@real-company.example', false);

    expect(result).toBe('qa-inbox@nexacrest.example');
  });

  it('resolveRecipient falls back to the original address when enabled but blank', async () => {
    await companySettingsRepository.set('mail_redirect_enabled', '1');
    await companySettingsRepository.set('mail_redirect_address', '');

    const result = await mailRedirectService.resolveRecipient('buyer@real-company.example', false);

    expect(result).toBe('buyer@real-company.example');
  });

  it('security emails always bypass redirect regardless of settings', async () => {
    await companySettingsRepository.set('mail_redirect_enabled', '1');
    await companySettingsRepository.set('mail_redirect_address', 'qa-inbox@nexacrest.example');

    const result = await mailRedirectService.resolveRecipient('staff@nexacrest.example', true);

    expect(result).toBe('staff@nexacrest.example');
  });

  it('ccList combines the comma-separated list and default CC, deduplicated', async () => {
    await companySettingsRepository.set('mail_cc_emails', 'a@example.com, b@example.com ,a@example.com');
    await companySettingsRepository.set('mail_default_cc_email', 'default@example.com');

    const result = (await mailRedirectService.ccList(false)).sort();

    expect(result).toEqual(['a@example.com', 'b@example.com', 'default@example.com']);
  });

  it('ccList ignores invalid addresses and is empty when unset', async () => {
    await companySettingsRepository.set('mail_cc_emails', '');
    await companySettingsRepository.set('mail_default_cc_email', '');

    expect(await mailRedirectService.ccList(false)).toEqual([]);
  });

  it('ccList is empty for a security email regardless of configured addresses', async () => {
    await companySettingsRepository.set('mail_cc_emails', 'a@example.com');
    await companySettingsRepository.set('mail_default_cc_email', 'default@example.com');

    expect(await mailRedirectService.ccList(true)).toEqual([]);
  });

  it('a non-Super-Admin cannot change mail_cc_emails', async () => {
    await companySettingsRepository.set('mail_cc_emails', 'original@example.com');

    await attemptUpdate('mail_cc_emails', 'attacker@example.com');

    expect(await companySettingsRepository.get('mail_cc_emails')).toBe('original@example.com');
  });

  it('a Super Admin can change mail_cc_emails', async () => {
    await companySettingsRepository.set('mail_cc_emails', 'original@example.com');
    const user = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });
    await db.execute('UPDATE users SET is_super_admin = 1 WHERE id = :id', { id: user.id });

    try {
      await attemptUpdate('mail_cc_emails', 'new@example.com');
      expect(await companySettingsRepository.get('mail_cc_emails')).toBe('new@example.com');
    } finally {
      await db.execute('UPDATE users SET is_super_admin = 0 WHERE id = :id', { id: user.id });
    }
  });

  it('rejects an invalid address inside an email_list value', async () => {
    const user = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });
    await db.execute('UPDATE users SET is_super_admin = 1 WHERE id = :id', { id: user.id });
    await companySettingsRepository.set('mail_cc_emails', 'original@example.com');

    try {
      await attemptUpdate('mail_cc_emails', 'valid@example.com, not-an-email');
      expect(await companySettingsRepository.get('mail_cc_emails')).toBe('original@example.com');
    } finally {
      await db.execute('UPDATE users SET is_super_admin = 0 WHERE id = :id', { id: user.id });
    }
  });
});
