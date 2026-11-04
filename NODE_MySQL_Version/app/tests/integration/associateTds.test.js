'use strict';

// Owner decision 2 Nov 2026 (D-235): TDS on associate commission worked out by
// the app — 194H at 2% past ₹20,000 a financial year with catch-up, 20%
// without a verified PAN, none for associates abroad once TRC / 10F / no-PE are
// on file (and 15CA / 15CB per payment). PHP twin: AssociateTdsTest.php.

const crypto = require('crypto');
const db = require('../../src/config/db');
const associateTdsController = require('../../src/controllers/associateTdsController');
const incentiveController = require('../../src/controllers/incentiveController');
const associateBankRepository = require('../../src/repositories/associateBankRepository');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const associateRepository = require('../../src/repositories/associateRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const userRepository = require('../../src/repositories/userRepository');
const associateTds = require('../../src/services/associateTds');
const permissionService = require('../../src/services/permissionService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

describe('TDS on associate commission (D-235)', () => {
  let admin;
  let finance1;
  let finance2;
  let session;
  let tag;

  const value = async (sql, params = {}) => {
    const row = await db.queryOne(sql, params);
    return row ? Object.values(row)[0] : null;
  };

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest TDS User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-tds-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  beforeAll(async () => {
    admin = await createTestUser('Admin');
    finance1 = await createTestUser('Finance Manager');
    finance2 = await createTestUser('Finance Director');
  });

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex');
    session = {};
    for (const [k, v] of Object.entries({ associate_tds_section: '194H', associate_tds_pct: '2.00', associate_tds_threshold_inr: '20000', associate_tds_no_pan_pct: '20.00',
      associate_tds_foreign: '0', associate_15cb_required: '1', zoho_tds_tax_id: '', associate_bank_wait_days: '0' })) {
      await incentiveRepository.setSetting(k, v, admin);
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function call(fn, { as = admin, params = {}, body = {}, query = {}, files = undefined } = {}) {
    const req = { params, body, query, session, files, method: 'POST', path: '/' };
    req.user = await userRepository.findById(as);
    req.permissions = await permissionService.load(as, req.user.role_id);
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

  async function doc(associateId, kind, payoutId, status = 'accepted') {
    const id = await associateDocumentRepository.add(associateId, kind, await createTestFile(null, null), payoutId, null, false, admin);
    await db.execute('UPDATE associate_documents SET status = :s WHERE id = :id', { s: status, id });
  }

  async function associate(fields = {}, panVerified = true) {
    const id = await associateRepository.create({
      name: `TDS Assoc ${tag} ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `tds-${crypto.randomBytes(4).toString('hex')}@assoc-${tag}.test`, status: 'active', pan: 'ABCDE1234F', ...fields,
    }, admin);
    if (panVerified) await doc(id, 'pan', null, 'accepted');
    const bank = await associateBankRepository.request(id, { holder_name: 'Holder', account_number: '123456789012', ifsc: 'HDFC0001234', swift: '',
      bank_name: 'HDFC Bank', bank_branch_address: '', intermediary_bank: '', account_currency: 'INR' }, true, null);
    await associateBankRepository.firstCheck(bank, finance1, 'Called back on the known number; ₹1 test credit confirmed.');
    await associateBankRepository.verify(bank, finance2, 0);
    // The payouts below are dated 2 Nov 2026: make the account usable from before then,
    // so this test does not depend on the day it runs.
    await db.execute("UPDATE associate_bank_accounts SET usable_from = '2026-04-01' WHERE id = :id", { id: bank });
    return associateRepository.find(id);
  }

  async function payout(a, amount, month = '2026-10') {
    const orderId = await createTestOrder(await createTestClient());
    const r = await db.execute("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status) VALUES (:o, 'associate', :a, 'associate', :amt, :m, 'due')",
      { o: orderId, a: a.id, amt: amount, m: month });
    return incentiveRepository.findPayout(r.insertId);
  }

  async function pay(p, body = {}) {
    await call(incentiveController.markPaid, { params: { payoutId: String(p.id) },
      body: { month: String(p.incentive_month), paid_on: '2026-11-02', paid_via: 'bank_transfer', paid_reference: `UTR${crypto.randomBytes(4).toString('hex').toUpperCase()}`, ...body } });
    return flash();
  }

  it('no TDS below the threshold; the crossing payment catches up; later payments carry TDS on themselves', async () => {
    const a = await associate();
    const p1 = await payout(a, 12000);
    const c1 = await associateTds.compute(p1, '2026-11-02');
    expect([c1.tds, c1.net]).toEqual([0, 12000]);
    expect(c1.reason).toBe('below threshold — FY 2026-27 total ₹12,000.00 of ₹20,000.00');
    expect((await pay(p1)).message).toBe('Payment recorded.');
    const r1 = await incentiveRepository.findPayout(Number(p1.id));
    expect([String(r1.paid_inr), String(r1.tds_inr), r1.tds_reason]).toEqual(['12000.00', '0.00', 'below threshold — FY 2026-27 total ₹12,000.00 of ₹20,000.00']);

    const p2 = await payout(a, 10000);
    const c2 = await associateTds.compute(p2, '2026-11-02');
    expect([c2.tds, c2.net, c2.rate, c2.section, c2.catch_up]).toEqual([440, 9560, 2, '194H', 12000]);
    expect(c2.reason).toBe('194H 2% — includes catch-up on ₹12,000.00 paid earlier this FY 2026-27 without TDS');
    expect((await pay(p2)).message).toBe('Payment recorded.');
    const r2 = await incentiveRepository.findPayout(Number(p2.id));
    expect([String(r2.paid_inr), String(r2.tds_inr), String(r2.tds_rate), r2.tds_section]).toEqual(['9560.00', '440.00', '2.00', '194H']);

    const c3 = await associateTds.compute(await payout(a, 5000), '2026-12-15');
    expect([c3.tds, c3.catch_up, c3.reason]).toEqual([100, 0, '194H 2%']);
    expect((await associateTds.compute(await payout(a, 5000), '2027-04-10')).tds).toBe(0);
  });

  it('without a verified PAN the rate is 20% (206AA)', async () => {
    const a = await associate({}, false);
    const c = await associateTds.compute(await payout(a, 25000), '2026-11-02');
    expect([c.tds, c.rate, c.section, c.reason]).toEqual([5000, 20, '206AA', 'no PAN 20% (206AA)']);
  });

  it('a changed TDS needs a reason, and paid + TDS never exceeds the commission', async () => {
    const a = await associate();
    const p = await payout(a, 25000);
    expect((await pay(p, { paid_inr: '25000', tds_inr: '0' })).message).toMatch(/^The TDS worked out for this payment is ₹500\.00 \(194H 2%\)\. To record a different TDS, give the reason/);
    expect((await pay(p, { paid_inr: '24800', tds_inr: '500' })).message).toBe('The amount paid plus the TDS cannot be more than the commission (₹25,000.00).');
    expect((await pay(p, { paid_inr: '24750', tds_inr: '250', tds_override_reason: 'Lower-deduction certificate u/s 197 at 1%.' })).message).toBe('Payment recorded.');
    const r = await incentiveRepository.findPayout(Number(p.id));
    expect([String(r.tds_inr), String(r.tds_rate)]).toEqual(['250.00', '1.00']);
    expect(r.tds_reason).toBe('changed by hand: Lower-deduction certificate u/s 197 at 1%. (worked out: ₹500.00, 194H 2%)');
    expect(Number(await value("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_TDS_OVERRIDDEN' AND entity_id = :id", { id: p.id }))).toBe(1);
  });

  it('abroad: no TDS, but TRC / 10F / no-PE and 15CA / 15CB must be on file before paying', async () => {
    const a = await associate({ based_in: 'abroad', country: 'United Kingdom', currency_code: 'GBP', pan: null }, false);
    const p = await payout(a, 40000);
    const c = await associateTds.compute(p, '2026-11-02');
    expect([c.tds, c.net]).toEqual([0, 40000]);
    expect(c.missing_foreign).toEqual(['Tax Residency Certificate (TRC)', 'Form 10F', 'No-PE declaration']);
    expect(c.missing_payout).toEqual(['Form 15CA', 'Form 15CB']);
    expect((await pay(p)).message).toBe("Before paying an associate based abroad, these must be on file: Tax Residency Certificate (TRC), Form 10F, No-PE declaration, Form 15CA, Form 15CB. The TRC, Form 10F and no-PE declaration are accepted on the associate's Documents tab; Form 15CA and 15CB are attached to this payment.");
    for (const kind of ['tax_residency', 'form_10f', 'no_pe']) await doc(Number(a.id), kind, null);
    await doc(Number(a.id), 'form_15ca', Number(p.id), 'received');
    expect((await pay(p)).message).toMatch(/^Before paying an associate based abroad, these must be on file: Form 15CB\./);
    await doc(Number(a.id), 'form_15cb', Number(p.id), 'received');
    expect((await pay(p)).message).toBe('Payment recorded.');
    const r = await incentiveRepository.findPayout(Number(p.id));
    expect([String(r.paid_inr), String(r.tds_inr), r.tds_reason]).toEqual(['40000.00', '0.00', 'abroad — no TDS (TRC/10F/no-PE on file)']);
  });

  it('the D-212 wording and the Zoho bill with and without a TDS tax', async () => {
    expect(associateTds.paymentDescription('Ravi Kumar', ['SC/ORD/26/001'], 25000, 500, 2, '194H', 24500, 'UTR123456'))
      .toBe('Associate commission for Ravi Kumar — order SC/ORD/26/001 — gross ₹25,000.00, TDS u/s 194H ₹500.00 (2%), net paid ₹24,500.00 · UTR UTR123456');
    expect(associateTds.paymentDescription('Ravi Kumar', ['A', 'B'], 1000, 0, null, null, 1000, 'U1'))
      .toBe('Associate commission for Ravi Kumar — orders A, B — gross ₹1,000.00, no TDS, net paid ₹1,000.00 · UTR U1');
    const a = await associate();
    const p = await payout(a, 25000);
    await pay(p, { paid_reference: 'UTR555666' });
    const ref = await value('SELECT buyer_inquiry_ref FROM orders WHERE id = :id', { id: p.order_id });
    const cost = { order_id: p.order_id, buyer_inquiry_ref: ref, buyer_name: 'Buyer' };
    let c = await zohoBooksService.associateCommission(cost);
    expect([c.gross_inr, c.paid_inr, c.tds_inr, c.tds_tax_id, c.tds_left_for_ca]).toEqual([25000, 24500, 500, null, true]);
    expect(c.payment_description.startsWith(`Associate commission for ${a.name} — order ${ref} — gross ₹25,000.00, TDS u/s 194H ₹500.00 (2%), net paid ₹24,500.00 · UTR UTR555666 · against bill `)).toBe(true);
    await incentiveRepository.setSetting('zoho_tds_tax_id', '460000000012345', admin);
    c = await zohoBooksService.associateCommission(cost);
    expect([c.tds_tax_id, c.tds_left_for_ca]).toEqual(['460000000012345', false]);
  });

  it('the register, its totals, the challan, reminders and who may open it', async () => {
    const a = await associate();
    const p = await payout(a, 25000);
    await pay(p);
    const mine = (await associateTds.register('2026-04-01', 3)).filter((r) => Number(r.id) === Number(p.id));
    expect(mine.length).toBe(1);
    expect([mine[0].quarter, String(mine[0].tds_inr), String(mine[0].paid_inr), Number(mine[0].form16a_count)]).toEqual([3, '500.00', '24500.00', 0]);
    expect((await associateTds.register('2026-04-01', 2)).filter((r) => Number(r.id) === Number(p.id))).toEqual([]);

    await call(associateTdsController.saveChallan, { params: { payoutId: String(p.id) }, body: { tds_challan_no: '0510308 07112026 00045', tds_challan_date: '2026-11-02', fy: '2026-04-01' } });
    expect(flash().message).toBe('Challan saved.');
    expect((await incentiveRepository.findPayout(Number(p.id))).tds_challan_no).toBe('0510308 07112026 00045');
    const res = await call(associateTdsController.register, { query: { fy: '2026-04-01', q: '3' } });
    expect(res.view).toBe('associates/tds');
    expect(res.data.rows.some((r) => Number(r.id) === Number(p.id) && r.tds_challan_no === '0510308 07112026 00045')).toBe(true);

    const sales = await createTestUser('Export Executive');
    const perms = await permissionService.load(sales, (await userRepository.findById(sales)).role_id);
    for (const perm of ['manage_incentives', 'pay_incentives', 'ca_module_view']) expect(!!perms[perm]).toBe(false);
    const fin = await permissionService.load(finance1, (await userRepository.findById(finance1)).role_id);
    expect(!!fin.pay_incentives).toBe(true);

    expect((await associateTds.reminders('2026-12-03')).map((r) => r.type)).toContain('associate_tds_deposit');
    await db.execute("UPDATE incentive_payouts SET tds_challan_no = 'X' WHERE payee_kind = 'associate' AND tds_inr > 0 AND (tds_challan_no IS NULL OR tds_challan_no = '')");
    expect((await associateTds.reminders('2026-12-03')).map((r) => r.type)).not.toContain('associate_tds_deposit');
    expect((await associateTds.reminders('2027-01-25')).map((r) => r.type)).toContain('associate_tds_26q');
  });

  it('settings are saved with a reason and audited', async () => {
    const body = { associate_tds_section: '194H', associate_tds_pct: '2', associate_tds_threshold_inr: '20000', associate_tds_no_pan_pct: '20',
      associate_15cb_required: '1', zoho_tds_tax_id: '460000000012345', reason: 'short' };
    await call(associateTdsController.saveSettings, { body });
    expect(flash().message).toMatch(/^Give the reason for the change/);
    await call(associateTdsController.saveSettings, { body: { ...body, reason: 'CA confirmed 194H at 2% on 2 Nov 2026.' } });
    flash();
    expect((await associateTds.settings()).zoho_tax_id).toBe('460000000012345');
    expect(Number(await value("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_TDS_SETTING_CHANGED' AND field_name = 'zoho_tds_tax_id'"))).toBeGreaterThanOrEqual(1);
  });
});
