'use strict';

const db = require('../config/db');

async function create(documentId, verifiedBy, result, comments) {
  const res = await db.execute(
    'INSERT INTO document_cross_verifications (document_id, verified_by, result, comments) VALUES (:document_id, :verified_by, :result, :comments)',
    { document_id: documentId, verified_by: verifiedBy, result, comments }
  );
  return res.insertId;
}

async function forDocument(documentId) {
  return db.query(
    `SELECT cv.*, u.name AS verified_by_name
     FROM document_cross_verifications cv JOIN users u ON u.id = cv.verified_by
     WHERE cv.document_id = :document_id
     ORDER BY cv.verified_at DESC`,
    { document_id: documentId }
  );
}

module.exports = { create, forDocument };
