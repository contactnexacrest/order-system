'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AnnexureNoteAndOrderReferenceTest.php. 90-item backlog
 * "Annexure note configurability + order_reference display": the
 * Annexure's Product Specification "Note:" banner was hardcoded
 * straight into the template (unlike bl_type_instruction/bl_consignee_
 * instruction's own admin-editable pattern), and the assembler never
 * exposed the order's own order_reference to any document template at
 * all — the Annexure's own meta table specifically needs it.
 */
describe('Annexure note and order_reference display (90-item backlog)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('exposes order_reference to every document template', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.order.order_reference).toBe(order.order_reference);
  });

  it('falls back to the original wording when unconfigured', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.annexure_product_note).toBe(
      'Product images and drawings are for reference only and may show optional accessories or decorative elements not included in the quoted price. All binding specifications are as stated in each product section below. Actual product appearance may vary slightly due to the natural characteristics of granite.'
    );
  });

  it('reads the configured override', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await companySettingsRepository.set('annexure_product_note', 'Custom note: colours may vary by batch.');

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.annexure_product_note).toBe('Custom note: colours may vary by batch.');
  });
});
