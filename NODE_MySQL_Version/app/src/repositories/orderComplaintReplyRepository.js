'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section BX — a complaint's own reply thread. Unlike
 * dispute_replies (staff-only), this is a genuine two-way conversation —
 * author_type/author_user_id/author_client_id, the same shape as
 * order_comments — because a Complaint is meant to be a back-and-forth
 * with the client, not an internal-only resolution log.
 */
async function createAsStaff(complaintId, authorUserId, body) {
  const result = await db.execute(
    `INSERT INTO order_complaint_replies (complaint_id, author_type, author_user_id, body)
     VALUES (:complaint_id, 'staff', :author_user_id, :body)`,
    { complaint_id: complaintId, author_user_id: authorUserId, body }
  );
  return result.insertId;
}

async function createAsClient(complaintId, authorClientId, body) {
  const result = await db.execute(
    `INSERT INTO order_complaint_replies (complaint_id, author_type, author_client_id, body)
     VALUES (:complaint_id, 'client', :author_client_id, :body)`,
    { complaint_id: complaintId, author_client_id: authorClientId, body }
  );
  return result.insertId;
}

async function forComplaint(complaintId) {
  return db.query(
    `SELECT r.*, u.name AS staff_author_name
     FROM order_complaint_replies r
     LEFT JOIN users u ON u.id = r.author_user_id
     WHERE r.complaint_id = :complaint_id
     ORDER BY r.created_at ASC, r.id ASC`,
    { complaint_id: complaintId }
  );
}

module.exports = { createAsStaff, createAsClient, forComplaint };
