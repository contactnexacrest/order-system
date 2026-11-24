'use strict';

/**
 * Owner decision D-410 (24 Nov 2026, confirm 1 + confirm 2): new associate commission rules.
 *  - B1 every order of the client earns (no 10-day window) — orders under the new rules.
 *  - B2 below ₹5,00,000 (value of the goods) no commission, except the client's first order.
 *  - B3 orders of the same client within 15 days are one ladder step; a later one is a new step.
 *  - B4 a step counts when the advance has cleared; LC orders when the LC is confirmed.
 *  - B5 a cancelled order loses its step.
 *  - B6 orders whose PI was issued before the cut-over date keep the old rule (2A).
 *  - B7 the MD's per-associate price choice needs a reason and is kept in the Audit Log.
 *  - B8 the group days are 7–45 and a change needs a reason.
 *  - B9 changing what the client's price includes needs a reason.
 * PHP twin: tests/Integration/AssociateRulesD410Test.php.
 */
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const associateCommission = require('../../src/services/associateCommission');
const associateRules = require('../../src/services/associateRules');
const { createTestClient, createTestOrder } = require('../support/fixtures');

describe('Associate commission rules (D-410)', () => {
  const PASSWORD = 'JestD410!12345';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const unescape = (s) => s.trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  let md;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Managing Director'");
    md = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest D410 MD', :email, NULL, :hash, :r, 1, 0, 0, 1)`,
      { email: `jest-d410-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id }
    )).insertId;
    await incentiveRepository.setSetting('associate_counting_threshold_inr', '500000', md);
    await incentiveRepository.setSetting('associate_group_days', '15', md);
    await incentiveRepository.setSetting('associate_rules_from', '', md);
    await pricingRepository.setSetting('pricing_fx_rate', '82.0000', md);
  });

  async function staff() {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: md })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  async function post(agent, page, url, fields = {}) {
    const csrf = csrfOf((await agent.get(page)).text);
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...fields });
    let flash = { type: null, message: null };
    if (res.status === 302 && res.headers.location) {
      const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/g);
      // The flash (success / error) — some pages also carry their own info boxes.
      const pick = m ? (m.find((x) => /alert-(success|error)"/.test(x)) || m[m.length - 1]) : null;
      const last = pick ? pick.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/) : null;
      if (last) flash = { type: last[1], message: unescape(last[2]) };
    }
    return { status: res.status, ...flash };
  }

  async function associate() {
    const id = await associateRepository.create({ name: `D410 Associate ${crypto.randomBytes(2).toString('hex')}`, based_in: 'abroad', country: 'USA', currency_code: 'USD',
      email: null, phone: null, tax_id: null, agreement_signed_on: '2025-12-01', status: 'active', notes: null }, md);
    return associateRepository.find(id);
  }

  /** An order of the client with goods of fobUsd, PO received on po; the advance cleared (or LC / no advance). */
  async function order(clientId, po, fobUsd, how = 'advance') {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const next = Number((await db.queryOne('SELECT COALESCE(MAX(sequence_no), 0) + 1 AS n FROM orders WHERE client_id = :c', { c: clientId })).n);
    await db.execute('UPDATE orders SET client_id = :c, sequence_no = :n, buyer_po_received_on = :po, created_at = :ca, payment_method = :pm, advance_pct = :ap WHERE id = :o',
      { c: clientId, n: next, po, ca: `${po} 09:00:00`, pm: how === 'lc' ? 'LC' : 'CASH_ADVANCE', ap: how === 'advance' ? 30 : 0, o: orderId });
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', :p, :f)",
      { o: orderId, p: fobUsd / 100, f: fobUsd });
    if (how === 'advance') {
      await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
      await db.execute('UPDATE order_payment_status SET advance_cleared_at = :a WHERE order_id = :o', { a: `${po} 12:00:00`, o: orderId });
    }
    return orderId;
  }

  async function steps(a) {
    const out = [];
    for (const r of await associateRules.history(Number(a.id))) out.push(await associateRules.stepText(r));
    return out;
  }

  async function linked() {
    const a = await associate();
    const client = await createTestClient();
    await associateRepository.linkClient(client, Number(a.id), '2025-12-05');
    return [a, client];
  }

  it('B1/B3: orders of the same client within 15 days are one step', async () => {
    const [a, client] = await linked();
    const o1 = await order(client, '2026-01-01', 9000);
    await order(client, '2026-01-10', 9000);
    await order(client, '2026-01-20', 9000);
    const ref1 = (await orderRepository.find(o1)).order_reference;
    expect(await steps(a)).toEqual(['Step 1', `Same step as ${ref1} (same client within 15 days)`, 'Step 2']);
    expect(await associateCommission.countingOrders(Number(a.id))).toBe(2);
  });

  it('B2: below the minimum earns nothing, except the first order', async () => {
    const [a, client] = await linked();
    const first = await order(client, '2026-01-01', 1000); // about ₹82,000: the client's first order
    const small = await order(client, '2026-03-01', 1000);
    expect((await associateCommission.forOrder(first)).below_min).toBe(false);
    const c = await associateCommission.forOrder(small);
    expect(c.below_min).toBe(true);
    expect(c.amount_fc).toBe(0);
    expect((await steps(a))[1]).toBe('Does not count — below ₹5,00,000');
  });

  it('B4: a step counts when the advance clears or the LC is confirmed', async () => {
    const [a, client] = await linked();
    const lc = await order(client, '2026-01-01', 9000, 'lc');
    expect(await steps(a)).toEqual(['Counts when the advance has cleared']);
    await db.execute('INSERT INTO order_lc_details (order_id, lc_confirmed_at) VALUES (:o, :t)', { o: lc, t: '2026-01-05 10:00:00' });
    expect(await steps(a)).toEqual(['Step 1']);
  });

  it('B5: a cancelled order loses its step', async () => {
    const [a, client] = await linked();
    const o1 = await order(client, '2026-01-01', 9000);
    await order(client, '2026-02-01', 9000);
    expect(await steps(a)).toEqual(['Step 1', 'Step 2']);
    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :id", { id: o1 });
    expect(await steps(a)).toEqual(['Step 1']);
  });

  it('B6: an order with a PI before the cut-over keeps the old rule', async () => {
    const [, client] = await linked();
    await order(client, '2026-01-01', 9000);
    const old = await order(client, '2026-03-01', 9000);
    await db.execute("UPDATE orders SET associate_level_fixed_at = '2026-03-02 10:00:00' WHERE id = :id", { id: old });
    expect(await associateCommission.forOrder(old)).not.toBeNull(); // no cut-over: the new rules
    await incentiveRepository.setSetting('associate_rules_from', '2026-11-24', md);
    expect(await associateCommission.forOrder(old)).toBeNull(); // PI before the cut-over and after day 10: the old rule
  });

  it('B7: the per-associate price choice needs a reason', async () => {
    const a = await associate();
    const agent = await staff();
    let r = await post(agent, `/associates/${a.id}`, `/associates/${a.id}/price-rule`, { price_rule: 'fixed', price_rule_fixed_pct: '6', price_rule_reason: 'short' });
    expect(r.message).toContain('Give the reason');
    expect((await associateRepository.find(Number(a.id))).price_rule_override).toBeNull();
    r = await post(agent, `/associates/${a.id}`, `/associates/${a.id}/price-rule`, { price_rule: 'fixed', price_rule_fixed_pct: '6', price_rule_reason: 'Agreed for the new market' });
    expect(r.type).toBe('success');
    const row = await associateRepository.find(Number(a.id));
    expect([row.price_rule_override, Number(row.price_rule_fixed_pct).toFixed(2)]).toEqual(['fixed', '6.00']);
    expect(await associateRules.priceRuleFor(row)).toEqual({ rule: 'fixed', pct: 6, own: true });
    const audit = await db.queryOne("SELECT new_value FROM audit_log WHERE action_type = 'ASSOCIATE_PRICE_RULE_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: a.id });
    expect(audit.new_value).toBe('fixed 6.00% — reason: Agreed for the new market');
    await post(agent, `/associates/${a.id}`, `/associates/${a.id}/price-rule`, { price_rule: 'company', price_rule_reason: 'Back to the company setting' });
    expect((await associateRules.priceRuleFor(await associateRepository.find(Number(a.id)))).own).toBe(false);
  });

  it('B8: the group days are 7–45 and need a reason', async () => {
    const form = { associate_tds_pct: '2.00', associate_commission_due_days: '30', associate_payout_run: 'monthly', associate_payout_day: 'last',
      associate_payout_holiday_rule: 'before', associate_counting_threshold_inr: '500000', associate_protection_months: '6', associate_gst_pct: '18.00',
      associate_portal_commission_months: '12', associate_abroad_charges: 'OUR', associate_abroad_min_payout_usd: '100.00' };
    for (const [k, v] of Object.entries({ associate_payout_run: 'monthly', associate_payout_day: 'last', associate_payout_holiday_rule: 'before' })) await incentiveRepository.setSetting(k, v, md);
    const agent = await staff();
    expect((await post(agent, '/associates/ladder', '/associates/ladder', { ...form, associate_group_days: '60' })).message).toContain('7 to 45');
    expect((await post(agent, '/associates/ladder', '/associates/ladder', { ...form, associate_group_days: '20' })).message).toContain('Give the reason for changing the minimum order value or the days');
    expect(await associateRules.groupDays()).toBe(15);
    await post(agent, '/associates/ladder', '/associates/ladder', { ...form, associate_group_days: '20', ladder_reason: 'Buyers split container orders over three weeks' });
    expect(await associateRules.groupDays()).toBe(20);
  });

  it('B9: changing what the client price includes needs a reason', async () => {
    const agent = await staff();
    const r = await post(agent, '/pricing/settings', '/pricing/settings', { ...(await pricingRepository.settings()), associate_price_rule: 'ladder' });
    expect([r.type, r.message]).toEqual(['error', expect.stringContaining("Give the reason for changing what the client's price includes")]);
    expect((await associateRules.companyPriceRule()).rule).toBe('fixed');
  });

  it('"Paid from our margin so far" counts earned commission and shows open orders apart (owner 24 Nov 2026)', async () => {
    const a = await associate();
    const today = new Date(Date.now() + 5.5 * 3600e3).toISOString().slice(0, 10);
    const ids = {};
    for (const kind of ['paid', 'open']) {
      const client = await createTestClient();
      await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2025-12-01' WHERE id = :c", { a: a.id, c: client });
      const id = await order(client, today, 10000);
      await db.execute(`UPDATE orders SET associate_id = :a, associate_level_name = 'Level 3', associate_level_kind = 'pct', associate_level_value = 7,
                        associate_rate_pct = 7, associate_included_pct = 5, associate_level_fixed_at = NOW() WHERE id = :o`, { a: a.id, o: id });
      await db.execute(`UPDATE order_payment_status SET advance_amount = 3000, advance_inr_actual = 252000, advance_exchange_rate = 84, balance_amount = 7000,
                        balance_cleared_at = :b, balance_inr_actual = :bi, balance_exchange_rate = 84 WHERE order_id = :o`,
        { b: kind === 'paid' ? `${today} 13:00:00` : null, bi: kind === 'paid' ? 588000 : null, o: id });
      const ins = "INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :amt, :d, 84, :inr, 'UTR-MSF')";
      await db.execute(ins, { o: id, l: 'advance', amt: 3000, d: today, inr: 252000 });
      if (kind === 'paid') await db.execute(ins, { o: id, l: 'balance', amt: 7000, d: today, inr: 588000 });
      ids[kind] = id;
    }
    const paid = await associateCommission.forOrder(ids.paid);
    const open = await associateCommission.forOrder(ids.open);
    expect([paid.state, open.state]).toEqual(['paid', 'awaiting']);
    const m = await associateRules.marginSoFar(Number(a.id), today);
    expect([m.orders, m.open_orders]).toEqual([1, 1]); // one earned order counted; the order still being paid shown apart
    expect(m.inr).toBe(Math.round(Number(paid.amount_inr) * 2 / 7 * 100) / 100); // 2 % of the 7 % comes from our margin
    expect(m.open_inr).toBe(Math.round(Number(open.amount_inr) * 2 / 7 * 100) / 100);
  });

  it('works out the included % from the choice', async () => {
    const a = await associate();
    const level7 = { kind: 'pct', value: 7 };
    expect(await associateRules.includedPct(a, level7)).toBe(5); // company default: a fixed 5 %
    expect(await associateRules.includedPct({ ...a, price_rule_override: 'ladder' }, level7)).toBe(7);
    expect(await associateRules.includedPct({ ...a, price_rule_override: 'starting' }, level7)).toBe(2); // the standard ladder's first step
    expect(await associateRules.includedPct(a, { kind: 'fixed_usd', value: 5 })).toBeNull();
    expect(await associateRules.rightNow(a, level7)).toBe('Right now for this associate: client price includes 5% · associate is at 7% · 2% comes from our margin.');
  });
});
