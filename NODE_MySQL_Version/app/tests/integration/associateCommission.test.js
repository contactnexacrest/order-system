'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const associateCommission = require('../../src/services/associateCommission');
const incentiveService = require('../../src/services/incentiveService');
const { createTestClient, createTestOrder, createTestFile, completeAssociateKyc } = require('../support/fixtures');

/**
 * Port of AssociateCommissionTest.php — owner decision D-245, phase P3 (4 Nov 2026):
 * associate commission on the final invoiced FOB of the goods, line by line, at the
 * level fixed at PI, full payment only; ladder by counting orders; due N days after
 * full payment, held while a buyer claim is open, paid in the payout run; GST on top
 * for a GST-registered associate with a tax invoice; take-back only for fraud or
 * breach; forcing commission on is the MD's, with a reason; a registration lapses.
 */
describe('Associate commission (D-245, P3)', () => {
  const PASSWORD = 'JestCommission!1';
  let admin;

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-p3-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email, roleId: role.id };
  }

  async function login(u) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return agent;
  }

  /** Posts a form and returns the flash message shown after the redirect. */
  async function post(agent, from, url, body) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  const level = async (name) => Number((await db.queryOne('SELECT id FROM commission_levels WHERE name = :n', { n: name })).id);

  beforeAll(async () => {
    admin = await user('Admin');
  });

  beforeEach(async () => {
    for (const [k, v] of Object.entries({ associate_commission_due_days: '30', associate_claim_hold: '1', associate_payout_run: 'monthly', associate_payout_day: '5',
      associate_counting_threshold_inr: '500000', associate_gst_pct: '18.00', associate_protection_months: '6' })) {
      await incentiveRepository.setSetting(k, v, admin.id);
    }
  });

  afterAll(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
    await db.pool.end();
  });

  /** [associate id, client id] */
  async function associate(mode = 'fixed', levelName = 'Level 2', gstin = null) {
    const a = (await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id, gstin) VALUES (:n, 'active', :m, :l, :g)",
      { n: `P3 Associate ${crypto.randomBytes(3).toString('hex')}`, m: mode, l: mode === 'fixed' ? await level(levelName) : null, g: gstin })).insertId;
    const client = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a, c: client });
    return [a, client];
  }

  /** A USD order with goods lines [fob, qty, below-floor approved?], paid in full at ₹83.00 (receipts with the INR credited). */
  async function order(client, lines, cleared = '2026-10-25', rate = 83) {
    const orderId = await createTestOrder(client, 'FOB', 'Standard — New Buyer', 'USD');
    let total = 0;
    for (const [i, l] of lines.entries()) {
      await db.execute(
        `INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type, price_approval_commission_zero, price_approved_by)
         VALUES (:o, :n, 'Granite', :q, 'CBM', :p, :f, 'goods', :z, :by)`,
        { o: orderId, n: i + 1, q: l[1], p: Math.round(l[0] / l[1] * 100) / 100, f: l[0], z: l[2] ? 1 : 0, by: l[2] ? admin.id : null }
      );
      total += l[0];
    }
    const advance = Math.round(total * 0.4 * 100) / 100;
    const balance = Math.round((total - advance) * 100) / 100;
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = :a, advance_cleared_at = :ac, advance_inr_actual = :ai, advance_exchange_rate = :r,
        balance_amount = :b, balance_cleared_at = :bc, balance_inr_actual = :bi, balance_exchange_rate = :r2 WHERE order_id = :o`,
      { a: advance, ac: '2026-10-10 10:00:00', ai: Math.round(advance * rate * 100) / 100, r: rate, b: balance,
        bc: cleared === null ? null : `${cleared} 11:00:00`, bi: cleared === null ? null : Math.round(balance * rate * 100) / 100, r2: rate, o: orderId }
    );
    for (const [leg, amount] of [['advance', advance], ['balance', balance]]) {
      await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', :r, :i, 'UTR-P3')",
        { o: orderId, l: leg, a: amount, r: rate, i: Math.round(amount * rate * 100) / 100 });
    }
    return orderId;
  }

  const payoutOf = (orderId) => db.queryOne("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL", { o: orderId });

  it('fixed 5% on the invoiced goods FOB, a line approved below the floor earns nothing', async () => {
    const [a, client] = await associate('fixed', 'Level 2');
    const orderId = await order(client, [[7525, 500], [4380, 300], [2840, 200, true]]);
    const c = await associateCommission.forOrder(orderId);
    expect([c.goods_fc, c.in_range_fc, c.basis_fc, c.amount_fc, c.rate_inr]).toEqual([14745, 11905, 11905, 595.25, 83]);
    expect(c.lines.map((l) => l.commission)).toEqual([376.25, 219, 0]);
    expect([c.amount_inr, c.basis_inr, c.state, c.counts]).toEqual([49405.75, 988115, 'paid', true]);
    expect([c.due_on, c.payout_run_on]).toEqual(['2026-11-24', '2026-12-05']);

    await associateCommission.fixLevelAtPi(orderId);
    await pricingRepository.setAssociateCommission(a, 'fixed', await level('Level 4'));
    expect((await associateCommission.forOrder(orderId)).level_label).toBe('Level 2 — 5%');

    await incentiveService.lockIfReady(orderId, admin.id);
    const p = await payoutOf(orderId);
    expect([p.amount_inr, p.amount_fc, p.due_on, p.payout_run_on, p.incentive_month]).toEqual(['49405.75', '595.25', '2026-11-24', '2026-12-05', '2026-12']);
    const name = (await associateRepository.find(a)).name;
    expect((await incentiveRepository.costEntries(orderId)).associate.description)
      .toBe(`Associate commission — ${name} (Level 2 — 5%) on the invoiced FOB of the goods, USD 11,905.00; lines the MD approved below the floor earn nothing`);
    const row = await incentiveRepository.forOrder(orderId);
    expect([String(row.associate_counts), row.associate_basis_fc, row.associate_basis_inr, row.associate_due_on]).toEqual(['1', '11905.00', '988115.00', '2026-11-24']);
  });

  it('bank charges never reduce the basis', async () => {
    const [, client] = await associate();
    const orderId = await order(client, [[1800, 10]]);
    await db.execute("UPDATE order_payment_receipts SET inr_credited = inr_credited - 502, rate_gap_choice = 'bank_charges', rate_gap_inr = 502 WHERE order_id = :o AND leg = 'balance'", { o: orderId });
    const c = await associateCommission.forOrder(orderId);
    expect([Math.round(c.rate_inr * 10000) / 10000, c.amount_fc, c.amount_inr]).toEqual([83, 90, 7470]);
  });

  it('the ladder counts orders and a small order earns the current step without counting', async () => {
    const [a, client] = await associate('ladder');
    const assoc = await associateRepository.find(a);
    expect((await associateCommission.levelForAssociate(assoc, null))[0].name).toBe('Level 1');
    for (let i = 0; i < 5; i++) {
      const old = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
      await incentiveRepository.saveOrder(old, { associate_id: a, associate_counts: 1, locked_at: '2026-10-01 10:00:00' }, admin.id);
    }
    expect(await associateCommission.countingOrders(a)).toBe(5);
    expect((await associateCommission.levelForAssociate(assoc, null, 600000))[0].name).toBe('Level 2');
    expect((await associateCommission.levelForAssociate(assoc, null, 100000))[0].name).toBe('Level 1');

    const big = await order(client, [[10000, 10]]);
    await associateCommission.fixLevelAtPi(big);
    const fixed = await db.queryOne('SELECT associate_level_name, associate_level_kind, associate_level_value FROM orders WHERE id = :id', { id: big });
    expect([fixed.associate_level_name, fixed.associate_level_kind, fixed.associate_level_value]).toEqual(['Level 2', 'pct', '5.0000']);
    await incentiveService.lockIfReady(big, admin.id);
    const bigRow = await incentiveRepository.forOrder(big);
    expect([String(bigRow.associate_counts), String(bigRow.associate_order_number)]).toEqual(['1', '6']);

    const client2 = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a, c: client2 });
    const small = await order(client2, [[1000, 1]]);
    await associateCommission.fixLevelAtPi(small);
    const c = await associateCommission.forOrder(small);
    expect([c.level_label, c.amount_fc, c.counts]).toEqual(['Level 2 — 5%', 50, false]);
    await incentiveService.lockIfReady(small, admin.id);
    expect([String((await incentiveRepository.forOrder(small)).associate_counts), await associateCommission.countingOrders(a)]).toEqual(['0', 6]);
  });

  it('an agreed claim lowers the basis and an unpaid amount pays nothing', async () => {
    const [, client] = await associate();
    const claim = await order(client, [[10000, 10]]);
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                      VALUES (:o, 'balance', 6000, 5000, 1000, 'written_off', 'Buyer claim on chipped edges settled', :u, 'claim_settled')`, { o: claim, u: admin.id });
    const c = await associateCommission.forOrder(claim);
    expect([c.reduction_fc, c.basis_fc, c.amount_fc, c.amount_inr]).toEqual([1000, 9000, 450, 37350]);

    const [a2, client2] = await associate();
    const unpaid = await order(client2, [[10000, 10]]);
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                      VALUES (:o, 'balance', 6000, 5000, 1000, 'written_off', 'Buyer stopped paying', :u, 'unpaid')`, { o: unpaid, u: admin.id });
    const u = await associateCommission.forOrder(unpaid);
    expect([u.state, u.amount_fc, u.amount_inr]).toEqual(['unpaid', 0, 0]);
    await incentiveService.lockIfReady(unpaid, admin.id);
    expect(await payoutOf(unpaid)).toBeNull();
    expect(String((await incentiveRepository.forOrder(unpaid)).associate_counts)).toBe('0');
    expect(await associateCommission.countingOrders(a2)).toBe(0);
  });

  it('nothing before full payment; the shortfall kinds', async () => {
    const [, client] = await associate();
    const orderId = await order(client, [[1000, 1]], null);
    const c = await associateCommission.forOrder(orderId);
    expect([c.state, c.due_on, c.amount_fc]).toEqual(['awaiting', null, 50]);
    await incentiveService.lockIfReady(orderId, admin.id);
    expect(await incentiveRepository.forOrder(orderId)).toBeNull();
    expect(Object.keys(associateCommission.SETTLEMENT_KINDS)).toEqual(['agreed_discount', 'claim_settled', 'unpaid']);

    // Nothing received yet: the estimate uses the Admin pricing exchange rate, never 0.
    await db.execute('DELETE FROM order_payment_receipts WHERE order_id = :o', { o: orderId });
    await db.execute('DELETE FROM order_payment_status WHERE order_id = :o', { o: orderId });
    const fx = Number((await require('../../src/repositories/pricingRepository').settings()).pricing_fx_rate);
    expect(fx).toBeGreaterThan(0);
    const est = await associateCommission.forOrder(orderId);
    expect([Number(est.rate_inr), !!est.rate_estimated, Math.round(Number(est.amount_inr) * 100) / 100]).toEqual([fx, true, Math.round(50 * fx * 100) / 100]);
  });

  it('due date, claim hold and payout run', async () => {
    expect(await associateCommission.payoutRunOn('2026-12-03')).toBe('2026-12-05');
    expect(await associateCommission.payoutRunOn('2026-12-05')).toBe('2026-12-05');
    expect(await associateCommission.payoutRunOn('2026-12-06')).toBe('2027-01-05');
    await incentiveRepository.setSetting('associate_payout_run', 'weekly', admin.id);
    await incentiveRepository.setSetting('associate_payout_day', '1', admin.id);
    expect(await associateCommission.payoutRunOn('2026-11-24')).toBe('2026-11-30');
    await incentiveRepository.setSetting('associate_payout_run', 'monthly', admin.id);
    await incentiveRepository.setSetting('associate_payout_day', '5', admin.id);

    const [kycA, client] = await associate();
    await completeAssociateKyc(typeof kycA === 'object' ? kycA.id : kycA); // D-252: KYC complete, so only the due date and claim hold apply
    const orderId = await order(client, [[1000, 1]]);
    await incentiveService.lockIfReady(orderId, admin.id);
    const p = await payoutOf(orderId);
    expect(await associateCommission.payBlockReason(p, '2026-11-20')).toContain('due on 24 Nov 2026');
    expect(await associateCommission.payBlockReason(p, '2026-12-05')).toBeNull();
    await db.execute("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Chipped edges on 3 slabs', 'open', '2026-11-20 09:00:00')", { o: orderId, c: client });
    expect(await associateCommission.payBlockReason(p, '2026-12-05')).toBe('A buyer claim on this order is open — the commission waits until it is resolved.');
    await db.execute("UPDATE order_complaints SET status = 'resolved' WHERE order_id = :o", { o: orderId });
    expect(await associateCommission.payBlockReason(p, '2026-12-05')).toBeNull();
    await db.execute("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Late complaint', 'open', '2026-11-30 09:00:00')", { o: orderId, c: client });
    expect(await associateCommission.payBlockReason(p, '2026-12-05')).toBeNull();
  });

  it('GST on top only for a GST-registered associate with a tax invoice', async () => {
    const [a, client] = await associate('fixed', 'Level 2', '29ABCDE1234F1Z5');
    const orderId = await order(client, [[10000, 10]]);
    await incentiveService.lockIfReady(orderId, admin.id);
    const p = await payoutOf(orderId);
    expect(p.amount_inr).toBe('41500.00');
    expect(await associateCommission.gstFor(p)).toBe(0);
    await associateDocumentRepository.add(a, 'invoice', await createTestFile(null, null), Number(p.id), 'INV-P3-1', true, null);
    expect(await associateCommission.gstFor(p)).toBe(7470);

    const [b, client2] = await associate();
    const order2 = await order(client2, [[10000, 10]]);
    await incentiveService.lockIfReady(order2, admin.id);
    const p2 = await payoutOf(order2);
    await associateDocumentRepository.add(b, 'invoice', await createTestFile(null, null), Number(p2.id), 'INV-P3-2', true, null);
    expect(await associateCommission.gstFor(p2)).toBe(0);
    const ref = (await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id: order2 })).order_reference;
    expect(await associateCommission.payoutDescription(p2))
      .toBe(`Associate commission to ${(await associateRepository.find(b)).name} on order ${ref} (payout no. ${p2.id}, settles their commission invoice for this order): ₹41,500.00`);
  });

  it('take-back only for fraud or breach, with a reason', async () => {
    const [a, client] = await associate();
    const orderId = await order(client, [[1000, 1]]);
    await incentiveService.lockIfReady(orderId, admin.id);
    const p = await payoutOf(orderId);
    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = amount_inr, paid_reference = 'UTR-PAID-1' WHERE id = :id", { id: p.id });
    const md = await user('Managing Director');
    const agent = await login(md);
    expect(await post(agent, `/associates/${a}`, `/associates/payouts/${p.id}/clawback`, { amount_inr: '1000', reason: 'Buyer was refunded for damage' }))
      .toContain('only for the associate\'s fraud or breach');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM incentive_payouts WHERE clawback_of_payout_id = :id', { id: p.id })).n)).toBe(0);
    await post(agent, `/associates/${a}`, `/associates/payouts/${p.id}/clawback`, { amount_inr: '1000', reason: 'Fake second quote', takeback_kind: 'fraud' });
    const tb = await db.queryOne('SELECT amount_inr, takeback_kind FROM incentive_payouts WHERE clawback_of_payout_id = :id', { id: p.id });
    expect([tb.amount_inr, tb.takeback_kind]).toEqual(['-1000.00', 'fraud']);
  });

  it('forcing commission on is the MD\'s, with a reason', async () => {
    const [, client] = await associate();
    const orderId = await order(client, [[1000, 1]], null);
    const reason = 'Buyer introduced before the registration was recorded';
    // An Admin may manage incentives but is not the MD.
    const ed = await login(await user('Admin'));
    expect(await post(ed, `/orders/${orderId}`, `/orders/${orderId}/incentive`, { associate_mode: 'yes', associate_mode_reason: reason }))
      .toBe('Only the MD can force the associate\'s commission on for an order.');
    const mdUser = await user('Managing Director');
    const md = await login(mdUser);
    expect(await post(md, `/orders/${orderId}`, `/orders/${orderId}/incentive`, { associate_mode: 'yes', associate_mode_reason: 'short' }))
      .toContain('10 to 300 characters');
    await post(md, `/orders/${orderId}`, `/orders/${orderId}/incentive`, { associate_mode: 'yes', associate_mode_reason: reason });
    const row = await incentiveRepository.forOrder(orderId);
    expect([row.associate_mode, row.associate_mode_reason, Number(row.associate_mode_set_by)]).toEqual(['yes', reason, mdUser.id]);
    expect((await db.queryOne("SELECT reason FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_FORCED' AND entity_id = :id", { id: orderId })).reason).toBe(reason);
  });

  it('a registration with no order lapses', async () => {
    const [a, client] = await associate();
    await db.execute("UPDATE clients SET associate_locked_until = '2026-10-01' WHERE id = :c", { c: client });
    const [, withOrder] = await associate();
    await db.execute("UPDATE clients SET associate_locked_until = '2026-10-01' WHERE id = :c", { c: withOrder });
    await createTestOrder(withOrder);
    expect(await associateCommission.lapseRegistrations('2026-11-04')).toBeGreaterThanOrEqual(1);
    expect((await db.queryOne('SELECT associate_id FROM clients WHERE id = :c', { c: client })).associate_id).toBeNull();
    expect((await db.queryOne('SELECT associate_id FROM clients WHERE id = :c', { c: withOrder })).associate_id).not.toBeNull();
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM associate_events WHERE associate_id = :a AND event = 'registration_lapsed'", { a })).n)).toBe(1);
  });

  // The P3 price list and its acknowledgement were replaced by price requests (owner decision D-255, 5 Nov 2026) — see d255PriceRequests.test.js.
});
