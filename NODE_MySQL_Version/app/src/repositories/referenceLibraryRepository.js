'use strict';

const db = require('../config/db');

/**
 * CRUD for custom Reference Library entries (schema.sql Section Y) —
 * distinct from internalReferenceDocRepository, which owns the 8 fixed,
 * document_types-linked entries. These are freely add/delete-able,
 * optionally carrying an uploaded source file alongside or instead of
 * typed content.
 */

async function all() {
  return db.query(
    `SELECT rld.*, cu.name AS created_by_name, uu.name AS updated_by_name
     FROM reference_library_documents rld
     LEFT JOIN users cu ON cu.id = rld.created_by
     LEFT JOIN users uu ON uu.id = rld.updated_by
     ORDER BY rld.title`
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM reference_library_documents WHERE id = :id', { id });
}

async function create(title, content, userId) {
  const result = await db.execute(
    `INSERT INTO reference_library_documents (title, content, created_by, updated_by)
     VALUES (:title, :content, :user_id, :user_id)`,
    { title, content, user_id: userId }
  );
  return result.insertId;
}

async function updateText(id, title, content, userId) {
  await db.execute(
    'UPDATE reference_library_documents SET title = :title, content = :content, updated_by = :user_id WHERE id = :id',
    { title, content, user_id: userId, id }
  );
}

/** Only ever points file_path/name/mime at the new file — never touches or deletes the previous one on disk. */
async function updateFile(id, filePath, originalName, mimeType, userId) {
  await db.execute(
    `UPDATE reference_library_documents
     SET file_path = :file_path, file_original_name = :original_name, file_mime_type = :mime_type, updated_by = :user_id
     WHERE id = :id`,
    { file_path: filePath, original_name: originalName, mime_type: mimeType, user_id: userId, id }
  );
}

/** Deletes only the DB row — the file on disk (if any) is never touched, matching the app's never-delete-files rule. */
async function deleteEntry(id) {
  await db.execute('DELETE FROM reference_library_documents WHERE id = :id', { id });
}

module.exports = { all, find, create, updateText, updateFile, deleteEntry };
