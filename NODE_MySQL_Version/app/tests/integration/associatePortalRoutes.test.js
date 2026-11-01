'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const associateEventRepository = require('../../src/repositories/associateEventRepository');
const associateInvitationRepository = require('../../src/repositories/associateInvitationRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associatePortalService = require('../../src/services/associatePortalService');
const associateService = require('../../src/services/associateService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of AssociatePortalRoutesTest.php. QA Round 8 ("15 routes without a
 * behaviour test", rule D-211): each route is driven through the real
 * Express app and its middleware, and the test checks what it does — the
 * record changed, the audit row, the page shown — and that another
 * associate's record is answered as not found with nothing written.
 * Associate portal: bank, clients, code, commission invoice, documents
 * (upload and download), invitation cancel, messages (page and send),
 * set-password (page and form); public: /invite/:token,
 * /quotation-details/verify; staff: inspection result, OC sent to buyer.
 */
describe('Associate portal and other QA Round 8 routes, through the real app', () => {
  const PASSWORD = 'JestRoutes!123';
  const ASSOCIATE_PASSWORD = 'Route1234567';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const unescape = (s) => s.trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  let tag;
  let staffId;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex').toUpperCase();
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    staffId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest Route Staff', :email, NULL, :hash, :r, 1, 0, 0, 1)`,
      { email: `jest-route-staff-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id }
    )).insertId;
  });

  async function associate() {
    const id = await associateRepository.create({ name: `Route Assoc ${tag} ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `route-assoc-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', portal_enabled: 1 }, staffId);
    await db.execute('UPDATE associates SET portal_enabled = 1 WHERE id = :id', { id });
    await associateCodeService.issue(id, `R${tag}`);
    return associateRepository.find(id);
  }

  /** Sets the associate's password through the set-password link, then logs in with the e-mailed code. */
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

  async function staff() {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: staffId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  /** Posts from `page`; returns the status, the body and the flash the next page shows. */
  async function post(agent, page, url, fields = {}) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...fields });
    let flash = { type: null, message: null };
    if (res.status === 302 && res.headers.location) {
      const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
      if (m) flash = { type: m[1], message: unescape(m[2]) };
    }
    return { status: res.status, text: res.text, ...flash };
  }

  async function payout(a) {
    const orderId = await createTestOrder(await createTestClient());
    return (await db.execute("INSERT INTO incentive_payouts (order_id, payee_kind, role, associate_id, amount_inr, incentive_month) VALUES (:o, 'associate', 'associate', :a, 1500, '2026-10')",
      { o: orderId, a: a.id })).insertId;
  }

  async function storedFile(bytes) {
    const fileId = await createTestFile(null, null);
    const p = path.join(os.tmpdir(), `assoc-route-${crypto.randomBytes(4).toString('hex')}.pdf`);
    fs.writeFileSync(p, bytes);
    await db.execute("UPDATE file_store SET server_path = :p, file_size_bytes = :s, original_filename = 'pan.pdf' WHERE id = :id", { p, s: bytes.length, id: fileId });
    return fileId;
  }

  const count = async (sql, params) => Number((await db.queryOne(sql, params)).n);

  // ---------------------------------------------------------------- set-password

  it('GET and POST /associate/set-password/:token', async () => {
    const a = await associate();
    const agent = request.agent(app);
    const token = await associatePortalService.issueToken(Number(a.id), 'set_password', 60);
    const page = await agent.get(`/associate/set-password/${token}`);
    expect(page.status).toBe(200);
    expect(page.text).toContain(a.email);
    expect(page.text).toContain('name="new_password"');
    expect((await agent.get(`/associate/set-password/${'0'.repeat(64)}`)).text).toContain('This link has expired or was already used.');

    const pagePath = `/associate/set-password/${token}`;
    expect((await post(agent, pagePath, pagePath, { new_password: 'Route1234567', confirm_password: 'Route1234568' })).message).toBe('The two passwords do not match.');
    expect((await post(agent, pagePath, pagePath, { new_password: 'short', confirm_password: 'short' })).message).toBe('Use at least 10 characters, with letters and digits.');
    expect((await associateRepository.find(a.id)).password_hash || null).toBeNull(); // nothing saved on a refusal
    const ok = await post(agent, pagePath, pagePath, { new_password: 'Route1234567', confirm_password: 'Route1234567' });
    expect([ok.type, ok.message]).toEqual(['success', 'Password set. Log in with your e-mail and the new password.']);
    expect(await bcrypt.compare('Route1234567', (await associateRepository.find(a.id)).password_hash.replace(/^\$2y\$/, '$2b$'))).toBe(true);
    expect((await post(agent, '/associate/login', pagePath, { new_password: 'Other1234567', confirm_password: 'Other1234567' })).message)
      .toBe('This link has expired or was already used. Ask NexaCrest for a new one.'); // the link works once
  });

  // ---------------------------------------------------------------- clients, code, invitations

  it('GET /associate/clients, POST /associate/code and POST /associate/invitations/:id/cancel', async () => {
    const a = await associate();
    const b = await associate();
    const mine = await createTestClient();
    const theirs = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :n WHERE id = :id', { n: `Mine Buyer ${tag}`, id: mine });
    await db.execute('UPDATE clients SET company_legal_name = :n WHERE id = :id', { n: `Theirs Buyer ${tag}`, id: theirs });
    await associateService.linkClient(mine, Number(a.id), 'code', null, '2026-10-01', staffId);
    await associateService.linkClient(theirs, Number(b.id), 'code', null, '2026-10-01', staffId);

    const agent = await portal(a);
    const page = await agent.get('/associate/clients');
    expect(page.status).toBe(200);
    expect(page.text).toContain(`Mine Buyer ${tag}`);
    expect(page.text).not.toContain(`Theirs Buyer ${tag}`); // only their own clients
    expect(page.text).toContain(a.personal_code);

    const clients = '/associate/clients';
    expect((await post(agent, clients, '/associate/code', { readable: 'x!' })).message).toBe('Use 3 to 12 letters or digits.');
    const chosen = await post(agent, clients, '/associate/code', { readable: `Mine${tag}` });
    const now = (await associateRepository.find(a.id)).personal_code;
    expect([chosen.type, chosen.message]).toEqual(['success', `Your personal code is now ${now}.`]);
    expect(now).not.toBe(a.personal_code);
    expect(await count("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_CODE_CHOSEN' AND entity_id = :id", { id: a.id })).toBe(1);
    expect((await post(agent, clients, '/associate/code', { readable: `Again${tag}` })).message).toBe('You have already chosen your code. Ask your relationship manager if it must change.');
    expect((await associateRepository.find(a.id)).personal_code).toBe(now); // chosen once only

    const mineInv = await associateInvitationRepository.createLink(Number(a.id), `Invited ${tag}`, 'Netherlands');
    const theirsInv = await associateInvitationRepository.createLink(Number(b.id), `Other ${tag}`, 'Germany');
    const notMine = await post(agent, clients, `/associate/invitations/${theirsInv.id}/cancel`);
    expect([notMine.status, notMine.text]).toEqual([404, 'Invitation not found.']);
    expect((await associateInvitationRepository.find(theirsInv.id)).status).toBe('created'); // another associate's invitation is untouched
    expect((await post(agent, clients, `/associate/invitations/${mineInv.id}/cancel`)).message).toBe('Invitation cancelled.');
    expect((await associateInvitationRepository.find(mineInv.id)).status).toBe('cancelled');
    expect((await post(agent, clients, `/associate/invitations/${mineInv.id}/cancel`)).message).toBe('This invitation can no longer be cancelled.');

    const anonymous = await request(app).get('/associate/clients');
    expect([anonymous.status, anonymous.headers.location]).toEqual([302, '/associate/login']); // not signed in
  });

  // ---------------------------------------------------------------- commission invoice, documents, bank

  it('POST /associate/commission/:id/invoice, POST /associate/documents, GET /associate/documents/:id and POST /associate/bank', async () => {
    const a = await associate();
    const b = await associate();
    const mine = await payout(a);
    const theirs = await payout(b);
    const docs = async (id) => (await associateDocumentRepository.forAssociate(id)).length;
    const before = await docs(a.id);
    const agent = await portal(a);
    const commission = '/associate/commission';
    const profile = '/associate/profile';

    const notMine = await post(agent, commission, `/associate/commission/${theirs}/invoice`, { invoice_no: 'INV-1' });
    expect([notMine.status, notMine.text]).toEqual([404, 'Payout not found.']);
    expect((await post(agent, commission, `/associate/commission/${mine}/invoice`, { invoice_no: '' })).message).toBe('Enter your invoice number.');
    expect((await post(agent, commission, `/associate/commission/${mine}/invoice`, { invoice_no: 'INV-1' })).type).toBe('error'); // no file attached
    expect(await docs(a.id)).toBe(before); // nothing stored on a refusal

    expect((await post(agent, profile, '/associate/documents', { kind: 'invoice' })).message).toBe('Choose what the document is.');
    expect((await post(agent, profile, '/associate/documents', { kind: 'pan' })).type).toBe('error'); // no file attached
    expect(await docs(a.id)).toBe(before);

    // A real upload is stored and listed.
    const csrf = csrfOf((await agent.get(profile)).text);
    const uploaded = await agent.post('/associate/documents').field('_csrf', csrf).field('kind', 'pan').attach('document_file', Buffer.from('%PDF-1.4 uploaded pan'), 'pan.pdf');
    expect(uploaded.status).toBe(302);
    expect(await docs(a.id)).toBe(before + 1);
    expect(await count("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_DOCUMENT_UPLOADED' AND entity_id = :id", { id: a.id })).toBe(1);

    // The associate downloads their own document, never another's.
    const own = await associateDocumentRepository.add(Number(a.id), 'pan', await storedFile('%PDF-1.4 own pan'), null, null, true, null);
    const other = await associateDocumentRepository.add(Number(b.id), 'pan', await storedFile('%PDF-1.4 other pan'), null, null, true, null);
    const dl = await agent.get(`/associate/documents/${own}`).buffer(true).parse((res, cb) => { let d = ''; res.on('data', (c) => { d += c; }); res.on('end', () => cb(null, d)); });
    expect([dl.status, dl.body]).toEqual([200, '%PDF-1.4 own pan']);
    const blocked = await agent.get(`/associate/documents/${other}`);
    expect([blocked.status, blocked.text]).toEqual([404, 'Document not found.']);

    const requests = async () => count('SELECT COUNT(*) AS n FROM associate_bank_accounts WHERE associate_id = :a', { a: a.id });
    const bankBefore = await requests();
    const bank = { holder_name: 'Route Assoc', account_number: '123456789012', account_number_confirm: '123456789012', ifsc: 'HDFC0001234', bank_name: 'HDFC Bank' };
    expect((await post(agent, profile, '/associate/bank', { ...bank, account_number_confirm: '999' })).message).toBe('The two account numbers do not match — type it again.');
    expect((await post(agent, profile, '/associate/bank', bank)).message).toBe('Attach a cancelled cheque or a bank letter (PDF or image).');
    expect(await requests()).toBe(bankBefore); // no bank change stored without the proof
  });

  // ---------------------------------------------------------------- messages

  it('GET and POST /associate/messages', async () => {
    const a = await associate();
    await associateEventRepository.addMessage(Number(a.id), false, staffId, `Welcome aboard ${tag}`);
    const agent = await portal(a);
    const page = await agent.get('/associate/messages');
    expect(page.status).toBe(200);
    expect(page.text).toContain(`Welcome aboard ${tag}`);
    expect(page.text).toContain('name="body"');

    const messages = async () => (await associateEventRepository.messages(Number(a.id))).length;
    const before = await messages();
    expect((await post(agent, '/associate/messages', '/associate/messages', { body: '  ' })).message).toBe('Write your message (up to 4,000 characters).');
    expect(await messages()).toBe(before);
    expect((await post(agent, '/associate/messages', '/associate/messages', { body: `Question about order ${tag}` })).message).toBe('Message sent.');
    expect(await messages()).toBe(before + 1);
    expect((await agent.get('/associate/messages')).text).toContain(`Question about order ${tag}`);
  });

  // ---------------------------------------------------------------- public: /invite/:token, /quotation-details/verify

  it('GET /invite/:token and GET /quotation-details/verify', async () => {
    const a = await associate();
    const inv = await associateInvitationRepository.createLink(Number(a.id), `Link Buyer ${tag}`, 'Netherlands');
    const r = await request(app).get(`/invite/${inv.token}`);
    expect([r.status, r.headers.location]).toEqual([302, `/quotation-details?inv=${inv.token}`]);
    const form = await request(app).get(`/quotation-details?inv=${inv.token}`);
    expect(form.text).toContain(`<input type="hidden" name="associate_inv" value="${inv.token}">`); // where the redirect lands: the form carries the link

    expect((await request(app).get('/quotation-details/verify')).text).toContain('Link No Longer Valid'); // no submission in this browser
    // A submission made in this browser through the link: the form puts its id in the session and sends the buyer here.
    const agent = request.agent(app);
    const formPage = (await agent.get(`/quotation-details?inv=${inv.token}`)).text;
    const company = `Verify Buyer ${tag}`;
    const submitted = await agent.post('/quotation-details/submit').type('form').send({
      _csrf: csrfOf(formPage), associate_inv: inv.token, company_legal_name: company, billing_address_line1: '1 Harbour Road', billing_city: 'Rotterdam',
      billing_country: 'Netherlands', vat_eori_tax_no: `NL${crypto.randomBytes(5).toString('hex').toUpperCase()}`, contact_person: 'Jan Buyer',
      email: `jan-${tag.toLowerCase()}@buyer.example`, country_of_destination: 'Netherlands', incoterm_preference: 'FOB', consignee_same_as_buyer: '1', website: '',
    });
    expect([submitted.status, submitted.headers.location]).toEqual([302, '/quotation-details/verify']);
    const page = await agent.get('/quotation-details/verify');
    expect(page.status).toBe(200);
    expect(page.text).toContain('Confirm your request');
    expect(page.text).toContain(company);
    expect(page.text).toContain('name="code"');
  });

  // ---------------------------------------------------------------- staff: OC sent to buyer, inspection result

  it('POST /orders/:id/oc-sent-to-buyer', async () => {
    const original = await companySettingsRepository.get('mail_sending_enabled');
    await companySettingsRepository.set('mail_sending_enabled', '0');
    try {
      const orderId = await createTestOrder(await createTestClient());
      const agent = await staff();
      const page = `/orders/${orderId}`;
      expect((await post(agent, page, `/orders/${orderId}/oc-sent-to-buyer`)).message).toBe('Stage 4 has not been unlocked for this order yet — complete Stage 3 first.');
      for (let stage = 1; stage <= 3; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
      const noOc = await post(agent, page, `/orders/${orderId}/oc-sent-to-buyer`);
      expect(noOc.type).toBe('error');
      expect(noOc.message).toContain('still outstanding: Order Confirmation');
      expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeFalsy(); // no clock started without an approved OC
      const docId = await createTestDocument(orderId, 'OC', 'approved');
      await emailDispatchService.markSentIfMailDisabled(docId);
      const ok = await post(agent, page, `/orders/${orderId}/oc-sent-to-buyer`);
      expect(ok.type).toBe('success');
      expect(ok.message.startsWith('Order Confirmation marked as sent to the buyer.')).toBe(true);
      expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeTruthy();
      expect(await count("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'OC_MARKED_SENT_TO_BUYER' AND entity_id = :id", { id: orderId })).toBe(1);
      expect((await post(agent, page, `/orders/${orderId}/oc-sent-to-buyer`)).message).toBe('This Order Confirmation is already marked as sent to the buyer.');
    } finally {
      await companySettingsRepository.set('mail_sending_enabled', original);
    }
  });

  it('POST /orders/:id/inspection-result', async () => {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 6; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    const agent = await staff();
    const page = `/orders/${orderId}`;
    const url = `/orders/${orderId}/inspection-result`;
    const recorded = async () => count('SELECT COUNT(*) AS n FROM order_inspections WHERE order_id = :o', { o: orderId });
    expect((await post(agent, page, url, { inspection_result: 'maybe', inspected_on: '2026-10-30', agency_name: 'SGS India' })).message).toBe('Choose whether the inspection passed or failed.');
    expect((await post(agent, page, url, { inspection_result: 'passed', inspected_on: '2099-01-01', agency_name: 'SGS India' })).message).toBe('Enter the date of the inspection (today or earlier).');
    expect((await post(agent, page, url, { inspection_result: 'passed', inspected_on: '2026-10-30', agency_name: 'S' })).message).toBe('Enter the inspection agency (2 to 150 characters).');
    expect((await post(agent, page, url, { inspection_result: 'passed', inspected_on: '2026-10-30', agency_name: 'SGS India' })).message).toBe('Attach the inspection report (PDF or image).');
    expect(await recorded()).toBe(0); // nothing recorded on a refusal

    const csrf = csrfOf((await agent.get(page)).text);
    const ok = await agent.post(url).field('_csrf', csrf).field('inspection_result', 'passed').field('inspected_on', '2026-10-30').field('agency_name', 'SGS India')
      .attach('inspection_report', Buffer.from('%PDF-1.4 inspection report'), 'report.pdf');
    expect(ok.status).toBe(302);
    expect(await recorded()).toBe(1);
    expect((await db.queryOne('SELECT result, agency_name FROM order_inspections WHERE order_id = :o', { o: orderId }))).toEqual({ result: 'passed', agency_name: 'SGS India' });

    const other = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET status = 'lost' WHERE id = :o", { o: other });
    expect((await post(agent, `/orders/${other}`, `/orders/${other}/inspection-result`, { inspection_result: 'passed', inspected_on: '2026-10-30', agency_name: 'SGS India' })).message)
      .toBe('The inspection can be recorded only on an active order.');
  });
});
