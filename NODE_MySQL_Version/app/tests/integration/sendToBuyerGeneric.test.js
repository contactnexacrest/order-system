'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of SendToBuyerGenericTest.php. 90-item backlog #12: "Send to Buyer"
 * must be generalized to every document type, not re-gated per type by
 * hand. orders/show.njk previously drove this off a hardcoded exclusion
 * array (`d.document_type_code != 'AMD' and ... != 'SUPPO' and ... !=
 * 'COOPREP' and ... != 'BLI'`), which silently omitted every OTHER
 * internal/procurement document_types row (CHECKLIST*, SOP_A_SALES,
 * SOP_B_SALES, STAGEGATE, WALLREF, CAFIN) — none of those were in the
 * exclusion list, so any of them would wrongly get offered "Send to
 * Buyer" if rendered on an order page. The fix drives this off
 * document_types.category instead, which requires documentRepository's
 * forOrder() to actually select it — previously it didn't (only find()
 * did), so the template had no category to read at all. This test checks
 * what ordersController.show() hands the view, same convention as
 * ocAckButtonVisibility.test.js, rather than rendering the full njk
 * template (which needs the whole layout/session/permission context).
 */
describe('Send to Buyer generalized across document types (90-item backlog #12)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  async function createTestDocument(orderId, typeCode, status) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-SENDGEN-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function categoryForSoleDocument(typeCode) {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, typeCode, 'approved');

    const req = { params: { id: String(orderId) }, permissions: new Proxy({}, { get: () => true }), session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    const doc = res.renderedData.documents.find((d) => d.document_type_code === typeCode);
    expect(doc).toBeDefined();
    return doc.document_type_category;
  }

  it('CAFIN (internal) is tagged with a non-customer_facing category', async () => {
    expect(await categoryForSoleDocument('CAFIN')).not.toBe('customer_facing');
  });

  it('CHECKLIST (internal) is tagged with a non-customer_facing category', async () => {
    expect(await categoryForSoleDocument('CHECKLIST')).not.toBe('customer_facing');
  });

  it('BLE (customer_facing) is tagged customer_facing, same as QT/PI/OC always have been', async () => {
    expect(await categoryForSoleDocument('BLE')).toBe('customer_facing');
  });

  it('SUPPO (procurement, already excluded before this fix) is tagged with a non-customer_facing category', async () => {
    expect(await categoryForSoleDocument('SUPPO')).not.toBe('customer_facing');
  });
});
