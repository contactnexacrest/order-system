'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { short } = require('../../src/helpers/dates');
const associateBankRepository = require('../../src/repositories/associateBankRepository');
const associatePortalRepository = require('../../src/repositories/associatePortalRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const vendorBankChangeRepository = require('../../src/repositories/vendorBankChangeRepository');
const vendorDetailsRepository = require('../../src/repositories/vendorDetailsRepository');
const associateBankConfirmation = require('../../src/services/associateBankConfirmation');
const associateCodeService = require('../../src/services/associateCodeService');
const associateCommission = require('../../src/services/associateCommission');
const associateKyc = require('../../src/services/associateKyc');
const associatePortalService = require('../../src/services/associatePortalService');
const associatePayoutService = require('../../src/services/associatePayoutService');
const bankDetailsCheck = require('../../src/services/bankDetailsCheck');
const vendorBankChangeService = require('../../src/services/vendorBankChangeService');

/**
 * Port of AssociateOwnerDecisionsNov11Test.php. Owner decisions 11 Nov 2026 (confirmed twice), associates:
 * D-290 KYC (ID document type + number; ID and address proof separate; app-set file names; 60 days),
 * D-291 commission rule (standard / own ladder / fixed %; MD, reason), D-292 who sees what + the associate's
 * current % and statuses + payout day, D-293 the associate confirms new bank details, one verifier,
 * D-294 the same for supplier bank details through the vendor confirmation link.
 */
describe('Associates — owner decisions 11 Nov 2026 (D-290 … D-294)', () => {
  const PASSWORD = 'JestNov11!123';
  const ASSOCIATE_PASSWORD = 'Nov11Assoc12345';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const unescape = (s) => s.trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  const sha256 = (s) => crypto.createHash('sha256').update(s).digest('hex');
  // The database's clock (the app writes confirm_by / usable_from with NOW() / CURDATE()).
  const dbDay = async (expr) => String((await db.queryOne(`SELECT DATE(${expr}) AS d`)).d).slice(0, 10);
  const BANK = { holder_name: 'Demo Associate', account_number: '501002345678', ifsc: 'HDFC0001234', swift: '', bank_name: 'HDFC Bank', bank_branch_address: '', intermediary_bank: '', account_currency: 'INR' };
  let tag;
  let staffId;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, superAdmin = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest Nov11 User', :email, NULL, :hash, :r, 1, 0, 0, :s)`,
      { email: `jest-nov11-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id, s: superAdmin ? 1 : 0 }
    )).insertId;
  }

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex').toUpperCase();
    staffId = await user('Admin', true);
    for (const [k, v] of Object.entries({ bank_details_check_people: 'one', associate_bank_wait_first_days: '0', associate_bank_wait_change_days: '15',
      associate_payout_run: 'monthly', associate_payout_day: 'last', associate_payout_holiday_rule: 'before' })) {
      await incentiveRepository.setSetting(k, v, staffId);
    }
  });

  async function associate(extra = {}) {
    const id = await associateRepository.create({ name: `Nov11 Associate ${tag} ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `associate-${crypto.randomBytes(4).toString('hex')}@email.com`, status: 'active', portal_enabled: 1, ...extra }, staffId);
    await db.execute('UPDATE associates SET portal_enabled = 1 WHERE id = :id', { id });
    await associateCodeService.issue(id, `N${tag}${String(id).slice(-2)}`);
    return associateRepository.find(id);
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

  async function staff(userId = staffId) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  async function post(agent, page, url, fields = {}) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...fields });
    let flash = { type: null, message: null };
    if (res.status === 302 && res.headers.location) {
      const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/g);
      const last = m ? m[m.length - 1].match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/) : null;
      if (last) flash = { type: last[1], message: unescape(last[2]) };
    }
    return { status: res.status, text: res.text, ...flash };
  }

  const status = async (id) => Object.values(await db.queryOne('SELECT status, cancelled_how FROM associate_bank_accounts WHERE id = :id', { id }));

  /** A request waiting for confirmation, with a known code ("123456"). */
  async function awaiting(a, enteredBy, number = '501002345678') {
    const id = await associateBankRepository.request(Number(a.id), { ...BANK, account_number: number }, enteredBy === null, enteredBy);
    await associateBankConfirmation.start(id, a, enteredBy === null, enteredBy);
    await associateBankRepository.setCode(id, sha256('123456'), 10);
    return id;
  }

  // ---------------------------------------------------------------- D-290: KYC

  it('ID document type and number; ID proof and address proof are separate; app-set file names', async () => {
    expect(associateKyc.normaliseReference('identity', ' ab-12/345  x ')).toEqual(['AB-12/345 X', null]);
    expect(associateKyc.normaliseReference('identity', 'z1234')).toEqual(['Z1234', null]);
    const msg = 'The ID document number should be 5 to 30 letters, digits, dashes (-), slashes (/) or spaces.';
    expect(associateKyc.normaliseReference('identity', 'Z123')).toEqual([null, msg]);
    expect(associateKyc.normaliseReference('identity', 'A'.repeat(31))).toEqual([null, msg]);
    expect(associateKyc.normaliseReference('identity', 'AB#12345')).toEqual([null, msg]);
    expect(associateKyc.normaliseReference('pan', 'abcde1234f')).toEqual(['ABCDE1234F', null]);
    expect(associateKyc.normaliseReference('aadhaar', '1234')).toEqual(['XXXX XXXX 1234', null]);
    expect(associateKyc.REFERENCE_RULES.identity[0]).toBe('[A-Za-z0-9 \\/\\-]{5,30}');

    expect(associateKyc.checkUpload('identity', 'Z1234567', '')).toEqual([null, null, 'Choose the type of ID document: Passport, National ID card, Residence permit or Driving licence.']);
    expect(associateKyc.checkUpload('identity', '', 'residence_permit')).toEqual([null, null, "Enter the ID document's number (5 to 30 letters, digits, dashes, slashes or spaces)."]);
    expect(associateKyc.checkUpload('identity', 'rp-2026/0001', 'residence_permit')).toEqual(['RP-2026/0001', 'residence_permit', null]);
    expect(Object.values(associateKyc.ID_DOC_TYPES)).toEqual(['Passport', 'National ID card', 'Residence permit', 'Driving licence']);

    const india = await associate();
    const abroad = await associate({ based_in: 'abroad', currency_code: 'USD' });
    expect(associateKyc.requiredItems(india).map((i) => i.key)).toEqual(['pan', 'id', 'address', 'bank', 'agreement']);
    expect(associateKyc.requiredItems(abroad).map((i) => i.key)).toEqual(['id', 'address', 'bank', 'agreement']);

    expect(associateKyc.appFileName('identity', 'Demo Associate', 'scan.PDF', '2026-11-11')).toBe('ID proof — Demo Associate — 11 Nov 2026.pdf');
    expect(associateKyc.appFileName('aadhaar', 'Demo Associate', 'a.jpg', '2026-11-11')).toBe('ID proof — Demo Associate — 11 Nov 2026.jpg');
    expect(associateKyc.appFileName('address', 'Demo / Associate', 'bill.png', '2026-11-11')).toBe('Address proof — Demo Associate — 11 Nov 2026.png');
    expect(associateKyc.appFileName('pan', 'Demo Associate', 'pan.pdf')).toBeNull();

    const agent = await portal(india);
    const r = await post(agent, '/associate/profile', '/associate/documents', { kind: 'identity', reference: 'Z1234567' });
    expect(r.message).toBe('Choose the type of ID document: Passport, National ID card, Residence permit or Driving licence.');
    const page = (await agent.get('/associate/profile')).text;
    expect(page).toContain('name="id_doc_type"');
    expect(page).toContain('Address proof');
    expect(page).not.toContain('Use the same file');
  });

  it('associates already accepted get 60 days for the address proof', async () => {
    let a = await associate();
    await require('../support/fixtures').completeAssociateKyc(Number(a.id));
    await db.execute("DELETE FROM associate_documents WHERE associate_id = :a AND kind = 'address'", { a: a.id });
    expect(await associateKyc.missing(a)).toEqual(['Address proof']);
    expect(await associateKyc.missingForPayout(a, '2026-11-11')).toEqual(['Address proof']);
    await db.execute("UPDATE associates SET address_proof_due_on = '2027-01-10' WHERE id = :a", { a: a.id });
    a = await associateRepository.find(Number(a.id));
    expect(await associateKyc.missingForPayout(a, '2027-01-10')).toEqual([]);
    expect(await associateKyc.missingForPayout(a, '2027-01-11')).toEqual(['Address proof']);
    expect(await associateKyc.addressGraceNote(a, '2026-11-11')).toBe('Address proof needed by 10 Jan 2027 — after that, commission payouts wait until it is accepted.');
    expect(associateKyc.ADDRESS_PROOF_GRACE_DAYS).toBe(60);
  });

  // ---------------------------------------------------------------- D-291 / D-292

  it('own ladder overrides the standard ladder; the MD sets it with a reason', async () => {
    const levels = (await pricingRepository.levels(true)).filter((l) => l.kind === 'pct');
    expect(levels.length).toBeGreaterThanOrEqual(2);
    const [low, high] = levels;
    let a = await associate();
    const md = await staff(await user('Managing Director'));
    const page = `/associates/${a.id}/commission-rule`;
    const fields = { rule: 'own_ladder', 'from[0]': '1', 'to[0]': '2', 'level_id[0]': String(high.id), 'from[1]': '3', 'to[1]': '', 'level_id[1]': String(low.id) };
    expect((await post(md, page, page, fields)).message).toBe('Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.');
    expect((await associateRepository.find(Number(a.id))).commission_mode).toBe('ladder');
    const r = await post(md, page, page, { ...fields, reason: 'Strong partner in UAE — agreed 10 Nov' });
    expect(r.message.startsWith(`Commission rule saved: Own ladder: 1–2: ${high.name}; 3–onward: ${low.name}`)).toBe(true);
    a = await associateRepository.find(Number(a.id));
    expect(a.commission_mode).toBe('own_ladder');
    expect(a.commission_rule_reason).toBe('Strong partner in UAE — agreed 10 Nov');
    const audit = await db.queryOne("SELECT reason FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_RULE_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: a.id });
    expect(audit.reason).toBe('Strong partner in UAE — agreed 10 Nov');

    const [level, count] = await associateCommission.levelForAssociate(a, null);
    expect([Number(level.id), count]).toEqual([Number(high.id), 0]);
    const p = await associateCommission.ruleProgress(a);
    expect([p.rule, p.count, p.of, p.more]).toEqual(['Own ladder', 0, 2, 2]);
    expect(p.next_rate).toBe(associateCommission.rateLabel(low));
    expect(p.steps.map((s) => s.label)).toEqual(['Orders 1–2', 'Orders 3 onwards']);

    const fm = await staff(await user('Finance Manager'));
    expect((await fm.get(page)).text).not.toContain('Save the rule');

    await post(md, page, page, { rule: 'ladder', reason: 'Back to the standard ladder.' });
    expect(await pricingRepository.ownLadder(Number(a.id))).toEqual([]);
    expect((await associateRepository.find(Number(a.id))).commission_mode).toBe('ladder');
  });

  it('who sees the rule, and what the associate sees', async () => {
    const roles = (await db.query(`SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                                    WHERE p.permission_key = 'view_associate_commission_rules' ORDER BY r.name`)).map((r) => r.name);
    expect(roles).toEqual(['Admin', 'Executive Director', 'Finance Director', 'Finance Manager', 'Managing Director']);
    const a = await associate();
    let page = (await (await staff(await user('Finance Manager'))).get(`/associates/${a.id}`)).text;
    expect(page).toContain('data-commission-rule');
    expect(page).toContain('Orders counted so far');
    page = (await (await staff(await user('Export Executive'))).get(`/associates/${a.id}`)).text;
    expect(page).toContain('Set by the MD.');
    expect(page).not.toContain('Orders counted so far');

    const [level] = await associateCommission.levelForAssociate(a, null);
    const agent = await portal(a);
    const profile = (await agent.get('/associate/profile')).text;
    expect(profile).toContain(`>${associateCommission.rateLabel(level)}</span> <b>of the FOB value of each order from the clients you introduced</b>`);
    expect(profile).toContain('How it works');
    expect(profile).toContain('It is worked out on the <b>FOB value on the Commercial Invoice</b>.');
    expect(profile).toContain('in our <b>monthly payment on the last day of the month</b>, in Indian rupees to your verified bank account.');
    expect(profile).not.toContain(String(level.name));
    expect(profile).not.toContain('goods value');
    const commission = (await agent.get('/associate/commission')).text;
    expect(commission).toContain('of the FOB value of each order from the clients you introduced');
    expect(commission).toContain('<th>Your %</th>');

    expect(associatePortalRepository.NOT_EARNED_LABEL).toBe('Not earned yet — buyer still paying');
    expect(associatePortalRepository.payoutLabel({ status: 'paid', paid_on: '2026-11-30', paid_reference: 'SWIFT FT2611300001' }, false)).toBe('Paid on 30 Nov 2026 (SWIFT FT2611300001)');
    expect(associatePortalRepository.payoutLabel({ status: 'due', due_on: '2026-12-28', payout_run_on: '2026-12-31' }, false)).toBe('Earned — will be paid on 31 Dec 2026');
    expect(associatePortalRepository.payoutLabel({ status: 'due', payout_run_on: '2026-12-31' }, true)).toBe('On hold — a buyer claim on this order is open');
    expect(associatePortalRepository.payoutLabel({ status: 'held', hold_reason: associatePayoutService.CLAIM_HOLD_REASON }, false)).toBe('On hold — a buyer claim on this order is open');
  });

  it('payout day: last day of the month, and the Sunday / holiday move', async () => {
    const set = await associateCommission.settings();
    expect([set.last_day, set.holiday_rule]).toEqual([true, 'before']);
    expect(await associateCommission.payoutRunOn('2026-11-15', set)).toBe('2026-11-30');
    expect(await associateCommission.payoutRunOn('2027-02-01', set)).toBe('2027-02-27');
    expect(await associateCommission.payoutRunOn('2027-01-10', set)).toBe('2027-01-30');
    expect(await associateCommission.payoutRunOn('2027-01-31', set)).toBe('2027-02-27');
    expect(await associateCommission.payoutRunOn('2027-01-10', { ...set, holiday_rule: 'after' })).toBe('2027-02-01');
    const had = !!(await db.queryOne("SELECT 1 AS x FROM company_holidays WHERE holiday_date = '2027-03-31'"));
    await db.execute("INSERT IGNORE INTO company_holidays (holiday_date, description) VALUES ('2027-03-31', 'Nov11 test holiday')");
    try {
      expect(await associateCommission.payoutRunOn('2027-03-10', set)).toBe('2027-03-30');
      expect(await associateCommission.payoutRunOn('2027-03-10', { ...set, holiday_rule: 'after' })).toBe('2027-04-01');
    } finally {
      if (!had) await db.execute("DELETE FROM company_holidays WHERE holiday_date = '2027-03-31'");
    }
    expect(associateCommission.payDayText(set)).toBe('monthly payment on the last day of the month');
    expect(associateCommission.payDayText({ run: 'monthly', day: 5, last_day: false })).toBe('monthly payment on the 5th');
    expect(associateCommission.payDayText({ run: 'weekly', day: 5, last_day: false })).toBe('weekly payment every Friday');
    expect(await associateCommission.payoutRunOn('2026-12-01', { run: 'weekly', day: 5, last_day: false, holiday_rule: 'before' })).toBe('2026-12-04');

    const mdId = await user('Managing Director');
    const md = await staff(mdId);
    const form = (await md.get('/associates/ladder')).text;
    expect(form).toContain('<option value="last" selected>Last day of the month</option>');
    expect(form).toContain('name="associate_code_changes_per_year"');
    expect(form).toContain('name="associate_upgrade_after_orders"');
    expect(form).toContain('name="bank_details_check_people"');
    const fields = { associate_tds_pct: '2', associate_commission_due_days: '30', associate_payout_run: 'monthly', associate_payout_day: '20', associate_payout_holiday_rule: 'after',
      associate_counting_threshold_inr: '500000', associate_protection_months: '6', associate_gst_pct: '18', associate_portal_commission_months: '12',
      associate_code_changes_per_year: '4', associate_upgrade_after_orders: '5', bank_details_check_people: 'one', associate_bank_wait_first_days: '0', associate_bank_wait_change_days: '15' };
    expect((await post(md, '/associates/ladder', '/associates/ladder', fields)).message).toBe('Give the reason for changing the payment run (10 to 300 characters) — it is kept in the Audit Log.');
    expect((await post(md, '/associates/ladder', '/associates/ladder', { ...fields, payout_reason: 'Finance pays vendors on the 20th.' })).message.startsWith('Saved.')).toBe(true);
    const s = await incentiveRepository.settings();
    expect([s.associate_payout_day, s.associate_payout_holiday_rule, s.associate_code_changes_per_year, s.associate_upgrade_after_orders]).toEqual(['20', 'after', '4', '5']);
    expect((await db.queryOne("SELECT reason FROM audit_log WHERE action_type = 'ASSOCIATE_SETTING_CHANGED' AND field_name = 'associate_payout_day' ORDER BY id DESC LIMIT 1")).reason).toBe('Finance pays vendors on the 20th.');
    expect((await post(md, '/associates/ladder', '/associates/ladder', { ...fields, associate_bank_wait_change_days: '91' })).message).toBe('Enter TDS as 0–30 % and each bank waiting time as 0–90 days.');
    for (const [k, v] of Object.entries({ associate_payout_day: 'last', associate_payout_holiday_rule: 'before', associate_code_changes_per_year: '3', associate_upgrade_after_orders: '3' })) {
      await incentiveRepository.setSetting(k, v, mdId);
    }
  });

  // ---------------------------------------------------------------- D-293

  it('the associate confirms before staff verify; one person verifies with two checks', async () => {
    const a = await associate();
    const id = await awaiting(a, null);
    expect(await status(id)).toEqual(['awaiting_confirmation', null]);
    expect(await associateBankConfirmation.open(Number(a.id))).toBeTruthy();
    expect(String((await associateBankRepository.find(id)).confirm_by).slice(0, 10)).toBe(await dbDay('DATE_ADD(NOW(), INTERVAL 7 DAY)'));

    const agent = await portal(a);
    expect((await agent.get('/associate')).text).toContain('New bank details are waiting for your confirmation.');
    const page = (await agent.get('/associate/bank/confirm')).text;
    expect(page).toContain('Please confirm your new bank details');
    expect(page).toContain('••••••••5678');
    expect(page).toContain('This is not me — cancel it');

    const admin = await staff();
    const verify = `/associates/${a.id}/bank/${id}/verify`;
    expect((await post(admin, `/associates/${a.id}`, verify, { check_bank_proof: '1', check_phone: '1' })).message).toBe('Verify is available once the associate confirms the new details.');

    expect(await associateBankConfirmation.confirm(a, id, '0000', '123456', true)).toBe('The last 4 digits do not match the new account number — check them and type them again.');
    expect(await associateBankConfirmation.confirm(a, id, '5678', '123456', false)).toBe('Tick "These are my bank details" to confirm.');
    expect(await associateBankConfirmation.confirm(a, id, '5678', '111111', true)).toBe('The code is not right — check the e-mail we sent you, or ask for a new code.');
    expect(Number((await associateBankRepository.find(id)).confirm_code_attempts)).toBe(1);
    const done = await post(agent, '/associate/bank/confirm', '/associate/bank/confirm', { last4: '5678', code: '123456', mine: '1' });
    expect(done.message.startsWith('Thank you — confirmed. NexaCrest will now check the new details.')).toBe(true);
    expect((await status(id))[0]).toBe('pending');

    expect((await post(admin, `/associates/${a.id}`, verify, { check_bank_proof: '1' })).message).toBe('Tick at least two of the ways you checked the bank details.');
    expect((await post(admin, `/associates/${a.id}`, verify, { check_bank_proof: '1', check_test_payment: '1' })).message).toBe('Enter the bank reference (UTR / SWIFT) of the test payment.');
    const ok = await post(admin, `/associates/${a.id}`, verify, { check_bank_proof: '1', check_test_payment: '1', test_payment_ref: 'UTR 412345678901', verify_note: 'Spoke to them at 3 pm' });
    expect(ok.message).toBe(`Verified. Usable from ${short(await dbDay('CURDATE()'))}; the associate was e-mailed.`);
    const row = await associateBankRepository.find(id);
    expect([Number(row.check_bank_proof), Number(row.check_phone), Number(row.check_test_payment), row.test_payment_ref, row.verify_note]).toEqual([1, 0, 1, 'UTR 412345678901', 'Spoke to them at 3 pm']);
    expect(bankDetailsCheck.checksText(row)).toBe('bank proof, test payment (ref UTR 412345678901)');

    const second = await awaiting(a, staffId, '600070008000');
    await associateBankConfirmation.confirm(a, second, '8000', '123456', true);
    expect((await post(admin, `/associates/${a.id}`, `/associates/${a.id}/bank/${second}/verify`, { check_bank_proof: '1', check_phone: '1' })).message).toBe('Someone else must verify details you entered.');

    expect((await db.query("SELECT r.name FROM self_approval_rules s JOIN roles r ON r.id = s.role_id WHERE s.rule_key = 'bank_details' ORDER BY r.name")).map((r) => r.name))
      .toEqual(['Executive Director', 'Managing Director']);
    const mdId = await user('Managing Director');
    const third = await awaiting(a, mdId, '700080009000');
    expect(await status(second)).toEqual(['cancelled', 'replaced']);
    await associateBankConfirmation.confirm(a, third, '9000', '123456', true);
    const md = await staff(mdId);
    const verify3 = `/associates/${a.id}/bank/${third}/verify`;
    expect((await post(md, `/associates/${a.id}`, verify3, { check_bank_proof: '1', check_phone: '1' })).message).toBe('You entered these details yourself — give the reason you verify them (at least 10 characters).');
    const own = await post(md, `/associates/${a.id}`, verify3, { check_bank_proof: '1', check_phone: '1', self_reason: 'Only finance person is on leave until 20 Nov' });
    expect(own.message).toBe(`Verified. Usable from ${short(await dbDay('DATE_ADD(CURDATE(), INTERVAL 15 DAY)'))}; the associate was e-mailed.`);
    expect((await associateBankRepository.find(third)).self_verify_reason).toBe('Only finance person is on leave until 20 Nov');
    expect(Number((await associateBankRepository.usable(Number(a.id))).id)).toBe(id);

    await incentiveRepository.setSetting('bank_details_check_people', 'two', staffId);
    try {
      const fourth = await awaiting(a, null, '800090001000');
      await associateBankConfirmation.confirm(a, fourth, '1000', '123456', true);
      const v4 = `/associates/${a.id}/bank/${fourth}`;
      expect((await post(admin, `/associates/${a.id}`, `${v4}/verify`)).message).toBe('Record the first check before verifying.');
      expect((await post(admin, `/associates/${a.id}`, `${v4}/check`, { check_bank_proof: '1', check_phone: '1' })).message).toBe('First check recorded. A different person now verifies it.');
      expect((await post(admin, `/associates/${a.id}`, `${v4}/verify`)).message).toBe('A different person must verify — you already acted on this request.');
    } finally {
      await incentiveRepository.setSetting('bank_details_check_people', 'one', staffId);
    }
  });

  it('"This is not me" cancels and alerts the MD and Finance; 7 days without an answer cancel', async () => {
    const a = await associate();
    const md = await user('Managing Director');
    const fm = await user('Finance Manager');
    const id = await awaiting(a, staffId);
    const agent = await portal(a);
    const r = await post(agent, '/associate/bank/confirm', '/associate/bank/not-me');
    expect(r.message).toBe(associateBankConfirmation.NOT_ME_MESSAGE);
    expect(associateBankConfirmation.NOT_ME_MESSAGE).toBe('Cancelled. Your bank details have not changed. We have told our Managing Director and Finance team. If someone contacted you about your bank details, please reply in Messages.');
    expect(await status(id)).toEqual(['cancelled', 'not_me']);
    for (const u of [md, fm]) {
      const n = await db.queryOne("SELECT message FROM notifications WHERE user_id = :u AND type = 'bank_change_refused' ORDER BY id DESC LIMIT 1", { u });
      expect(n.message).toContain(`Associate ${a.name} clicked "This is not me — cancel it"`);
    }
    expect((await agent.get('/associate')).text).not.toContain('data-bank-confirm-bar');

    const late = await awaiting(a, null, '900010002000');
    await db.execute('UPDATE associate_bank_accounts SET confirm_by = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id', { id: late });
    expect(await associateBankConfirmation.open(Number(a.id))).toBeFalsy();
    expect(await associateBankConfirmation.expireOverdue()).toBeGreaterThanOrEqual(1);
    expect(await status(late)).toEqual(['cancelled', 'expired']);
  });

  // ---------------------------------------------------------------- D-294

  it('supplier bank details are confirmed through the link, then verified', async () => {
    const supplierId = await supplierRepository.create({ supplier_legal_name: `Nov11 Stone Supplier ${tag}`, types: ['Quarry Owner'], email: 'accounts@email.com' }, staffId);
    const bank = { bank_account_name: 'Nov11 Stone Supplier', bank_name: 'State Bank of India', bank_branch: 'Main', bank_ifsc: 'SBIN0001234', bank_account_type: 'Current', bank_upi_id: null, bank_account_number: '112233445566' };
    const r = await vendorBankChangeService.propose('supplier', supplierId, bank, 'staff', staffId, 'accounts@email.com');
    expect(r.emailedTo).toBe('accounts@email.com');
    expect(vendorDetailsRepository.bankDetails(await supplierRepository.find(supplierId)).has_bank).toBe(false);
    const token = `nov11-token-${tag}`;
    await db.execute('UPDATE vendor_bank_changes SET confirm_token_hash = :h WHERE id = :id', { h: sha256(token), id: r.id });

    const admin = await staff();
    const verify = `/suppliers/${supplierId}/bank-changes/${r.id}/verify`;
    expect((await post(admin, `/suppliers/${supplierId}/edit`, verify, { check_bank_proof: '1', check_phone: '1' })).message)
      .toBe('Verify is available once they confirm the new details (link sent to their registered e-mail).');

    const pub = request.agent(app);
    const page = (await pub.get(`/vendor-bank-confirm/${token}`)).text;
    expect(page).toContain('Please confirm your new bank details');
    expect(page).toContain('••••5566');
    expect(page).not.toContain('112233445566');
    expect(page).toContain('This is not us — cancel it');
    expect(await vendorBankChangeService.confirm(await vendorBankChangeRepository.find(r.id), '1111', true)).toBe('The last 4 digits do not match the new account number — check them and type them again.');
    const done = await pub.post(`/vendor-bank-confirm/${token}`).type('form').send({ _csrf: csrfOf(page), last4: '5566', ours: '1' });
    expect(done.text).toContain('Thank you — confirmed');
    expect((await vendorBankChangeRepository.find(r.id)).status).toBe('pending');

    expect((await post(admin, `/suppliers/${supplierId}/edit`, verify, { check_bank_proof: '1', check_phone: '1' })).message).toBe('Someone else must verify details you entered.');
    const other = await staff(await user('Admin', true));
    expect((await post(other, `/suppliers/${supplierId}/edit`, verify, { check_bank_proof: '1', check_phone: '1' })).message)
      .toBe('Bank details verified. They are used from the date shown; until then payments go to the account on file.');
    expect((await vendorBankChangeRepository.find(r.id)).status).toBe('applied');
    const onFile = vendorDetailsRepository.bankDetails(await supplierRepository.find(supplierId));
    expect([onFile.bank_account_number, onFile.bank_ifsc]).toEqual(['112233445566', 'SBIN0001234']);
    expect(onFile.bank_verified_at).toBeTruthy();

    const md = await user('Managing Director');
    const r2 = await vendorBankChangeService.propose('supplier', supplierId, { ...bank, bank_account_number: '998877665544' }, 'vendor', null, 'accounts@email.com');
    expect(await vendorBankChangeService.notUs(await vendorBankChangeRepository.find(r2.id))).toBe(true);
    expect(Object.values(await db.queryOne('SELECT status, cancelled_how FROM vendor_bank_changes WHERE id = :id', { id: r2.id }))).toEqual(['cancelled', 'not_me']);
    expect((await db.queryOne("SELECT message FROM notifications WHERE user_id = :u AND type = 'bank_change_refused' ORDER BY id DESC LIMIT 1", { u: md })).message)
      .toContain('clicked "This is not us — cancel it"');
    expect(vendorDetailsRepository.bankDetails(await supplierRepository.find(supplierId)).bank_account_number).toBe('112233445566');

    const r3 = await vendorBankChangeService.propose('supplier', supplierId, { ...bank, bank_account_number: '556677889900' }, 'staff', staffId, 'accounts@email.com');
    await db.execute('UPDATE vendor_bank_changes SET confirm_by = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id', { id: r3.id });
    expect((await vendorBankChangeService.daily()).expired).toBeGreaterThanOrEqual(1);
    expect((await vendorBankChangeRepository.find(r3.id)).status).toBe('cancelled');
  });
});
