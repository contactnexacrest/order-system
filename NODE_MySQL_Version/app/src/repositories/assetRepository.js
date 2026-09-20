'use strict';

const db = require('../config/db');

async function all() {
  return db.query('SELECT * FROM assets ORDER BY asset_type, name');
}

async function findActiveByType(assetType) {
  return db.queryOne('SELECT * FROM assets WHERE asset_type = :type AND is_active = 1 ORDER BY id DESC LIMIT 1', { type: assetType });
}

async function find(id) {
  return db.queryOne('SELECT * FROM assets WHERE id = :id', { id });
}

/**
 * Hard-delete is only ever allowed for an inactive (superseded) asset row —
 * never the currently active one. If a foreign key still points at this
 * row (watermark_settings, or a document's seal_asset_id_snapshot for a
 * company-seal document), the database refuses the delete and mysql2
 * throws — the controller translates that into a plain message rather
 * than silently orphaning historical documents.
 */
async function remove(id) {
  await db.execute('DELETE FROM assets WHERE id = :id AND is_active = 0', { id });
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

module.exports = { all, findActiveByType, find, remove, insert, replace };
