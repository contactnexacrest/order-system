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

module.exports = { insertGenerated, insertReceived, find, forOrder };
