'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const sessionTimeouts = require('../../src/services/sessionTimeouts');
const settingValueValidator = require('../../src/helpers/settingValueValidator');
const rememberBrowserService = require('../../src/services/rememberBrowserService');
const userController = require('../../src/controllers/userController');
const clientAuth = require('../../src/middleware/clientAuth');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestClientLogin } = require('../support/fixtures');

/**
 * Port of SessionTimeoutsD283Test.php — owner decision D-283 (11 Nov 2026,
 * point 21): the Admin idle timeouts really apply — staff 4 h, buyers 2 h,
 * associates 2 h, staff signed out after 12 h however active; the session store
 * and cookie follow them (rolling); a 2-minute warning with "Stay logged in";
 * "This is my own office computer" for 30 days and "Forget all remembered computers".
 */
describe('D-283 staying logged in', () => {
  const KEYS = ['session_timeout_staff_minutes', 'session_timeout_client_minutes', 'session_timeout_associate_minutes', 'session_absolute_staff_hours'];
  const before = {};

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-d283-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role_id: role.id }
    );
    return r.insertId;
  }

  const value = async (sql) => {
    const row = await db.queryOne(sql);
    return row ? Object.values(row)[0] : null;
  };

  beforeAll(async () => {
    for (const k of KEYS) before[k] = await companySettingsRepository.get(k);
  });

  afterAll(async () => {
    for (const k of KEYS) await companySettingsRepository.set(k, before[k]);
    await db.pool.end();
  });

  it('the seed has separate timeouts and 30 remembered days; the one shared timeout is gone', async () => {
    expect(String(await value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_timeout_staff_minutes'"))).toBe('240');
    expect(String(await value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_timeout_client_minutes'"))).toBe('120');
    expect(String(await value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_timeout_associate_minutes'"))).toBe('120');
    expect(String(await value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_absolute_staff_hours'"))).toBe('12');
    expect(String(await value("SELECT setting_value FROM company_settings WHERE setting_key = 'login_remember_browser_days'"))).toBe('30');
    expect(await value("SELECT 1 FROM company_settings WHERE setting_key = 'session_timeout_minutes'")).toBeNull();
  });

  it('each area has its own idle limit', async () => {
    await companySettingsRepository.set('session_timeout_staff_minutes', '240');
    await companySettingsRepository.set('session_timeout_client_minutes', '120');
    const now = Date.now();
    expect(await sessionTimeouts.idleExpired('staff', now - 3 * 3600000, now)).toBe(false);
    expect(await sessionTimeouts.idleExpired('staff', now - 241 * 60000, now)).toBe(true);
    expect(await sessionTimeouts.idleExpired('client', now - 3 * 3600000, now)).toBe(true);
    expect(await sessionTimeouts.idleExpired('client', null, now)).toBe(false);
    expect(sessionTimeouts.describeMinutes(240)).toBe('4 hours');
    expect(sessionTimeouts.describeMinutes(90)).toBe('90 minutes');
  });

  it('the session store and cookie keep the session as long as the longest timeout, rolling', async () => {
    await companySettingsRepository.set('session_timeout_staff_minutes', '240');
    await companySettingsRepository.set('session_timeout_client_minutes', '120');
    await companySettingsRepository.set('session_timeout_associate_minutes', '300');
    expect(await sessionTimeouts.maxIdleMs()).toBe(300 * 60000);
    const src = require('fs').readFileSync(require('path').join(__dirname, '../../src/server.js'), 'utf8');
    expect(src).toContain('rolling: true');
    expect(src).toContain("req.session.cookie.maxAge = await require('./services/sessionTimeouts').maxIdleMs();");
  });

  it('staff are signed out after 12 hours however active', async () => {
    await companySettingsRepository.set('session_absolute_staff_hours', '12');
    const now = Date.now();
    expect(await sessionTimeouts.absoluteExpired(now - 13 * 3600000, now)).toBe(true);
    expect(await sessionTimeouts.absoluteExpired(now - 11 * 3600000, now)).toBe(false);
    expect(await sessionTimeouts.staffAbsoluteLeft(now - 3600000, now)).toBe(11 * 3600);
  });

  it('a signed-in page is marked for the warning popup', async () => {
    const req = {};
    await sessionTimeouts.markPage(req, 'associate');
    expect(req.idleMeta).toEqual({ area: 'associate', idle: (await sessionTimeouts.idleMinutes('associate')) * 60, warn: 120, absoluteLeft: null,
      keepalive: '/associate/keep-alive', login: '/associate/login' });
  });

  it('a buyer idle for longer than the buyer timeout is signed out', async () => {
    await companySettingsRepository.set('session_timeout_client_minutes', '120');
    const clientId = await createTestClient();
    await createTestClientLogin(clientId, 'irrelevant-password-1');
    const session = {
      _client_portal_client_id: clientId,
      _client_last_activity_ts: Date.now() - 3 * 3600000,
      regenerate(cb) {
        for (const key of Object.keys(session)) if (key !== 'regenerate') delete session[key];
        cb(null);
      },
    };
    const req = { session, path: '/client', method: 'GET', ip: 'test' };
    let redirectedTo = null;
    await clientAuth.required()(req, { redirect(u) { redirectedTo = u; } }, () => { redirectedTo = 'next'; });
    expect(redirectedTo).toBe('/client/login');
    expect(flash.pull(req)[0].message).toBe('You were signed out after 2 hours without activity. Please log in again.');
  });

  it('the timeout settings have allowed ranges', () => {
    expect(settingValueValidator.check('number', '240', 'session_timeout_staff_minutes')).toBeNull();
    expect(settingValueValidator.check('number', '2000', 'session_timeout_staff_minutes')).toBe('must be a whole number from 5 to 1440 minutes (got "2000")');
    expect(settingValueValidator.check('number', '0', 'session_absolute_staff_hours')).toBe('must be a whole number from 1 to 24 hours (got "0")');
    expect(settingValueValidator.check('number', '91', 'login_remember_browser_days')).toBe('must be a whole number from 0 to 90 days (got "91")');
    expect(settingValueValidator.check('number', '91', 'some_other_number')).toBeNull();
  });

  it('Admin can forget all remembered computers of a person', async () => {
    const userId = await createTestUser('Export Executive');
    const was = await companySettingsRepository.get('login_remember_browser_days');
    await companySettingsRepository.set('login_remember_browser_days', '30');
    try {
      const fakeRes = { cookie() {} };
      await rememberBrowserService.remember(fakeRes, { id: userId, session_version: 0 });
      await rememberBrowserService.remember(fakeRes, { id: userId, session_version: 0 });
      expect(await rememberBrowserService.countFor(userId)).toBe(2);
      const admin = await createTestUser('Admin');
      const req = { session: {}, params: { id: String(userId) }, user: { id: admin } };
      await userController.forgetComputers(req, { redirect() {}, status() { return { send() {} }; } });
      expect(await rememberBrowserService.countFor(userId)).toBe(0);
      expect(flash.pull(req)[0].message).toBe('2 remembered computers forgotten — the e-mailed login code is asked again at the next login.');
    } finally {
      await companySettingsRepository.set('login_remember_browser_days', was);
    }
  });
});
