'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const crypto = require('crypto');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productRepository = require('../../src/repositories/productRepository');
const productSupplierRepository = require('../../src/repositories/productSupplierRepository');
const specRepository = require('../../src/repositories/specRepository');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const orderLinePricing = require('../../src/services/orderLinePricing');
const priceRequestService = require('../../src/services/priceRequestService');
const productSpecs = require('../../src/services/productSpecs');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of D270OrderLineUnitsTest.php — owner decisions of 11 Nov 2026, double-confirmed:
 *  - D-270 (point 25): an order line in any unit of its category; its price is converted to the
 *    product's reference unit (per CBM from 50 mm) with the piece size for every check; a missing
 *    size is asked for, never "too low"; Set / Lot go to the MD.
 *  - D-271/D-272 (points 13 and 24): options shown as labels, a switched-off option is never replaced
 *    silently; Duplicate is an exact copy right below, approvals not copied.
 *  - D-273 (point 17): the summary bar and the totals row with CBM and containers.
 */
describe('D-270 to D-273 order lines in any unit, full duplicate, products summary', () => {
  let admin;
  let product;
  let associate;
  let client;
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const THICK = { 'Grade / quality': 'Standard', 'Surface finish': 'Natural Polish' };

  async function supplier(inr, basis) {
    const s = await productSupplierRepository.create({ product_id: product, supplier_name: `Test Quarry ${basis}`, location: 'Test location', fob_source: 'direct', fob_value: inr, is_primary: basis === 'unit' ? 1 : 0 }, admin);
    await pricingRepository.setSupplierDates(s, ymd(new Date()), null);
    if (basis === 'cbm') {
      await pricingRepository.setSupplierBasis(s, 'cbm');
      await pricingRepository.setCbmPrimary(product, s);
    }
  }
  const p = async () => productRepository.find(product);
  async function sel(byName) {
    const out = {};
    for (const s of await productSpecs.offered(await p())) {
      for (const o of s.options) if (byName[s.name] === o.name) out[s.id] = o.id;
    }
    return out;
  }
  const order = async () => db.queryOne('SELECT * FROM orders WHERE id = :id', { id: await createTestOrder(client, 'FOB', 'Standard — New Buyer', 'USD') });
  const size = (l, w, t) => ({ piece_length_cm: l, piece_width_cm: w, piece_thickness_cm: t });
  async function line(orderId, selection, unit, sz, price, qty = '40') {
    const id = await orderProductRepository.add(orderId, await orderProductRepository.nextLineNo(orderId), 'Absolute black granite', null, null, qty, false, unit, price, '68022390');
    await orderProductRepository.setCatalogProduct(id, product);
    await orderLinePricing.saveSelection(id, selection, null);
    await orderProductRepository.setPieceSize(id, sz);
    return id;
  }
  async function thick13() {
    const [s, err] = await orderLinePricing.syncThickness(product, await sel(THICK), '13');
    expect(err).toBeNull();
    return s;
  }
  const optionNames = async (s) => Promise.all(Object.values(s).map(async (o) => (await specRepository.findOption(Number(o))).name));

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    admin = Number((await db.execute(
      "INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled) VALUES ('Jest D270', :e, 'x', :r, 1, 0, 0)",
      { e: `jest-d270-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`, r: role.id })).insertId);
  });

  beforeEach(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    product = await productRepository.create({ name: `D270 Absolute Black ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022390', reference_unit: 'SQM' }, admin);
    await pricingRepository.saveProductOverrides(product, { pricing_margin_pct: null, pricing_other_cost_mode: 'pct', pricing_other_cost_value: '0', pricing_incentive_mode: null, pricing_incentive_value: null, pricing_buffer_pct: null });
    await specRepository.setProductFields(product, null, 'slab,tile', null);
    await supplier(3280, 'unit');
    await supplier(53300, 'cbm');
    const lvl = await db.queryOne("SELECT id FROM commission_levels WHERE name = 'Level 2'");
    associate = Number((await db.execute("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES (:n, 'active', 'fixed', :l)",
      { n: `D270 Associate ${crypto.randomBytes(3).toString('hex')}`, l: lvl.id })).insertId);
    client = await createTestClient();
    await priceRequestService.saveAccess(associate, { [await specRepository.systemCategoryId()]: ['slab', 'tile'] });
  });

  afterAll(async () => {
    await db.execute("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
    await db.pool.end();
  });

  it('the thickness box chooses the Thickness option', async () => {
    expect(await optionNames((await orderLinePricing.syncThickness(product, await sel(THICK), '3'))[0])).toContain('30 mm');
    expect(await optionNames(await thick13())).toContain('Other, 50 mm or more');
    const [, err] = await orderLinePricing.syncThickness(product, await sel(THICK), '2.5');
    expect(err).toContain('There is no 25 mm Thickness option');
  });

  it('13 cm needs no manual price and is priced per CBM', async () => {
    const f = await orderLinePricing.liveFigures(await p(), await orderLinePricing.context(await order()), await thick13(), 130);
    expect(f.missing).toBeUndefined();
    expect(f.unit).toBe('CBM');
  });

  it('a piece price is compared with the per-CBM price, not refused as too low', async () => {
    const o = await order();
    const s = await thick13();
    const f = await orderLinePricing.liveFigures(await p(), await orderLinePricing.context(o), s, 130);
    const perPieceMin = Number(f.minimum) * 0.1872;
    const sz = size('180.00', '80.00', '13.00');
    expect(await orderLinePricing.saveRefusal(o, product, 'goods', (perPieceMin + 1).toFixed(2), true, s, 'PCS', null, sz)).toBeNull();
    const refusal = String(await orderLinePricing.saveRefusal(o, product, 'goods', (perPieceMin - 1).toFixed(2), true, s, 'PCS', null, sz));
    expect(refusal).toContain('below what NexaCrest can accept');
    expect(refusal).toContain('per piece');
    expect(refusal).not.toContain('set the unit to CBM');
  });

  it('a missing size is asked for, never too low', async () => {
    expect(await orderLinePricing.saveRefusal(await order(), product, 'goods', '1.00', true, await thick13(), 'PCS', null, size(null, '80.00', '13.00')))
      .toBe("Enter the length (cm) — needed to compare a price per piece with the product's price per CBM.");
  });

  it('a saved line without its size holds the Quotation but is not sent to the MD', async () => {
    const orderId = Number((await order()).id);
    const id = await line(orderId, await thick13(), 'PCS', size(null, null, '13.00'), '495.00');
    await orderLinePricing.afterSave(id, null, admin);
    expect((await orderLinePricing.forOrder(orderId)).lines[id].status).toBe(orderLinePricing.NEEDS_SIZE);
    expect((await orderProductRepository.find(id)).price_approval_requested_at).toBeFalsy();
    expect(await orderLinePricing.documentBlockReason(orderId, 'QT')).toContain('needs its piece size');
  });

  it('a Set cannot be converted and goes to the MD', async () => {
    const orderId = Number((await order()).id);
    const id = await line(orderId, await thick13(), 'SET', size(null, null, '13.00'), '495.00');
    await orderLinePricing.afterSave(id, null, admin);
    const check = (await orderLinePricing.forOrder(orderId)).lines[id];
    expect(check.status).toBe(orderLinePricing.NO_PRICE);
    expect(check.note).toContain('cannot be converted');
    expect((await orderProductRepository.find(id)).price_approval_requested_at).toBeTruthy();
  });

  it('the check shows the reference and the converted figures', async () => {
    const orderId = Number((await order()).id);
    const s = await thick13();
    const ref = await orderLinePricing.liveFigures(await p(), await orderLinePricing.context(await order()), s, 130);
    const id = await line(orderId, s, 'PCS', size('180.00', '80.00', '13.00'), (Number(ref.quote) * 0.1872).toFixed(2));
    const check = (await orderLinePricing.forOrder(orderId)).lines[id];
    expect(check.status).toBe(orderLinePricing.OK);
    expect(check.reference.unit).toBe('CBM');
    expect(check.figures.unit).toBe('PCS');
    expect(Math.abs(Number(ref.quote) * 0.1872 - Number(check.figures.quote))).toBeLessThan(0.0001);
    expect(check.conversion.factor).toBeCloseTo(0.1872, 9);
    expect(Math.abs(Number(ref.quote) - check.conversion.price_ref)).toBeLessThan(0.03);
  });

  it('Ton uses the category weight and the product override', async () => {
    expect(await orderLinePricing.weightPerCbm(await p())).toBe(2.7);
    await specRepository.setProductFields(product, null, 'slab,tile', null, '3.000');
    expect(await orderLinePricing.weightPerCbm(await p())).toBe(3);
  });

  it('Duplicate is an exact copy right below, without the approval', async () => {
    const orderId = Number((await order()).id);
    const first = await line(orderId, await thick13(), 'PCS', size('180.00', '80.00', '13.00'), '495.00');
    const second = await line(orderId, null, 'SQM', size(null, null, null), '58.00');
    const [prId] = await priceRequestService.create(await associateRepository.find(associate), await p(),
      await sel({ 'Grade / quality': 'Standard', Thickness: '30 mm', 'Surface finish': 'Leather' }), false, null);
    expect(prId).toBeTruthy();
    await db.execute(`UPDATE order_products SET material = 'Granite', zoho_item_id = 'TEST-ITEM-1', price_request_id = :pr,
      price_snapshot = '{"quote":1}', price_approved_by = :u, price_approved_at = NOW(), price_approved_unit_price = 495.00, price_approval_reason = 'Test approval reason' WHERE id = :id`,
    { pr: prId, u: admin, id: first });

    const copy = await orderProductRepository.duplicate(first);
    const c = await orderProductRepository.find(copy);
    const src = await orderProductRepository.find(first);
    expect(Number(c.line_no)).toBe(2);
    expect(Number((await orderProductRepository.find(second)).line_no)).toBe(3);
    for (const k of ['spec_selection', 'piece_length_cm', 'piece_width_cm', 'piece_thickness_cm', 'material', 'price_request_id', 'zoho_item_id', 'catalog_product_id', 'unit', 'unit_price', 'quantity']) {
      expect([k, c[k]]).toEqual([k, src[k]]);
    }
    expect(Number(c.copied_from_id)).toBe(first);
    expect(orderProductRepository.copiedFromLineNo(c, await orderProductRepository.forOrder(orderId))).toBe(1);
    expect(c.price_approved_at).toBeNull();
    expect(c.price_snapshot).toBeNull();
  });

  it('a copy below the lowest price goes to the MD at once', async () => {
    const orderId = Number((await order()).id);
    const s = await thick13();
    const ref = await orderLinePricing.liveFigures(await p(), await orderLinePricing.context(await order()), s, 130);
    const belowLowest = (((Number(ref.floor) + Number(ref.minimum)) / 2) * 0.1872).toFixed(2);
    const first = await line(orderId, s, 'PCS', size('180.00', '80.00', '13.00'), belowLowest);
    const l = await orderProductRepository.find(first);
    await db.execute('UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approved_unit_price = :p, price_approved_selection = :k, price_approval_reason = :r WHERE id = :id',
      { u: admin, p: belowLowest, k: orderLinePricing.approvalSelectionKey(l), r: 'Test approval reason', id: first });
    expect((await orderLinePricing.forOrder(orderId)).lines[first].status).toBe(orderLinePricing.APPROVED);

    const copy = await orderProductRepository.duplicate(first);
    await orderLinePricing.afterSave(copy, null, admin);
    expect((await orderLinePricing.forOrder(orderId)).lines[copy].status).toBe(orderLinePricing.BELOW);
    expect((await orderProductRepository.find(copy)).price_approval_requested_at).toBeTruthy();
  });

  it('whole-order duplicate carries the options and sizes', async () => {
    const orderId = Number((await order()).id);
    const first = await line(orderId, await thick13(), 'PCS', size('180.00', '80.00', '13.00'), '495.00');
    const newOrder = await orderDuplicationService.duplicate(orderId, admin);
    const [copy] = await orderProductRepository.forOrder(newOrder);
    const src = await orderProductRepository.find(first);
    expect(copy.spec_selection).toBe(src.spec_selection);
    expect([copy.piece_length_cm, copy.piece_width_cm, copy.piece_thickness_cm]).toEqual([src.piece_length_cm, src.piece_width_cm, src.piece_thickness_cm]);
    expect(copy.copied_from_id).toBeNull();
  });

  it('options are shown and a switched-off option is never replaced', async () => {
    const orderId = Number((await order()).id);
    const s = await sel({ 'Grade / quality': 'Standard', Thickness: '30 mm', 'Surface finish': 'Honed' });
    const id = await line(orderId, s, 'SQM', size(null, null, null), '58.00');
    expect((await orderLinePricing.optionChips(await orderProductRepository.find(id))).map((c) => c.name)).toEqual(['Standard', '30 mm', 'Honed']);
    const names = await optionNames(s);
    const honed = Number(Object.values(s)[names.indexOf('Honed')]);
    await specRepository.setOptionOverride(product, honed, true, null);
    const chips = await orderLinePricing.optionChips(await orderProductRepository.find(id));
    expect(chips[2]).toEqual({ name: '"Honed" is no longer offered — choose again', gone: true });
    expect((await orderLinePricing.forOrder(orderId)).lines[id].status).toBe(orderLinePricing.NO_PRICE);
  });

  it('the summary bar and the totals row', () => {
    const l = (no, status) => ({ status, line: { line_no: no } });
    expect(orderLinePricing.summary([l(1, 'ok'), l(2, 'approved'), l(3, 'below')]).text).toBe('2 lines OK · 1 line needs MD approval (line 3) — the Quotation waits for it.');
    expect(orderLinePricing.summary([l(1, 'ok'), l(2, 'needs_size'), l(3, 'needs_size')]).text).toBe('1 line OK · 2 lines need their piece size (lines 2, 3) — the Quotation waits for them.');
    expect(orderLinePricing.summary([l(1, 'ok')])).toEqual({ text: '1 line OK.', blocking: false });
    expect(orderLinePricing.summary([l(1, 'unchecked')])).toBeNull();

    const lines = [
      { id: 1, line_type: 'goods', quantity: '40', quantity_is_tbc: 0, unit: 'PCS', fob_value: '19800.00', piece_length_cm: '180', piece_width_cm: '80', piece_thickness_cm: '13' },
      { id: 2, line_type: 'goods', quantity: '60', quantity_is_tbc: 0, unit: 'PCS', fob_value: '19200.00', piece_length_cm: '140', piece_width_cm: '70', piece_thickness_cm: '13' },
      { id: 3, line_type: 'goods', quantity: '400', quantity_is_tbc: 0, unit: 'SQM', fob_value: '23200.00', piece_length_cm: null, piece_width_cm: null, piece_thickness_cm: null },
      { id: 4, line_type: 'charge', quantity: '1', quantity_is_tbc: 0, unit: 'LOT', fob_value: '500.00' },
    ];
    const t = orderLinePricing.totals(lines, { 3: { reference: { thickness_mm: 20 } } });
    expect(t.amount).toBe(62200);
    expect(t.cbm).toBe(23.13);
    expect(t.containers).toBe(3);
    expect(t.uncounted).toBe(0);
  });
});
