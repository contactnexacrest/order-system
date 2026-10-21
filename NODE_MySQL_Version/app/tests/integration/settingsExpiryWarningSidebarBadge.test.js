'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Phase 3 item 11 of the approved remediation plan: "Sidebar alert
 * count badge for settings nearing expiry (e.g. LUT)." The underlying
 * calculation (dashboardRepository.complianceExpiryWarnings()) already
 * existed and already drove a warning banner on the Dashboard page
 * body — but nothing surfaced it from every other page, the way the
 * other standing-request sidebar badges (Email Approvals, Quotation
 * Intake Review, ...) already do. Port of PHP's
 * SettingsExpiryWarningSidebarBadgeTest.php.
 */
describe('Settings expiry warning sidebar badge (Phase 3 item 11)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function futureDate(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
  }

  async function createTestStaffUserWithRealPassword() {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: 'Admin' });
    const PASSWORD = 'Settings-Expiry-Badge-Test-1!';
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-settings-expiry-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Settings Expiry Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { email, password: PASSWORD };
  }

  async function loggedInAgent() {
    const { email, password } = await createTestStaffUserWithRealPassword();
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email, password });
    expect(loginRes.status).toBe(302);
    return agent;
  }

  it('sidebar shows no badge when nothing is near expiry', async () => {
    await companySettingsRepository.set('lut_expiry_date', futureDate(730));
    await companySettingsRepository.set('rcmc_valid_until', futureDate(730));

    const agent = await loggedInAgent();
    const res = await agent.get('/');
    const settingsLinkPos = res.text.indexOf('Company Settings');
    expect(settingsLinkPos).toBeGreaterThan(-1);
    const nearbyMarkup = res.text.slice(settingsLinkPos, settingsLinkPos + 120);

    expect(nearbyMarkup).not.toContain('nav-badge');
  });

  it('sidebar shows a badge of 1 when only LUT is near expiry', async () => {
    await companySettingsRepository.set('lut_expiry_date', futureDate(10));
    await companySettingsRepository.set('lut_alert_days_x', '30');
    await companySettingsRepository.set('rcmc_valid_until', futureDate(730));

    const agent = await loggedInAgent();
    const res = await agent.get('/');
    const settingsLinkPos = res.text.indexOf('Company Settings');
    const nearbyMarkup = res.text.slice(settingsLinkPos, settingsLinkPos + 120);

    expect(nearbyMarkup).toContain('nav-badge">1<');
  });

  it('sidebar shows a badge of 2 when both LUT and RCMC are near expiry', async () => {
    await companySettingsRepository.set('lut_expiry_date', futureDate(10));
    await companySettingsRepository.set('lut_alert_days_x', '30');
    await companySettingsRepository.set('rcmc_valid_until', futureDate(10));
    await companySettingsRepository.set('rcmc_alert_days_a', '60');

    const agent = await loggedInAgent();
    const res = await agent.get('/');
    const settingsLinkPos = res.text.indexOf('Company Settings');
    const nearbyMarkup = res.text.slice(settingsLinkPos, settingsLinkPos + 120);

    expect(nearbyMarkup).toContain('nav-badge">2<');
  });
});
