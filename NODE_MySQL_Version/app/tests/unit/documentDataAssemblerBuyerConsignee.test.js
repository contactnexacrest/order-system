'use strict';

const db = require('../../src/config/db');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of DocumentDataAssemblerBuyerConsigneeTest.php. 90-item backlog
 * #11: the Buyer PO document (and, since this field is read from the
 * same shared `buyer` object by every DOCX builder branch — QT, PI, OC,
 * FDN, BUYERPO, BLI, CI — effectively every document type's DOCX output)
 * printed order.consignee_name/order.consignee_address verbatim. Those
 * two columns live on `clients` and are frequently NULL whenever
 * consignee_same_as_buyer is set (the default/common case, meaning "the
 * consignee IS the buyer's own current details") — a wiring gap, not a
 * missing feature, since documentDataAssembler's resolveConsignee()
 * already computes the correct value earlier in the very same assemble()
 * call and every OTHER consignee-aware field already defers to it. Fixed
 * by wiring buyer.consignee_name/buyer.consignee_address to the
 * already-resolved resolvedConsignee instead of the raw columns.
 */
describe('documentDataAssembler buyer consignee fields (backlog #11)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it("resolve to the buyer's own details when consignee is same-as-buyer", async () => {
    const orderId = await createTestOrder(await createTestClient());

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.consignee.same_as_buyer).toBe(true);
    expect(data.buyer.consignee_name).toBe('Jest Test Buyer Ltd');
    expect(data.buyer.consignee_address).toBe('1 Test Street, Test City');
    expect(data.buyer.consignee_name).toBe(data.consignee.company_legal_name);
    expect(data.buyer.consignee_address).toBe(data.consignee.address_line1);
  });

  it('use the independent consignee when consignee is not same-as-buyer', async () => {
    const clientId = await createTestClient();
    await db.execute(
      `UPDATE clients SET
          consignee_same_as_buyer = 0,
          consignee_name = :name, consignee_address_line1 = :addr1, consignee_city = :city
       WHERE id = :id`,
      { name: 'Independent Consignee Co', addr1: '5 Consignee Avenue', city: 'Manchester', id: clientId }
    );
    const orderId = await createTestOrder(clientId);

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.consignee.same_as_buyer).toBe(false);
    expect(data.buyer.consignee_name).toBe('Independent Consignee Co');
    expect(data.buyer.consignee_address).toBe('5 Consignee Avenue');
    expect(data.buyer.consignee_name).toBe(data.consignee.company_legal_name);
    expect(data.buyer.consignee_address).toBe(data.consignee.address_line1);
  });
});
