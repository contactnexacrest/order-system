'use strict';

const db = require('../config/db');
const auditLogRepository = require('./auditLogRepository');

// Port of App\Repositories\SignatoryRepository.

async function designations() {
  return db.query('SELECT * FROM designations ORDER BY is_active DESC, title');
}

async function createDesignation(title, createdBy) {
  const result = await db.execute('INSERT INTO designations (title, created_by) VALUES (:title, :by)', { title, by: createdBy });
  return result.insertId;
}

async function toggleDesignationActive(id) {
  await db.execute('UPDATE designations SET is_active = 1 - is_active WHERE id = :id', { id });
}

async function usersWithSignatoryInfo() {
  return db.query(
    `SELECT u.id, u.name, u.email, u.is_signatory_eligible, u.designation_id, d.title AS designation_title
     FROM users u LEFT JOIN designations d ON d.id = u.designation_id
     WHERE u.is_active = 1
     ORDER BY u.name`
  );
}

async function eligibleSignatories() {
  return db.query(
    `SELECT u.id, u.name, d.title AS designation_title
     FROM users u LEFT JOIN designations d ON d.id = u.designation_id
     WHERE u.is_active = 1 AND u.is_signatory_eligible = 1
     ORDER BY u.name`
  );
}

async function setEligibility(userId, eligible, designationId, updatedBy) {
  await db.execute(
    'UPDATE users SET is_signatory_eligible = :eligible, designation_id = :designation_id WHERE id = :id',
    { eligible: eligible ? 1 : 0, designation_id: designationId, id: userId }
  );
  await auditLogRepository.log(updatedBy, eligible ? 'SIGNATORY_ELIGIBILITY_GRANTED' : 'SIGNATORY_ELIGIBILITY_REVOKED', 'users', userId, null, null, null);
}

async function assetsForUser(userId) {
  return db.query('SELECT * FROM user_signature_assets WHERE user_id = :uid ORDER BY asset_kind, is_default_for_kind DESC, id DESC', { uid: userId });
}

async function addUserAsset(userId, kind, label, serverPath, mime, uploadedBy) {
  return db.transaction(async (conn) => {
    await conn.execute('UPDATE user_signature_assets SET is_default_for_kind = 0 WHERE user_id = :uid AND asset_kind = :kind', { uid: userId, kind });
    const result = await conn.execute(
      `INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, uploaded_by)
       VALUES (:uid, :kind, :label, :path, :mime, 1, :by)`,
      { uid: userId, kind, label, path: serverPath, mime, by: uploadedBy }
    );
    return result.insertId;
  });
}

async function deactivateUserAsset(id) {
  await db.execute('UPDATE user_signature_assets SET is_active = 0 WHERE id = :id', { id });
}

async function globalDefaultSignatoryUserId() {
  const row = await db.queryOne('SELECT user_id FROM company_default_signatory WHERE id = 1');
  return row ? row.user_id : null;
}

async function setGlobalDefaultSignatory(userId, updatedBy) {
  await db.execute(
    `INSERT INTO company_default_signatory (id, user_id, updated_by) VALUES (1, :uid, :by)
     ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_by = VALUES(updated_by)`,
    { uid: userId, by: updatedBy }
  );
  await auditLogRepository.log(updatedBy, 'GLOBAL_DEFAULT_SIGNATORY_SET', 'company_default_signatory', 1, null, null, String(userId));
}

async function documentTypeSignatories() {
  return db.query(
    `SELECT dt.id AS document_type_id, dt.code, dt.name,
            dts.user_id, dts.use_designation_seal, u.name AS signatory_name
     FROM document_types dt
     LEFT JOIN document_type_signatories dts ON dts.document_type_id = dt.id
     LEFT JOIN users u ON u.id = dts.user_id
     WHERE dt.is_active = 1
     ORDER BY dt.code`
  );
}

async function setDocumentTypeSignatory(documentTypeId, userId, useDesignationSeal, updatedBy) {
  if (userId === null) {
    await db.execute('DELETE FROM document_type_signatories WHERE document_type_id = :dt', { dt: documentTypeId });
  } else {
    await db.execute(
      `INSERT INTO document_type_signatories (document_type_id, user_id, use_designation_seal, updated_by)
       VALUES (:dt, :uid, :seal, :by)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), use_designation_seal = VALUES(use_designation_seal), updated_by = VALUES(updated_by)`,
      { dt: documentTypeId, uid: userId, seal: useDesignationSeal ? 1 : 0, by: updatedBy }
    );
  }
  await auditLogRepository.log(updatedBy, 'DOCUMENT_TYPE_SIGNATORY_SET', 'document_type_signatories', documentTypeId, null, null, String(userId));
}

module.exports = {
  designations, createDesignation, toggleDesignationActive,
  usersWithSignatoryInfo, eligibleSignatories, setEligibility,
  assetsForUser, addUserAsset, deactivateUserAsset,
  globalDefaultSignatoryUserId, setGlobalDefaultSignatory,
  documentTypeSignatories, setDocumentTypeSignatory,
};
