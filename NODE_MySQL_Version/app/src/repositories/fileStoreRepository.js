'use strict';

const db = require('../config/db');

async function insertGenerated(
  clientId,
  orderId,
  stageId,
  serverPath,
  uuidFilename,
  originalFilename,
  fileSizeBytes,
  mimeType,
  uploadedBy,
  internalOnly = false
) {
  const result = await db.execute(
    `INSERT INTO file_store
        (client_id, order_id, stage_id, file_origin, generation_method, sent_to_client, internal_only,
         server_path, uuid_filename, original_filename, file_size_bytes, mime_type, uploaded_by)
     VALUES
        (:client_id, :order_id, :stage_id, 'GENERATED_AUTO', 'nunjucks+puppeteer/docx', 0, :internal_only,
         :server_path, :uuid_filename, :original_filename, :file_size_bytes, :mime_type, :uploaded_by)`,
    {
      client_id: clientId,
      order_id: orderId,
      stage_id: stageId,
      internal_only: internalOnly ? 1 : 0,
      server_path: serverPath,
      uuid_filename: uuidFilename,
      original_filename: originalFilename,
      file_size_bytes: fileSizeBytes,
      mime_type: mimeType,
      uploaded_by: uploadedBy,
    }
  );
  return result.insertId;
}

/**
 * A file NexaCrest received from someone else (signed amendment copy,
 * dispute correspondence, ...) rather than generated — see
 * fileUploadService. file_origin='RECEIVED', never internal_only (that
 * flag means "internal-parity DOCX", not relevant here).
 */
async function insertReceived(
  clientId,
  orderId,
  serverPath,
  uuidFilename,
  originalFilename,
  fileSizeBytes,
  mimeType,
  uploadedBy,
  receivedFrom = null,
  documentTypeLabel = null
) {
  const result = await db.execute(
    `INSERT INTO file_store
        (client_id, order_id, file_origin, received_from, document_type_label,
         server_path, uuid_filename, original_filename, file_size_bytes, mime_type, uploaded_by)
     VALUES
        (:client_id, :order_id, 'RECEIVED', :received_from, :document_type_label,
         :server_path, :uuid_filename, :original_filename, :file_size_bytes, :mime_type, :uploaded_by)`,
    {
      client_id: clientId,
      order_id: orderId,
      received_from: receivedFrom,
      document_type_label: documentTypeLabel,
      server_path: serverPath,
      uuid_filename: uuidFilename,
      original_filename: originalFilename,
      file_size_bytes: fileSizeBytes,
      mime_type: mimeType,
      uploaded_by: uploadedBy,
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM file_store WHERE id = :id', { id });
}

/**
 * Every live file for one order, generated or received — order_id is set
 * on file_store directly for both origins (insertGenerated() and
 * insertReceived() above), so this is the one query the full order
 * dossier ZIP needs, rather than separately joining through documents/
 * dispute_documents/orderBuyerPoDocuments/etc. Soft-deleted rows
 * (is_active = 0) are excluded — file_store is never hard-deleted.
 */
async function forOrder(orderId) {
  return db.query(
    `SELECT * FROM file_store WHERE order_id = :order_id AND is_active = 1
     ORDER BY file_origin, uploaded_at`,
    { order_id: orderId }
  );
}

/**
 * Point 2 follow-up — same as forOrder(), but excludes the internal-only
 * CA Financial Annexure (CAFIN). Used by the general-purpose staff
 * dossier ZIP (ordersController.downloadDossier()), which is gated only
 * on the broad manage_orders permission most staff hold — bundling
 * CAFIN into it would let anyone with that permission pull sensitive
 * financial data in bulk, bypassing the ca_module_view gate
 * documentController.download() enforces for it everywhere else. Staff
 * who actually hold that permission still get it directly from the
 * order page/CA module, one click away — this only keeps it out of the
 * unrelated bulk export.
 */
async function forOrderExcludingInternalCaDocs(orderId) {
  return db.query(
    `SELECT fs.* FROM file_store fs
     WHERE fs.order_id = :order_id AND fs.is_active = 1
       AND NOT EXISTS (
           SELECT 1 FROM documents d
           JOIN document_types dt ON dt.id = d.document_type_id
           WHERE dt.code = 'CAFIN' AND (d.pdf_file_id = fs.id OR d.docx_file_id = fs.id)
       )
     ORDER BY fs.file_origin, fs.uploaded_at`,
    { order_id: orderId }
  );
}

/**
 * Batch 3 #13b — flips the marker that separates this feature's own
 * ad-hoc attachments from every other RECEIVED/GENERATED file_store
 * row (buyer PO copies, dispute documents, generated PDFs, ...) that
 * already lives in the same table. Called right after
 * fileUploadService.handleUpload() stores the row, rather than
 * widening that shared service's own parameter list for one caller.
 */
async function markAsAdditionalDocument(fileId, notes) {
  await db.execute(
    'UPDATE file_store SET is_additional_document = 1, notes = :notes WHERE id = :id',
    { notes, id: fileId }
  );
}

async function additionalDocumentsForOrder(orderId) {
  return db.query(
    `SELECT fs.*, u.name AS uploaded_by_name FROM file_store fs
     LEFT JOIN users u ON u.id = fs.uploaded_by
     WHERE fs.order_id = :order_id AND fs.is_active = 1 AND fs.is_additional_document = 1
     ORDER BY fs.uploaded_at DESC`,
    { order_id: orderId }
  );
}

async function additionalDocumentsForClient(clientId) {
  return db.query(
    `SELECT fs.*, u.name AS uploaded_by_name FROM file_store fs
     LEFT JOIN users u ON u.id = fs.uploaded_by
     WHERE fs.client_id = :client_id AND fs.is_active = 1 AND fs.is_additional_document = 1
     ORDER BY fs.uploaded_at DESC`,
    { client_id: clientId }
  );
}

/** Soft delete only — file_store rows are never hard-deleted, and the file on disk is never touched. */
async function deactivate(id) {
  await db.execute('UPDATE file_store SET is_active = 0 WHERE id = :id', { id });
}

/**
 * External QA report L-18: "no screen to see removed files" — the
 * deactivated counterpart to additionalDocumentsForOrder() above. Joins
 * audit_log for the removal reason/actor/timestamp; a document removed
 * before this fix existed (reason column was always null) still shows
 * up, just without that detail.
 */
async function removedAdditionalDocumentsForOrder(orderId) {
  return db.query(
    `SELECT fs.*, u.name AS uploaded_by_name,
            al.reason AS removal_reason, al.created_at AS removed_at, ru.name AS removed_by_name
     FROM file_store fs
     LEFT JOIN users u ON u.id = fs.uploaded_by
     LEFT JOIN audit_log al ON al.entity_type = 'file_store' AND al.entity_id = fs.id AND al.action_type = 'ORDER_ADDITIONAL_DOCUMENT_REMOVED'
     LEFT JOIN users ru ON ru.id = al.user_id
     WHERE fs.order_id = :order_id AND fs.is_active = 0 AND fs.is_additional_document = 1
     ORDER BY al.created_at DESC, fs.uploaded_at DESC`,
    { order_id: orderId }
  );
}

/** External QA report L-18: client-side counterpart to removedAdditionalDocumentsForOrder() above. */
async function removedAdditionalDocumentsForClient(clientId) {
  return db.query(
    `SELECT fs.*, u.name AS uploaded_by_name,
            al.reason AS removal_reason, al.created_at AS removed_at, ru.name AS removed_by_name
     FROM file_store fs
     LEFT JOIN users u ON u.id = fs.uploaded_by
     LEFT JOIN audit_log al ON al.entity_type = 'file_store' AND al.entity_id = fs.id AND al.action_type = 'CLIENT_ADDITIONAL_DOCUMENT_REMOVED'
     LEFT JOIN users ru ON ru.id = al.user_id
     WHERE fs.client_id = :client_id AND fs.is_active = 0 AND fs.is_additional_document = 1
     ORDER BY al.created_at DESC, fs.uploaded_at DESC`,
    { client_id: clientId }
  );
}

/** QA Round 5, R5-21: the document type of the generated document this file belongs to, if any. */
async function documentTypeCodeFor(fileId) {
  const rows = await db.query(
    `SELECT dt.code FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
     WHERE d.pdf_file_id = :pdf OR d.docx_file_id = :docx LIMIT 1`,
    { pdf: fileId, docx: fileId }
  );
  return rows.length ? rows[0].code : null;
}

module.exports = {
  documentTypeCodeFor,
  insertGenerated, insertReceived, find, forOrder, forOrderExcludingInternalCaDocs,
  markAsAdditionalDocument, additionalDocumentsForOrder, additionalDocumentsForClient, deactivate,
  removedAdditionalDocumentsForOrder, removedAdditionalDocumentsForClient,
};
