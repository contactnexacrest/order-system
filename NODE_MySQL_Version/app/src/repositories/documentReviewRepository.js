'use strict';

const db = require('../config/db');

async function assign(documentId, reviewerId) {
  const result = await db.execute(
    "INSERT INTO document_reviews (document_id, reviewer_id, status) VALUES (:document_id, :reviewer_id, 'pending')",
    { document_id: documentId, reviewer_id: reviewerId }
  );
  return result.insertId;
}

async function forDocument(documentId) {
  return db.query(
    `SELECT dr.*, u.name AS reviewer_name
     FROM document_reviews dr JOIN users u ON u.id = dr.reviewer_id
     WHERE dr.document_id = :document_id
     ORDER BY dr.assigned_at`,
    { document_id: documentId }
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM document_reviews WHERE id = :id', { id });
}

async function pendingForReviewer(reviewerId) {
  return db.query(
    `SELECT dr.*, d.order_id, d.document_reference, d.revision_number, dt.code AS document_type_code, dt.name AS document_type_name
     FROM document_reviews dr
     JOIN documents d ON d.id = dr.document_id
     JOIN document_types dt ON dt.id = d.document_type_id
     WHERE dr.reviewer_id = :reviewer_id AND dr.status = 'pending'
     ORDER BY dr.assigned_at`,
    { reviewer_id: reviewerId }
  );
}

async function approve(id, comments) {
  await db.execute("UPDATE document_reviews SET status = 'approved', comments = :comments, reviewed_at = NOW() WHERE id = :id", { comments, id });
}

async function reject(id, comments) {
  await db.execute("UPDATE document_reviews SET status = 'rejected', comments = :comments, reviewed_at = NOW() WHERE id = :id", { comments, id });
}

async function countPending(documentId) {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM document_reviews WHERE document_id = :document_id AND status = 'pending'", { document_id: documentId });
  return parseInt(row.c, 10);
}

async function countApproved(documentId) {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM document_reviews WHERE document_id = :document_id AND status = 'approved'", { document_id: documentId });
  return parseInt(row.c, 10);
}

async function countRejected(documentId) {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM document_reviews WHERE document_id = :document_id AND status = 'rejected'", { document_id: documentId });
  return parseInt(row.c, 10);
}

module.exports = { assign, forDocument, find, pendingForReviewer, approve, reject, countPending, countApproved, countRejected };
