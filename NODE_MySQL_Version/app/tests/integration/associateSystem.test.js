'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateAdminController = require('../../src/controllers/associateAdminController');
const associatePortalController = require('../../src/controllers/associatePortalController');
const clientIntakeController = require('../../src/controllers/clientIntakeController');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const incentiveController = require('../../src/controllers/incentiveController');
const associateAuth = require('../../src/middleware/associateAuth');
const associateBankRepository = require('../../src/repositories/associateBankRepository');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const associateEventRepository = require('../../src/repositories/associateEventRepository');
const associateInvitationRepository = require('../../src/repositories/associateInvitationRepository');
const associatePortalRepository = require('../../src/repositories/associatePortalRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const userRepository = require('../../src/repositories/userRepository');
const associateCodeService = require('../../src/services/associateCodeService');
const associateIntakeService = require('../../src/services/associateIntakeService');
const associatePortalService = require('../../src/services/associatePortalService');
const associateService = require('../../src/services/associateService');
const incentiveService = require('../../src/services/incentiveService');
const permissionService = require('../../src/services/permissionService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, createTestFile, completeAssociateKyc } = require('../support/fixtures');

/**
 * Port of AssociateSystemTest.php. Owner decision 1 Nov 2026 ("AS all as
 * recommended", AS-1 … AS-17): the associate system — personal codes and
 * one-off links, the buyer's own registration with e-mail code and
 * confirmation, first completed registration wins, the 12-month lock and a
 * direct approach caught; the standard ladder and personal rates fixed on
 * the order; commission only when the buyer has paid in full, pro rata on
 * short pay, held while suspended, clawed back after a refund; bank details
 * checked by two people with a waiting period; the portal shows only the
 * associate's own, never prices, costs or profit.
 *
 * Actions run the controllers in-process with a session object (as the PHP
 * test does); pages are opened through the real app so the HTML is checked.
 */
const PASSWORD = 'JestAssociate1!';

describe('Associate system (owner decision 1 Nov 2026)', () => {
  let admin;
  let md;
  let ed;
  let finance1;
  let finance2;
  let tag;
  let session;

  const todayIst = () => associateService.todayIst();
  const value = async (sql, params = {}) => {
    const row = await db.queryOne(sql, params);
    return row ? Object.values(row)[0] : null;
  };

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-assoc-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    return result.insertId;
  }

  beforeAll(async () => {
    admin = await createTestUser('Admin');
    md = await createTestUser('Managing Director');
    ed = await createTestUser('Executive Director');
    finance1 = await createTestUser('Finance Manager');
    finance2 = await createTestUser('Finance Director');
  });

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex');
    session = {};
    const values = {
      associate_bank_wait_days: '7', associate_protection_months: '12', associate_tds_pct: '2.00', cutoff_day: '20',
      associate_commission_due_days: '30', associate_claim_hold: '1', associate_payout_run: 'monthly', associate_payout_day: '5',
    };
    for (const [k, v] of Object.entries(values)) {
      await incentiveRepository.setSetting(k, v, admin);
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** Runs one controller action in-process as $userId (or with no staff user), sharing `session`. */
  async function call(fn, { as = null, params = {}, body = {}, query = {}, method = 'POST' } = {}) {
    const req = { params, body, query, session, method, ip: `10.9.${crypto.randomInt(0, 250)}.${crypto.randomInt(1, 250)}`, path: '/' };
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

  /** A staff agent signed in through the real login. */
  async function staffAgent(userId) {
    const user = await userRepository.findById(userId);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  async function associate(fields = {}) {
    const id = await associateRepository.create({
      name: `Assoc ${tag} ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `assoc-${crypto.randomBytes(4).toString('hex')}@assoc-${tag}.test`, status: 'active', ...fields,
    }, admin);
    await associateCodeService.issue(id, `R${tag.toUpperCase()}`);
    return associateRepository.find(id);
  }

  /** A client with its own name, VAT and e-mail domain, so the identity match sees only what the test means. */
  async function client(name) {
    const id = await createTestClient();
    await db.execute('UPDATE clients SET company_legal_name = :n, vat_eori_tax_no = :v, email = :e WHERE id = :id', {
      n: `${name} ${tag}`, v: `VAT${crypto.randomBytes(4).toString('hex').toUpperCase()}`, e: `buyer@${crypto.randomBytes(4).toString('hex')}.example`, id,
    });
    return id;
  }

  /** USD order of the client: products USD 4,500; advance USD 1,800 and balance USD `balance` (cleared if `cleared`). */
  async function order(clientId, balance = 2700, cleared = true) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [admin]);
    const next = await value('SELECT COALESCE(MAX(sequence_no), 0) + 1 AS n FROM orders WHERE client_id = :c', { c: clientId });
    await db.execute('UPDATE orders SET client_id = :c, sequence_no = :n WHERE id = :o', { c: clientId, n: next, o: orderId });
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
        balance_amount = :bal, balance_cleared_at = :b, balance_inr_actual = :binr, balance_exchange_rate = 92.5926 WHERE order_id = :o`,
      { a: '2026-10-10 10:00:00', bal: balance, b: cleared ? '2026-10-25 11:00:00' : null, binr: cleared ? Math.round(balance * 92.5926 * 100) / 100 : null, o: orderId }
    );
    await associateService.onOrderCreated(orderId);
    return orderId;
  }

  async function verifiedBank(a) {
    const id = await associateBankRepository.request(Number(a.id), { holder_name: a.name, account_number: '123456789012', ifsc: 'HDFC0001234', swift: '',
      bank_name: 'HDFC Bank', bank_branch_address: '', intermediary_bank: '', account_currency: 'INR' }, true, null);
    await associateBankRepository.firstCheck(id, finance1, 'Called back on the known number; ₹1 test credit confirmed.');
    await associateBankRepository.verify(id, finance2, 0);
    return id;
  }

  const associatePayout = async (orderId) => (await incentiveRepository.payoutsForOrder(orderId)).find((p) => p.payee_kind === 'associate');

  // ---------------------------------------------------------------- codes

  it('a personal code has a check letter and belongs to one associate for ever', async () => {
    const a = await associate();
    const code = String(a.personal_code);
    expect(code).toMatch(/^NXA-R[0-9A-Z]+-[A-Z0-9]{2}$/);
    expect(associateCodeService.isValid(code)).toBe(true);
    expect(Number((await associateCodeService.resolve(code.toLowerCase())).id)).toBe(Number(a.id)); // case does not matter
    expect(Number((await associateCodeService.resolve(` ${code.replace(/O/g, '0')} `)).id)).toBe(Number(a.id)); // O and 0 are the same
    const typo = code.slice(0, -1) + (code.slice(-1) === 'A' ? 'B' : 'A');
    expect(await associateCodeService.resolve(typo)).toBeNull(); // a mistyped check letter is caught

    const fresh = await associateCodeService.issue(Number(a.id), 'NEWNAME');
    // D-254 (owner, 4 Nov 2026): the old code keeps bringing buyers to the same associate…
    expect(Number((await associateCodeService.resolve(code)).id)).toBe(Number(a.id));
    expect(Number((await associateCodeService.resolve(fresh)).id)).toBe(Number(a.id));
    // …until staff block it.
    await associateCodeService.issue(Number(a.id), 'NEWER', true, 'Leaked in a public post');
    expect(await associateCodeService.resolve(fresh)).toBeNull(); // a blocked code no longer brings new clients
    expect(Number(await value('SELECT COUNT(*) AS n FROM associate_codes WHERE code = :c', { c: code }))).toBe(1); // the retired code is kept, never given to anyone else
  });

  // ---------------------------------------------------------------- rates (AS-17)

  // Owner decision 1 Nov 2026 (D-221): an empty rung carries on the last personal rate below it, else the standard ladder.
  const level = async (name) => Number(await value('SELECT id FROM commission_levels WHERE name = :n', { n: name }));

  // Owner decision D-245 P3: the commission level is the associate's fixed level or the ladder step; none when marked "no commission".
  it('the commission level is the fixed level or the ladder, and no commission', async () => {
    const associateCommission = require('../../src/services/associateCommission');
    const ladderA = await associate();
    expect(associateCommission.levelLabel((await associateCommission.levelForAssociate(ladderA, null))[0])).toBe('Level 1 — 2%');
    const fixed = await associate();
    await pricingRepository.setAssociateCommission(Number(fixed.id), 'fixed', await level('Level 3'));
    expect(associateCommission.levelLabel((await associateCommission.levelForAssociate(await associateRepository.find(Number(fixed.id)), null))[0])).toBe('Level 3 — 7%');
    const none = await associate({ no_commission: 1 });
    const c = await client('No Level Buyer');
    await associateService.linkClient(c, Number(none.id), 'code', null, '2026-10-01', admin);
    expect((await incentiveService.calculate(await order(c))).associate).toBeNull();
  });

  it('the level is fixed at the PI and earned only when the buyer has paid everything', async () => {
    const associateCommission = require('../../src/services/associateCommission');
    const a = await associate();
    await pricingRepository.setAssociateCommission(Number(a.id), 'fixed', await level('Level 2'));
    const c = await client('Rate Fixed Buyer');
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);
    const orderId = await order(c, 2700, false);
    const row = await db.queryOne('SELECT associate_id, associate_rate_pct, associate_rate_source FROM orders WHERE id = :id', { id: orderId });
    expect([String(row.associate_id), row.associate_rate_pct, row.associate_rate_source]).toEqual([String(a.id), '5.00', 'fixed']);
    expect(await associateEventRepository.hasEvent(Number(a.id), 'order_created', c, orderId)).toBe(true); // the associate is told of the new order

    await associateCommission.fixLevelAtPi(orderId);
    await pricingRepository.setAssociateCommission(Number(a.id), 'fixed', await level('Level 4'));
    await incentiveService.lockIfReady(orderId, admin);
    expect(await incentiveRepository.forOrder(orderId)).toBeNull(); // advance alone: nothing earned
    const line = (await associatePortalRepository.orders(Number(a.id), orderId))[0].commission;
    expect([line.state, line.rate, line.amount, line.level]).toEqual(['expected', 5, 225, 'Level 2 — 5%']); // the order keeps the level fixed at its PI

    await db.execute("UPDATE order_payment_status SET balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000 WHERE order_id = :o", { o: orderId });
    await incentiveService.lockIfReady(orderId, admin);
    const p = await associatePayout(orderId);
    expect([p.amount_fc, p.currency_code, p.status, p.incentive_month, p.due_on, p.payout_run_on]).toEqual(['225.00', 'USD', 'due', '2026-12', '2026-11-24', '2026-12-05']);
    expect(await associateEventRepository.hasEvent(Number(a.id), 'commission_earned', c, orderId)).toBe(true);
    expect((await associatePortalRepository.orders(Number(a.id), orderId))[0].commission.state).toBe('earned');
  });

  it('no-commission associate, a cancelled order, and an unpaid shortfall earns nothing', async () => {
    const none = await associate({ no_commission: 1 });
    const c1 = await client('No Commission Buyer');
    await associateService.linkClient(c1, Number(none.id), 'code', null, '2026-10-01', admin);
    const o1 = await order(c1);
    expect((await incentiveService.calculate(o1)).associate).toBeNull(); // no commission under the agreement
    expect((await associatePortalRepository.orders(Number(none.id), o1))[0].commission.label).toBe('No commission under your agreement');

    // D-245 P3: no pro rata — a buyer who did not pay in full earns the associate nothing.
    const a = await associate();
    const c2 = await client('Short Pay Buyer');
    await associateService.linkClient(c2, Number(a.id), 'code', null, '2026-10-01', admin);
    const o2 = await order(c2, 2250);
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                      VALUES (:o, 'balance', 2700, 2250, 450, 'written_off', 'The buyer did not pay the rest', :u, 'unpaid')`, { o: o2, u: admin });
    const calc = (await incentiveService.calculate(o2)).associate;
    expect([calc.state, calc.amount_fc]).toEqual(['unpaid', 0]); // USD 4,050 of 4,500 received: nothing
    await incentiveService.lockIfReady(o2, admin);
    expect(await associatePayout(o2)).toBeUndefined();
    expect((await associatePortalRepository.orders(Number(a.id), o2))[0].commission.label).toBe('No commission — the buyer did not pay this order in full');

    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :o", { o: o1 });
    expect((await associatePortalRepository.orders(Number(none.id), o1))[0].commission.label).toBe('No commission — order cancelled');
  });

  // ---------------------------------------------------------------- bank (AS-7)

  it('bank details need two different people and a wait before the first payment', async () => {
    const a = await associate();
    const post = { holder_name: 'X', account_number: '123456789012', account_number_confirm: '123456789013', ifsc: 'HDFC0001234', bank_name: 'HDFC Bank', account_currency: 'INR' };
    expect(associateAdminController.validateBank(a, post)).toBe('Enter the account holder\'s name as the bank has it (as on the PAN).');
    post.holder_name = 'Ravi Kumar';
    expect(associateAdminController.validateBank(a, post)).toBe('The two account numbers do not match — type it again.');
    post.account_number_confirm = '1234 5678 9012';
    post.ifsc = 'HDFC1234';
    expect(associateAdminController.validateBank(a, post)).toBe('Enter the 11-character IFSC (e.g. HDFC0001234).');
    expect(associateAdminController.validateBank({ ...a, based_in: 'abroad' }, { holder_name: 'R K', account_number: 'DE89370400440532013000', account_number_confirm: 'DE89370400440532013000', bank_name: 'Deutsche Bank', account_currency: 'EUR' }))
      .toBe('Enter the bank\'s SWIFT / BIC (8 or 11 characters).');

    const old = await verifiedBank(a);
    const id = await associateBankRepository.request(Number(a.id), { holder_name: 'Ravi Kumar', account_number: '999988887777', ifsc: 'ICIC0001234', swift: '',
      bank_name: 'ICICI Bank', bank_branch_address: '', intermediary_bank: '', account_currency: 'INR' }, true, null);
    expect(await value('SELECT account_number_enc FROM associate_bank_accounts WHERE id = :id', { id })).not.toBe('999988887777'); // stored encrypted
    expect(await associateBankRepository.fullNumber(id)).toBe('999988887777');

    const params = { id: String(a.id), bankId: String(id) };
    await call(associateAdminController.firstCheck, { as: finance1, params, body: { check_method: 'Called back on the known number; ₹1 test credit confirmed.' } });
    expect(flash().type).toBe('success');
    await call(associateAdminController.verifyBank, { as: finance1, params });
    expect(flash().message).toBe('A different person must verify — you already acted on this request.');
    await call(associateAdminController.verifyBank, { as: finance2, params });
    expect(flash().message).toMatch(/^Verified\. Usable from /);
    expect(Number((await associateBankRepository.usable(Number(a.id))).id)).toBe(old); // during the 7 days the old verified account is still used
    expect(Number((await associateBankRepository.usable(Number(a.id), associateService.addDays(todayIst(), 7))).id)).toBe(id); // then the new one
    expect(await associateEventRepository.hasEvent(Number(a.id), 'bank_changed', null, null)).toBe(true); // the associate is e-mailed
  });

  it('an associate is paid only into a verified account, and a claw-back comes off the next payment', async () => {
    const a = await associate();
    const c1 = await client('Pay Buyer One');
    const c2 = await client('Pay Buyer Two');
    await associateService.linkClient(c1, Number(a.id), 'code', null, '2026-10-01', admin);
    await associateService.linkClient(c2, Number(a.id), 'code', null, '2026-10-01', admin);
    const o1 = await order(c1);
    await incentiveService.lockIfReady(o1, admin);
    const p1 = await associatePayout(o1);

    const pay = { month: '2026-11', paid_on: todayIst(), paid_via: 'bank_transfer', paid_reference: `UTR${tag.toUpperCase()}01` };
    await call(incentiveController.markPaid, { as: admin, params: { payoutId: String(p1.id) }, body: pay });
    expect(flash().message).toMatch(/^This associate has no verified bank account usable on /);
    await verifiedBank(a);
    await completeAssociateKyc(a.id); // D-252: paid only once the KYC is complete
    // D-245 P3: paid only from its due date (30 days after full payment).
    await call(incentiveController.markPaid, { as: admin, params: { payoutId: String(p1.id) }, body: { ...pay, month: p1.incentive_month } });
    expect(flash().message).toMatch(/^This commission is due on 24 Nov 2026/);
    await db.execute("UPDATE incentive_payouts SET due_on = '2026-10-30', incentive_month = '2026-11' WHERE order_id = :o", { o: o1 });
    await call(incentiveController.markPaid, { as: admin, params: { payoutId: String(p1.id) },
      // D-235: below the ₹20,000 threshold the app works out no TDS; a different figure needs a reason.
      body: { ...pay, paid_inr: String(Math.round(Number(p1.amount_inr) * 98) / 100), tds_inr: String(Math.round(Number(p1.amount_inr) * 2) / 100), tds_override_reason: 'CA advised deducting from the first payment.' } });
    expect(flash().message).toBe('Payment recorded.');
    expect(await associateEventRepository.hasEvent(Number(a.id), 'payout_paid', null, o1)).toBe(true);

    const paid = await incentiveRepository.findPayout(Number(p1.id));
    // D-245 P3: a later refund to the buyer does not take commission back — only fraud or breach does.
    await call(associateAdminController.clawback, { as: admin, params: { payoutId: String(p1.id) }, body: { amount_inr: '1000', reason: 'Buyer refunded 20% for a damaged crate.' } });
    expect(flash().message).toContain('only for the associate\'s fraud or breach');
    await call(associateAdminController.clawback, { as: admin, params: { payoutId: String(p1.id) }, body: { amount_inr: '1000', reason: 'Invented a second buyer quote to inflate the order.', takeback_kind: 'fraud' } });
    expect(flash().type).toBe('success');
    let claw = await db.queryOne('SELECT * FROM incentive_payouts WHERE clawback_of_payout_id = :id', { id: p1.id });
    expect([claw.amount_inr, claw.status, claw.takeback_kind]).toEqual(['-1000.00', 'due', 'fraud']);
    expect(String(claw.note)).toMatch(/^Taken back for fraud: commission paid on order /);
    await call(associateAdminController.clawback, { as: admin, params: { payoutId: String(p1.id) }, body: { amount_inr: String(Number(paid.paid_inr)), reason: 'Breach found later as well.', takeback_kind: 'breach' } });
    expect(flash().message).toMatch(/^Enter the amount to claw back, above 0 and at most what is left/); // never more than was paid

    await db.execute('UPDATE incentive_payouts SET incentive_month = :m WHERE id = :id', { m: '2026-11', id: claw.id });
    const o2 = await order(c2);
    await incentiveService.lockIfReady(o2, admin);
    await db.execute("UPDATE incentive_payouts SET due_on = '2026-10-30', incentive_month = '2026-11' WHERE order_id = :o", { o: o2 });
    const p2 = await associatePayout(o2);
    await call(incentiveController.markPaid, { as: admin, params: { payoutId: String(p2.id) },
      body: { month: '2026-11', paid_on: todayIst(), paid_via: 'bank_transfer', paid_reference: `UTR${tag.toUpperCase()}02`, paid_inr: String(Number(p2.amount_inr) - 1000) } });
    claw = await incentiveRepository.findPayout(Number(claw.id));
    expect([claw.status, claw.paid_reference]).toEqual(['paid', `Deducted from payout no. ${p2.id}`]);
  });

  it('suspension holds commission until released', async () => {
    const a = await associate();
    const c = await client('Held Buyer');
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);
    await verifiedBank(a);
    await call(associateAdminController.changeStatus, { as: md, params: { id: String(a.id) }, body: { status: 'suspended', reason: 'Agreement under review.' } });
    expect(flash().message).toBe('Status changed to Suspended.');
    const o = await order(c);
    await incentiveService.lockIfReady(o, admin);
    const p = await associatePayout(o);
    expect([p.status, p.hold_reason]).toEqual(['held', 'The associate is suspended.']);
    expect((await associatePortalRepository.orders(Number(a.id), o))[0].commission.state).toBe('held');
    await call(associateAdminController.releasePayout, { as: md, params: { payoutId: String(p.id) } });
    expect((await incentiveRepository.findPayout(Number(p.id))).status).toBe('due');
  });

  // ---------------------------------------------------------------- the buyer's own registration (AS-5)

  const intakePost = (company, extra = {}) => ({
    company_legal_name: company, billing_address_line1: '1 Harbour Road', billing_city: 'Rotterdam', billing_country: 'Netherlands',
    vat_eori_tax_no: `NL${crypto.randomBytes(5).toString('hex').toUpperCase()}`, contact_person: 'Jan Buyer', email: `jan@${crypto.randomBytes(4).toString('hex')}-buyer.example`,
    country_of_destination: 'Netherlands', incoterm_preference: 'FOB', consignee_same_as_buyer: '1', website: '', ...extra,
  });

  /** Submits the public form; returns the submission id and the e-mail code. */
  async function submit(post) {
    session = {};
    await call(clientIntakeController.submit, { body: post });
    const id = Number(await value('SELECT MAX(id) AS id FROM client_intake_submissions'));
    let code = null;
    for (const f of session._flash || []) {
      const m = f.message.match(/the e-mail code is (\d{6})/);
      if (m) code = m[1];
    }
    if (code === null && session[associateIntakeService.SESSION_KEY]) {
      code = await associateIntakeService.sendEmailCode(id, String(post.email));
    }
    return [id, code];
  }

  async function verify(id, code, introduced) {
    session[associateIntakeService.SESSION_KEY] = id;
    await call(clientIntakeController.verify, { body: { code, introduced } });
  }

  async function accept(id) {
    delete session._flash;
    await call(clientIntakeReviewController.accept, { as: admin, params: { id: String(id) } });
    return flash();
  }

  it('a one-off link: the buyer verifies and confirms, staff accept, and the twelve-month lock starts', async () => {
    const a = await associate();
    const inv = await associateInvitationRepository.createLink(Number(a.id), 'Link Buyer BV', 'Netherlands');
    const form = (await request(app).get(`/quotation-details?inv=${inv.token}`)).text;
    // Owner decision 1 Nov 2026 (revised): the link travels as a hidden field — nothing for the buyer to tick or type.
    expect(form).toContain(`<input type="hidden" name="associate_inv" value="${inv.token}">`);
    expect(form).not.toContain('introduction_confirmed');
    expect(form).not.toContain(a.name); // the associate is not named on the buyer's form
    expect((await associateInvitationRepository.find(inv.id)).status).toBe('opened');

    const company = `Link Buyer BV ${tag}`;
    const [id, code] = await submit(intakePost(company, { associate_inv: inv.token }));
    expect(code).not.toBeNull();
    expect((await accept(id)).message).toContain('has not yet verified their e-mail'); // not verified: cannot be accepted yet

    await verify(id, code === '000000' ? '111111' : '000000', 'yes');
    expect(flash().message).toBe('That code is not correct.');
    await verify(id, code, 'yes');
    expect((await associateInvitationRepository.find(inv.id)).status).toBe('registered');

    const f = await accept(id);
    expect(f.message).toContain(`Linked to associate ${a.name} — protected until `);
    const c = await db.queryOne('SELECT * FROM clients WHERE company_legal_name = :n', { n: company });
    expect([String(c.associate_id), c.associate_source, String(c.associate_locked_until).slice(0, 10)]).toEqual([String(a.id), 'link', associateService.addMonths(todayIst(), 12)]);
    expect((await associateInvitationRepository.find(inv.id)).status).toBe('accepted');
    expect(await associateEventRepository.hasEvent(Number(a.id), 'client_accepted', Number(c.id), null)).toBe(true);

    // A6 (4 Nov 2026): a used link shows the buyer the normal form, no error; it travels silently so staff see it.
    const usedForm = (await request(app).get(`/quotation-details?inv=${inv.token}`)).text;
    expect(usedForm).not.toContain('expired');
    expect(usedForm).toContain(`<input type="hidden" name="associate_inv" value="${inv.token}">`);
  });

  it('the personal code, typed or in the link, and what is refused', async () => {
    const a = await associate();
    const company = `Code Buyer GmbH ${tag}`;
    const [id, code] = await submit(intakePost(company, { associate_code: String(a.personal_code).toLowerCase() }));
    expect(Number((await associateIntakeService.submission(id)).associate_id)).toBe(Number(a.id)); // a code typed in lower case works
    await verify(id, String(code), 'yes');
    await accept(id);
    const c = await db.queryOne('SELECT associate_id, associate_source FROM clients WHERE company_legal_name = :n', { n: company });
    expect([String(c.associate_id), c.associate_source]).toEqual([String(a.id), 'code']);
    const inv = (await associateInvitationRepository.forAssociate(Number(a.id)))[0];
    expect([inv.kind, inv.status]).toEqual(['personal_code', 'accepted']); // a code registration shows like any invitation

    // Owner decision 4 Nov 2026 (A6): a bad code never stops the buyer — they register as a direct buyer and staff see why.
    const pc = String(a.personal_code);
    const typo = pc.slice(0, -1) + (pc.slice(-1) === 'A' ? 'B' : 'A');
    const [typoId] = await submit(intakePost(`Typo Buyer ${tag}`, { associate_code: typo }));
    const typoRow = await associateIntakeService.submission(typoId);
    expect([typoRow.company_legal_name, typoRow.associate_id]).toEqual([`Typo Buyer ${tag}`, null]); // saved as a direct buyer
    expect(String(typoRow.associate_note)).toContain('given but not applied: the code is not valid (mistyped?).');

    const suspended = await associate({ status: 'suspended' });
    const [suspId] = await submit(intakePost(`Suspended Code Buyer ${tag}`, { associate_code: String(suspended.personal_code) }));
    expect((await associateIntakeService.submission(suspId)).associate_id).toBeNull();
    expect(String((await associateIntakeService.submission(suspId)).associate_note)).toContain('given but not applied: the associate is suspended.');
  });

  it('the quotation form has no associate checkbox or code field', async () => {
    const plain = (await request(app).get('/quotation-details')).text;
    for (const never of ['associate_code', 'associate_inv', 'introduction_confirmed', 'Associate code']) {
      expect(plain).not.toContain(never);
    }
    const a = await associate();
    const withCode = (await request(app).get(`/quotation-details?code=${encodeURIComponent(a.personal_code)}`)).text;
    expect(withCode).toContain(`<input type="hidden" name="associate_code" value="${a.personal_code}">`);
    expect(withCode).not.toContain('type="checkbox" name="introduction_confirmed"');
    const company = `Hidden Code Buyer ${tag}`;
    const [id, code] = await submit(intakePost(company, { associate_code: String(a.personal_code) }));
    expect(Number((await associateIntakeService.submission(id)).introduction_confirmed)).toBe(1); // the link or code is enough
    await verify(id, String(code), '');
    expect((await accept(id)).message).toContain(`Linked to associate ${a.name}`);
  });

  it('two associates claim one buyer: the first completed registration wins', async () => {
    const first = await associate();
    const second = await associate();
    const company = `Contested Buyer SA ${tag}`;
    const vat = `FR${crypto.randomBytes(5).toString('hex').toUpperCase()}`;
    const [idB, codeB] = await submit(intakePost(company, { associate_code: String(second.personal_code), vat_eori_tax_no: vat }));
    const [idA, codeA] = await submit(intakePost(company, { associate_code: String(first.personal_code), vat_eori_tax_no: vat }));
    await verify(idA, String(codeA), 'yes');
    await new Promise((r) => { setTimeout(r, 1100); });
    await verify(idB, String(codeB), 'yes');
    expect((await accept(idB)).message).toContain('the first completed registration counts, so decide that one first'); // B submitted first but verified second
    await accept(idA);
    const c = await db.queryOne('SELECT id, associate_id FROM clients WHERE vat_eori_tax_no = :v', { v: vat });
    expect(String(c.associate_id)).toBe(String(first.id));
    expect((await associateIntakeService.reviewInfo(await associateIntakeService.submission(idB))).flags)
      .toContain(`Same company as ${company}, already the client of associate ${first.name}.`);
  });

  it('a direct approach is recognised, and only the MD changes the link', async () => {
    const a = await associate();
    const other = await associate();
    const c = await client('Loyal Buyer');
    const row = await db.queryOne('SELECT * FROM clients WHERE id = :id', { id: c });
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);

    await submit(intakePost('Other name', { vat_eori_tax_no: String(row.vat_eori_tax_no) }));
    expect(await associateEventRepository.hasEvent(Number(a.id), 'direct_contact', c, null)).toBe(true); // the same VAT number without the code: the associate is told

    const params = { id: String(a.id), clientId: String(c) };
    await call(associateAdminController.unlinkClient, { as: ed, params, body: { reason: 'The buyer says another agent introduced them.', move_to: String(other.id) } });
    expect(flash().message).toBe('Only the Managing Director can change or remove a client\'s associate.');
    expect(String(await value('SELECT associate_id FROM clients WHERE id = :id', { id: c }))).toBe(String(a.id));

    await call(associateAdminController.unlinkClient, { as: md, params, body: { reason: 'short', move_to: '' } });
    expect(flash().message).toBe('Write the reason (10 to 300 characters) — the associate is shown it.');
    await call(associateAdminController.unlinkClient, { as: md, params, body: { reason: 'The buyer says another agent introduced them.', move_to: String(other.id) } });
    expect(String(await value('SELECT associate_id FROM clients WHERE id = :id', { id: c }))).toBe(String(other.id));
    expect(await associateEventRepository.hasEvent(Number(a.id), 'link_changed', c, null)).toBe(true); // the old associate is told
    expect(await associateEventRepository.hasEvent(Number(other.id), 'link_changed', c, null)).toBe(true); // and the new one
  });

  it('reminders before the protection ends', async () => {
    const a = await associate();
    const c = await client('Quiet Buyer');
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-01-01', admin);
    await db.execute('UPDATE clients SET associate_locked_until = :d WHERE id = :id', { d: associateService.addDays(todayIst(), 30), id: c });
    await associateService.dailyReminders();
    expect(await associateEventRepository.hasEvent(Number(a.id), 'lock_ending_30', c, null)).toBe(true);
    const count = async () => Number(await value("SELECT COUNT(*) AS n FROM associate_events WHERE associate_id = :a AND event = 'lock_ending'", { a: a.id }));
    const before = await count();
    await associateService.dailyReminders();
    expect(await count()).toBe(before); // once only
  });

  // ---------------------------------------------------------------- the portal (AS-1 … AS-4, AS-8)

  it('two-step login, and a lock after five wrong passwords', async () => {
    const a = await associate();
    await associateRepository.setFields(Number(a.id), { portal_enabled: 1 });
    const token = await associatePortalService.issueToken(Number(a.id), 'set_password', 60);
    expect(await associatePortalService.setPassword(token, 'Secret12345')).toBe(true);
    expect(await associatePortalService.setPassword(token, 'Secret12345')).toBe(false); // the link works once

    const req = { session: {}, ip: '127.0.0.1' };
    const r = await associatePortalService.attemptLogin(req, String(a.email), 'Secret12345');
    expect(r.status).toBe('code_sent');
    expect(associatePortalService.currentId(req)).toBeNull(); // not in before the code
    expect(await associatePortalService.verifyCode(req, r.code === '000000' ? '000001' : '000000')).toBe(false);
    expect(await associatePortalService.verifyCode(req, r.code)).toBe(true);
    expect(associatePortalService.currentId(req)).toBe(Number(a.id));

    for (let i = 0; i < 5; i++) {
      expect((await associatePortalService.attemptLogin(req, String(a.email), 'wrong')).status).toBe('invalid');
    }
    expect((await associatePortalService.attemptLogin(req, String(a.email), 'Secret12345')).status).toBe('locked');
  });

  it('the portal shows only their own, and never prices, costs, profit or CAFIN', async () => {
    const a = await associate();
    const b = await associate();
    const ca = await client('Portal Buyer A');
    const cb = await client('Portal Buyer B');
    await associateService.linkClient(ca, Number(a.id), 'code', null, '2026-10-01', admin);
    await associateService.linkClient(cb, Number(b.id), 'code', null, '2026-10-01', admin);
    const oa = await order(ca);
    const ob = await order(cb);
    await associateRepository.setFields(Number(a.id), { portal_enabled: 1 });
    // The associate's own portal, opened read-only through staff "view as" (the pages are the same).
    const agent = await staffAgent(admin);
    await agent.get(`/associates/${a.id}/view-as`);
    const strip = (html) => html.replace(/<[^>]+>/g, ' ').toLowerCase();
    const orders = (await agent.get('/associate/orders')).text;
    expect(orders).toContain(`/associate/orders/${oa}`);
    expect(orders).not.toContain(`/associate/orders/${ob}`);
    expect(orders).toContain('USD 4,500.00'); // the FOB value
    for (const never of ['unit price', 'profit', 'cafin', 'supplier', 'margin', '45.00', '150,000', '250,000']) {
      expect([never, strip(orders).includes(never)]).toEqual([never, false]);
    }
    const page = (await agent.get(`/associate/orders/${oa}`)).text;
    for (const never of ['unit price', 'profit', 'cafin', 'supplier', '1,800', '2,700']) {
      expect([never, strip(page).includes(never)]).toEqual([never, false]);
    }
    expect(page).toContain('Buyer paid in full');
    const otherOrder = await agent.get(`/associate/orders/${ob}`);
    expect(otherOrder.status).toBe(404); // another associate's order
    expect(otherOrder.text).toContain('Order not found.');
    expect(otherOrder.text).toContain('Back to your home page'); // shown inside the portal with a way back home
    expect((await agent.get('/associate/commission?format=csv')).text).not.toContain('Portal Buyer B');
    expect((await agent.get(`/associate/clients/${ca}/certificate`)).text).toContain('Client registration certificate');
    expect((await agent.get(`/associate/clients/${cb}/certificate`)).text).toContain('Client not found.');
  });

  it('staff "view as" is read-only', async () => {
    const a = await associate();
    const req = { session: {}, method: 'GET' };
    await associatePortalService.startViewAs(req, admin, Number(a.id));
    let passed = false;
    const res = { statusCode: 200, status(c) { this.statusCode = c; return this; }, send(b) { this.body = b; }, redirect(l) { this.location = l; } };
    await associateAuth.required()(req, res, () => { passed = true; });
    expect(passed).toBe(true);
    req.method = 'POST';
    passed = false;
    await associateAuth.required()(req, res, () => { passed = true; });
    expect([passed, res.statusCode, res.body]).toEqual([false, 403, 'Read-only: you are viewing this portal as staff.']);
  });

  it('an invited or suspended associate cannot invite clients', async () => {
    const a = await associate({ status: 'invited' });
    session = { _associate_portal_id: Number(a.id) };
    await call(associatePortalController.createInvitation, { body: { company_name: 'Too Early Ltd', country: 'UK' } });
    expect(flash().message).toBe('You can invite clients once your account is active.');
    expect(await associateInvitationRepository.forAssociate(Number(a.id))).toEqual([]);
    await call(associateAdminController.changeStatus, { as: md, params: { id: String(a.id) }, body: { status: 'active', reason: 'KYC and agreement done.' } });
    expect(flash().message).toBe('An associate becomes Active once Accounts have verified their bank details (two people).');
  });

  // ---------------------------------------------------------------- staff pages, permissions, Zoho wording

  it('who sees what on the staff side', async () => {
    const a = await associate();
    await pricingRepository.setAssociateCommission(Number(a.id), 'fixed', await level('Level 3'));
    const c = await client('Visible Buyer');
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);
    const sales = await createTestUser('Export Executive');
    const salesAgent = await staffAgent(sales);
    const list = (await salesAgent.get('/associates?view=list')).text;
    expect(list).toContain(a.name);
    expect(list).not.toContain('Commission terms'); // Sales: who introduced whom, no amounts
    let show = (await salesAgent.get(`/associates/${a.id}`)).text;
    expect(show).toContain('Visible Buyer');
    for (const never of ['Level 3', 'Bank details', 'Payouts', 'Show full number']) {
      expect([never, show.includes(never)]).toEqual([never, false]); // Sales never sees it
    }
    show = (await (await staffAgent(ed)).get(`/associates/${a.id}`)).text;
    expect(show).toContain('Fixed level: <b>Level 3</b>');
    expect(show).not.toContain('Show full number'); // the full bank number is Finance / MD only
    const role = async (name) => Number(await value('SELECT id FROM roles WHERE name = :n', { n: name }));
    expect(!!(await permissionService.load(sales, await role('Export Executive'))).manage_associates).toBe(false);
    expect(!!(await permissionService.load(finance1, await role('Finance Manager'))).manage_associate_bank).toBe(true);
    expect(!!(await permissionService.load(finance1, await role('Finance Manager'))).manage_associates).toBe(false);
  });

  it('the Zoho bill and payment say what they are', async () => {
    const a = await associate({ name: `TEST-Assoc ${tag}` });
    const c = await client('Zoho Buyer');
    await associateService.linkClient(c, Number(a.id), 'code', null, '2026-10-01', admin);
    await verifiedBank(a);
    const o = await order(c);
    await incentiveService.lockIfReady(o, admin);
    const p = await associatePayout(o);
    await incentiveService.markPaid(Number(p.id), todayIst(), 2744.0, 56.0, admin, 'bank_transfer', 'UTR777888');
    const fileId = await createTestFile(null, null);
    await associateDocumentRepository.add(Number(a.id), 'invoice', fileId, Number(p.id), 'RK/24-25/017', true, null);
    const ref = await value('SELECT buyer_inquiry_ref FROM orders WHERE id = :id', { id: o });
    const z = await zohoBooksService.associateCommission({ order_id: o, buyer_inquiry_ref: ref, buyer_name: `Zoho Buyer ${tag}` });
    expect(z.bill_description).toBe(`Associate commission — ${a.name} — for order ${ref} (Zoho Buyer ${tag}), 2% on the invoiced FOB of the goods (basis USD 4500.00) · associate invoice RK/24-25/017 · TDS 56.00 deducted`);
    expect(z.payment_description).toBe(`Associate commission for ${a.name} — order ${ref} — gross ₹2,800.00, TDS u/s 194H ₹56.00, net paid ₹2,744.00 · UTR UTR777888 · against bill RK/24-25/017`);
    expect([z.bill_no, z.gross_inr, z.paid_inr, z.tds_inr, z.reference]).toEqual(['RK/24-25/017', 2800, 2744, 56, 'UTR777888']);
  });

  it('the register and report open', async () => {
    const a = await associate();
    const agent = await staffAgent(md);
    expect((await agent.get('/associates?view=list')).text).toContain('Add an associate');
    expect((await agent.get('/associates/ladder')).text).toContain('When commission is paid');
    expect((await agent.get('/reports/associates')).text).toContain('TDS register');
    expect((await agent.get(`/associates/${a.id}`)).text).toContain('Change status');
    const base = { associate_tds_pct: '2', associate_bank_wait_days: '7', associate_commission_due_days: '30', associate_payout_run: 'monthly', associate_payout_day: '40',
      associate_counting_threshold_inr: '500000', associate_protection_months: '6', associate_gst_pct: '18', associate_claim_hold: '1', associate_portal_commission_months: '12' };
    const associateCommission = require('../../src/services/associateCommission');
    await call(associateAdminController.saveLadder, { as: md, body: base });
    expect(flash().message).toBe('Choose the payout day: 1 to 28 for a monthly run, a weekday for a weekly run.');
    await call(associateAdminController.saveLadder, { as: md, body: { ...base, associate_payout_day: '10' } });
    expect([(await associateCommission.settings()).day, (await associateCommission.settings()).run]).toEqual([10, 'monthly']);
    // The ED may save TDS but not the commission rules (MD only).
    await call(associateAdminController.saveLadder, { as: ed, body: { ...base, associate_payout_day: '20' } });
    expect((await associateCommission.settings()).day).toBe(10);
    await incentiveRepository.setSetting('associate_payout_day', '5', admin);
  });
});
