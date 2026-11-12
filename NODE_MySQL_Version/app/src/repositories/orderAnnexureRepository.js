'use strict';

const db = require('../config/db');

// Port of App\Repositories\OrderAnnexureRepository. Annexure A — Product
// Technical Specifications: order_annexure_products/order_annexure_images
// shipped in the original delivery with no repository, controller, or
// template ever built against them — this is that missing piece.

/** Owner decision D-336: 'A' = Annexure A (as quoted), 'B' = Annexure B (as shipped). 'B' for B, anything else 'A'. */
function which(value) {
  return String(value == null ? '' : value).trim().toUpperCase() === 'B' ? 'B' : 'A';
}

/** @returns {Promise<Array<object>>} each product row with its images nested under 'images' — D-336: one annexure's entries (A unless 'B'). */
async function forOrder(orderId, whichAnnexure = 'A') {
  const products = await db.query(
    'SELECT * FROM order_annexure_products WHERE order_id = :order_id AND `which` = :which ORDER BY sort_order ASC, id ASC',
    { order_id: orderId, which: which(whichAnnexure) }
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

/** D-336: number of product entries in one annexure. */
async function countFor(orderId, whichAnnexure = 'A') {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM order_annexure_products WHERE order_id = :o AND `which` = :w', { o: orderId, w: which(whichAnnexure) });
  return row ? Number(row.n) : 0;
}

async function createProduct(orderId, data, whichAnnexure = 'A') {
  const result = await db.execute(
    `INSERT INTO order_annexure_products
        (order_id, \`which\`, name, description, dimensions, finish, components, technical_notes, sort_order)
     VALUES
        (:order_id, :which, :name, :description, :dimensions, :finish, :components, :technical_notes,
         (SELECT next_sort FROM (SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM order_annexure_products WHERE order_id = :order_id_2 AND \`which\` = :which_2) t))`,
    {
      order_id: orderId,
      order_id_2: orderId,
      which: which(whichAnnexure),
      which_2: which(whichAnnexure),
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

/**
 * D-336: "Copy everything from Annexure A" — replaces Annexure B's product entries
 * with a copy of Annexure A's (same fields and order). The images point at the same
 * stored files (file_store rows are never deleted — soft-delete only — so removing an
 * image from one annexure never touches the other's). Returns the number copied.
 */
async function replaceWithCopy(orderId, from = 'A', to = 'B') {
  const src = which(from);
  const dst = which(to);
  for (const old of await forOrder(orderId, dst)) {
    await deleteProduct(old.id);
  }
  let copied = 0;
  for (const p of await forOrder(orderId, src)) {
    const result = await db.execute(
      `INSERT INTO order_annexure_products
          (order_id, \`which\`, product_id, product_code, name, description, dimensions, finish, components, technical_notes, sort_order)
       VALUES (:o, :w, :pid, :pc, :n, :d, :dim, :f, :c, :t, :s)`,
      {
        o: orderId, w: dst, pid: p.product_id ?? null, pc: p.product_code ?? null,
        n: p.name, d: p.description, dim: p.dimensions, f: p.finish, c: p.components, t: p.technical_notes, s: Number(p.sort_order) || 0,
      }
    );
    for (const img of p.images) {
      await db.execute('INSERT INTO order_annexure_images (order_annexure_product_id, file_id, sort_order) VALUES (:p, :f, :s)',
        { p: result.insertId, f: img.file_id, s: Number(img.sort_order) || 0 });
    }
    copied++;
  }
  return copied;
}

/** @param {Array<number>} ids — always our own already-int-cast primary keys, never raw user input */
function inQuery(template, ids) {
  return template.replace('%s', ids.join(','));
}

module.exports = {
  which, countFor, replaceWithCopy, forOrder, findProduct, createProduct, updateProduct, deleteProduct, addImage, findImage, removeImage,
};
