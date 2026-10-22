'use strict';

const db = require('../../src/config/db');
const emailDispatchService = require('../../src/services/emailDispatchService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report L-40: document_types.never_shown_to_buyer (Business
 * Rule #20) was a real, seeded column — set on SUPPO (Supplier PO, which
 * carries internal INR unit prices), BLI, COOPREP, the internal
 * checklists, and AMD — but was never actually checked anywhere in the
 * codebase. The order page only offers "Send to Buyer" for
 * category='customer_facing' documents, which happens to exclude all of
 * these by coincidence, but that's a UI convenience, not a security
 * boundary: the send route itself (emailDispatchService.buildPreview(),
 * the one chokepoint requestSend()/sendNowOverride() both go through) did
 * no check at all, so a crafted request hitting it directly for a SUPPO
 * document's id would queue — and, once approved, actually send — a
 * buyer's internal cost data to the buyer themselves.
 */
describe('emailDispatchService — L-40 never_shown_to_buyer gate', () => {
  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft', pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      {
        order_id: orderId,
        type_id: type.id,
        ref: `JEST-L40-${Math.random().toString(16).slice(2, 10)}`,
        status,
        pdf_file_id: pdfFileId,
      }
    );
    return result.insertId;
  }

  afterAll(async () => {
    await db.pool.end();
  });

  it('refuses a document type flagged never_shown_to_buyer', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // SUPPO is seeded with never_shown_to_buyer=1 (it carries internal
    // INR unit prices) — approved status so the earlier "must clear
    // internal review first" check doesn't mask this one.
    const documentId = await createTestDocument(orderId, 'SUPPO', 'approved', null);

    await expect(emailDispatchService.buildPreview(orderId, documentId, 'send_qt', 1)).rejects.toThrow(
      /internal document and can never be sent to the buyer/
    );
  });

  it('still allows an ordinary customer-facing document', async () => {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', {
      email: `jest-l40-buyer-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`,
      id: clientId,
    });
    const orderId = await createTestOrder(clientId);
    // QT is customer_facing and never_shown_to_buyer=0 — must not be
    // refused by the same gate.
    const documentId = await createTestDocument(orderId, 'QT', 'approved', null);

    const preview = await emailDispatchService.buildPreview(orderId, documentId, 'send_qt', 1);

    expect(preview.document.id).toBe(documentId);
  });
});
