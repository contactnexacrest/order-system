'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const associatePortalRepository = require('../../src/repositories/associatePortalRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const associatePortalService = require('../../src/services/associatePortalService');
const associateAccess = require('../../src/services/associateAccess');
const associateCommission = require('../../src/services/associateCommission');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AssociateCommissionPeriodTest.php. Task #80 / register D-263 (owner, 6 Nov 2026):
 * - "Commission period shown to associates" (3–24 months, default 12): the portal totals and the
 *   commission list / statement / CSV / print show the last N months, plus anything still unpaid;
 * - an associate never sees a total of orders or business, the running count of their orders,
 *   the ₹ threshold, or a ladder level name; FOB in notices follows "Show FOB order value";
 *   My clients hides order numbers without "orders"; the dashboard upgrade suggestion is gated by
 *   "Manage associates", not the MD role.
 */
describe('Associate commission period and no business totals (D-263)', () => {
  const PASSWORD = 'JestPeriod!123';
  const ASSOCIATE_PASSWORD = 'Period1234567';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  let admin;
  let savedMonths;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, superAdmin = 0) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES (:name, :email, NULL, :hash, :r, 1, 0, 0, :s)`,
      { name: `Jest ${roleName}`, email: `jest-period-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id, s: superAdmin },
    )).insertId;
  }

  beforeEach(async () => {
    admin = await user('Admin', 1);
    savedMonths = (await incentiveRepository.settings()).associate_portal_commission_months ?? '12';
  });

  afterEach(async () => {
    await incentiveRepository.setSetting('associate_portal_commission_months', String(savedMonths), null);
    for (const [level, fs] of Object.entries(associateAccess.defaultMatrix())) {
      for (const [f, on] of Object.entries(fs)) {
        if (f !== 'profile') await db.execute('UPDATE associate_access_level_settings SET allowed = :v WHERE level = :l AND feature_key = :f', { v: on ? 1 : 0, l: level, f });
      }
    }
  });

  async function associate(level) {
    const id = await associateRepository.create({ name: `Period Assoc ${crypto.randomBytes(3).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `period-${crypto.randomBytes(4).toString('hex')}@assoc.test`, status: 'active', portal_enabled: 1 }, admin);
    await db.execute('UPDATE associates SET portal_enabled = 1, access_level = :l WHERE id = :id', { l: level, id });
    return associateRepository.find(id);
  }

  async function customAll() {
    const a = await associate('starter');
    const md = await db.queryOne('SELECT * FROM users WHERE id = :id', { id: await user('Managing Director') });
    const all = Object.keys(associateAccess.FEATURES).filter((f) => f !== 'profile');
    expect(await associateAccess.setLevel(a, 'custom', all, 'Everything on for the totals check', md)).toBeNull();
    return associateRepository.find(Number(a.id));
  }

  /** The associate's client with one order (goods FOB 12,345.67); returns {client, order, ref}. */
  async function order(a, client = null) {
    const c = client || await createTestClient();
    await db.execute('UPDATE clients SET associate_id = :a WHERE id = :c', { a: Number(a.id), c });
    const o = await createTestOrder(c);
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 123.4567, 12345.67)", { o });
    await db.execute('UPDATE orders SET associate_id = :a WHERE id = :o', { a: Number(a.id), o });
    const ref = (await db.queryOne('SELECT order_reference FROM orders WHERE id = :o', { o })).order_reference;
    return { client: c, order: o, ref };
  }

  async function payout(a, orderId, month, status, amount, paidOn = null) {
    return (await db.execute(
      `INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, status, paid_on, paid_inr, tds_inr)
       VALUES (:o, 'associate', :a, 'associate', :amt, :fc, 'USD', :m, :s, :p, :pi, :t)`,
      { o: orderId, a: Number(a.id), amt: amount, fc: Math.round(amount / 85 * 100) / 100, m: month, s: status, p: paidOn,
        pi: status === 'paid' ? Math.round(amount * 0.9 * 100) / 100 : null, t: status === 'paid' ? Math.round(amount * 0.1 * 100) / 100 : null },
    )).insertId;
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

  async function staff(userId) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  /** The month (YYYY-MM) `back` months before the current IST month. */
  function monthsAgo(back) {
    const t = new Date(Date.now() + 5.5 * 3600 * 1000);
    const d = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), 1));
    d.setUTCMonth(d.getUTCMonth() - back);
    return d.toISOString().slice(0, 7);
  }

  /** Owner rule (6 Nov 2026): no aggregate count or total of orders or business, anywhere an associate looks. */
  const AGGREGATE = [
    /counting order no/i,
    /\btotal\s+(?:of\s+)?(?:your\s+|all\s+)?(?:orders|business|order value|sales|turnover)/i,
    /\b(?:orders|business|turnover|order value)\s*(?:\(\s*\d+\s*\)|:\s*(?:<[^>]+>\s*)*(?:₹|USD|EUR|\d))/i,
    /\b\d+\s+(?:completed,?\s+)?(?:fully paid\s+|paid\s+)?orders\b/i,
    /under ₹[\d,]+ within range/i,
    /5,00,000|500,000|5 lakh/i,
  ];

  function assertNoAggregate(label, text) {
    for (const re of AGGREGATE) {
      const m = String(text).match(re);
      expect([label, m ? m[0] : null]).toEqual([label, null]);
    }
  }

  it('works out the window from the current month', () => {
    expect(associatePortalRepository.portalFromMonth(12, '2026-11-15')).toBe('2025-12');
    expect(associatePortalRepository.portalFromMonth(3, '2026-11-15')).toBe('2026-09');
    expect(associatePortalRepository.portalFromMonth(24, '2026-11-15')).toBe('2024-12');
    expect(associatePortalRepository.portalFromMonth(3, '2026-01-31')).toBe('2025-11');
    expect(associateCommission.portalMonths({})).toBe(12);
    expect(associateCommission.portalMonths({ associate_portal_commission_months: '7' })).toBe(12);
    expect(associateCommission.portalMonths({ associate_portal_commission_months: '18' })).toBe(18);
    expect(associateCommission.PORTAL_MONTH_CHOICES).toEqual([3, 6, 9, 12, 15, 18, 24]);
  });

  it('limits the totals and the list to the last N months, keeping everything still unpaid', async () => {
    const a = await associate('full');
    const o = await order(a);
    const ids = {
      now: await payout(a, o.order, '2026-11', 'paid', 100, '2026-11-10'),
      june: await payout(a, o.order, '2026-06', 'paid', 200, '2026-07-05'),
      oldPaidLate: await payout(a, o.order, '2025-10', 'paid', 400, '2025-12-03'),
      oldDue: await payout(a, o.order, '2025-03', 'due', 800),
      oldHeld: await payout(a, o.order, '2024-05', 'held', 1600),
    };
    const t12 = await associatePortalRepository.totals(Number(a.id), 12, '2026-11-15');
    const { fc: fc12, ...rest12 } = t12; // D-264: the per-currency totals are checked in associatePaymentsD264.test.js
    expect(fc12).toBeTruthy();
    expect(rest12).toEqual({ earned: 300, paid: 90 + 180 + 360, waiting: 800, held: 1600, months: 12 });
    const t3 = await associatePortalRepository.totals(Number(a.id), 3, '2026-11-15');
    const { fc: fc3, ...rest3 } = t3;
    expect(fc3).toBeTruthy();
    expect(rest3).toEqual({ earned: 100, paid: 90, waiting: 800, held: 1600, months: 3 });
    const list12 = (await associatePortalRepository.portalPayouts(Number(a.id), 12, '2026-11-15')).map((p) => Number(p.id)).sort();
    expect(list12).toEqual([ids.now, ids.june, ids.oldDue, ids.oldHeld].sort());
    const list3 = (await associatePortalRepository.portalPayouts(Number(a.id), 3, '2026-11-15')).map((p) => Number(p.id)).sort();
    expect(list3).toEqual([ids.now, ids.oldDue, ids.oldHeld].sort());
    // Staff screens keep everything.
    expect((await associatePortalRepository.payouts(Number(a.id))).length).toBe(5);
  });

  it('the portal pages, CSV and printable statement follow the setting', async () => {
    const a = await associate('full');
    const recent = await order(a);
    const old = await order(a);
    const oldDue = await order(a);
    await payout(a, recent.order, monthsAgo(1), 'paid', 1000, `${monthsAgo(1)}-15`);
    await payout(a, old.order, monthsAgo(5), 'paid', 2000, `${monthsAgo(5)}-20`);
    await payout(a, oldDue.order, monthsAgo(8), 'due', 4000);
    await incentiveRepository.setSetting('associate_portal_commission_months', '3', null);
    const agent = await portal(a);
    const home = (await agent.get('/associate')).text;
    expect(home).toContain('Earned in the last 3 months');
    expect(home).toContain('Paid in the last 3 months');
    expect(home).not.toContain('this year');
    const page = (await agent.get('/associate/commission')).text;
    expect(page).toContain('data-commission-period');
    expect(page).toContain('This shows the commission of the last 3 months, and any commission still waiting to be paid.');
    expect(page).toContain(recent.ref);
    expect(page).not.toContain(old.ref);
    expect(page).toContain(oldDue.ref);
    const csv = (await agent.get('/associate/commission?format=csv')).text;
    expect(csv).toContain(recent.ref);
    expect(csv).not.toContain(old.ref);
    expect(csv).toContain(oldDue.ref);
    const print = (await agent.get('/associate/commission?print=1')).text;
    expect(print).not.toContain(old.ref);

    await incentiveRepository.setSetting('associate_portal_commission_months', '12', null);
    expect((await agent.get('/associate/commission')).text).toContain(old.ref);
    expect((await agent.get('/associate')).text).toContain('Earned in the last 12 months');
    // Staff still see every payout on the associate page.
    const staffPage = (await (await staff(admin)).get(`/associates/${a.id}`)).text;
    expect(staffPage).toContain(old.ref);
  });

  it('the setting is validated, MD-gated and audit-logged', async () => {
    const md = await user('Managing Director');
    const ed = await user('Executive Director');
    const set = await associateCommission.settings();
    const fields = {
      associate_tds_pct: '2', associate_bank_wait_days: '2', associate_commission_due_days: String(set.due_days), associate_payout_run: set.run,
      associate_payout_day: set.last_day ? 'last' : String(set.day), associate_counting_threshold_inr: String(set.threshold_inr), associate_protection_months: String(set.registration_months),
      associate_gst_pct: String(set.gst_pct), associate_claim_hold: set.claim_hold ? '1' : '',
    };
    const save = async (agent, months) => {
      const csrf = csrfOf((await agent.get('/associates/ladder')).text);
      const res = await agent.post('/associates/ladder').type('form').send({ _csrf: csrf, ...fields, associate_portal_commission_months: months });
      const next = (await agent.get(res.headers.location)).text;
      const m = next.match(/class="alert alert-(?:success|error)"[^>]*>([^<]*)</);
      return m ? m[1].trim() : null;
    };
    const mdAgent = await staff(md);
    const form = (await mdAgent.get('/associates/ladder')).text;
    expect(form).toContain('name="associate_portal_commission_months"');
    expect(form).toContain('Commission period shown to associates *');
    expect(await save(mdAgent, '7')).toBe('Choose the commission period shown to associates: 3, 6, 9, 12, 15, 18 or 24 months.');
    expect(await save(mdAgent, '')).toBe('Choose the commission period shown to associates: 3, 6, 9, 12, 15, 18 or 24 months.');
    expect(await save(mdAgent, '6')).toMatch(/^Saved\./);
    expect((await incentiveRepository.settings()).associate_portal_commission_months).toBe('6');
    const log = await db.queryOne("SELECT * FROM audit_log WHERE action_type = 'ASSOCIATE_SETTING_CHANGED' AND field_name = 'associate_portal_commission_months' ORDER BY id DESC LIMIT 1");
    expect(log.new_value).toBe('6');
    expect(Number(log.user_id)).toBe(md);
    // Without "Manage pricing" (Executive Director) the commission rules are not changed.
    await save(await staff(ed), '24');
    expect((await incentiveRepository.settings()).associate_portal_commission_months).toBe('6');
  });

  it('no aggregate count or total of orders or business on any portal page or download, at every level', async () => {
    for (const level of ['starter', 'quoting', 'full', 'custom']) {
      const a = level === 'custom' ? await customAll() : await associate(level);
      const first = await order(a);
      const second = await order(a);
      await order(a);
      await payout(a, first.order, monthsAgo(0), 'paid', 1500, `${monthsAgo(0)}-01`);
      await payout(a, second.order, monthsAgo(1), 'due', 2500);
      const { notify } = require('../../src/services/associateService');
      await notify(a, 'commission_earned', `Your client paid order ${first.ref} in full. Commission earned: USD 617.28 (5% on the invoiced FOB of the goods, USD 12,345.67).`,
        first.client, first.order);
      const agent = await portal(a);
      const pages = ['/associate', '/associate/clients', '/associate/orders', `/associate/orders/${first.order}`, '/associate/commission',
        '/associate/commission?format=csv', '/associate/commission?print=1', '/associate/messages', '/associate/catalogue', '/associate/prices',
        '/associate/profile', `/associate/clients/${first.client}/certificate`];
      for (const p of pages) {
        const r = await agent.get(p);
        expect([level, p, r.status < 500]).toEqual([level, p, true]);
        assertNoAggregate(`${level} ${p}`, r.text);
      }
    }
  });

  it('shows the rate only — never a level name — and only with "Show commission %"', async () => {
    expect(associateCommission.rateLabel({ name: 'Level 2', kind: 'pct', value: '5.0000' })).toBe('5%');
    expect(associateCommission.rateLabel({ name: 'Level 9', kind: 'fixed_usd', value: '1.5' })).toBe('USD 1.50 per unit');
    expect(associateCommission.rateLabel(null)).toBe('no commission level');
    expect(associateAccess.rateOnly('Level 2 — 5%')).toBe('5%');
    expect(associateAccess.rateOnly('Level 2 — 5% (for now — fixed when the PI is issued)')).toBe('5% (for now — fixed when the PI is issued)');
    expect(associateAccess.rateOnly('no level')).toBe('no level');

    const full = await associate('full');
    const fullAccess = await associateAccess.features(full);
    const filtered = associateAccess.filterOrder(fullAccess, { fob_value: 1, commission: { level: 'Level 2 — 5%', rate: 5,
      detail: { lines: [], level_label: 'Level 2 — 5%', rate: 5, counting_number: 7, threshold_inr: 500000, counts: true, state: 'paid' } } });
    expect(filtered.commission.level).toBe('5%');
    expect(filtered.commission.detail.level_label).toBe('5%');
    expect(filtered.commission.detail.counting_number).toBeNull();
    expect(filtered.commission.detail.threshold_inr).toBeNull();
    expect(filtered.commission.detail.portal).toBe(true);

    const [level] = await associateCommission.levelForAssociate(full, null);
    const fullProfile = (await (await portal(full)).get('/associate/profile')).text;
    // D-292 (owner 11 Nov 2026): only the current %, "of the FOB value of each order from the clients you introduced".
    expect(fullProfile).toContain(`>${associateCommission.rateLabel(level)}</span> <b>of the FOB value of each order from the clients you introduced</b>`);
    if (level) expect(fullProfile).not.toContain(`${level.name} —`);
    expect(fullProfile).not.toContain('Ladder — your next order');

    const starterProfile = (await (await portal(await associate('starter'))).get('/associate/profile')).text;
    expect(starterProfile).toContain('Your commission is as set out in your agreement.');
  });

  it('the FOB in a notice follows "Show FOB order value"', async () => {
    const message = 'Your client paid order NX-1 in full. You have earned USD 617.28 commission (5% of the invoice value of the goods, USD 12,345.67).';
    expect(await associateAccess.maskNotice(await associate('full'), message)).toBe(message);
    expect(await associateAccess.maskNotice(await associate('starter'), message))
      .toBe('Your client paid order NX-1 in full. You have earned [amount in your portal] commission ([rate in your agreement] of the invoice value of the goods).');
    const md = await db.queryOne('SELECT * FROM users WHERE id = :id', { id: await user('Managing Director') });
    const c = await associate('starter');
    expect(await associateAccess.setLevel(c, 'custom', ['clients', 'commission', 'commission_pct', 'notice_amounts'], 'Amounts on, FOB value off for this one', md)).toBeNull();
    expect(await associateAccess.maskNotice(await associateRepository.find(Number(c.id)), message))
      .toBe('Your client paid order NX-1 in full. You have earned USD 617.28 commission (5% of the invoice value of the goods).');
  });

  it('My clients hides order numbers when "orders" is off', async () => {
    const md = await db.queryOne('SELECT * FROM users WHERE id = :id', { id: await user('Managing Director') });
    const a = await associate('starter');
    expect(await associateAccess.setLevel(a, 'custom', ['clients', 'registration_links'], 'Clients only, no orders for this one', md)).toBeNull();
    const now = await associateRepository.find(Number(a.id));
    const o = await order(now);
    const page = (await (await portal(now)).get('/associate/clients')).text;
    expect(page).not.toContain('<th>Orders</th>');
    expect(page).not.toContain(o.ref);
    const s = await associate('starter');
    const so = await order(s);
    const withOrders = (await (await portal(s)).get('/associate/clients')).text;
    expect(withOrders).toContain('<th>Orders</th>');
    expect(withOrders).toContain(so.ref);
  });

  it('the dashboard upgrade question follows "Manage associates", not the MD role', async () => {
    const a = await associate('starter');
    for (let i = 0; i < 3; i += 1) {
      const { order: o } = await order(a);
      await db.execute("UPDATE orders SET status = 'complete' WHERE id = :o", { o });
      await db.execute(`INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:o, 1000, NOW())
        ON DUPLICATE KEY UPDATE advance_amount = 1000, advance_cleared_at = NOW(), balance_amount = NULL, freight_amount = NULL`, { o });
    }
    const ed = await user('Executive Director');
    expect((await (await staff(ed)).get('/')).text).toContain('Associates ready for the Quoting level?');
    const viewer = await user('Viewer / Auditor');
    expect((await (await staff(viewer)).get('/')).text).not.toContain('Associates ready for the Quoting level?');
  });
});
