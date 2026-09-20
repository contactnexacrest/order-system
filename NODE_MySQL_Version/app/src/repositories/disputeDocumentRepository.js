'use strict';

const db = require('../config/db');

async function attach(disputeId, fileId) {
  const result = await db.execute('INSERT INTO dispute_documents (dispute_id, file_id) VALUES (:dispute_id, :file_id)', {
    dispute_id: disputeId,
    file_id: fileId,
  });
  return result.insertId;
}

async function forDispute(disputeId) {
  return db.query(
    `SELECT dd.*, fs.original_filename, fs.server_path, fs.mime_type, fs.uploaded_at, fs.received_from
     FROM dispute_documents dd JOIN file_store fs ON fs.id = dd.file_id
     WHERE dd.dispute_id = :dispute_id
     ORDER BY fs.uploaded_at`,
    { dispute_id: disputeId }
  );
}

module.exports = { attach, forDispute };
