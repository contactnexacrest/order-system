'use strict';

const db = require('../config/db');

// Port of App\Repositories\ProductImageRepository.

async function forProduct(productId) {
  return db.query('SELECT * FROM catalog_product_images WHERE product_id = :product_id ORDER BY id', { product_id: productId });
}

async function find(id) {
  return db.queryOne('SELECT * FROM catalog_product_images WHERE id = :id', { id });
}

async function create(productId, serverPath, mimeType, uploadedBy) {
  const result = await db.execute(
    `INSERT INTO catalog_product_images (product_id, server_path, mime_type, uploaded_by)
     VALUES (:product_id, :server_path, :mime_type, :uploaded_by)`,
    { product_id: productId, server_path: serverPath, mime_type: mimeType ?? null, uploaded_by: uploadedBy ?? null }
  );
  return result.insertId;
}

async function remove(id) {
  await db.execute('DELETE FROM catalog_product_images WHERE id = :id', { id });
}

module.exports = { forProduct, find, create, remove };
