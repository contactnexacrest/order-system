'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Phase 1 item 8 of the approved remediation plan: "1-container vs
 * 2-container / multi-product-multi-container handling" had no dedicated
 * field at all — container_no was free text that might list more than one
 * container number, but how many containers a shipment actually uses was
 * never captured as its own value. User chose "add a container-count
 * field" when asked how this should work. Added order_shipping.container_count,
 * surfaced on the Stage 7 Shipping Details form and printed on both the
 * BL Instruction Sheet and the Commercial Invoice. Port of PHP's
 * ShippingContainerCountTest.php.
 */
describe('Shipping container count (Phase 1 item 8)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0];
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('shipping details save the container count', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', container_count: 2 });

    const shipping = await orderShippingRepository.find(orderId);

    expect(Number(shipping.container_count)).toBe(2);
  });

  it('BL Instruction Sheet shows the number of containers', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');
    await orderShippingRepository.upsert(orderId, {
      shipping_line: 'Maersk',
      container_count: 2,
      container_no: 'MSCU1111111; MSCU2222222',
    });

    await documentGenerationService.generate(orderId, 'BLI', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toMatch(/Number of Containers[\s\S]{0,40}>2</);
  });

  it('Commercial Invoice shows the number of containers', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', container_count: 3 });
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');

    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toMatch(/Number of Containers[\s\S]{0,40}>3</);
  });

  it('BL Instruction Sheet shows To Be Confirmed when the container count is not yet set', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk' });

    await documentGenerationService.generate(orderId, 'BLI', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toMatch(/Number of Containers[\s\S]{0,60}To Be Confirmed/);
  });
});
