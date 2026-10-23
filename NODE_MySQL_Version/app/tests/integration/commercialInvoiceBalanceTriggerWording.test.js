'use strict';

const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CommercialInvoiceBalanceTriggerWordingTest.php. External QA
 * report L-06 (High, Both) — "CI on a Tier 1 order prints the Tier 2
 * balance trigger ('within 7 Calendar Days of the date NexaCrest
 * emails the scanned BL copy')." The Commercial Invoice's own Section 7
 * "BALANCE DUE NOW" clause used to hardcode that Tier 2 wording (plus a
 * separate, order-independent `ci_balance_days_post_bl` company
 * setting) regardless of which payment preset the order was actually
 * on. QA's own fix direction: "CI template/terms must follow the
 * order's payment-preset tier like QT/PI do." Now reuses the same
 * balance_terms_text field QT/PI/BuyerPO already correctly use.
 */
describe('Commercial Invoice balance trigger wording (external QA report L-06)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function addTestProduct(orderId) {
    await orderProductRepository.add(orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '1825.00');
  }

  it('a Tier 1 order prints the before-shipment trigger, not the BL-copy one', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');
    await addTestProduct(orderId);

    const data = await documentDataAssembler.assemble(orderId, 'CI');

    expect(data.financial.balance_terms_text).toContain('before shipment');
    expect(data.financial.balance_terms_text).toContain('Shipment Readiness Confirmation');
    expect(data.financial.balance_terms_text).not.toContain('scanned copy of Bill of Lading');
  });

  it('a Tier 2 order prints the against-BL trigger', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Established Buyer — Post-BL');
    await addTestProduct(orderId);

    const data = await documentDataAssembler.assemble(orderId, 'CI');

    expect(data.financial.balance_terms_text).toContain('scanned copy of Bill of Lading');
    expect(data.financial.balance_terms_text).not.toContain('before shipment');
  });

  // No real-PDF-render assertion here — puppeteer-core is intentionally
  // stubbed out under Jest (see tests/support/mocks/puppeteerCoreStub.js's
  // own docblock); the PHP sibling test
  // (CommercialInvoiceBalanceTriggerWordingTest::
  // testRenderedTier1CommercialInvoicePdfCarriesTheBeforeShipmentWording)
  // renders the real PDF and confirms this exact string ends up on the
  // page — this template interpolates financial.balance_terms_text
  // verbatim with no further logic in between, identically in both
  // stacks' CI templates.
});
