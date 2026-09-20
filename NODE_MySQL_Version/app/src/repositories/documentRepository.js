'use strict';

const db = require('../config/db');

async function forOrder(orderId) {
  return db.query(
    `SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name, dt.min_reviewers_default
     FROM documents d
     JOIN document_types dt ON dt.id = d.document_type_id
     WHERE d.order_id = :order_id
     ORDER BY d.generated_at DESC`,
    { order_id: orderId }
  );
}

async function find(id) {
  return db.queryOne(
    `SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name, dt.min_reviewers_default
     FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
     WHERE d.id = :id`,
    { id }
  );
}

async function findLatestForOrderAndType(orderId, documentTypeId) {
  return db.queryOne(
    'SELECT * FROM documents WHERE order_id = :order_id AND document_type_id = :document_type_id ORDER BY revision_number DESC LIMIT 1',
    { order_id: orderId, document_type_id: documentTypeId }
  );
}

/** Looks up the latest generated document of a given type CODE for an order, for cross-referencing on a later-stage document. */
async function findLatestForOrderAndTypeCode(orderId, documentTypeCode) {
  return db.queryOne(
    `SELECT d.* FROM documents d
     JOIN document_types dt ON dt.id = d.document_type_id
     WHERE d.order_id = :order_id AND dt.code = :code
     ORDER BY d.revision_number DESC LIMIT 1`,
    { order_id: orderId, code: documentTypeCode }
  );
}

async function markApproved(id, finalPdfFileId) {
  await db.execute("UPDATE documents SET status = 'approved', pdf_file_id = :pdf_file_id WHERE id = :id", { pdf_file_id: finalPdfFileId, id });
}

async function markInReview(id) {
  await db.execute("UPDATE documents SET status = 'in_review' WHERE id = :id", { id });
}

async function markDraft(id) {
  await db.execute("UPDATE documents SET status = 'draft' WHERE id = :id", { id });
}

async function markSent(id) {
  await db.execute("UPDATE documents SET status = 'sent' WHERE id = :id", { id });
}

async function create(orderId, documentTypeId, documentReference, revisionNumber, pdfFileId, docxFileId, generatedBy) {
  const result = await db.execute(
    `INSERT INTO documents
        (order_id, document_type_id, document_reference, revision_number, status, generated_by, docx_file_id, pdf_file_id)
     VALUES
        (:order_id, :document_type_id, :document_reference, :revision_number, 'draft', :generated_by, :docx_file_id, :pdf_file_id)`,
    {
      order_id: orderId, document_type_id: documentTypeId, document_reference: documentReference,
      revision_number: revisionNumber, generated_by: generatedBy, docx_file_id: docxFileId, pdf_file_id: pdfFileId,
    }
  );
  return result.insertId;
}

module.exports = {
  forOrder, find, findLatestForOrderAndType, findLatestForOrderAndTypeCode,
  markApproved, markInReview, markDraft, markSent, create,
};
