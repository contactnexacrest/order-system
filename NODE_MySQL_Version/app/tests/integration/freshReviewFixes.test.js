'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const crypto = require('crypto');
const db = require('../../src/config/db');
const associateDocumentRepository = require('../../src/repositories/associateDocumentRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderQualityDocumentRepository = require('../../src/repositories/orderQualityDocumentRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const associateCommission = require('../../src/services/associateCommission');
const associateTds = require('../../src/services/associateTds');
const divisionService = require('../../src/services/divisionService');
const incentiveReportService = require('../../src/services/incentiveReportService');
const incentiveService = require('../../src/services/incentiveService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const priceRequestService = require('../../src/services/priceRequestService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { round2 } = require('../../src/helpers/money');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of FreshReviewFixesTest.php — the fresh-eyes review of 5 Nov 2026, item by item,
 * with the same figures on both stacks.
 *
 *   H1  a "USD x per unit" commission or incentive on a non-USD order is converted at the USD rate
 *   M1  an order's incentive is locked once (also when two requests lock it at the same moment);
 *       unlock never deletes a paid payout
 *   M2  after the client moves to another associate, the old associate's fixed level no longer applies
 *   M3  money rounding matches PHP round(): half away from zero, negatives included
 *   M4  a change of a line's options or unit clears its fixed figures and voids the MD's approval
 *   M5  the Zoho payment description names the GST paid on top
 *   L1  the incentive register, ready-to-lock and the price-request report follow the division scope
 *   L2  GST on a paid payout is worked out on the gross billed (paid + TDS)
 *   L3  a deleted line asks for no quality document
 *   (L4, the atomic daily price-request limit, is in d255PriceRequests.test.js.)
 */
describe('Fresh-eyes review fixes (5 Nov 2026)', () => {
  let admin;
  let fxBefore;
  const levels = [];

  const level = async (name) => Number((await db.queryOne('SELECT id FROM commission_levels WHERE name = :n', { n: name })).id);

  async function newLevel(kind, value) {
    const id = Number(await pricingRepository.createLevel(`FR ${kind} ${crypto.randomBytes(3).toString('hex')}`, kind, value, admin));
    levels.push(id);
    return id;
  }

  /** [associate id, client id] — the associate on a fixed level */
  async function associate(levelId, gstin = null) {
    const a = Number((await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id, gstin) VALUES (:n, 'active', 'fixed', :l, :g)",
      { n: `FR Associate ${crypto.randomBytes(3).toString('hex')}`, l: levelId, g: gstin })).insertId);
    const client = await createTestClient();
    await db.execute("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c", { a, c: client });
    return [a, client];
  }

  /** An order in `currency` with goods lines [fob, qty], paid in full at `rate` rupees per unit of the order currency, balance cleared 25 Oct. */
  async function order(client, currency, lines, rate) {
    const orderId = await createTestOrder(client, 'FOB', 'Standard — New Buyer', currency);
    let total = 0;
    for (const [i, l] of lines.entries()) {
      await db.execute(
        `INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type)
         VALUES (:o, :n, 'Granite', :q, 'SQM', :p, :f, 'goods')`,
        { o: orderId, n: i + 1, q: l[1], p: round2(l[0] / l[1]), f: l[0] }
      );
      total += l[0];
    }
    const advance = round2(total * 0.4);
    const balance = round2(total - advance);
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(
      `UPDATE order_payment_status SET advance_amount = :a, advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = :ai, advance_exchange_rate = :r,
        balance_amount = :b, balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = :bi, balance_exchange_rate = :r2 WHERE order_id = :o`,
      { a: advance, ai: round2(advance * rate), r: rate, b: balance, bi: round2(balance * rate), r2: rate, o: orderId }
    );
    for (const [leg, amount] of [['advance', advance], ['balance', balance]]) {
      await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', :r, :i, 'UTR-FR')",
        { o: orderId, l: leg, a: amount, r: rate, i: round2(amount * rate) });
    }
    return orderId;
  }

  const payoutOf = (orderId) => db.queryOne("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL", { o: orderId });

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = Number((await db.execute(
      "INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled) VALUES ('Jest Fresh Review', :e, 'x', :r, 1, 0, 0)",
      { e: `jest-fr-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, r: role.id })).insertId);
    fxBefore = String((await pricingRepository.settings()).pricing_fx_rate);
  });

  beforeEach(async () => {
    for (const [k, v] of Object.entries({ associate_commission_due_days: '30', associate_claim_hold: '1', associate_payout_run: 'monthly', associate_payout_day: '5',
      associate_counting_threshold_inr: '500000', associate_gst_pct: '18.00', associate_protection_months: '6' })) {
      await incentiveRepository.setSetting(k, v, admin);
    }
    await pricingRepository.setSetting('pricing_fx_rate', '83.5000', admin);
  });

  afterEach(async () => {
    await pricingRepository.setSetting('pricing_fx_rate', fxBefore, admin);
    await db.execute("UPDATE pricing_settings SET setting_value = 'pct' WHERE setting_key = 'incentive_mode'");
    await db.execute("UPDATE pricing_settings SET setting_value = '15.0000' WHERE setting_key = 'incentive_value'");
    await db.execute('UPDATE orders SET division_id = 1 WHERE division_id <> 1');
    await db.execute('UPDATE associates SET division_id = 1 WHERE division_id <> 1');
  });

  afterAll(async () => {
    for (const id of levels) {
      await db.execute('UPDATE associates SET commission_level_id = NULL WHERE commission_level_id = :l', { l: id });
      await db.execute('UPDATE orders SET associate_level_id = NULL WHERE associate_level_id = :l', { l: id });
      await db.execute('DELETE FROM commission_levels WHERE id = :l', { l: id });
    }
    await db.pool.end();
  });

  it('H1: a fixed USD level on a euro order is converted at the USD rate', async () => {
    const [, client] = await associate(await newLevel('fixed_usd', '1.00'));
    // EUR order, 2,000 SQM, the buyer's euros realised ₹90.00; the Admin pricing exchange rate (USD) is ₹83.50.
    const eur = await order(client, 'EUR', [[30000, 2000]], 90);
    let c = await associateCommission.forOrder(eur);
    expect([c.amount_inr, c.amount_fc, c.usd_rate, c.rate_inr]).toEqual([167000, 1855.56, 83.5, 90]);
    expect(c.lines.map((l) => l.commission)).toEqual([1855.56]);

    // A USD order is unchanged: USD 2,000 at the rate its receipts realised (₹83.00).
    const [, client2] = await associate(await newLevel('fixed_usd', '1.00'));
    const usd = await order(client2, 'USD', [[30000, 2000]], 83);
    c = await associateCommission.forOrder(usd);
    expect([c.amount_inr, c.amount_fc, c.usd_rate]).toEqual([166000, 2000, null]);
  });

  it('H1: a USD incentive rule on a euro order, and the ladder estimate', async () => {
    const [, client] = await associate(await level('Level 2'));
    const eur = await order(client, 'EUR', [[30000, 2000]], 90);
    await pricingRepository.setSetting('incentive_mode', 'usd', admin);
    await pricingRepository.setSetting('incentive_value', '0.5000', admin);
    const g = await incentiveService.globalAmount(eur, await orderRepository.find(eur), 1000000);
    expect(g.amount).toBe(83500);

    expect(await associateCommission.estimateInr({ ...(await orderRepository.find(eur)), id: eur, currency_code: 'EUR' }, 5800)).toBe(522000);
    expect(await associateCommission.estimateInr({ currency_code: 'USD' }, 5800)).toBe(484300);
    expect(await associateCommission.estimateInr({ currency_code: 'INR' }, 5800)).toBe(5800);
  });

  it('M1: an order is locked once and unlock never deletes a paid payout', async () => {
    const [, client] = await associate(await level('Level 2'));
    const orderId = await order(client, 'USD', [[10000, 10]], 83);
    // Two lock requests at the same moment: exactly one writes.
    const results = await Promise.all([incentiveService.lockIfReady(orderId, admin), incentiveService.lockIfReady(orderId, admin)]);
    expect(results.filter((r) => r === null)).toHaveLength(1);
    expect(results.filter((r) => r !== null)).toEqual(['This order\'s incentive is already locked.']);
    expect(await incentiveService.lock(orderId, '2026-10-25', admin)).toBe(false);
    expect(await incentiveService.lockIfReady(orderId, admin)).toBe('This order\'s incentive is already locked.');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM incentive_payouts WHERE order_id = :o', { o: orderId })).n)).toBe(1);
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM order_cost_entries WHERE order_id = :o AND incentive_kind = 'associate'", { o: orderId })).n)).toBe(1);

    const p = await payoutOf(orderId);
    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = amount_inr, paid_reference = 'UTR-FR-PAID' WHERE id = :id", { id: p.id });
    expect(await incentiveService.unlock(orderId, admin, 'Correcting the commission level')).toBe('Part of this order\'s incentive is already paid, so it can no longer be unlocked.');
    await incentiveRepository.deleteOrderPayouts(orderId);
    expect((await payoutOf(orderId)).paid_reference).toBe('UTR-FR-PAID');
  });

  it('M2: after the client moves, the new associate earns at their own level', async () => {
    const [a, client] = await associate(await level('Level 4'));
    const orderId = await order(client, 'USD', [[10000, 10]], 83);
    await associateCommission.fixLevelAtPi(orderId);
    let c = await associateCommission.forOrder(orderId);
    expect([c.level_label, c.level_fixed, c.amount_fc]).toEqual(['Level 4 — 10%', true, 1000]);

    const [b] = await associate(await level('Level 1'));
    await db.execute('UPDATE clients SET associate_id = :b WHERE id = :c', { b, c: client });
    c = await associateCommission.forOrder(orderId);
    expect([c.id, c.level_label, c.level_fixed, c.amount_fc, c.amount_inr]).toEqual([b, 'Level 1 — 2%', false, 200, 16600]);
    expect(associateCommission.fixedLevel(await orderRepository.find(orderId), b)).toBeNull();
    expect(associateCommission.fixedLevel(await orderRepository.find(orderId), a).name).toBe('Level 4');
  });

  it('M3: money rounding is half away from zero, with the same figures as PHP', async () => {
    expect([round2(1003 * 0.075), round2(75.23 * 83.5), round2(2.135), round2(8.995), round2(-12.345)]).toEqual([75.23, 6281.71, 2.14, 9, -12.35]);
    expect(associateCommission.round2(-12.345)).toBe(-12.35);
    expect(incentiveService.round2(8.995)).toBe(9);
    const [, client] = await associate(await newLevel('pct', '7.5'));
    const orderId = await order(client, 'USD', [[1003, 1]], 83.5);
    const c = await associateCommission.forOrder(orderId);
    expect([c.amount_fc, c.amount_inr]).toEqual([75.23, 6281.71]);
  });

  it('M4: a change of options or unit clears the fixed figures and voids the approval', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const lineId = Number((await db.execute(
      `INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type, spec_selection, price_snapshot,
         price_approved_by, price_approved_at, price_approval_reason, price_approved_unit_price, price_approved_selection, price_approval_commission_zero)
       VALUES (:o, 1, 'Granite 20 mm polished', 100, 'SQM', 52.00, 5200.00, 'goods', '{"1":2}', '{"floor":50}', :u, NOW(), 'Buyer repeat order', 52.00, '{"1":2}|SQM', 1)`,
      { o: orderId, u: admin })).insertId);
    let before = await orderProductRepository.find(lineId);
    expect(orderLinePricing.approvalHolds(before)).toBe(true);

    await db.execute(`UPDATE order_products SET spec_selection = '{"1":3}' WHERE id = :id`, { id: lineId });
    expect(orderLinePricing.approvalHolds(await orderProductRepository.find(lineId))).toBe(false);
    await orderLinePricing.afterSave(lineId, before, admin);
    const after = await orderProductRepository.find(lineId);
    expect([after.price_snapshot, after.price_approved_at, after.price_approved_unit_price, after.price_approved_selection, Number(after.price_approval_commission_zero)])
      .toEqual([null, null, null, null, 0]);
    expect((await db.queryOne("SELECT reason FROM audit_log WHERE action_type = 'LINE_PRICE_APPROVAL_VOIDED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: lineId })).reason)
      .toBe('Order line 1: the options or unit changed after the MD approved it — the approval no longer holds.');

    await db.execute(`UPDATE order_products SET price_snapshot = '{"floor":50}' WHERE id = :id`, { id: lineId });
    before = await orderProductRepository.find(lineId);
    await db.execute("UPDATE order_products SET unit = 'CBM' WHERE id = :id", { id: lineId });
    await orderLinePricing.afterSave(lineId, before, admin);
    expect((await orderProductRepository.find(lineId)).price_snapshot).toBeNull();
    expect(orderLinePricing.approvalSelectionKey(await orderProductRepository.find(lineId))).toBe('{"1":3}|CBM');
  });

  it('M5 and L2: the Zoho payment names the GST on the gross billed', async () => {
    expect(associateTds.paymentDescription('Ravi Kumar', ['SC/ORD/26/001'], 50000, 2500, 5, '194H', 47500, 'UTR998877', '194H', 9000))
      .toBe('Associate commission for Ravi Kumar — order SC/ORD/26/001 — gross ₹50,000.00, TDS u/s 194H ₹2,500.00 (5%), net paid ₹47,500.00'
        + " + GST ₹9,000.00 on the associate's tax invoice (input credit), total paid ₹56,500.00 · UTR UTR998877");
    expect(associateTds.paymentDescription('Ravi Kumar', ['SC/ORD/26/001'], 50000, 0, null, null, 50000, 'U1'))
      .toBe('Associate commission for Ravi Kumar — order SC/ORD/26/001 — gross ₹50,000.00, no TDS, net paid ₹50,000.00 · UTR U1');

    const [a, client] = await associate(await level('Level 2'), '29ABCDE1234F1Z5');
    const orderId = await order(client, 'USD', [[10000, 10]], 83);
    await incentiveService.lockIfReady(orderId, admin);
    const p = await payoutOf(orderId);
    await associateDocumentRepository.add(a, 'invoice', await createTestFile(null, null), Number(p.id), 'INV-FR-1', true, null);
    expect(await associateCommission.gstFor(p)).toBe(7470);
    expect(await associateCommission.gstFor({ ...p, paid_inr: '40000.00', tds_inr: '1000.00' })).toBe(7380);

    await db.execute("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = 40000.00, tds_inr = 1000.00, gst_pct = 18.00, gst_inr = 7380.00, paid_reference = 'UTR-FR-GST' WHERE id = :id", { id: p.id });
    const ref = (await db.queryOne('SELECT buyer_inquiry_ref FROM orders WHERE id = :id', { id: orderId })).buyer_inquiry_ref;
    const z = await zohoBooksService.associateCommission({ order_id: orderId, buyer_inquiry_ref: ref, buyer_name: 'Buyer' });
    expect(zohoBooksService.associatePaymentPayload(z, 'V1', 'B1', 'A1').amount).toBe(47380);
    expect(z.payment_description).toContain(", net paid ₹40,000.00 + GST ₹7,380.00 on the associate's tax invoice (input credit), total paid ₹47,380.00 · UTR UTR-FR-GST");
  });

  it('L1: the register, ready-to-lock and the price-request report follow the division', async () => {
    const [a, client] = await associate(await level('Level 2'));
    const exp = await order(client, 'USD', [[1000, 1]], 83);
    const [b, client2] = await associate(await level('Level 2'));
    const dom = await order(client2, 'USD', [[1000, 1]], 83);
    await db.execute('UPDATE orders SET division_id = 2 WHERE id = :id', { id: dom });
    await db.execute('UPDATE associates SET division_id = 2 WHERE id = :id', { id: b });

    expect(await divisionService.withDivision(1, () => incentiveReportService.openOrderIds({ order_ids: [exp, dom] }))).toEqual([exp]);
    const ready = await divisionService.withDivision(1, () => incentiveRepository.unlockedOrderIdsWithPayments());
    expect(ready).toContain(exp);
    expect(ready).not.toContain(dom);

    await incentiveService.lockIfReady(exp, admin);
    await incentiveService.lockIfReady(dom, admin);
    const rows = await divisionService.withDivision(2, () => incentiveReportService.rows({ order_ids: [exp, dom] }));
    expect([...new Set(rows.map((r) => r.order_id))]).toEqual([dom]);

    const product = Number(await require('../../src/repositories/productRepository').create({ name: `FR Granite ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022390', reference_unit: 'SQM' }, admin));
    for (const associateId of [a, b]) {
      await db.execute(`INSERT INTO price_requests (number, associate_id, product_id, selection, selection_text, kind, status, unit, requested_at)
                        VALUES (:n, :a, :p, '{}', 'Standard specification', 'manual', 'pending', 'SQM', NOW())`,
      { n: `PR-FR-${associateId}`, a: associateId, p: product });
    }
    await db.execute('UPDATE price_requests SET division_id = 2 WHERE associate_id = :b', { b });
    const ids = (await divisionService.withDivision(1, () => priceRequestService.report())).map((r) => r.associate_id);
    expect(ids).toContain(a);
    expect(ids).not.toContain(b);
  });

  it('L3: a deleted line asks for no quality document', async () => {
    const category = Number((await db.execute("INSERT INTO product_categories (name, default_unit, allowed_units, quality_documents) VALUES (:n, 'PCS', 'PCS', 'Mill test certificate 3.1')",
      { n: `FR Engineering ${crypto.randomBytes(3).toString('hex')}` })).insertId);
    const orderId = await createTestOrder(await createTestClient());
    const lineId = Number((await db.execute(
      "INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type, category_id) VALUES (:o, 1, 'Weld neck flange', 40, 'PCS', 18.50, 740.00, 'goods', :c)",
      { o: orderId, c: category })).insertId);
    expect((await orderQualityDocumentRepository.forOrder(orderId)).map((d) => d.document_name)).toEqual(['Mill test certificate 3.1']);
    await db.execute('UPDATE order_products SET is_active = 0 WHERE id = :id', { id: lineId });
    expect(await orderQualityDocumentRepository.forOrder(orderId)).toEqual([]);
  });
});
