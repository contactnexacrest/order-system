'use strict';

// Owner decision D-338 (12 Nov 2026, owner "will go with recommendation") — follow-ups to
// D-335 / D-336: (1a) reorder request lines "As per annexure"; (1b) a duplicated order copies
// Annexure A, never Annexure B or the CI annexure choice; (1c) one wording when a line "As per
// annexure" needs its piece size for the price check; (2a) no new "add up the cost parts"
// supplier price; (2b) a line priced from a per-CBM price only CALCULATED from a thinner base
// goes to the MD; (2d) Pricing settings headings and legends in Title Case.
// Twin of PHP tests/Integration/FollowUpsD338Test.php.

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const annexureChoice = require('../../src/services/annexureChoice');
const orderLinePricing = require('../../src/services/orderLinePricing');
const orderDuplicationService = require('../../src/services/orderDuplicationService');
const thicknessPrices = require('../../src/services/thicknessPrices');
const orderRepository = require('../../src/repositories/orderRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderAnnexureRepository = require('../../src/repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../../src/repositories/orderAnnexureTermsRepository');
const orderReorderRequestRepository = require('../../src/repositories/orderReorderRequestRepository');
const productRepository = require('../../src/repositories/productRepository');
const { lineRefusalText } = require('../../src/controllers/ordersController');
const productsController = require('../../src/controllers/productsController');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

jest.setTimeout(60000);

const APP = path.join(__dirname, '..', '..');
const read = (rel) => fs.readFileSync(path.join(APP, rel), 'utf8');

async function user(roleName) {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
  const email = `jest-d338-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
  return (await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
    { email, hash: await bcrypt.hash('JestD338!1', 4), role_id: role.id },
  )).insertId;
}

/** Natural Stone's seeded rules (pure tests), as in d335SupplierPricesBase.test.js. */
const rules = () => ({
  grid: true, base_mm: 20, k: 40, cbm_from: 50,
  rows: [{ mm: 15, pct: -20, option: '15 mm' }, { mm: 18, pct: -5, option: '18 mm' }, { mm: 20, pct: 0, option: '20 mm' },
    { mm: 30, pct: 35, option: '30 mm' }, { mm: 40, pct: 70, option: '40 mm' }],
  container_cbm: 8.2, container_max_t: 27, t_per_cbm: 2.7, own: [], category_id: 1, category_name: 'Natural Stone', unit: 'CBM',
});

/** Fixed line figures (price_snapshot) so a check needs no live supplier price. */
const figures = (unit, cbmCalculated) => ({
  cost: 1000, minimum: 1250, reserve: 37.5, staff_floor: 1287.5, staff_quote: 1326.13, floor: 1287.5, quote: 1326.13,
  sold_by: 'staff', sold_by_label: 'Staff', margin_pct: 20, unit,
  cbm_calculated: cbmCalculated, cbm_calc_from_mm: cbmCalculated ? 20 : null, cbm_from_mm: 50,
});

/** A USD order with one goods line picked from Products and fixed figures. */
async function checkedLine(lineUnit, price, f, perAnnexure = false) {
  const admin = await user('Admin');
  const orderId = await createTestOrder(await createTestClient());
  const productId = await productRepository.create({ name: `D338 Granite ${crypto.randomBytes(3).toString('hex')}`, specifications: 'Granite', hs_code: '68022310', reference_unit: f.unit }, admin);
  const lineId = await orderProductRepository.add(orderId, 1, 'Absolute black granite', null, 'Polished', '8.801', false, lineUnit, price, '68022310');
  await orderProductRepository.setCatalogProduct(lineId, productId);
  await orderProductRepository.setAsPerAnnexure(lineId, perAnnexure);
  await db.execute('UPDATE order_products SET price_snapshot = :s WHERE id = :id', { s: JSON.stringify(f), id: lineId });
  return { orderId, lineId, admin };
}

async function check(orderId, lineId) {
  return orderLinePricing.checkLine(await orderProductRepository.find(lineId), await orderLinePricing.context(await orderRepository.find(orderId)));
}

afterAll(async () => {
  await db.pool.end();
});

// ------------------------------------------------------------------ 1a

test('1a: reorder line "As per annexure" rules and wording', () => {
  expect(annexureChoice.reorderLineError(1, false, true, '', 'Qty To Be Confirmed')).toBeNull();
  expect(annexureChoice.reorderLineError(2, true, true, '5', 'Qty To Be Confirmed')).toBe('Line 2: tick either "Qty To Be Confirmed" or "As per annexure", not both.');
  expect(annexureChoice.reorderLineError(1, true, true, '5', 'Quantity not decided yet')).toBe('Line 1: tick either "Quantity not decided yet" or "As per annexure", not both.');
  expect(annexureChoice.reorderLineError(3, true, false, '  ', 'Qty To Be Confirmed')).toBe('Line 3: a line "As per annexure" still needs its quantity — enter the Qty.');
  expect(annexureChoice.reorderLineError(1, true, false, '8.801', 'Qty To Be Confirmed')).toBeNull();
});

test('1a: the reorder line is stored and carried onto the new order', async () => {
  const clientId = await createTestClient();
  const sourceId = await createTestOrder(clientId);
  const requestId = await orderReorderRequestRepository.create(clientId, sourceId, null);
  await orderReorderRequestRepository.addProductLine(requestId, 1, 'Slab as per annexure', null, null, '8.801', false, 'CBM', null, null, true);
  await orderReorderRequestRepository.addProductLine(requestId, 2, 'Slab with sizes', '60 x 40 x 3 cm', null, '5', false, 'SQM', null, null);
  const lines = await orderReorderRequestRepository.productLines(requestId);
  expect(lines.map((l) => Number(l.as_per_annexure))).toEqual([1, 0]);

  const newOrderId = await orderDuplicationService.duplicate(sourceId, await user('Admin'), [
    { description: 'Slab as per annexure', dimensions: null, finish: null, quantity: '8.801', quantity_is_tbc: false, unit: 'CBM', unit_price: '100', hs_code: '68022310', as_per_annexure: true },
    { description: 'Slab with sizes', dimensions: '60 x 40 x 3 cm', finish: null, quantity: '5', quantity_is_tbc: false, unit: 'SQM', unit_price: '50', hs_code: '68022310' },
  ]);
  const newLines = await orderProductRepository.forOrder(newOrderId);
  expect(newLines.map((l) => Number(l.as_per_annexure))).toEqual([1, 0]);
});

test('1a: both reorder forms have the tick box, wired to app.js', () => {
  const staff = read('views/reorder_requests/show.njk');
  for (const s of ['name="as_per_annexure[{{ loop.index0 }}]"', 'data-annexure-line', 'data-annexure-tbc', 'data-annexure-size', 'data-annexure-scope']) expect(staff).toContain(s);
  const portal = read('views/client_portal/reorder.njk');
  expect(portal).toContain('name="product_as_per_annexure[{{ loop.index0 }}]"');
  expect(portal).toContain('name="product_as_per_annexure[0]"');
  expect(portal).toContain('data-annexure-scope');
  const js = read('public/js/app.js');
  expect(js).toContain("el.closest('[data-annexure-scope]')");
  expect(js).toContain('[data-annexure-size]');
});

// ------------------------------------------------------------------ 1b

test('1b: a duplicate copies Annexure A but not Annexure B', async () => {
  const admin = await user('Admin');
  const sourceId = await createTestOrder(await createTestClient());
  const a = await orderAnnexureRepository.createProduct(sourceId, { name: 'Slab A', description: 'Slab', dimensions: '180 x 120 x 15 cm', finish: 'Polished' }, 'A');
  const fileId = await createTestFile(sourceId, null);
  await orderAnnexureRepository.addImage(a, fileId);
  await orderAnnexureRepository.createProduct(sourceId, { name: 'Slab B', description: 'Shipped', dimensions: '1 x 1 x 1 cm', finish: '' }, 'B');
  await orderAnnexureTermsRepository.upsert(sourceId, '<p>Terms A</p>', admin, 'A');
  await orderAnnexureTermsRepository.upsert(sourceId, '<p>Terms B</p>', admin, 'B');
  await orderRepository.setIncludeAnnexureA(sourceId, true);
  await orderRepository.setAnnexureMode(sourceId, 'BOTH');
  await orderRepository.setIncludeAnnexureB(sourceId, true);
  await orderRepository.setCiAnnexure(sourceId, 'B');

  const newId = await orderDuplicationService.duplicate(sourceId, admin);
  const copyA = await orderAnnexureRepository.forOrder(newId, 'A');
  expect(copyA).toHaveLength(1);
  expect([copyA[0].name, copyA[0].dimensions]).toEqual(['Slab A', '180 x 120 x 15 cm']);
  expect(Number(copyA[0].images[0].file_id)).toBe(Number(fileId)); // the same stored file
  expect((await orderAnnexureTermsRepository.find(newId, 'A')).content_html).toBe('<p>Terms A</p>');
  const fresh = await orderRepository.find(newId);
  expect([Number(fresh.include_annexure_a), String(fresh.annexure_mode)]).toEqual([1, 'BOTH']);
  expect(await orderAnnexureRepository.forOrder(newId, 'B')).toEqual([]);
  expect(await orderAnnexureTermsRepository.find(newId, 'B')).toBeFalsy();
  expect(Number(fresh.include_annexure_b)).toBe(0);
  expect(fresh.ci_annexure).toBeNull();
  expect(await orderAnnexureRepository.forOrder(sourceId, 'A')).toHaveLength(1);
});

// ------------------------------------------------------------------ 1c

test('1c: the message follows the line unit', () => {
  expect(orderLinePricing.annexureSizeMessage(3, 'CBM')).toBe('Line 3 says "As per annexure", so its size is not known — type the price per CBM for this line.');
  expect(orderLinePricing.annexureSizeMessage(4, 'SQM')).toBe('Line 4 says "As per annexure", so its size is not known — type the price per m² for this line.');
  expect(orderLinePricing.annexureSizeMessage(1, 'PCS')).toBe('Line 1 says "As per annexure", so its size is not known — type the price per piece for this line.');
  expect(orderLinePricing.annexureSizeMessage(2, '')).toBe('Line 2 says "As per annexure", so its size is not known — type the price per CBM for this line.');
});

test('1c: a line "As per annexure" that needs its size gets the same message everywhere', async () => {
  const { orderId, lineId, admin } = await checkedLine('CBM', '2834.54', figures('SQM', false), true);
  const expected = 'Line 1 says "As per annexure", so its size is not known — type the price per CBM for this line.';
  const c = await check(orderId, lineId);
  expect([c.status, c.note]).toEqual([orderLinePricing.NEEDS_SIZE, expected]);
  expect(await orderLinePricing.documentBlockReason(orderId, 'QT')).toBe(expected);
  expect(await orderLinePricing.documentBlockReason(orderId, 'PI')).toBe(expected);
  expect(await orderLinePricing.approve(orderId, lineId, admin, 'Checked with the buyer and agreed')).toBe(expected);

  expect(lineRefusalText(expected)).toBe(expected);
  expect(lineRefusalText('Enter the thickness (cm).')).toBe('Product line: Enter the thickness (cm).');

  await orderProductRepository.setAsPerAnnexure(lineId, false);
  const plain = await check(orderId, lineId);
  expect(plain.status).toBe(orderLinePricing.NEEDS_SIZE);
  expect(String(plain.note)).not.toContain('As per annexure');
});

// ------------------------------------------------------------------ 2a

test('2a: no new "add up the cost parts" price; older ones keep working', () => {
  const { validateSupplier, readSupplierForm, COST_PARTS_REFUSAL } = productsController;
  expect(validateSupplier({ supplier_name: 'Quarry', fob_source: 'computed' }, null)).toBe(COST_PARTS_REFUSAL);
  expect(validateSupplier({ supplier_name: 'Quarry', fob_source: 'computed' }, { fob_source: 'direct' })).toBe(COST_PARTS_REFUSAL);
  expect(validateSupplier({ supplier_name: 'Quarry', fob_source: 'computed' }, { fob_source: 'computed' })).toBeNull();
  expect(validateSupplier({ supplier_name: 'Quarry', fob_source: 'direct' }, { fob_source: 'computed' })).toBeNull();
  expect(COST_PARTS_REFUSAL).toBe("Adding up the cost parts is no longer offered for a new price — type the supplier's price in FOB Value (₹). Prices saved that way earlier keep working.");

  const data = readSupplierForm({ body: { supplier_name: 'Quarry', fob_source: 'direct', fob_value: '4500' } },
    { fob_source: 'direct', factory_cost: '350000.00', transportation_cost: null, packing_cost: '12000.00', loading_cost: null, cha_cost: null });
  expect([data.factory_cost, data.transportation_cost, data.packing_cost]).toEqual([350000, null, 12000]);

  const view = read('views/products/show.njk');
  expect(view.split('<option value="computed"').length - 1).toBe(1);
  expect(view).toContain("{% if s.fob_source == 'computed' %}");
  expect(view).toContain('<label>FOB Value (₹)<input type="number" step="0.01" min="0" name="fob_value" placeholder="e.g. 4500.00"></label>');
});

// ------------------------------------------------------------------ 2b

test('2b: the thickness cost tells when the per-CBM price is only worked out', () => {
  const product = { default_packing_cost: 0, default_loading_cost: 0 };
  const row = { auto_fill: 0, base_thickness_mm: 20, includes_packing_loading: 1 };
  const calc = thicknessPrices.cost(rules(), { 20: 4600, 0: { price: 184000, how: 'calculated', calc_from_mm: 20 } }, row, product, 'cbm', null);
  expect([calc.unit, calc.how, calc.calc_from_mm, calc.cbm_from]).toEqual(['CBM', 'calculated', 20, 50]);
  const typed = thicknessPrices.cost(rules(), { 20: 4600, 0: 180000 }, row, product, 'cbm', null);
  expect([typed.how, typed.calc_from_mm]).toEqual(['typed', null]);
  const old = thicknessPrices.cost(rules(), { 20: 4600 }, { auto_fill: 1, base_thickness_mm: 20, includes_packing_loading: 1 }, product, 'cbm', null);
  expect([old.how, old.calc_from_mm]).toEqual(['calculated', 20]);

  expect(orderLinePricing.cbmCalculatedReason(figures('CBM', true))).toBe("CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted");
  expect(orderLinePricing.cbmCalculatedReason(figures('CBM', false))).toBeNull();
});

test('2b: a worked-out CBM price goes to the MD like a line below the floor', async () => {
  const { orderId, lineId, admin } = await checkedLine('CBM', '1300.00', figures('CBM', true));
  const c = await check(orderId, lineId);
  expect(c.status).toBe(orderLinePricing.CBM_CALCULATED);
  expect(c.cbm_reason).toBe("CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted");
  expect(orderLinePricing.BLOCKING).toContain(orderLinePricing.CBM_CALCULATED);

  await orderLinePricing.afterSave(lineId, null, admin);
  expect((await orderProductRepository.find(lineId)).price_approval_requested_at).toBeTruthy();
  expect(await orderLinePricing.documentBlockReason(orderId, 'QT')).toBe(
    "Line 1 needs MD approval: CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted — the MD must approve it before the Quotation (order page → Products).");
  expect(await orderLinePricing.documentBlockReason(orderId, 'PI')).not.toBeNull();
  const summary = orderLinePricing.summary((await orderLinePricing.forOrder(orderId)).lines);
  expect(summary.text).toBe('1 line needs MD approval (line 1) — the Quotation waits for it.');
  const view = await orderLinePricing.approvalView(orderId, lineId);
  expect(view.check.cbm_reason).toBe("CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted");

  expect(await orderLinePricing.approve(orderId, lineId, admin, 'Supplier confirmed the 50 mm price by phone')).toBeNull();
  expect((await check(orderId, lineId)).status).toBe(orderLinePricing.APPROVED);
  expect(await orderLinePricing.documentBlockReason(orderId, 'QT')).toBeNull();
});

test('2b: a typed CBM price needs no approval', async () => {
  const { orderId, lineId } = await checkedLine('CBM', '1300.00', figures('CBM', false));
  const c = await check(orderId, lineId);
  expect([c.status, c.cbm_reason]).toEqual([orderLinePricing.OK, null]);
  expect(await orderLinePricing.documentBlockReason(orderId, 'QT')).toBeNull();
});

test('2b: the approval screens know the new status', () => {
  const approval = read('views/orders/price_approval.njk');
  expect(approval).toContain("check.status == 'cbm_calculated'");
  expect(approval).toContain('<tr><td>Reason</td><td><strong>{{ check.cbm_reason }}</strong></td></tr>');
  const show = read('views/orders/show.njk');
  expect(show).toContain('CBM price worked out — needs MD approval');
  expect(show).toContain("pc.status == 'cbm_calculated'");
  expect(read('views/orders/_line_price.njk')).toContain('CBM price worked out — needs MD approval');
  expect(read('src/services/approvalsInbox.js')).toContain("'Price — CBM worked out'");
});

// ------------------------------------------------------------------ 2d

test('2d: Pricing settings headings and legends are in Title Case', () => {
  const view = read('views/pricing/settings.njk');
  for (const h of ['<h2>Exchange Rate</h2>', '<legend>Margin and Costs</legend>', '<legend>Exchange Rate (USD → INR)</legend>', '<legend>Team Incentive Reserve</legend>',
    '<legend>Supplier Prices</legend>', '<legend>Order Lines</legend>', '<legend>Specifications and Associate Prices (D-255)</legend>', '<h2>Who Can Do What</h2>']) {
    expect(view).toContain(h);
  }
  for (const old of ['<h2>Exchange rate</h2>', '<legend>Margin and costs</legend>', '<legend>Team incentive reserve</legend>', '<h2>Who can do what</h2>']) {
    expect(view).not.toContain(old);
  }
});
