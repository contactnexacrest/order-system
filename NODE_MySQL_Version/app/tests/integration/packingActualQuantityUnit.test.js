'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const orderPackingRepository = require('../../src/repositories/orderPackingRepository');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Phase 2 item 13 of the approved remediation plan: "Packing Actual
 * Quantity needs unit dropdown / default to PI's unit." The Packing form
 * already implicitly assumed actual_quantity_packed shared the order's
 * own product unit (the shortfall-tolerance error message already
 * quoted that unit), but never stored or showed a unit anywhere — the
 * Packing List document printed the bare number with no unit at all.
 * Port of PHP's PackingActualQuantityUnitTest.php.
 */
describe('Packing actual quantity unit (Phase 2 item 13)', () => {
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

  it('Packing List PDF shows the quantity with its unit', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');
    await orderPackingRepository.upsert(orderId, { actual_quantity_packed: '95', actual_quantity_unit: 'Sq.Mtr' });

    await documentGenerationService.generate(orderId, 'PL', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toMatch(/Total Quantity[\s\S]{0,40}95 Sq\.Mtr/);
  });

  it('Packing List PDF shows To Be Confirmed when the quantity is not yet set', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'PL', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toMatch(/Total Quantity[\s\S]{0,40}To Be Confirmed/);
  });
});
