'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const dotenv = require('dotenv');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateAdminController = require('../../src/controllers/associateAdminController');
const associatePortalController = require('../../src/controllers/associatePortalController');
const clientIntakeController = require('../../src/controllers/clientIntakeController');
const gstRefundController = require('../../src/controllers/gstRefundController');
const associateAuth = require('../../src/middleware/associateAuth');
const associateBankRepository = require('../../src/repositories/associateBankRepository');
const associateInvitationRepository = require('../../src/repositories/associateInvitationRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const gstRefundRepository = require('../../src/repositories/gstRefundRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const userRepository = require('../../src/repositories/userRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associateIntakeService = require('../../src/services/associateIntakeService');
const associatePortalService = require('../../src/services/associatePortalService');
const associateService = require('../../src/services/associateService');
const incentiveService = require('../../src/services/incentiveService');
const permissionService = require('../../src/services/permissionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of QaRound8Part1Test.php. QA Round 8, part 1 (1 Nov 2026): money
 * saved twice at once (R8-N11 GST refund credits, R8-N12 associate
 * claw-backs), the commission CSV (R8-N03), one-off invitation links
 * (R8-N04), the associate ladder without client numbers (R8-N05 replaced by D-245 P3), a suspended
 * associate's read-only portal (Q-R8-2, AS-3) and the bank e-mails (R8-N01).
 */
const PASSWORD = 'JestQaRound8!';

describe('QA Round 8, part 1', () => {
  let admin;
  let md;
  let finance;
  let tag;
  let session;

  const value = async (sql, params = {}) => {
    const row = await db.queryOne(sql, params);
    return row ? Object.values(row)[0] : null;
  };

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest R8 User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email: `jest-r8-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    return result.insertId;
  }

  beforeAll(async () => {
    admin = await createTestUser('Admin');
    md = await createTestUser('Managing Director');
    finance = await createTestUser('Finance Manager');
  });

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex');
    session = {};
    const values = {
      associate_bank_wait_days: '7', associate_protection_months: '12', associate_tds_pct: '2.00', cutoff_day: '20',
    };
    for (const [k, v] of Object.entries(values)) {
      await incentiveRepository.setSetting(k, v, admin);
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function call(fn, { as = null, params = {}, body = {}, query = {}, method = 'POST' } = {}) {
    const req = { params, body, query, session, method, ip: `10.8.${crypto.randomInt(0, 250)}.${crypto.randomInt(1, 250)}`, path: '/' };
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

  async function staffAgent(userId) {
    const user = await userRepository.findById(userId);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  async function associate(fields = {}) {
    const id = await associateRepository.create({
      name: `R8 Assoc ${tag} ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `r8-${crypto.randomBytes(4).toString('hex')}@assoc-${tag}.test`, status: 'active', ...fields,
    }, admin);
    await associateCodeService.issue(id, `Q${tag.toUpperCase()}${crypto.randomBytes(1).toString('hex')}`);
    return associateRepository.find(id);
  }

  async function client(name) {
    const id = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :n, vat_eori_tax_no = :v, email = :e WHERE id = :id', {
      n: name, v: `VAT${crypto.randomBytes(4).toString('hex').toUpperCase()}`, e: `buyer@${crypto.randomBytes(4).toString('hex')}.example`, id,
    });
    return id;
  }

  async function order(clientId) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [admin]);
    const next = await value('SELECT COALESCE(MAX(sequence_no), 0) + 1 AS n FROM orders WHERE client_id = :c', { c: clientId });
    await db.execute('UPDATE orders SET client_id = :c, sequence_no = :n WHERE id = :o', { c: clientId, n: next, o: orderId });
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
        balance_amount = 2700, balance_cleared_at = :b, balance_inr_actual = 250000, balance_exchange_rate = 92.5926 WHERE order_id = :o`,
      { a: '2026-10-10 10:00:00', b: '2026-10-25 11:00:00', o: orderId }
    );
    await associateService.onOrderCreated(orderId);
    return orderId;
  }

  async function bank(a, number, verified) {
    const id = await associateBankRepository.request(Number(a.id), { holder_name: a.name, account_number: number, ifsc: 'HDFC0001234', swift: '',
      bank_name: 'HDFC Bank', bank_branch_address: '', intermediary_bank: '', account_currency: 'INR' }, true, null);
    if (verified) {
      await associateBankRepository.firstCheck(id, md, 'Called back on the known number; ₹1 test credit confirmed.');
      await associateBankRepository.verify(id, finance, 0);
    }
    return id;
  }

  const lastEvent = (associateId, event) => value('SELECT message FROM associate_events WHERE associate_id = :a AND event = :e ORDER BY id DESC LIMIT 1', { a: associateId, e: event });

  // ---------------------------------------------------------------- R8-N11, R8-N12: one save at a time

  it('eight GST refund credits sent at once never credit more than the claim', async () => {
    const claimId = await gstRefundRepository.createClaim('2026-04-01', '2026-06-30', `AA${crypto.randomBytes(6).toString('hex').toUpperCase()}R`, '2026-07-10', 1000, admin);
    const out = await Promise.all(Array.from({ length: 8 }, (_, i) => gstRefundRepository.addCreditWithinClaim(claimId, 600, 0, '', associateService.todayIst(), `UTR${tag}${i}`, 'provisional', admin)));
    expect(out.filter((r) => r.id !== null)).toHaveLength(1);
    expect(out.filter((r) => r.id === null)).toHaveLength(7);
    const credits = await gstRefundRepository.creditsFor(claimId);
    expect(credits.map((c) => Number(c.amount))).toEqual([600]);

    await call(gstRefundController.recordCredit, { as: admin, params: { id: String(claimId) },
      body: { amount: '600', amount_refused: '', credited_on: associateService.todayIst(), bank_reference: `UTR${tag.toUpperCase()}`, part: 'final' } });
    expect(flash().message).toBe('Credited plus refused (₹600.00) is more than what is still open on this claim (₹400.00). Nothing was saved.');
    expect(await gstRefundRepository.creditsFor(claimId)).toHaveLength(1);
  });

  it('simultaneous claw-backs never take back more than was paid', async () => {
    const a = await associate();
    const c = await client(`R8 Claw Buyer ${tag}`);
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);
    const o = await order(c);
    const pid = await incentiveRepository.addPayout({ order_id: o, payee_kind: 'associate', associate_id: a.id, role: 'associate', amount_inr: 1000, amount_fc: null, currency_code: 'INR', incentive_month: '2026-10' });
    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-10-30', paid_inr = 1000, tds_inr = 0 WHERE id = :id", { id: pid });

    const out = await Promise.all(Array.from({ length: 8 }, () => incentiveRepository.addClawbackWithinPaid(pid, 600, '2026-11', 'QA R8 concurrency test')));
    expect(out.filter((r) => r.id !== null)).toHaveLength(1);
    expect(Number(await value('SELECT -SUM(amount_inr) AS t FROM incentive_payouts WHERE clawback_of_payout_id = :p', { p: pid }))).toBe(600);

    await call(associateAdminController.clawback, { as: admin, params: { payoutId: String(pid) }, body: { amount_inr: '600', reason: 'Breach: dealt with a competitor for this buyer.', takeback_kind: 'breach' } });
    expect(flash().message).toBe('Enter the amount to claw back, above 0 and at most what is left of the paid commission (₹400.00).');
    await call(associateAdminController.clawback, { as: admin, params: { payoutId: String(pid) }, body: { amount_inr: '400', reason: 'Breach: dealt with a competitor for this buyer.', takeback_kind: 'breach' } });
    expect(flash().type).toBe('success');
    expect(Number(await value('SELECT -SUM(amount_inr) AS t FROM incentive_payouts WHERE clawback_of_payout_id = :p', { p: pid }))).toBe(1000);
  });

  // ---------------------------------------------------------------- R8-N03: the portal's commission CSV

  it('the commission CSV neutralises a formula in a company name', async () => {
    const a = await associate();
    const c = await client('=HYPERLINK("http://evil.example","Click")');
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);
    const o = await order(c);
    await incentiveRepository.addPayout({ order_id: o, payee_kind: 'associate', associate_id: a.id, role: 'associate', amount_inr: 2500, amount_fc: null, currency_code: 'INR', incentive_month: '2026-10' });
    await associateRepository.setFields(Number(a.id), { portal_enabled: 1 });
    const agent = await staffAgent(admin);
    await agent.get(`/associates/${a.id}/view-as`);
    const res = await agent.get('/associate/commission?format=csv').buffer(true).parse((r, cb) => {
      let data = '';
      r.setEncoding('utf8');
      r.on('data', (chunk) => { data += chunk; });
      r.on('end', () => cb(null, data));
    });
    const csv = res.body;
    expect(csv.startsWith('﻿')).toBe(true);
    expect(csv).toContain('"\'=HYPERLINK(""http://evil.example"",""Click"")"');
    expect(csv).not.toContain(',"=HYPERLINK');
    expect(csv).not.toContain(',=HYPERLINK');
  });

  // ---------------------------------------------------------------- R8-N04: a one-off link registers one company

  const intakePost = (company, token) => ({
    company_legal_name: company, billing_address_line1: '1 Harbour Road', billing_city: 'Rotterdam', billing_country: 'Netherlands',
    vat_eori_tax_no: `NL${crypto.randomBytes(5).toString('hex').toUpperCase()}`, contact_person: 'Purchase Head', email: `buyer@${crypto.randomBytes(4).toString('hex')}-buyer.example`,
    country_of_destination: 'Netherlands', incoterm_preference: 'FOB', consignee_same_as_buyer: '1', website: '', associate_inv: token,
  });
  const submissionsFor = (associateId) => db.query('SELECT * FROM client_intake_submissions WHERE associate_id = :a ORDER BY id', { a: associateId });

  it('two registrations through one link: only the first is taken', async () => {
    const a = await associate();
    const inv = await associateInvitationRepository.createLink(Number(a.id), `Link One BV ${tag}`, 'Netherlands');
    for (const company of [`Link One BV ${tag}`, `Someone Else GmbH ${tag}`]) {
      session = {};
      await call(clientIntakeController.submit, { body: intakePost(company, inv.token) });
    }
    // Owner decision A6 (D-246): the buyer sees no error; the second request is saved as a direct buyer with a note for staff.
    expect(flash().message).not.toBe(associateIntakeService.LINK_USED);
    const subs = await submissionsFor(Number(a.id));
    expect(subs.map((s) => s.company_legal_name)).toEqual([`Link One BV ${tag}`]);
    expect(Number((await associateInvitationRepository.find(inv.id)).submission_id)).toBe(Number(subs[0].id));
    const direct = await db.queryOne('SELECT associate_id, associate_note FROM client_intake_submissions WHERE company_legal_name = :c', { c: `Someone Else GmbH ${tag}` });
    expect(direct.associate_id).toBeNull();
    expect(direct.associate_note).toContain('given but not applied');
    expect((await request(app).get(`/quotation-details?inv=${inv.token}`)).text).not.toContain('has expired or was already used'); // A6: the form opens normally

    const inv2 = await associateInvitationRepository.createLink(Number(a.id), `Race BV ${tag}`, 'Netherlands');
    const claims = await Promise.all([associateInvitationRepository.claim(inv2.id, Number(subs[0].id)), associateInvitationRepository.claim(inv2.id, Number(subs[0].id) + 1)]);
    expect(claims.filter(Boolean)).toHaveLength(1); // at the same moment, only one submission takes the link
  });

  it('accepting links only the submission recorded on the link, and staff see a name mismatch', async () => {
    const a = await associate();
    const inv = await associateInvitationRepository.createLink(Number(a.id), `Named Buyer BV ${tag}`, 'Netherlands');
    session = {};
    await call(clientIntakeController.submit, { body: intakePost(`Different Trading LLC ${tag}`, inv.token) });
    const s = (await submissionsFor(Number(a.id)))[0];
    const info = await associateIntakeService.reviewInfo(s);
    expect(info.flags).toContain(`The associate made this link for Named Buyer BV ${tag}, but the buyer registered as Different Trading LLC ${tag}.`);

    const other = { ...s, id: Number(s.id) + 100000, introduction_confirmed: 1 };
    const c = await client(`Other Copy ${tag}`);
    expect(await associateIntakeService.onAccepted(other, c, admin)).toBe('Not linked to the associate: their invitation link was used by another registration.');
    expect(await value('SELECT associate_id FROM clients WHERE id = :id', { id: c })).toBeNull();
  });

  // ---------------------------------------------------------------- R8-N05 replaced (D-245 P3): no client numbers; the ladder counts orders

  it('clients are not numbered and every order takes the ladder step for counting orders', async () => {
    const a = await associate();
    let o;
    for (let i = 1; i <= 7; i++) {
      const c = await client(`R8 Numbered ${i} ${tag}`);
      await associateService.linkClient(c, Number(a.id), 'code', null, `2026-10-0${i}`, admin);
      o = await order(c);
      const row = await db.queryOne('SELECT associate_rate_pct, associate_rate_source FROM orders WHERE id = :id', { id: o });
      expect([String(row.associate_rate_pct), row.associate_rate_source]).toEqual(['2.00', 'ladder']); // no counting order yet: step 1 for every client
    }
    expect((await incentiveService.calculate(o)).associate.level_label).toBe('Level 1 — 2%');
    expect(Number(await value("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'associate_client_number'"))).toBe(0);

    // The MD moves a client to another associate, then removes the link.
    const c3 = Number(await value('SELECT id FROM clients WHERE company_legal_name = :n', { n: `R8 Numbered 3 ${tag}` }));
    const b = await associate();
    await call(associateAdminController.unlinkClient, { as: md, params: { id: String(a.id), clientId: String(c3) }, body: { reason: 'The buyer says another agent introduced them.', move_to: String(b.id) } });
    expect(flash().type).toBe('success');
    expect(Number(await value('SELECT associate_id FROM clients WHERE id = :id', { id: c3 }))).toBe(Number(b.id));
    await call(associateAdminController.unlinkClient, { as: md, params: { id: String(b.id), clientId: String(c3) }, body: { reason: 'The agreement with this buyer was cancelled.', move_to: '' } });
    expect(await value('SELECT associate_id FROM clients WHERE id = :id', { id: c3 })).toBeNull();
  });

  // ---------------------------------------------------------------- Q-R8-2: a suspended associate's portal is read-only

  it('a suspended associate sees everything but cannot change anything', async () => {
    const a = await associate({ status: 'suspended' });
    await associateRepository.setFields(Number(a.id), { portal_enabled: 1 });
    session = { _associate_portal_id: Number(a.id) };
    let passed = false;
    const res = { statusCode: 200, status(c) { this.statusCode = c; return this; }, send(b) { this.body = b; }, redirect(l) { this.location = l; } };
    await associateAuth.required()({ session, method: 'POST' }, res, () => { passed = true; });
    expect(passed).toBe(true); // a suspended associate stays logged in

    const agent = await staffAgent(admin);
    await agent.get(`/associates/${a.id}/view-as`);
    for (const page of ['/associate', '/associate/clients', '/associate/orders', '/associate/commission', '/associate/profile', '/associate/messages']) {
      expect([page, (await agent.get(page)).text.includes(associatePortalService.SUSPENDED_BANNER)]).toEqual([page, true]);
    }
    expect(associatePortalService.SUSPENDED_BANNER).toBe('Your account is suspended — you can see your clients, orders and commission, but cannot invite clients or change your details.');

    const inv = await associateInvitationRepository.createLink(Number(a.id), 'Before Suspension Ltd', 'UK');
    const before = await associateRepository.find(Number(a.id));
    const refused = [
      ['createInvitation', {}, { company_name: 'New Buyer Ltd', country: 'UK' }],
      ['cancelInvitation', { id: String(inv.id) }, {}],
      ['chooseCode', {}, { readable: 'NEWCODE' }],
      ['saveProfile', {}, { phone: '+91 99999 00000', address: 'New address', pan: '', gstin: '' }],
      ['uploadDocument', {}, { kind: 'pan_card' }],
      ['requestBank', {}, { holder_name: 'X', account_number: '999988887777', ifsc: 'HDFC0001234', bank_name: 'HDFC Bank' }],
    ];
    for (const [action, params, body] of refused) {
      await call(associatePortalController[action], { params, body });
      const f = flash();
      expect([action, f.type, f.message]).toEqual([action, 'error', associatePortalService.SUSPENDED_REFUSED]);
    }
    expect(associatePortalService.SUSPENDED_REFUSED).toBe('Your account is suspended — you cannot invite clients or change your details. Please contact your NexaCrest relationship manager.');
    const after = await associateRepository.find(Number(a.id));
    expect([after.phone, after.personal_code]).toEqual([before.phone, before.personal_code]);
    expect(await associateInvitationRepository.forAssociate(Number(a.id))).toHaveLength(1);
    expect((await associateInvitationRepository.find(inv.id)).status).not.toBe('cancelled');
    expect(await associateBankRepository.forAssociate(Number(a.id))).toEqual([]);

    await call(associatePortalController.sendMessage, { body: { body: 'Why is my account suspended?' } });
    expect(flash().message).toBe('Message sent.'); // messages stay open

    const b = await associate();
    await associateRepository.setFields(Number(b.id), { portal_enabled: 1 });
    const agentB = await staffAgent(admin);
    await agentB.get(`/associates/${b.id}/view-as`);
    expect((await agentB.get('/associate')).text).not.toContain('Your account is suspended');
  });

  // ---------------------------------------------------------------- R8-N01: the bank e-mails

  it('the bank rejection e-mail has one full stop and names a verified account only when there is one', async () => {
    const a = await associate();
    const pending = await bank(a, '111122223333', false);
    await call(associateAdminController.rejectBank, { as: finance, params: { id: String(a.id), bankId: String(pending) }, body: { reason: 'Account holder name does not match the PAN.. ' } });
    expect(flash().type).toBe('success');
    expect(await lastEvent(Number(a.id), 'bank_rejected')).toBe('Your new bank details (HDFC Bank ••••3333) were not accepted: Account holder name does not match the PAN. No payment can be made until new bank details are verified.');

    await bank(a, '444455556666', true);
    const second = await bank(a, '777788889999', false);
    await call(associateAdminController.rejectBank, { as: finance, params: { id: String(a.id), bankId: String(second) }, body: { reason: 'The cancelled cheque is not readable.' } });
    expect(await lastEvent(Number(a.id), 'bank_rejected')).toBe('Your new bank details (HDFC Bank ••••9999) were not accepted: The cancelled cheque is not readable. Payments keep going to your verified account (••••6666).');
  });

  it('the payments sentence used in the bank change e-mails', async () => {
    const a = await associate();
    expect(await associateService.bankPaymentsSentence(Number(a.id))).toBe('No payment can be made until new bank details are verified.');
    await bank(a, '123412341234', false);
    expect(await associateService.bankPaymentsSentence(Number(a.id))).toBe('No payment can be made until new bank details are verified.'); // a pending request is not a verified account
    await bank(a, '567856785678', true);
    expect(await associateService.bankPaymentsSentence(Number(a.id))).toBe('Payments keep going to your verified account (••••5678).');
    expect(associateService.cleanReason(' Too short .. \n')).toBe('Too short');
  });
});
