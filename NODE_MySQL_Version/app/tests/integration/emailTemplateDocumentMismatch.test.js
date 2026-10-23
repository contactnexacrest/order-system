'use strict';

const db = require('../../src/config/db');
const emailDispatchService = require('../../src/services/emailDispatchService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * External QA report L-41: "Any e-mail template is accepted for any
 * document: a Supplier PO went out under the subject 'Quotation
 * SC/SPO/…'; COO Prep went out as 'Quotation — —'." Nothing tied
 * templateKey to the document actually being sent — every buyer-facing
 * document type has exactly one seeded template, send_<lowercase code>
 * (docs/schema.sql Section AU), so emailDispatchService.buildPreview()
 * (the one chokepoint requestSend()/sendNowOverride() both go through)
 * must refuse any other template for a document-bound send.
 */
describe('emailDispatchService — L-41 template/document mismatch gate', () => {
  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft', pdfFileId = null) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
       VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)`,
      {
        order_id: orderId,
        type_id: type.id,
        ref: `JEST-L41-${Math.random().toString(16).slice(2, 10)}`,
        status,
        pdf_file_id: pdfFileId,
      }
    );
    return result.insertId;
  }

  function uniqueEmail() {
    return `jest-buyer-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
  }

  afterAll(async () => {
    await db.pool.end();
  });

  it('buildPreview refuses a mismatched template for a document', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'QT', 'approved', null);

    await expect(emailDispatchService.buildPreview(orderId, documentId, 'send_ci', 1)).rejects.toThrow(
      'Wrong template for this document'
    );
  });

  it('buildPreview allows the matching template for the document type', async () => {
    const orderId = await createTestOrder(await createTestClient(uniqueEmail()));
    const documentId = await createTestDocument(orderId, 'QT', 'approved', null);

    const preview = await emailDispatchService.buildPreview(orderId, documentId, 'send_qt', 1);

    expect(preview).toBeTruthy();
  });

  it('generic document-less templates are unaffected by the guard', async () => {
    const orderId = await createTestOrder(await createTestClient(uniqueEmail()));

    const preview = await emailDispatchService.buildPreview(orderId, null, 'payment_followup', 1);

    expect(preview).toBeTruthy();
  });
});
