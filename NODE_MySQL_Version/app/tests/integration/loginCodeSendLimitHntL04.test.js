'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const db = require('../../src/config/db');
const authService = require('../../src/services/authService');
const clientPortalService = require('../../src/services/clientPortalService');
const associatePortalService = require('../../src/services/associatePortalService');
const twoFactorService = require('../../src/services/twoFactorService');
const associateRepository = require('../../src/repositories/associateRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestClientLogin } = require('../support/fixtures');

// Port of LoginCodeSendLimitHntL04Test.php — HNT-L04 (QA 13 Nov 2026): signing in
// again with the password sent a new e-mailed login code every time, without limit —
// for staff, buyers and associates alike — so whoever knows a password could flood
// the inbox and get a fresh set of 5 code tries as often as they liked. Now at most
// 5 codes are sent per account in 15 minutes (a "send a new code" counts too); a
// successful sign-in starts the count again.

global.__D265_MANUAL_LOGIN_CODE__ = true; // this file tests the code step itself (tests/support/loginCodeAuto.js)

const PASSWORD = 'Known-Password-123!';

/** A request with a plain in-memory session, like express-session's (regenerate swaps it). */
function fakeReq() {
  const req = { ip: '127.0.0.1', headers: {} };
  const fresh = () => {
    const s = {};
    Object.defineProperty(s, 'regenerate', { enumerable: false, value: (cb) => { req.session = fresh(); cb(); } });
    return s;
  };
  req.session = fresh();
  return req;
}

async function staff() {
  const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
  const email = `jest-l04-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
  const r = await db.execute(
    `INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, :hash, :role, 1, 0, 0)`,
    { email, hash: await bcrypt.hash(PASSWORD, 4), role: role.id }
  );
  return userRepository.findById(r.insertId);
}

async function buyer() {
  const email = `l04-buyer-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
  const clientId = await createTestClient(email);
  await createTestClientLogin(clientId, PASSWORD);
  return { id: Number(clientId), email };
}

async function associate() {
  const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
  const staffId = (await db.execute(
    `INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, 'x', :role, 1, 0, 0)`,
    { email: `jest-l04-staff-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role: role.id }
  )).insertId;
  const id = await associateRepository.create({ name: `L04 Assoc ${crypto.randomBytes(3).toString('hex')}`, based_in: 'india', currency_code: 'INR',
    email: `l04-assoc-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', portal_enabled: 1 }, staffId);
  await associateRepository.setFields(id, { portal_enabled: 1, password_hash: await bcrypt.hash(PASSWORD, 4) });
  return associateRepository.find(id);
}

describe('HNT-L04 at most 5 login codes per account in 15 minutes', () => {
  let rememberDaysBefore;
  beforeAll(async () => { rememberDaysBefore = await companySettingsRepository.get('login_remember_browser_days'); });
  beforeEach(async () => { await companySettingsRepository.set('login_remember_browser_days', '0'); });
  afterAll(async () => {
    await companySettingsRepository.set('login_remember_browser_days', String(rememberDaysBefore ?? '7'));
    await db.pool.end();
  });

  it('HNT-L04: staff get at most 5 codes in 15 minutes by signing in again', async () => {
    const u = await staff();
    for (let i = 1; i <= twoFactorService.MAX_CODES_PER_WINDOW; i++) {
      expect((await authService.attemptLogin(fakeReq(), u.email, PASSWORD)).status).toBe('requires_2fa');
    }
    const req = fakeReq();
    expect((await authService.attemptLogin(req, u.email, PASSWORD)).status).toBe('too_many_codes');
    expect(twoFactorService.pendingUserId(req)).toBeNull();
  });

  it('HNT-L04: "send a new code" counts towards the same limit and the latest code still works', async () => {
    const u = await staff();
    let req;
    for (let i = 1; i <= 4; i++) {
      req = fakeReq();
      await authService.attemptLogin(req, u.email, PASSWORD);
    }
    req.session._2fa_pending.issued_at = Date.now() - 61000;
    const fifth = await twoFactorService.resend(req);
    expect(fifth.status).toBe('sent');
    req.session._2fa_pending.issued_at = Date.now() - 61000;
    expect((await twoFactorService.resend(req)).status).toBe('too_many');
    expect((await authService.completeTwoFactor(req, fifth.dev_code)).status).toBe('ok');
  });

  it('HNT-L04: a successful sign-in starts the count again', async () => {
    const u = await staff();
    for (let round = 1; round <= 3; round++) {
      let req;
      let r;
      for (let i = 1; i <= 4; i++) {
        req = fakeReq();
        r = await authService.attemptLogin(req, u.email, PASSWORD);
        expect(r.status).toBe('requires_2fa');
      }
      expect((await authService.completeTwoFactor(req, r.dev_code)).status).toBe('ok');
    }
  });

  it('HNT-L04: buyers get at most 5 codes; another buyer is not affected', async () => {
    const b = await buyer();
    for (let i = 1; i <= 5; i++) {
      expect((await clientPortalService.attemptLogin(fakeReq(), b.email, PASSWORD)).status).toBe('requires_code');
    }
    const req = fakeReq();
    expect((await clientPortalService.attemptLogin(req, b.email, PASSWORD)).status).toBe('too_many_codes');
    expect(twoFactorService.pendingUserId(req, 'client')).toBeNull();

    const other = await buyer();
    const req2 = fakeReq();
    const r = await clientPortalService.attemptLogin(req2, other.email, PASSWORD);
    expect(r.status).toBe('requires_code');
    expect((await clientPortalService.completeLogin(req2, r.dev_code)).status).toBe('ok');
  });

  it('HNT-L04: associates get at most 5 codes', async () => {
    const a = await associate();
    for (let i = 1; i <= 5; i++) {
      expect((await associatePortalService.attemptLogin(fakeReq(), a.email, PASSWORD)).status).toBe('code_sent');
    }
    const req = fakeReq();
    expect((await associatePortalService.attemptLogin(req, a.email, PASSWORD)).status).toBe('too_many_codes');
    expect(associatePortalService.pendingId(req)).toBeNull();
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM associate_tokens WHERE associate_id = :a AND purpose = 'login_code'", { a: a.id });
    expect(Number(n.n)).toBe(5); // exactly 5 codes were issued (and e-mailed)
  });

  it('HNT-L04: an associate\'s successful sign-in starts the count again', async () => {
    const a = await associate();
    for (let round = 1; round <= 2; round++) {
      let req;
      let r;
      for (let i = 1; i <= 4; i++) {
        req = fakeReq();
        r = await associatePortalService.attemptLogin(req, a.email, PASSWORD);
        expect(r.status).toBe('code_sent');
      }
      expect(await associatePortalService.verifyCode(req, r.code)).toBe(true);
    }
  });

  it('HNT-L04: the refusal message is plain', () => {
    expect(twoFactorService.TOO_MANY_CODES_MESSAGE).toContain('at most 5 in 15 minutes');
  });
});
