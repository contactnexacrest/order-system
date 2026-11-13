'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const reportRepository = require('../../src/repositories/reportRepository');
const associateCommission = require('../../src/services/associateCommission');
const incentiveReportService = require('../../src/services/incentiveReportService');
const incentiveService = require('../../src/services/incentiveService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const pricingService = require('../../src/services/pricingService');
const { createTestClient, createTestOrder, createTestFile, completeAssociateKyc, pinWorkedExamplePricing } = require('../support/fixtures');

/**
 * Port of D245WorkedExamplesTest.php. Owner decision D-245, phase P6: five worked
 * example orders, end to end, to the paisa — the same inputs and the same figures
 * as the PHP twin. Price from a product whose ₹80,000 supplier price is "used for
 * pricing" (cost USD 1,000.00, minimum 1,250.00, staff floor 1,287.50, staff quote
 * 1,326.13; associate 5% floor 1,355.26, quote 1,395.92), lines picked from
 * Products, the level fixed at the PI, buyer remittances with the INR credited,
 * the MD's lock, payouts and payment; every profit line with its state; and the
 * register, "To pay", "Owed" and profitability report totals equal the sum of
 * these orders.
 */
describe('D-245 worked examples (P6)', () => {
  let admin;
  const p = {};
  const round2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;

  function today() {
    const d = new Date();
    const z = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`;
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-p6-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest P6 User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email, role_id: role.id }
    );
    return r.insertId;
  }

  beforeEach(async () => {
    // QA 13 Nov 2026 (D-347 stale test): the worked examples use margin 20 % and other costs
    // 2.5 % (installed values are 15 % / 5.5 % since D-347) — every pricing setting is pinned.
    await pinWorkedExamplePricing();
    admin = await createTestUser('Admin');
    for (const [k, role] of Object.entries({ sales: 'Export Executive', manager: 'Export Executive', export: 'Export Executive', logistics: 'Logistics Executive',
      marketing: 'Export Executive', accounts: 'Accounts Executive' })) {
      p[k] = await createTestUser(role);
    }
    await incentiveRepository.replaceUserRoles({
      [p.sales]: ['sales', 'export'], [p.manager]: ['sales_manager'], [p.export]: ['export'],
      [p.logistics]: ['logistics'], [p.marketing]: ['marketing'], [p.accounts]: ['accounts'],
    });
    await incentiveRepository.setSetting('program_enabled', '1', admin);
    for (const [k, v] of Object.entries({ associate_commission_due_days: '30', associate_claim_hold: '1', associate_payout_run: 'monthly', associate_payout_day: '5',
      associate_counting_threshold_inr: '500000', associate_gst_pct: '18.00' })) {
      await incentiveRepository.setSetting(k, v, admin);
    }
    incentiveRepository.forgetRoles();
  });

  afterEach(async () => {
    await incentiveRepository.replaceUserRoles({});
    await incentiveRepository.setSetting('program_enabled', '0', admin);
    for (const [k, v] of Object.entries(pricingService.DEFAULTS)) {
      await db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v: String(v) });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** Calls an incentive controller action as the admin (who holds manage_pricing). */
  async function call(action, orderId, body) {
    const controller = require('../../src/controllers/incentiveController');
    const req = { params: { id: String(orderId) }, body, query: {}, session: {}, user: { id: admin }, permissions: { manage_pricing: true, manage_incentives: true } };
    const res = { redirect() {}, status() { return this; }, send() {}, renderView() {} };
    await controller[action](req, res);
    return flash.pull(req)[0] || { type: 'none', message: '' };
  }

  /** The product: ₹80,000 per CBM from the supplier, used for pricing. */
  async function product() {
    const id = await productRepository.create({ name: `P6 Black Galaxy ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite block', hs_code: '25161100', reference_unit: 'CBM' }, admin);
    const s = await productSupplierRepository.create({ product_id: id, supplier_name: 'Sri Balaji Granites', fob_source: 'direct', fob_value: 80000, is_primary: 1 }, admin);
    await pricingRepository.setSupplierDates(s, today(), null);
    return id;
  }

  async function associate(mode, levelName, gstin = null) {
    const level = levelName === null ? null : (await db.queryOne('SELECT id FROM commission_levels WHERE name = :n', { n: levelName })).id;
    const a = (await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id, gstin) VALUES (:n, 'active', :m, :l, :g)",
      { n: `P6 Associate ${crypto.randomBytes(3).toString('hex')}`, m: mode, l: level, g: gstin })).insertId;
    const client = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a, c: client });
    return [a, client];
  }

  /** An order whose lines are picked from the product: [qty, unit price]. */
  async function order(productId, lines, client = null, assignees = []) {
    const orderId = await createTestOrder(client || (await createTestClient()), 'FOB', 'Standard — New Buyer', 'USD', null, null, assignees);
    const ids = [];
    for (let i = 0; i < lines.length; i++) {
      const [qty, price] = lines[i];
      const lineId = await orderProductRepository.add(orderId, i + 1, 'Black Galaxy block', null, null, qty, false, 'CBM', price, '25161100');
      await orderProductRepository.setCatalogProduct(lineId, productId);
      ids.push(lineId);
    }
    return [orderId, ids];
  }

  /** 40% advance and 60% balance at rate (INR credited on each receipt), balance cleared on cleared (null = not yet); supplier bill supplierInr. */
  async function money(orderId, rate, cleared, supplierInr) {
    const total = Number((await db.queryOne('SELECT SUM(fob_value) AS t FROM order_products WHERE order_id = :o', { o: orderId })).t);
    const advance = round2(total * 0.4);
    const balance = round2(total - advance);
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = :a, advance_cleared_at = :ac, advance_inr_actual = :ai, advance_exchange_rate = :r,
        balance_amount = :b, balance_cleared_at = :bc, balance_inr_actual = :bi, balance_exchange_rate = :r2 WHERE order_id = :o`,
      { a: advance, ac: '2026-10-10 10:00:00', ai: round2(advance * rate), r: rate, b: balance,
        bc: cleared === null ? null : `${cleared} 11:00:00`, bi: cleared === null ? null : round2(balance * rate), r2: rate, o: orderId }
    );
    for (const [leg, amount] of [['advance', advance], ['balance', balance]]) {
      if (leg === 'balance' && cleared === null) continue;
      await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', :r, :i, 'UTR-P6')",
        { o: orderId, l: leg, a: amount, r: rate, i: round2(amount * rate) });
    }
    const supplier = (await db.execute("INSERT INTO suppliers (supplier_legal_name) VALUES ('TEST-P6 Supplier')")).insertId;
    await db.execute("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference, total_payable_inr) VALUES (:o, :s, 'TEST-P6 Supplier', :ref, :t)",
      { o: orderId, s: supplier, ref: `TEST-SPO-P6-${orderId}`, t: supplierInr });
  }

  async function profit(orderId) {
    const x = await orderProfitabilityService.computeForOrder(orderId);
    return { revenue: x.revenue_inr, cost: x.cost_before_incentives_inr, before: x.profit_before_commission_inr, commission: x.commission_inr,
      commission_state: x.commission_state === undefined ? null : x.commission_state, incentive: x.incentive_inr, incentive_state: x.incentive_state, after: x.profit_after_incentive_inr };
  }

  async function payouts(orderId) {
    const out = {};
    for (const row of await incentiveRepository.payoutsForOrder(orderId)) {
      const key = row.payee_kind === 'staff' ? Object.keys(p).find((k) => p[k] === Number(row.user_id)) : 'associate';
      out[key] = round2((out[key] || 0) + Number(row.amount_inr));
    }
    return Object.fromEntries(Object.entries(out).sort(([a], [b]) => a.localeCompare(b)));
  }

  /** The register, "To pay", "Owed" and the profitability report add up to these orders, with nothing else mixed in. */
  async function expectReportsAddUp(orderIds) {
    let expected = 0;
    for (const id of orderIds) {
      const x = await orderProfitabilityService.computeForOrder(id);
      expected += x.commission_inr + x.incentive_inr;
    }
    const rows = await incentiveReportService.rows({ order_ids: orderIds });
    for (const r of rows) expect(orderIds).toContain(r.order_id);
    const sum = round2(rows.reduce((s, r) => s + Number(r.amount_inr), 0));
    expect(sum).toBe(round2(expected));
    expect(incentiveReportService.totals(rows).amount_inr).toBe(sum);
    const paged = [];
    const pages = incentiveReportService.page(rows, 1, 2).pages;
    for (let i = 1; i <= pages; i++) paged.push(...incentiveReportService.page(rows, i, 2).rows);
    expect(paged).toEqual(rows);
    const toPay = await incentiveReportService.toPay('', null, { order_ids: orderIds });
    let groupSum = 0;
    for (const g of Object.values(toPay)) {
      expect(g.totals.amount_inr).toBe(round2(g.rows.reduce((s, r) => s + Number(r.amount_inr), 0)));
      groupSum += g.totals.amount_inr;
    }
    expect(round2(groupSum)).toBe(round2(rows.reduce((s, r) => s + (r.state === 'paid' ? 0 : Number(r.amount_inr)), 0)));
    const owed = await incentiveReportService.owedAt(today(), { order_ids: orderIds });
    expect(incentiveReportService.totals(owed).amount_inr).toBe(round2(owed.reduce((s, r) => s + Number(r.amount_inr), 0)));
    const report = await reportRepository.orderProfitabilityReport(null, null, undefined, 'all');
    for (const r of report.rows) {
      if (orderIds.includes(Number(r.order_id))) {
        const x = await orderProfitabilityService.computeForOrder(Number(r.order_id));
        expect([Number(r.profit_before_commission_inr), Number(r.commission_inr), Number(r.incentive_inr), Number(r.profit_after_incentive_inr)])
          .toEqual([x.profit_before_commission_inr, x.commission_inr, x.incentive_inr, x.profit_after_incentive_inr]);
      }
    }
  }

  it('example 1: direct sale, global 15%, locked then paid', async () => {
    const productId = await product();
    const [orderId, lines] = await order(productId, [['20', '1326.13']], null, [p.sales, p.logistics]);
    const f = (await orderLinePricing.forOrder(orderId)).lines[lines[0]];
    expect([f.status, f.figures.cost, f.figures.minimum, f.figures.floor, f.figures.quote]).toEqual([orderLinePricing.OK, 1000, 1250, 1287.5, 1326.13]);
    await orderLinePricing.snapshotForPi(orderId);
    await money(orderId, 83.0, '2026-10-25', 1640000);

    expect(await profit(orderId)).toEqual({ revenue: 2201375.8, cost: 1640000, before: 561375.8, commission: 0, commission_state: null,
      incentive: 84206.37, incentive_state: 'estimated', after: 477169.43 });
    expect(await incentiveService.readyToLock()).toContain(orderId);
    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    // ₹84,206.37 over the 95% of roles with someone (Export Compliance has nobody); the seller holds Sales and Export Documentation.
    expect(await payouts(orderId)).toEqual({ accounts: 8863.83, logistics: 13295.74, manager: 8863.83, marketing: 13295.74, sales: 39887.23 });
    expect(round2(Object.values(await payouts(orderId)).reduce((s, v) => s + v, 0))).toBe(84206.37);
    const locked = await profit(orderId);
    expect([locked.incentive_state, locked.after]).toEqual(['locked', 477169.43]);
    await expectReportsAddUp([orderId]);

    for (const row of await incentiveRepository.payoutsForOrder(orderId)) {
      await incentiveService.markPaid(Number(row.id), today(), Number(row.amount_inr), null, admin);
    }
    const paid = await profit(orderId);
    expect([paid.incentive_state, paid.after]).toEqual(['paid', 477169.43]); // paying changes the state, never the profit
    await expectReportsAddUp([orderId]);
  });

  it('example 2: associate 5%, a below-floor line, GST, the Sales Manager and the claim hold', async () => {
    const productId = await product();
    const [a, client] = await associate('fixed', 'Level 2', '29ABCDE1234F1Z5');
    const [orderId, lines] = await order(productId, [['20', '1395.92'], ['5', '1300.00']], client, [p.sales, p.manager, p.export, p.logistics]);
    const check = (await orderLinePricing.forOrder(orderId)).lines;
    expect([check[lines[0]].status, check[lines[1]].status]).toEqual([orderLinePricing.OK, orderLinePricing.BELOW]);
    expect(await orderLinePricing.piBlockReason(orderId)).not.toBeNull();
    expect(await orderLinePricing.approve(orderId, lines[1], admin, 'Trial container for a new distributor')).toBeNull();
    expect(await orderLinePricing.piBlockReason(orderId)).toBeNull();
    await associateCommission.fixLevelAtPi(orderId);
    await orderLinePricing.snapshotForPi(orderId);
    await money(orderId, 83.0, '2026-10-25', 2050000);

    const c = await associateCommission.forOrder(orderId);
    expect([c.goods_fc, c.basis_fc, c.amount_fc, c.amount_inr, c.due_on, c.payout_run_on]).toEqual([34418.4, 27918.4, 1395.92, 115861.36, '2026-11-24', '2026-12-05']);
    expect(await profit(orderId)).toEqual({ revenue: 2856727.2, cost: 2050000, before: 806727.2, commission: 115861.36, commission_state: 'estimated',
      incentive: 103629.88, incentive_state: 'estimated', after: 587235.96 });

    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    expect(await payouts(orderId)).toEqual({ accounts: 10908.41, associate: 115861.36, export: 8181.3, logistics: 16362.61, manager: 10908.41, marketing: 16362.61, sales: 40906.54 });
    const assoc = await db.queryOne("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate'", { o: orderId });
    expect([String(assoc.due_on).slice(0, 10), String(assoc.payout_run_on).slice(0, 10)]).toEqual(['2026-11-24', '2026-12-05']);
    expect(await associateCommission.gstFor(assoc)).toBe(0); // no tax invoice yet
    await associateDocumentRepository.add(a, 'invoice', await createTestFile(null, null), Number(assoc.id), 'INV-P6-1', true, null);
    expect(await associateCommission.gstFor(assoc)).toBe(20855.04); // GST 18% on top, not a cost
    await completeAssociateKyc(a); // D-252: KYC complete, so only the claim hold applies
    await db.execute("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Two blocks cracked', 'open', '2026-11-20 09:00:00')", { o: orderId, c: client });
    expect(await associateCommission.payBlockReason(assoc, '2026-12-05')).toBe('A buyer claim on this order is open — the commission waits until it is resolved.');
    await db.execute("UPDATE order_complaints SET status = 'resolved' WHERE order_id = :o", { o: orderId });
    expect(await associateCommission.payBlockReason(assoc, '2026-12-05')).toBeNull();
    const after = await profit(orderId);
    expect([after.commission_state, after.incentive_state, after.after]).toEqual(['locked', 'locked', 587235.96]);
    await expectReportsAddUp([orderId]);
  });

  it('example 3: the ladder crosses to 5% at the 6th counting order; a small order does not count', async () => {
    const productId = await product();
    const [a, client] = await associate('ladder', null);
    for (let i = 0; i < 5; i++) {
      const old = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
      await incentiveRepository.saveOrder(old, { associate_id: a, associate_counts: 1, locked_at: new Date() }, admin);
    }
    expect(await associateCommission.countingOrders(a)).toBe(5);
    const [big] = await order(productId, [['10', '1395.92']], client, [p.sales]);
    await associateCommission.fixLevelAtPi(big);
    await money(big, 83.0, '2026-10-25', 820000);
    let c = await associateCommission.forOrder(big);
    expect([c.level_label, c.basis_fc, c.amount_fc, c.amount_inr, c.counts]).toEqual(['Level 2 — 5%', 13959.2, 697.96, 57930.68, true]);
    expect(await incentiveService.lockIfReady(big, admin)).toBeNull();
    expect(await associateCommission.countingOrders(a)).toBe(6);

    const client2 = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a, c: client2 });
    const [small] = await order(productId, [['2', '1395.92']], client2, [p.sales]);
    await associateCommission.fixLevelAtPi(small);
    await money(small, 83.0, '2026-10-25', 164000);
    c = await associateCommission.forOrder(small);
    expect([c.level_label, c.basis_fc, c.amount_fc, c.amount_inr, c.counts]).toEqual(['Level 2 — 5%', 2791.84, 139.59, 11585.97, false]); // USD 139.59 × ₹83.00
    expect(await incentiveService.lockIfReady(small, admin)).toBeNull();
    expect(await associateCommission.countingOrders(a)).toBe(6); // under ₹5 lakh: the current step, not counted
    expect(await profit(big)).toEqual({ revenue: 1158613.6, cost: 820000, before: 338613.6, commission: 57930.68, commission_state: 'locked',
      incentive: 42102.44, incentive_state: 'locked', after: 238580.48 });
    await expectReportsAddUp([big, small]);
  });

  it('example 4: per-order override, ticks, one person with two roles, lock, unlock and history', async () => {
    const productId = await product();
    const [orderId] = await order(productId, [['10', '1326.13']], null, [p.sales, p.logistics, p.marketing, p.accounts]);
    await money(orderId, 83.0, '2026-10-25', 700687.9);
    expect((await profit(orderId)).before).toBe(400000);

    expect((await call('saveOrder', orderId, { incentive_mode: 'pct', incentive_value: '20', incentive_reason: 'Twenty per cent for this order' })).type).toBe('success');
    expect((await incentiveService.calculate(orderId)).amount).toBe(80000);
    expect((await call('saveOrder', orderId, { incentive_mode: 'fixed', incentive_value: '100000', incentive_reason: 'Large first order, weekend work' })).type).toBe('success');
    expect((await call('saveOrderPeople', orderId, { gets_it: { sales: '1', export: '1', logistics: '1' },
      people: { sales: [String(p.sales)], export: [String(p.sales)], logistics: [String(p.logistics)] } })).type).toBe('success');
    const calc = await incentiveService.calculate(orderId);
    expect([calc.team_total, calc.roles.sales.amount, calc.roles.export.amount, calc.roles.logistics.amount, calc.roles.marketing.gets_it, calc.roles.accounts.gets_it])
      .toEqual([100000, 50000, 25000, 25000, false, false]);
    expect(await profit(orderId)).toEqual({ revenue: 1100687.9, cost: 700687.9, before: 400000, commission: 0, commission_state: null,
      incentive: 100000, incentive_state: 'estimated', after: 300000 });

    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    expect(await payouts(orderId)).toEqual({ logistics: 25000, sales: 75000 }); // one person holding Sales and Export Documentation gets both shares
    expect(await incentiveService.unlock(orderId, admin, 'Checking the shares with the team')).toBeNull();
    expect(await incentiveRepository.payoutsForOrder(orderId)).toEqual([]);
    expect(await incentiveService.lockIfReady(orderId, admin)).toBeNull();
    expect(await payouts(orderId)).toEqual({ logistics: 25000, sales: 75000 });
    expect((await incentiveRepository.history(orderId)).map((h) => h.action)).toEqual(['lock', 'unlock', 'lock', 'people', 'amount', 'amount']);
    const x = await profit(orderId);
    expect([x.incentive_state, x.after]).toEqual(['locked', 300000]);
    await expectReportsAddUp([orderId]);
  });

  it('example 5: an agreed claim lowers the basis, unpaid pays nothing, bank charges never reduce it', async () => {
    const productId = await product();
    const [, client] = await associate('fixed', 'Level 2');
    const [claim] = await order(productId, [['10', '1395.92']], client, [p.sales]);
    await associateCommission.fixLevelAtPi(claim);
    await money(claim, 83.0, '2026-10-25', 820000);
    // The bank kept ₹502 of the balance (D-244 "the bank deducted its charges"): never lowers the basis.
    await db.execute("UPDATE order_payment_receipts SET inr_credited = inr_credited - 502, rate_gap_choice = 'bank_charges', rate_gap_inr = 502 WHERE order_id = :o AND leg = 'balance'", { o: claim });
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                      VALUES (:o, 'balance', 8375.52, 7375.52, 1000, 'written_off', 'Buyer claim on chipped edges settled', :u, 'claim_settled')`, { o: claim, u: admin });
    let c = await associateCommission.forOrder(claim);
    expect([c.reduction_fc, c.basis_fc, c.amount_fc, c.amount_inr, round2(c.rate_inr)]).toEqual([1000, 12959.2, 647.96, 53780.68, 83]);

    const [, client2] = await associate('fixed', 'Level 2');
    const [unpaid] = await order(productId, [['10', '1395.92']], client2, [p.sales]);
    await associateCommission.fixLevelAtPi(unpaid);
    await money(unpaid, 83.0, '2026-10-25', 820000);
    await db.execute(`INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                      VALUES (:o, 'balance', 8375.52, 7375.52, 1000, 'written_off', 'Buyer stopped paying', :u, 'unpaid')`, { o: unpaid, u: admin });
    c = await associateCommission.forOrder(unpaid);
    expect([c.state, c.amount_fc, c.amount_inr]).toEqual(['unpaid', 0, 0]); // nothing unless paid in full

    for (const id of [claim, unpaid]) await incentiveService.lockIfReady(id, admin);
    expect((await payouts(claim)).associate).toBe(53780.68);
    expect((await payouts(unpaid)).associate).toBeUndefined();
    await expectReportsAddUp([claim, unpaid]);
  });
});
