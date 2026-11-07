'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const authService = require('../../src/services/authService');
const clientPortalService = require('../../src/services/clientPortalService');
const twoFactorService = require('../../src/services/twoFactorService');
const backupCodeService = require('../../src/services/backupCodeService');
const rememberBrowserService = require('../../src/services/rememberBrowserService');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestClientLogin } = require('../support/fixtures');

// Port of LoginCodeD265Test.php — owner decision D-265 (7 Nov 2026): a 6-digit code
// e-mailed at every login for every staff member and every buyer (valid 10 minutes,
// 5 tries, a new code at most once a minute); emergency backup codes for the accounts
// that run the company; "Remember this browser" for staff only, off by default.

global.__D265_MANUAL_LOGIN_CODE__ = true; // this file tests the code step itself (tests/support/loginCodeAuto.js)

const PASSWORD = 'Known-Password-123!';

/** A request with a plain in-memory session, like express-session's (regenerate swaps it). */
function fakeReq(cookie = '') {
  const req = { ip: '127.0.0.1', headers: { cookie } };
  const fresh = () => {
    const s = {};
    Object.defineProperty(s, 'regenerate', { enumerable: false, value: (cb) => { req.session = fresh(); cb(); } });
    return s;
  };
  req.session = fresh();
  return req;
}

function fakeRes() {
  const res = { cookies: {} };
  res.cookie = (name, value) => { res.cookies[name] = value; };
  return res;
}

async function staff(roleName) {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
  const email = `jest-d265-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
  const r = await db.execute(
    `INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, :hash, :role, 1, 0, 0)`,
    { email, hash: await bcrypt.hash(PASSWORD, 10), role: role.id }
  );
  return userRepository.findById(r.insertId);
}

async function buyer() {
  const email = `d265-buyer-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
  const clientId = await createTestClient(email);
  await createTestClientLogin(clientId, PASSWORD);
  return { id: Number(clientId), email };
}

async function audits(action, userId) {
  return Number((await db.queryOne('SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :a AND user_id = :u', { a: action, u: userId })).n);
}

describe('D-265 e-mailed login code for staff and buyers', () => {
  beforeEach(async () => { await companySettingsRepository.set('login_remember_browser_days', '0'); });
  afterAll(async () => {
    await companySettingsRepository.set('login_remember_browser_days', '0');
    await db.pool.end();
  });

  it('every staff role needs the e-mailed code even with two-factor switched off', async () => {
    for (const role of ['Admin', 'Managing Director', 'Executive Director', 'Export Executive', 'Accounts Executive', 'Logistics Executive', 'Viewer / Auditor', 'CA / Chartered Accountant']) {
      const u = await staff(role);
      const req = fakeReq();
      const r = await authService.attemptLogin(req, u.email, PASSWORD);
      expect([role, r.status, r.method]).toEqual([role, 'requires_2fa', 'email']);
      expect(r.dev_code).toMatch(/^\d{6}$/);
      expect(authService.currentUserId(req)).toBeNull();
      expect((await authService.completeTwoFactor(req, r.dev_code)).status).toBe('ok');
      expect(Number(authService.currentUserId(req))).toBe(Number(u.id));
    }
  });

  it('a wrong code, five tries and expiry all refuse', async () => {
    const u = await staff('Export Executive');
    let req = fakeReq();
    let r = await authService.attemptLogin(req, u.email, PASSWORD);
    const wrong = r.dev_code === '000000' ? '111111' : '000000';
    for (let i = 1; i <= 4; i++) {
      expect((await authService.completeTwoFactor(req, wrong)).status).toBe('invalid_code');
      expect(twoFactorService.pendingUserId(req)).toBe(Number(u.id));
    }
    expect((await authService.completeTwoFactor(req, wrong)).status).toBe('invalid_code');
    expect(twoFactorService.pendingUserId(req)).toBeNull();
    expect((await authService.completeTwoFactor(req, r.dev_code)).status).toBe('no_pending_2fa');

    req = fakeReq();
    r = await authService.attemptLogin(req, u.email, PASSWORD);
    req.session._2fa_pending.expires_at = Date.now() - 1000;
    expect((await authService.completeTwoFactor(req, r.dev_code)).status).toBe('invalid_code');
    expect(authService.currentUserId(req)).toBeNull();
  });

  it('the code is valid for ten minutes', async () => {
    const u = await staff('Export Executive');
    const req = fakeReq();
    await authService.attemptLogin(req, u.email, PASSWORD);
    const p = req.session._2fa_pending;
    expect((p.expires_at - p.issued_at) / 1000).toBe(600);
  });

  it('a new code at most once a minute', async () => {
    const u = await staff('Export Executive');
    const req = fakeReq();
    const first = (await authService.attemptLogin(req, u.email, PASSWORD)).dev_code;
    const soon = await twoFactorService.resend(req);
    expect(soon.status).toBe('too_soon');
    expect(soon.wait).toBeGreaterThan(0);

    req.session._2fa_pending.issued_at = Date.now() - 61000;
    const again = await twoFactorService.resend(req);
    expect(again.status).toBe('sent');
    expect(again.dev_code).toMatch(/^\d{6}$/);
    expect(req.session._2fa_pending.attempts).toBe(0);
    if (again.dev_code !== first) {
      expect((await authService.completeTwoFactor(req, first)).status).toBe('invalid_code');
    }
    expect((await authService.completeTwoFactor(req, again.dev_code)).status).toBe('ok');
  });

  it('buyers need the e-mailed code too', async () => {
    const b = await buyer();
    let req = fakeReq();
    const r = await clientPortalService.attemptLogin(req, b.email, PASSWORD);
    expect(r.status).toBe('requires_code');
    expect(clientPortalService.currentClientId(req)).toBeNull();
    expect((await clientPortalService.completeLogin(req, r.dev_code === '000000' ? '111111' : '000000')).status).toBe('invalid_code');
    expect((await clientPortalService.completeLogin(req, r.dev_code)).status).toBe('ok');
    expect(Number(clientPortalService.currentClientId(req))).toBe(b.id);

    req = fakeReq();
    await clientPortalService.attemptLogin(req, b.email, PASSWORD);
    expect((await twoFactorService.resend(req, 'client')).status).toBe('too_soon');
    expect(twoFactorService.pendingUserId(req, 'staff')).toBeNull();
  });

  it('backup codes: only for company runners, hashed, one-time and replaced', async () => {
    const md = await staff('Managing Director');
    const exec = await staff('Export Executive');
    expect(await backupCodeService.eligible(md.id)).toBe(true);
    expect(await backupCodeService.eligible(exec.id)).toBe(false);

    const codes = await backupCodeService.generate(md.id);
    expect(codes).toHaveLength(10);
    expect(new Set(codes).size).toBe(10);
    for (const c of codes) expect(c).toMatch(/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/);
    const stored = (await db.query('SELECT code_hash FROM two_fa_backup_codes WHERE user_id = :u', { u: md.id })).map((r) => r.code_hash);
    expect(stored).toHaveLength(10);
    for (const h of stored) {
      expect(codes).not.toContain(h);
      expect(codes.map(backupCodeService.normalise)).not.toContain(backupCodeService.normalise(h));
    }
    expect(await audits('BACKUP_CODES_GENERATED', md.id)).toBe(1);

    let req = fakeReq();
    await authService.attemptLogin(req, md.email, PASSWORD);
    expect((await authService.completeWithBackupCode(req, codes[0].replace('-', '').toLowerCase())).status).toBe('ok');
    expect(Number(authService.currentUserId(req))).toBe(Number(md.id));
    expect(await backupCodeService.remaining(md.id)).toBe(9);
    expect(await audits('LOGIN_BACKUP_CODE_USED', md.id)).toBe(1);
    const notified = await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE type = 'backup_code_used' AND message LIKE :m", { m: `%${md.email}%` });
    expect(Number(notified.n)).toBeGreaterThan(0);

    req = fakeReq();
    await authService.attemptLogin(req, md.email, PASSWORD);
    expect((await authService.completeWithBackupCode(req, codes[0])).status).toBe('invalid_code');

    const fresh = await backupCodeService.generate(md.id);
    expect(await backupCodeService.remaining(md.id)).toBe(10);
    expect((await authService.completeWithBackupCode(req, codes[1])).status).toBe('invalid_code');
    expect((await authService.completeWithBackupCode(req, fresh[0])).status).toBe('ok');

    req = fakeReq();
    await authService.attemptLogin(req, exec.email, PASSWORD);
    expect((await authService.completeWithBackupCode(req, fresh[1])).status).toBe('not_allowed');
    expect(authService.currentUserId(req)).toBeNull();
  });

  it('remember this browser is off by default and forgotten on a password change', async () => {
    const u = await staff('Export Executive');
    const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM login_remembered_browsers WHERE user_id = :u', { u: u.id })).n);
    expect(await rememberBrowserService.days()).toBe(0);
    let req = fakeReq();
    let res = fakeRes();
    let r = await authService.attemptLogin(req, u.email, PASSWORD);
    await authService.completeTwoFactor(req, r.dev_code, true, res);
    expect(await count()).toBe(0);
    expect(res.cookies[rememberBrowserService.COOKIE]).toBeUndefined();

    await companySettingsRepository.set('login_remember_browser_days', '7');
    req = fakeReq();
    res = fakeRes();
    r = await authService.attemptLogin(req, u.email, PASSWORD);
    expect(r.status).toBe('requires_2fa');
    await authService.completeTwoFactor(req, r.dev_code, true, res);
    const cookie = res.cookies[rememberBrowserService.COOKIE];
    const row = await db.queryOne('SELECT * FROM login_remembered_browsers WHERE user_id = :u', { u: u.id });
    expect(row).not.toBeNull();
    expect(cookie).not.toContain(row.validator_hash);
    expect(Math.abs(new Date(row.expires_at).getTime() - (Date.now() + 7 * 86400000))).toBeLessThan(5 * 60000);

    const header = `${rememberBrowserService.COOKIE}=${encodeURIComponent(cookie)}`;
    req = fakeReq(header);
    expect((await authService.attemptLogin(req, u.email, PASSWORD)).status).toBe('ok');
    expect(Number(authService.currentUserId(req))).toBe(Number(u.id));

    req = fakeReq(`${header}x`);
    expect((await authService.attemptLogin(req, u.email, PASSWORD)).status).toBe('requires_2fa');

    const other = await staff('Export Executive');
    req = fakeReq(header);
    expect((await authService.attemptLogin(req, other.email, PASSWORD)).status).toBe('requires_2fa');

    await userRepository.updatePassword(u.id, await bcrypt.hash(PASSWORD, 10));
    req = fakeReq(header);
    expect((await authService.attemptLogin(req, u.email, PASSWORD)).status).toBe('requires_2fa');
    expect(await count()).toBe(0);

    await companySettingsRepository.set('login_remember_browser_days', '0');
    expect(await rememberBrowserService.isRemembered(fakeReq(), await userRepository.findById(u.id), cookie)).toBe(false);
  });

  it('the code e-mail is security mail with the ten-minute wording', async () => {
    const t = await db.queryOne("SELECT body FROM email_templates WHERE template_key = 'two_factor_code'");
    expect(t.body).toContain('This code expires in 10 minutes.');
    const src = fs.readFileSync(path.join(__dirname, '../../src/services/twoFactorService.js'), 'utf8');
    expect(src).toContain('await emailService.sendPlainText(destination, rendered.subject, rendered.body, { isSecurityEmail: true });');
  });

  it('through the real routes: password, then the code page, then the dashboard (staff) and the portal (buyer)', async () => {
    const u = await staff('Export Executive');
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf" value="([^"]+)"/)[1];
    const step1 = await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    expect(step1.headers.location).toBe('/2fa');
    expect((await agent.get('/account')).headers.location).toBe('/login');
    const page = await agent.get('/2fa');
    expect(page.text).toContain('Send me a new code');
    expect(page.text).not.toContain('Use a backup code instead');
    const code = page.text.match(/code is: <strong>(\d{6})<\/strong>/)[1];
    const ok = await agent.post('/2fa').type('form').send({ _csrf: page.text.match(/name="_csrf" value="([^"]+)"/)[1], code });
    expect(ok.headers.location).toBe('/');
    expect((await agent.get('/account')).status).toBe(200);

    const md = await staff('Managing Director');
    const mdAgent = request.agent(app);
    const mdCsrf = (await mdAgent.get('/login')).text.match(/name="_csrf" value="([^"]+)"/)[1];
    await mdAgent.post('/login').type('form').send({ _csrf: mdCsrf, email: md.email, password: PASSWORD });
    expect((await mdAgent.get('/2fa')).text).toContain('Use a backup code instead');

    const b = await buyer();
    const client = request.agent(app);
    const cCsrf = (await client.get('/client/login')).text.match(/name="_csrf" value="([^"]+)"/)[1];
    const c1 = await client.post('/client/login').type('form').send({ _csrf: cCsrf, email: b.email, password: PASSWORD });
    expect(c1.headers.location).toBe('/client/login/code');
    const cPage = await client.get('/client/login/code');
    const cCode = cPage.text.match(/code is: <strong>(\d{6})<\/strong>/)[1];
    const c2 = await client.post('/client/login/code').type('form').send({ _csrf: cPage.text.match(/name="_csrf" value="([^"]+)"/)[1], code: cCode });
    expect(c2.headers.location).toBe('/client');
  });
});
