'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section AY — Reference Library categories. A category
 * is purely a grouping label (name) with an optional required_permission:
 * NULL means every authenticated staff member can see documents filed
 * under it (today's behaviour, unchanged); set, only a user holding that
 * permission key can see them. Enforcement itself lives in
 * referenceDocController.index() (it filters referenceLibraryRepository.
 * all()'s rows by permissionService.can()) — this repository is plain
 * CRUD for the category list.
 */

async function all() {
  return db.query(
    `SELECT rlc.*, (SELECT COUNT(*) FROM reference_library_documents d WHERE d.category_id = rlc.id) AS document_count
     FROM reference_library_categories rlc ORDER BY rlc.name`
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM reference_library_categories WHERE id = :id', { id });
}

async function create(name, requiredPermission) {
  const result = await db.execute(
    'INSERT INTO reference_library_categories (name, required_permission) VALUES (:name, :required_permission)',
    { name, required_permission: requiredPermission }
  );
  return result.insertId;
}

async function update(id, name, requiredPermission) {
  await db.execute(
    'UPDATE reference_library_categories SET name = :name, required_permission = :required_permission WHERE id = :id',
    { name, required_permission: requiredPermission, id }
  );
}

/** Documents filed under the deleted category fall back to uncategorized (visible to everyone) — never deleted themselves. */
async function deleteCategory(id) {
  await db.execute('UPDATE reference_library_documents SET category_id = NULL WHERE category_id = :id', { id });
  await db.execute('DELETE FROM reference_library_categories WHERE id = :id', { id });
}

module.exports = { all, find, create, update, deleteCategory };
