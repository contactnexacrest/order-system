'use strict';

// Owner decision D-336 (12 Nov 2026, owner confirmed twice): an order line "As per
// annexure", Annexure B (as shipped) and the annexure printed with the Commercial
// Invoice and Packing List. Twin of PHP tests/Integration/AnnexureBAndAsPerAnnexureTest.php.

const db = require('../../src/config/db');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const annexureChoice = require('../../src/services/annexureChoice');
const orderRepository = require('../../src/repositories/orderRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderAnnexureRepository = require('../../src/repositories/orderAnnexureRepository');
const orderAnnexureTermsRepository = require('../../src/repositories/orderAnnexureTermsRepository');
const { annexureLineInput } = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

jest.setTimeout(60000);

async function orderWithTickedLine() {
  const orderId = await createTestOrder(await createTestClient());
  const lineId = await orderProductRepository.add(orderId, 1, 'Absolute black granite', '180 x 120 x 15 cm', 'Polished', '8.801', false, 'CBM', '2834.54', '68029300');
  await orderProductRepository.setAsPerAnnexure(lineId, true);
  return { orderId, lineId };
}

function annexureProduct(orderId, which, dimensions) {
  return orderAnnexureRepository.createProduct(orderId, { name: 'Slab', description: 'Slab', dimensions, finish: 'Polished' }, which);
}

async function anyUserId() {
  return (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id;
}

afterAll(async () => {
  await db.pool.end();
});

test('the Quotation, PI, OC and Buyer PO say "as per Annexure A"', async () => {
  const { orderId } = await orderWithTickedLine();
  for (const code of ['QT', 'PI', 'OC', 'BUYERPO']) {
    const line = (await documentDataAssembler.assemble(orderId, code)).products[0];
    expect([code, line.quantity]).toEqual([code, '8.801 (as per Annexure A)']);
    expect([code, line.dimensions]).toEqual([code, 'As per Annexure A']);
  }
});

test('the CI with Annexure B chosen says Annexure B and prints Annexure B; the QT keeps A', async () => {
  const { orderId } = await orderWithTickedLine();
  await orderRepository.setIncludeAnnexureA(orderId, true);
  await annexureProduct(orderId, 'A', 'QUOTED 180 x 120 x 15 cm');
  await annexureProduct(orderId, 'B', 'SHIPPED 181 x 121 x 15 cm');
  await orderRepository.setCiAnnexure(orderId, 'B');

  for (const code of ['CI', 'PL']) {
    const data = await documentDataAssembler.assemble(orderId, code);
    expect(data.annexure_letter).toBe('B');
    expect(data.order.include_annexure_a).toBe(true);
    expect(data.annexure_has_content).toBe(true);
    expect(data.annexure_products[0].dimensions).toBe('SHIPPED 181 x 121 x 15 cm');
  }
  const ci = (await documentDataAssembler.assemble(orderId, 'CI')).products[0];
  expect(ci.quantity).toBe('8.801 (as per Annexure B)');
  expect(ci.dimensions).toBe('As per Annexure B');
  const qt = await documentDataAssembler.assemble(orderId, 'QT');
  expect(qt.annexure_letter).toBe('A');
  expect(qt.annexure_products[0].dimensions).toBe('QUOTED 180 x 120 x 15 cm');
});

test('a line without the tick is unchanged', async () => {
  const orderId = await createTestOrder(await createTestClient());
  await orderProductRepository.add(orderId, 1, 'Granite', '180 x 80 x 13 cm', 'Polished', '40', false, 'PCS', '100', '68029300');
  const data = await documentDataAssembler.assemble(orderId, 'CI');
  expect(data.products[0].quantity).toBe('40');
  expect(data.products[0].dimensions).toBe('180 x 80 x 13 cm');
  expect(data.annexure_letter).toBe('A');
});

test('the CI default is B when included and filled, else A, else none; a saved choice wins', async () => {
  const orderId = await createTestOrder(await createTestClient());
  const fresh = () => orderRepository.find(orderId);
  expect(await annexureChoice.ciDefault(await fresh())).toBe('NONE');
  await orderRepository.setIncludeAnnexureA(orderId, true);
  await annexureProduct(orderId, 'A', '1 x 1 x 1 cm');
  expect(await annexureChoice.ciDefault(await fresh())).toBe('A');
  await annexureProduct(orderId, 'B', '2 x 2 x 2 cm');
  expect(await annexureChoice.ciDefault(await fresh())).toBe('A');
  await orderRepository.setIncludeAnnexureB(orderId, true);
  expect(await annexureChoice.ciDefault(await fresh())).toBe('B');
  await orderRepository.setCiAnnexure(orderId, 'A');
  expect(await annexureChoice.ciChoice(await fresh())).toBe('A');
});

test('the safety checks refuse with the exact wording', async () => {
  const { orderId } = await orderWithTickedLine();
  expect(await annexureChoice.blockReason(orderId, 'QT')).toBe('Line 1 says "As per annexure" but Annexure A has no product yet — add it in Annexure A (order page › Annexure A), then generate again.');
  expect(await annexureChoice.blockReason(orderId, 'CI')).toBe('Line 1 says "As per annexure" but "No annexure" is chosen for the Commercial Invoice and Packing List — choose Annexure B (as shipped) or Annexure A (as quoted), then generate again.');
  await orderRepository.setIncludeAnnexureA(orderId, true);
  await annexureProduct(orderId, 'A', '1 x 1 x 1 cm');
  expect(await annexureChoice.blockReason(orderId, 'PI')).toBeNull();
  expect(await annexureChoice.blockReason(orderId, 'PL', 'B')).toBe('Line 1 says "As per annexure" but Annexure B (as shipped) has no product yet — add it, or choose Annexure A, then generate again.');
  expect(await annexureChoice.blockReason(orderId, 'CI')).toBeNull();
  expect(await annexureChoice.blockReason(orderId, 'FDN')).toBeNull();
});

test('copy from Annexure A replaces B with its products, images, terms and mode', async () => {
  const orderId = await createTestOrder(await createTestClient());
  const a = await annexureProduct(orderId, 'A', '1 x 1 x 1 cm');
  const fileId = await createTestFile(orderId, null);
  await orderAnnexureRepository.addImage(a, fileId);
  await annexureProduct(orderId, 'B', 'OLD B');
  const userId = await anyUserId();
  await orderAnnexureTermsRepository.upsert(orderId, '<p>Terms A</p>', userId, 'A');
  await orderRepository.setAnnexureMode(orderId, 'BOTH');

  expect(await annexureChoice.copyAToB(await orderRepository.find(orderId), userId)).toBe(1);
  const b = await orderAnnexureRepository.forOrder(orderId, 'B');
  expect(b).toHaveLength(1);
  expect(b[0].dimensions).toBe('1 x 1 x 1 cm');
  expect(Number(b[0].images[0].file_id)).toBe(Number(fileId));
  expect(await orderAnnexureRepository.forOrder(orderId, 'A')).toHaveLength(1);
  expect((await orderAnnexureTermsRepository.find(orderId, 'B')).content_html).toBe('<p>Terms A</p>');
  expect((await orderAnnexureTermsRepository.find(orderId, 'A')).content_html).toBe('<p>Terms A</p>');
  expect((await orderRepository.find(orderId)).annexure_b_mode).toBe('BOTH');
});

test('the tick box and "Qty To Be Confirmed" are mutually exclusive; the Qty is required', () => {
  expect(annexureLineInput({ as_per_annexure_field: '1', as_per_annexure: '1', quantity_is_tbc: '1', quantity: '5' }, null))
    .toEqual([true, 'tick either "Qty To Be Confirmed" or "As per annexure", not both.']);
  expect(annexureLineInput({ as_per_annexure_field: '1', as_per_annexure: '1', quantity: '' }, null))
    .toEqual([true, 'a line "As per annexure" still needs its quantity — enter the Qty.']);
  const body = { as_per_annexure_field: '1', as_per_annexure: '1', quantity: '8.801', piece_length_cm: '180', dimensions: '180 x 1' };
  expect(annexureLineInput(body, null)).toEqual([true, null]);
  expect(body.piece_length_cm).toBe('');
  expect(body.dimensions).toBe('');
  expect(annexureLineInput({ quantity: '1' }, 1)[0]).toBe(true);
  expect(annexureLineInput({ as_per_annexure_field: '1', quantity: '1' }, 1)[0]).toBe(false);
});

test('duplicating a line keeps the tick', async () => {
  const { lineId } = await orderWithTickedLine();
  const copy = await orderProductRepository.duplicate(lineId);
  expect(Number((await orderProductRepository.find(copy)).as_per_annexure)).toBe(1);
});
