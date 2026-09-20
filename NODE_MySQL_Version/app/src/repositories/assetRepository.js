'use strict';

const db = require('../config/db');

async function all() {
  return db.query('SELECT * FROM assets ORDER BY asset_type, name');
}

async function findActiveByType(assetType) {
  return db.queryOne('SELECT * FROM assets WHERE asset_type = :type AND is_active = 1 ORDER BY id DESC LIMIT 1', { type: assetType });
}

async function insert(assetType, name, serverPath, mimeType, uploadedBy, isActive = true) {
  const result = await db.execute(
    `INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
     VALUES (:type, :name, :path, :mime, :active, :uploaded_by)`,
    { type: assetType, name, path: serverPath, mime: mimeType, active: isActive ? 1 : 0, uploaded_by: uploadedBy }
  );
  return result.insertId;
}

/** Deactivate the current active row for that type, insert the new one as active — old rows kept for history. */
async function replace(assetType, name, serverPath, mimeType, uploadedBy) {
  return db.transaction(async (conn) => {
    await conn.execute('UPDATE assets SET is_active = 0 WHERE asset_type = :type AND is_active = 1', { type: assetType });
    const result = await conn.execute(
      `INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
       VALUES (:type, :name, :path, :mime, 1, :uploaded_by)`,
      { type: assetType, name, path: serverPath, mime: mimeType, uploaded_by: uploadedBy }
    );
    return result.insertId;
  });
}

module.exports = { all, findActiveByType, insert, replace };
