'use strict';

const db = require('../config/db');

/**
 * The client portal's own view — every customer_facing document ever
 * approved/sent for this order, most recent revision first. Deliberately
 * excludes draft/in_review status (a client never sees a document before
 * it's approved) and internal/procurement categories (BLI, COOPREP, SUPPO —
 * never buyer-facing by design, per document_types). A client sees this
 * retroactively from the Quotation onward, per the confirmed scope — there
 * is no date filter here at all.
 */
async function customerFacingForOrder(orderId) {
  return db.query(
    `SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name
     FROM documents d
     JOIN document_types dt ON dt.id = d.document_type_id
     WHERE d.order_id = :order_id
       AND dt.category = 'customer_facing'
       AND d.status IN ('approved', 'sent')
     ORDER BY d.generated_at DESC`,
    { order_id: orderId }
  );
}

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
    `SELECT d.*, dt.code AS document_type_code, dt.name AS document_type_name, dt.min_reviewers_default, dt.category AS document_type_category
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

/**
 * How many prior documents of this (order, type) actually reached the
 * client — 'sent' (currently with the client) or 'superseded' (was sent,
 * later replaced by a newer send). Used to compute client_revision_number
 * (docs/schema.sql Section AH): purely a count of past real sends, so an
 * internal-only regeneration between sends never moves it.
 */
async function countPriorSent(orderId, documentTypeId) {
  const row = await db.queryOne(
    `SELECT COUNT(*) AS c FROM documents
     WHERE order_id = :order_id AND document_type_id = :document_type_id
       AND status IN ('sent', 'superseded')`,
    { order_id: orderId, document_type_id: documentTypeId }
  );
  return parseInt(row.c, 10);
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

/**
 * @param {object|null} signatory as returned by documentDataAssembler.signatoryBlock() —
 *        snapshotted onto the row so a later change to any signatory
 *        default never alters how a document that was already generated reads.
 * @param {object|null} companySnapshot as returned by documentDataAssembler.companyBlock() —
 *        snapshotted onto the row for the same reason: a bank account
 *        switch or LUT renewal must never alter how a document that was
 *        already generated reads. See schema.sql SECTION P.
 */
async function create(orderId, documentTypeId, documentReference, revisionNumber, pdfFileId, docxFileId, generatedBy, signatory = null, companySnapshot = null, clientRevisionNumber = null) {
  const result = await db.execute(
    `INSERT INTO documents
        (order_id, document_type_id, document_reference, revision_number, client_revision_number, status, generated_by, docx_file_id, pdf_file_id,
         signatory_user_id, signatory_name_snapshot, signatory_designation_snapshot,
         signature_asset_id_snapshot, seal_asset_id_snapshot, used_designation_seal, company_snapshot_json)
     VALUES
        (:order_id, :document_type_id, :document_reference, :revision_number, :client_revision_number, 'draft', :generated_by, :docx_file_id, :pdf_file_id,
         :signatory_user_id, :signatory_name_snapshot, :signatory_designation_snapshot,
         :signature_asset_id_snapshot, :seal_asset_id_snapshot, :used_designation_seal, :company_snapshot_json)`,
    {
      order_id: orderId, document_type_id: documentTypeId, document_reference: documentReference,
      revision_number: revisionNumber, client_revision_number: clientRevisionNumber, generated_by: generatedBy, docx_file_id: docxFileId, pdf_file_id: pdfFileId,
      signatory_user_id: signatory ? signatory.user_id : null,
      signatory_name_snapshot: signatory ? signatory.name : null,
      signatory_designation_snapshot: signatory ? signatory.designation : null,
      signature_asset_id_snapshot: signatory ? signatory.signature_asset_id : null,
      seal_asset_id_snapshot: signatory ? signatory.seal_asset_id : null,
      used_designation_seal: signatory && signatory.used_designation_seal ? 1 : 0,
      company_snapshot_json: companySnapshot !== null ? JSON.stringify(companySnapshot) : null,
    }
  );
  return result.insertId;
}

module.exports = {
  customerFacingForOrder, forOrder, find, findLatestForOrderAndType, findLatestForOrderAndTypeCode,
  countPriorSent, markApproved, markInReview, markDraft, markSent, create,
};
