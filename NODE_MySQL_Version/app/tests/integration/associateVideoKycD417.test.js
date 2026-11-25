'use strict';

// Owner decisions of 25 Nov 2026:
//  - D-417: a live video KYC item — staff record that they saw the associate on a live video call holding the ID
//    proof and the address proof clearly (date, who, what was seen); existing associates get 60 days
//    (video_kyc_due_on) before payouts wait for it.
//  - D-418: the associate's bank form says why we ask for bank details.
// PHP twin: AssociateVideoKycD417Test.php.

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associateKyc = require('../../src/services/associateKyc');
const associatePortalService = require('../../src/services/associatePortalService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { completeAssociateKyc } = require('../support/fixtures');

describe('D-417 live video KYC and D-418 why we ask for bank details', () => {
  const PASSWORD = 'JestD417!1234';
  const ASSOCIATE_PASSWORD = 'D417Assoc12345';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  let tag;
  let staffId;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, superAdmin = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest D417 User', :email, NULL, :hash, :r, 1, 0, 0, :s)`,
      { email: `jest-d417-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id, s: superAdmin ? 1 : 0 }
    )).insertId;
  }

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex').toUpperCase();
    staffId = await user('Admin', true);
  });

  async function associate(basedIn = 'india') {
    const id = await associateRepository.create({ name: `D417 Associate ${tag} ${crypto.randomBytes(2).toString('hex')}`, based_in: basedIn, currency_code: basedIn === 'india' ? 'INR' : 'USD',
      email: `associate-${crypto.randomBytes(4).toString('hex')}@email.com`, status: 'active', portal_enabled: 1 }, staffId);
    await db.execute('UPDATE associates SET portal_enabled = 1 WHERE id = :id', { id });
    await associateCodeService.issue(id, `V${tag}${String(id).slice(-2)}`);
    return associateRepository.find(id);
  }

  async function staff(userId = staffId) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  async function portal(a) {
    const agent = request.agent(app);
    const token = await associatePortalService.issueToken(Number(a.id), 'set_password', 60);
    const csrf = csrfOf((await agent.get(`/associate/set-password/${token}`)).text);
    await agent.post(`/associate/set-password/${token}`).type('form').send({ _csrf: csrf, new_password: ASSOCIATE_PASSWORD, confirm_password: ASSOCIATE_PASSWORD });
    const loginCsrf = csrfOf((await agent.get('/associate/login')).text);
    await agent.post('/associate/login').type('form').send({ _csrf: loginCsrf, email: a.email, password: ASSOCIATE_PASSWORD });
    const codePage = (await agent.get('/associate/login/code')).text;
    const code = codePage.match(/your login code is (\d{6})/)[1];
    await agent.post('/associate/login/code').type('form').send({ _csrf: csrfOf(codePage), code });
    return agent;
  }

  const videoItem = async (a) => (await associateKyc.checklist(a)).find((i) => i.key === 'video');

  it('is a required item in India and abroad', async () => {
    for (const where of ['india', 'abroad']) {
      expect(associateKyc.requiredItems({ based_in: where }).map((i) => i.key)).toContain('video');
    }
    const a = await associate();
    const item = await videoItem(a);
    expect(item.status).toBe('missing');
    expect(item.status_label).toBe('Not done yet');
    expect(await associateKyc.missing(a)).toContain('Live video KYC (ID proof and address proof clearly visible)');
  });

  it('staff record it with a date and what was seen', async () => {
    let a = await associate();
    expect(await associateKyc.recordVideo(a, '2099-01-01', 'Passport and electricity bill shown', staffId)).toBe('Enter the date of the video call (today or earlier).');
    expect(await associateKyc.recordVideo(a, '2026-11-20', 'ok', staffId)).toMatch(/^Say what was seen on the call/);
    expect(await associateKyc.recordVideo(a, '2026-11-20', 'Passport and electricity bill shown; name and address match', staffId)).toBeNull();

    a = await associateRepository.find(Number(a.id));
    const item = await videoItem(a);
    expect(item.status).toBe('accepted');
    expect(item.video.on).toBe('2026-11-20');
    expect(item.video.by).toBe(staffId);
    expect(await associateKyc.missing(a)).not.toContain(associateKyc.VIDEO_LABEL);
    const audit = await db.query("SELECT action_type FROM audit_log WHERE entity_type = 'associates' AND entity_id = :id AND action_type = 'ASSOCIATE_VIDEO_KYC_RECORDED'", { id: Number(a.id) });
    expect(audit).toHaveLength(1);
  });

  it('gives existing associates 60 days before payouts wait', async () => {
    let a = await associate();
    await completeAssociateKyc(a.id);
    await db.execute('UPDATE associates SET video_kyc_at = NULL, video_kyc_note = NULL WHERE id = :id', { id: Number(a.id) });
    a = await associateRepository.find(Number(a.id));
    expect(await associateKyc.missingForPayout(a, '2026-11-25')).toEqual([associateKyc.VIDEO_LABEL]);

    await db.execute("UPDATE associates SET video_kyc_due_on = '2027-01-24' WHERE id = :id", { id: Number(a.id) });
    a = await associateRepository.find(Number(a.id));
    expect(await associateKyc.missingForPayout(a, '2027-01-24')).toEqual([]);
    expect(await associateKyc.missingForPayout(a, '2027-01-25')).toEqual([associateKyc.VIDEO_LABEL]);
    expect(associateKyc.videoGraceNote(a, '2026-11-25')).toBe('Live video KYC needed by 24 Jan 2027 — after that, commission payouts wait until it is done.');
  });

  it('shows the form to staff, and the portal explains both', async () => {
    const a = await associate();
    const agent = await staff();
    const page = (await agent.get(`/associates/${a.id}`)).text;
    expect(page).toContain('data-video-kyc-form');
    expect(page).toContain('Live video KYC (ID proof and address proof clearly visible)');

    const res = await agent.post(`/associates/${a.id}/video-kyc`).type('form').send({ _csrf: csrfOf(page), seen_on: todayIst(), note: 'Passport and bank statement shown clearly' });
    expect(res.status).toBe(302);
    expect((await agent.get(`/associates/${a.id}`)).text).toContain('Passport and bank statement shown clearly');

    const p = await portal(await associateRepository.find(Number(a.id)));
    const profile = (await p.get('/associate/profile')).text;
    expect(profile).toContain('data-bank-why');
    expect(profile).toContain('so nobody can redirect your money');
    expect(profile).toContain('shown everywhere else only as its last 4 digits');
    expect(profile).toContain('Done on ');
  });

  it('keeps an optional recording for staff only (D-417 follow-up)', async () => {
    const ctx = await db.queryOne("SELECT allowed_extensions, max_size_bytes FROM file_upload_contexts WHERE context_key = 'associate_video_kyc'");
    expect(ctx.allowed_extensions).toBe('mp4,mov,webm,3gp,jpg,jpeg,png,pdf');
    expect(Number(ctx.max_size_bytes)).toBe(26214400);
    const a = await associate();
    const agent = await staff();
    let page = (await agent.get(`/associates/${a.id}`)).text;
    const refused = await agent.post(`/associates/${a.id}/video-kyc`).field('_csrf', csrfOf(page)).field('seen_on', todayIst()).field('note', 'Passport and bank statement shown clearly')
      .attach('video_file', Buffer.from('MZ not a video'), 'call.exe');
    expect(refused.status).toBe(302);
    expect((await associateRepository.find(Number(a.id))).video_kyc_at).toBeNull();

    page = (await agent.get(`/associates/${a.id}`)).text;
    const ok = await agent.post(`/associates/${a.id}/video-kyc`).field('_csrf', csrfOf(page)).field('seen_on', todayIst()).field('note', 'Passport and bank statement shown clearly')
      .attach('video_file', Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0, 16, 74, 70, 73, 70]), 'call.jpg');
    expect(ok.status).toBe(302);
    const doc = await db.queryOne("SELECT id, status FROM associate_documents WHERE associate_id = :a AND kind = 'video_kyc'", { a: Number(a.id) });
    expect(doc.status).toBe('accepted');
    expect((await agent.get(`/associates/${a.id}`)).text).toContain('Video KYC recording / screenshots');

    const p = await portal(await associateRepository.find(Number(a.id)));
    expect((await p.get('/associate/profile')).text).not.toContain('Video KYC recording');
    expect((await p.get(`/associate/documents/${doc.id}`)).status).toBe(404);
  });

  it('needs Manage associates to record it', async () => {
    const a = await associate();
    const agent = await staff(await user('Export Executive'));
    const csrf = csrfOf((await agent.get('/orders')).text);
    await agent.post(`/associates/${a.id}/video-kyc`).type('form').send({ _csrf: csrf, seen_on: todayIst(), note: 'Passport and bank statement shown clearly' });
    expect((await associateRepository.find(Number(a.id))).video_kyc_at).toBeNull();
  });
});
