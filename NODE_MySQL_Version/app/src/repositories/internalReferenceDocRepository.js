'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\InternalReferenceDocRepository — the Internal
 * Reference Library (schema.sql SECTION R): evergreen, admin-editable
 * content for the eight internal document_types rows (the four
 * Cross-Verification Checklists — CHECKLIST, CHECKLIST_2_FINANCE,
 * CHECKLIST_3_PACKING, CHECKLIST_4_SHIPPING — plus SOP_A_SALES,
 * SOP_B_SALES, STAGEGATE, WALLREF) that existed only as unused rows
 * before this. One row per document_type_id, not per-order — these apply
 * company-wide, not to any single order.
 */

async function all() {
  return db.query(
    `SELECT dt.id AS document_type_id, dt.code, dt.name, ird.content, ird.updated_at
     FROM document_types dt
     LEFT JOIN internal_reference_docs ird ON ird.document_type_id = dt.id
     WHERE dt.code IN ('CHECKLIST', 'CHECKLIST_2_FINANCE', 'CHECKLIST_3_PACKING', 'CHECKLIST_4_SHIPPING', 'SOP_A_SALES', 'SOP_B_SALES', 'STAGEGATE', 'WALLREF')
     ORDER BY dt.id`
  );
}

async function findByCode(code) {
  return db.queryOne(
    `SELECT dt.id AS document_type_id, dt.code, dt.name, ird.content, ird.updated_at, ird.updated_by
     FROM document_types dt
     LEFT JOIN internal_reference_docs ird ON ird.document_type_id = dt.id
     WHERE dt.code = :code`,
    { code }
  );
}

/** Upsert — a code with no row yet (content never entered) becomes one on first save. */
async function upsert(documentTypeId, content, updatedBy) {
  await db.execute(
    `INSERT INTO internal_reference_docs (document_type_id, content, updated_by)
     VALUES (:document_type_id, :content, :updated_by)
     ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)`,
    { document_type_id: documentTypeId, content, updated_by: updatedBy }
  );
}

module.exports = { all, findByCode, upsert };
