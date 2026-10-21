'use strict';

const db = require('../../src/config/db');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CommercialInvoiceNonFobTotalTest.php. 90-item backlog item
 * #18 (BLOCKER): for a non-FOB order, the Commercial Invoice's TOTAL
 * COMMERCIAL INVOICE VALUE must include Freight & Insurance — the
 * buyer-country customs/import-duty authority assesses duty against
 * the full CFR/CIF value, and a Commercial Invoice that understates it
 * (goods value only) risks customs delay or a penalty for the buyer.
 * FOB Value itself stays completely unchanged everywhere (QT/PI/OC's
 * own "Total ... Value" fields, Section A's own FOB Value row, Section
 * B's advance/balance payment math) — this only adds a new, additional
 * "total commercial invoice value" figure used solely by the CI
 * document.
 */
describe('Commercial Invoice non-FOB total (90-item backlog item #18, BLOCKER)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function addTestProduct(orderId) {
    await orderProductRepository.add(orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '1825.00');
  }

  it('non-FOB total includes confirmed Stage 6 freight when no FDN payment recorded yet', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await addTestProduct(orderId);
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '1200.00', insurance_amount: '150.00' });

    const data = await documentDataAssembler.assemble(orderId, 'CI');

    expect(data.financial.fob_value).toBe('18,250.00');
    expect(data.financial.freight_insurance_amount).toBe('1,350.00');
    expect(data.financial.total_commercial_invoice_value).toBe('19,600.00');
  });

  it('non-FOB total prefers the actually-cleared FDN payment amount over Stage 6 confirmed', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CFR');
    await addTestProduct(orderId);
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '1200.00', insurance_amount: '150.00' });
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordFreightReceived(orderId, 1500.0, new Date().toISOString().slice(0, 10));

    const data = await documentDataAssembler.assemble(orderId, 'CI');

    expect(data.financial.freight_insurance_amount).toBe('1,500.00');
    expect(data.financial.total_commercial_invoice_value).toBe('19,750.00');
  });

  it('FOB order total equals FOB value only', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    await addTestProduct(orderId);

    const data = await documentDataAssembler.assemble(orderId, 'CI');

    expect(data.financial.total_commercial_invoice_value).toBe('18,250.00');
    expect(data.financial.freight_insurance_amount).toBeNull();
  });

  it('non-FOB order with no freight info yet falls back to FOB value only', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await addTestProduct(orderId);

    const data = await documentDataAssembler.assemble(orderId, 'CI');

    expect(data.financial.total_commercial_invoice_value).toBe('18,250.00');
    expect(data.financial.freight_insurance_amount).toBeNull();
  });

  it("QT/PI/OC's own total_value field is completely unaffected", async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await addTestProduct(orderId);
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '1200.00', insurance_amount: '150.00' });

    const data = await documentDataAssembler.assemble(orderId, 'QT');

    expect(data.financial.total_value).toBe('18,250.00');
  });
});
