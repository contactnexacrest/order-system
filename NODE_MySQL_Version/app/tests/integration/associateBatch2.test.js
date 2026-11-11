'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const associateAdminController = require('../../src/controllers/associateAdminController');
const associatePortalController = require('../../src/controllers/associatePortalController');
const associateRegistrationController = require('../../src/controllers/associateRegistrationController');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const associateInvitationRepository = require('../../src/repositories/associateInvitationRepository');
const associatePortalRepository = require('../../src/repositories/associatePortalRepository');
const associateRegistrationRepository = require('../../src/repositories/associateRegistrationRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const userRepository = require('../../src/repositories/userRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associateCommission = require('../../src/services/associateCommission');
const associateIntakeService = require('../../src/services/associateIntakeService');
const associateKyc = require('../../src/services/associateKyc');
const associateService = require('../../src/services/associateService');
const permissionService = require('../../src/services/permissionService');
const { createTestClient, createTestFile } = require('../support/fixtures');

/**
 * Port of AssociateBatch2Test.php. Owner decisions 4 Nov 2026 (Batch 2):
 * D-251 (A4) document number / reference per kind, Aadhaar last 4 digits only;
 * D-252 (A3) the KYC checklist, accept / reject, no commission paid until complete;
 * D-253 (A1) associate self-registration through a one-use link;
 * D-254 (A5) the associate changes their code; old codes keep working.
 */
describe('Associates Batch 2 (owner, 4 Nov 2026)', () => {
  let session;
  let admin;
  let tag;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    session = {};
    tag = crypto.randomBytes(3).toString('hex').toUpperCase();
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest B2 Admin', :email, NULL, 'x', :r, 1, 0, 0)`,
      { email: `jest-b2-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, r: role.id }
    )).insertId;
  });

  async function call(fn, { as = null, params = {}, body = {}, query = {} } = {}) {
    const req = { params, body, query, session, method: 'POST', ip: `10.8.${crypto.randomInt(0, 250)}.${crypto.randomInt(1, 250)}`, path: '/' };
    if (as !== null) {
      req.user = await userRepository.findById(as);
      req.permissions = await permissionService.load(as, req.user.role_id);
    }
    const res = {
      statusCode: 200,
      status(c) { this.statusCode = c; return this; },
      redirect(l) { this.location = l; },
      send(b) { this.body = b; },
      renderView(view, data) { this.view = view; this.data = data; },
      setHeader() {},
    };
    await fn(req, res);
    return res;
  }

  function flash() {
    const f = (session._flash || [])[0] || { type: '', message: '' };
    delete session._flash;
    return f;
  }

  const asAssociate = (id) => {
    for (const k of Object.keys(session)) delete session[k];
    session._associate_portal_id = Number(id);
  };

  async function associate(fields = {}) {
    const id = await associateRepository.create({ name: `B2 Assoc ${tag}${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `b2-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', ...fields }, admin);
    await associateCodeService.issue(id, `K${tag}`);
    return associateRepository.find(id);
  }

  async function doc(associateId, kind, reference = null, status = 'received') {
    const id = await associateDocumentRepository.add(Number(associateId), kind, await createTestFile(null, null), null, reference, true, null);
    if (status !== 'received') await db.execute('UPDATE associate_documents SET status = :s WHERE id = :id', { s: status, id });
    return id;
  }

  it('checks references per kind and keeps only the last four Aadhaar digits', async () => {
    expect(associateKyc.normaliseReference('pan', 'abcde1234f')).toEqual(['ABCDE1234F', null]);
    expect(associateKyc.normaliseReference('pan', 'ABC123')[1]).not.toBeNull();
    expect(associateKyc.normaliseReference('aadhaar', '1234')).toEqual(['XXXX XXXX 1234', null]);
    expect(associateKyc.normaliseReference('aadhaar', 'XXXX XXXX 1234')).toEqual(['XXXX XXXX 1234', null]);
    const [v, e] = associateKyc.normaliseReference('aadhaar', '2345 6789 1234');
    expect(v).toBeNull(); // a full Aadhaar number is never stored
    expect(e).toContain('only the last 4 digits');
    expect(associateKyc.normaliseReference('aadhaar', '12a4')[1]).not.toBeNull();
    expect(associateKyc.normaliseReference('gst', '29abcde1234f1z5')).toEqual(['29ABCDE1234F1Z5', null]);
    expect(associateKyc.normaliseReference('identity', 'z1234567')).toEqual(['Z1234567', null]);
    expect(associateKyc.normaliseReference('bank_proof', '  ')).toEqual([null, null]);
    expect(associateKyc.normaliseReference('other', 'x'.repeat(51))[1]).not.toBeNull();
    expect(associateDocumentRepository.KINDS.aadhaar).toBe('Aadhaar card');
    expect(associateDocumentRepository.ASSOCIATE_KINDS).toContain('aadhaar');

    // The portal refuses a full Aadhaar number before anything is stored.
    const a = await associate();
    asAssociate(a.id);
    await call(associatePortalController.uploadDocument, { body: { kind: 'aadhaar', reference: '234567891234' } });
    expect(flash().message).toContain('only the last 4 digits');
    expect(await associateDocumentRepository.forAssociate(Number(a.id))).toEqual([]);
    // The page offers Aadhaar with its rule for the browser check.
    const page = await call(associatePortalController.profile);
    expect(page.data.associateKinds).toContain('aadhaar');
    expect(page.data.docRules.aadhaar[0]).toBe('[0-9]{4}');
    expect(page.data.kyc.map((i) => i.label)).toContain('ID proof (Aadhaar card, passport or other ID document)'); // D-290
  });

  it('KYC checklist, review and the payout gate', async () => {
    const a = await associate({ gstin: '29ABCDE1234F1Z5' });
    // D-290 (11 Nov 2026): ID proof and address proof are separate required items in India and abroad.
    expect((await associateKyc.checklist(a)).map((i) => i.label)).toEqual(['PAN card', 'ID proof (Aadhaar card, passport or other ID document)', 'Address proof', 'Bank proof (cancelled cheque or bank statement)', 'Signed agreement', 'GST certificate']);
    const abroad = await associate({ based_in: 'abroad', currency_code: 'USD' });
    expect((await associateKyc.checklist(abroad)).map((i) => i.label)).toEqual(['ID proof (passport, national ID card, residence permit or driving licence)', 'Address proof', 'Bank proof (cancelled cheque or bank statement)', 'Signed agreement']);

    const pan = await doc(a.id, 'pan', 'ABCDE1234F');
    const aad = await doc(a.id, 'aadhaar', 'XXXX XXXX 1234');
    const addr = await doc(a.id, 'address', 'Electricity bill 0042'); // D-290: the address proof is its own item
    const bank = await doc(a.id, 'bank_proof');
    const agr = await doc(a.id, 'agreement');
    const gst = await doc(a.id, 'gst', '29ABCDE1234F1Z5');
    expect([...new Set((await associateKyc.checklist(a)).map((i) => i.status))]).toEqual(['pending']);

    await call(associateAdminController.reviewDocument, { as: admin, params: { id: String(a.id), docId: String(bank) }, body: { decision: 'reject', reason: 'blurry' } });
    expect(flash().message).toContain('at least 10 characters');
    await call(associateAdminController.reviewDocument, { as: admin, params: { id: String(a.id), docId: String(bank) }, body: { decision: 'reject', reason: 'The cheque photo is blurry' } });
    const items = Object.fromEntries((await associateKyc.checklist(a)).map((i) => [i.key, i]));
    expect(items.bank.status).toBe('rejected');
    expect(items.bank.doc.review_reason).toBe('The cheque photo is blurry');
    const ev = await db.queryOne("SELECT message FROM associate_events WHERE associate_id = :a AND event = 'document_rejected'", { a: a.id });
    expect(ev.message).toContain('The cheque photo is blurry');
    const audit = await db.queryOne("SELECT action_type FROM audit_log WHERE entity_type = 'associate_documents' AND entity_id = :d ORDER BY id DESC LIMIT 1", { d: bank });
    expect(audit.action_type).toBe('ASSOCIATE_DOCUMENT_REJECTED');

    const payout = { payee_kind: 'associate', associate_id: a.id, order_id: 0, due_on: '2020-01-01', amount_inr: 100 };
    const block = await associateCommission.payBlockReason(payout, '2026-11-05');
    expect(block).toContain('KYC is not complete');
    expect(block).toContain('Bank proof');

    for (const d of [pan, aad, addr, agr, gst]) {
      await call(associateAdminController.reviewDocument, { as: admin, params: { id: String(a.id), docId: String(d) }, body: { decision: 'accept', reason: '' } });
      expect(flash().type).toBe('success');
    }
    const bank2 = await doc(a.id, 'bank_proof');
    expect(Object.fromEntries((await associateKyc.checklist(a)).map((i) => [i.key, i])).bank.status).toBe('pending'); // a re-upload is pending again
    await call(associateAdminController.reviewDocument, { as: admin, params: { id: String(a.id), docId: String(bank2) }, body: { decision: 'accept' } });
    expect(await associateKyc.missing(a)).toEqual([]);
    expect(await associateCommission.payBlockReason(payout, '2026-11-05')).toBeNull(); // KYC complete — the payout may be paid

    const page = await call(associateAdminController.show, { as: admin, params: { id: String(a.id) } });
    expect(page.data.kyc.every((i) => i.status === 'accepted')).toBe(true);
  });

  it('self-registration through a one-use link', async () => {
    await call(associateRegistrationController.createLink, { as: admin });
    const url = String(session._associate_registration_link || '');
    expect(url).toMatch(/\/associate-register\/[0-9a-f]{48}$/);
    const token = url.slice(-48);
    expect(await associateRegistrationRepository.linkDays()).toBe(7);

    expect((await call(associateRegistrationController.show, { params: { token: `nope${tag}` } })).view).toBe('associate_register/unavailable');
    expect((await call(associateRegistrationController.show, { params: { token } })).view).toBe('associate_register/form');

    const bad = await call(associateRegistrationController.submit, { params: { token }, body: { name: 'R', email: 'bad', phone: 'x', country: '', based_in: 'mars', city: '' } });
    expect(bad.statusCode).toBe(422);
    expect(Object.keys(bad.data.errors).sort()).toEqual(['based_in', 'city', 'consent', 'country', 'email', 'name', 'phone']);
    expect(await associateRegistrationRepository.usableLink(token)).not.toBeNull(); // a refused form does not use the link

    const email = `reg-${tag.toLowerCase()}@assoc.test`;
    const ok = await call(associateRegistrationController.submit, { params: { token }, body: { name: `Associate ${tag}`, email, phone: '+91-0000000000', country: 'India', based_in: 'india', city: 'Bengaluru', consent: '1' } });
    expect(ok.view).toBe('associate_register/done');
    expect(await associateRegistrationRepository.usableLink(token)).toBeNull(); // one use
    expect((await call(associateRegistrationController.submit, { params: { token }, body: {} })).view).toBe('associate_register/unavailable');
    const reg = await db.queryOne('SELECT * FROM associate_registrations WHERE email = :e', { e: email });
    expect(reg.status).toBe('pending');

    const list = await call(associateRegistrationController.index, { as: admin, query: {} });
    expect(list.data.registrations.map((r) => r.name)).toContain(`Associate ${tag}`);
    await call(associateRegistrationController.accept, { as: admin, params: { id: String(reg.id) } });
    const a = await associateRepository.findByEmail(email);
    expect([a.status, a.based_in, a.currency_code, a.city, Number(a.portal_enabled)]).toEqual(['invited', 'india', 'INR', 'Bengaluru', 1]);
    expect(a.personal_code).toBeTruthy();
    expect((await associateRegistrationRepository.find(Number(reg.id))).status).toBe('accepted');

    await call(associateRegistrationController.createLink, { as: admin });
    const t2 = String(session._associate_registration_link).slice(-48);
    await call(associateRegistrationController.submit, { params: { token: t2 }, body: { name: `Second ${tag}`, email: `reg2-${tag.toLowerCase()}@assoc.test`, phone: '+44 20 7946 0000', country: 'UK', based_in: 'abroad', city: 'London', consent: '1' } });
    const reg2 = await db.queryOne('SELECT id FROM associate_registrations WHERE name = :n', { n: `Second ${tag}` });
    delete session._flash;
    await call(associateRegistrationController.reject, { as: admin, params: { id: String(reg2.id) }, body: { reason: 'no' } });
    expect(flash().message).toContain('10 to 300 characters');
    await call(associateRegistrationController.reject, { as: admin, params: { id: String(reg2.id) }, body: { reason: 'We do not work with associates in this region yet' } });
    expect((await associateRegistrationRepository.find(Number(reg2.id))).status).toBe('rejected');

    await call(associateRegistrationController.createLink, { as: admin });
    const t3 = String(session._associate_registration_link).slice(-48);
    await db.execute('UPDATE associate_registration_links SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = :h', { h: crypto.createHash('sha256').update(t3).digest('hex') });
    expect((await call(associateRegistrationController.show, { params: { token: t3 } })).view).toBe('associate_register/unavailable');
  });

  it('the associate changes their code and old codes keep working', async () => {
    const a = await associate();
    const first = a.personal_code;
    asAssociate(a.id);

    await call(associatePortalController.chooseCode, { body: { code: 'NXA-WHATEVER-AA' } });
    expect(flash().message).toContain('Show another ending'); // nothing switches without a proposal

    await call(associatePortalController.proposeCode, { body: { readable: 'Demo Exim' } });
    const p1 = String(session.associate_code_proposal.code);
    expect(p1).toMatch(/^NXA-DEMOEXIM-..$/);
    expect(associateCodeService.isValid(p1)).toBe(true);
    const page = await call(associatePortalController.clients);
    expect(page.data.proposal.code).toBe(p1);
    expect((await associateRepository.find(a.id)).personal_code).toBe(first); // a proposal changes nothing

    await call(associatePortalController.chooseCode, { body: { code: p1 } });
    expect(flash().message).toContain(`Your personal code is now ${p1}`);
    expect((await associateRepository.find(a.id)).personal_code).toBe(p1);

    expect(Number((await associateIntakeService.introducer(first, null)).associate.id)).toBe(Number(a.id));
    expect(Number((await associateIntakeService.introducer(p1, null)).associate.id)).toBe(Number(a.id));
    expect((await associateCodeService.codes(Number(a.id))).length).toBe(2);

    // The yearly limit (setting).
    await db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'associate_code_changes_per_year'");
    await call(associatePortalController.proposeCode, { body: { readable: 'Again' } });
    await call(associatePortalController.chooseCode, { body: { code: String(session.associate_code_proposal.code) } });
    expect(flash().message).toContain('1 times a year');
    await db.execute("UPDATE incentive_settings SET setting_value = '3' WHERE setting_key = 'associate_code_changes_per_year'");

    // Came via: the code the buyer used is kept.
    const intro = await associateIntakeService.introducer(first, null);
    expect(intro.code).toBe(associateCodeService.normalise(first));
    const invId = await associateInvitationRepository.createForCode(Number(a.id), 'Came Via Buyer', 'NL', intro.code);
    const clientId = await createTestClient();
    await associateService.linkClient(clientId, Number(a.id), 'code', invId, '2026-11-04', admin);
    const row = (await associatePortalRepository.clients(Number(a.id))).find((c) => Number(c.id) === clientId);
    expect(row.came_via).toBe(`Code ${associateCodeService.normalise(first)}`);

    // Staff may block an old code (e.g. a leak), with a reason.
    await call(associateAdminController.setCode, { as: admin, params: { id: String(a.id) }, body: { readable: 'Leak', block_old: '1', block_reason: 'short' } });
    expect(flash().message).toContain('at least 10 characters');
    await call(associateAdminController.setCode, { as: admin, params: { id: String(a.id) }, body: { readable: 'Leak', block_old: '1', block_reason: 'The code was posted on a public forum' } });
    expect((await associateIntakeService.introducer(p1, null)).associate).toBeNull(); // the blocked code no longer applies
    expect(Number((await associateIntakeService.introducer(first, null)).associate.id)).toBe(Number(a.id)); // older codes not blocked keep working
  });
});
