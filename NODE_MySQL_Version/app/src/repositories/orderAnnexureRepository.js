'use strict';

const db = require('../config/db');

// Port of App\Repositories\OrderAnnexureRepository. Annexure A — Product
// Technical Specifications: order_annexure_products/order_annexure_images
// shipped in the original delivery with no repository, controller, or
// template ever built against them — this is that missing piece.

/** @returns {Promise<Array<object>>} each product row with its images nested under 'images' */
async function forOrder(orderId) {
  const products = await db.query(
    'SELECT * FROM order_annexure_products WHERE order_id = :order_id ORDER BY sort_order ASC, id ASC',
    { order_id: orderId }
  );
  if (!products.length) {
    return [];
  }

  const ids = products.map((p) => parseInt(p.id, 10));
  const images = await db.query(
    inQuery(
      `SELECT oai.*, fs.server_path, fs.mime_type, fs.original_filename
       FROM order_annexure_images oai
       JOIN file_store fs ON fs.id = oai.file_id
       WHERE oai.order_annexure_product_id IN (%s)
       ORDER BY oai.sort_order ASC, oai.id ASC`,
      ids
    )
  );

  const imagesByProduct = {};
  for (const img of images) {
    const key = img.order_annexure_product_id;
    if (!imagesByProduct[key]) imagesByProduct[key] = [];
    imagesByProduct[key].push(img);
  }

  return products.map((p) => ({ ...p, images: imagesByProduct[p.id] || [] }));
}

async function findProduct(id) {
  return db.queryOne('SELECT * FROM order_annexure_products WHERE id = :id', { id });
}

async function createProduct(orderId, data) {
  const result = await db.execute(
    `INSERT INTO order_annexure_products
        (order_id, name, description, dimensions, finish, components, technical_notes, sort_order)
     VALUES
        (:order_id, :name, :description, :dimensions, :finish, :components, :technical_notes,
         (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_products WHERE order_id = :order_id_2) t))`,
    {
      order_id: orderId,
      order_id_2: orderId,
      name: data.name,
      description: data.description || null,
      dimensions: data.dimensions || null,
      finish: data.finish || null,
      components: data.components || null,
      technical_notes: data.technical_notes || null,
    }
  );
  return result.insertId;
}

async function updateProduct(id, data) {
  await db.execute(
    `UPDATE order_annexure_products
     SET name = :name, description = :description, dimensions = :dimensions,
         finish = :finish, components = :components, technical_notes = :technical_notes
     WHERE id = :id`,
    {
      id,
      name: data.name,
      description: data.description || null,
      dimensions: data.dimensions || null,
      finish: data.finish || null,
      components: data.components || null,
      technical_notes: data.technical_notes || null,
    }
  );
}

/** Deletes the product and its image rows (not the underlying file_store rows — those are soft-delete-only, per convention). */
async function deleteProduct(id) {
  await db.execute('DELETE FROM order_annexure_images WHERE order_annexure_product_id = :id', { id });
  await db.execute('DELETE FROM order_annexure_products WHERE id = :id', { id });
}

async function addImage(productId, fileId) {
  const result = await db.execute(
    `INSERT INTO order_annexure_images (order_annexure_product_id, file_id, sort_order)
     VALUES (:product_id, :file_id,
        (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_images WHERE order_annexure_product_id = :product_id_2) t))`,
    { product_id: productId, product_id_2: productId, file_id: fileId }
  );
  return result.insertId;
}

async function findImage(id) {
  return db.queryOne('SELECT * FROM order_annexure_images WHERE id = :id', { id });
}

async function removeImage(id) {
  await db.execute('DELETE FROM order_annexure_images WHERE id = :id', { id });
}

/** @param {Array<number>} ids — always our own already-int-cast primary keys, never raw user input */
function inQuery(template, ids) {
  return template.replace('%s', ids.join(','));
}

module.exports = {
  forOrder, findProduct, createProduct, updateProduct, deleteProduct, addImage, findImage, removeImage,
};
