'use strict';

/**
 * Owner decision D-257 Phase 0B (5 Nov 2026): the quality documents an
 * order must collect, as its lines' product categories ask for them (e.g.
 * "Mill test certificate 3.1", "Drawing approval"). A line with no category
 * is Natural Stone, which asks for none today, so a stone order shows no
 * checklist. Port of App\Repositories\OrderQualityDocumentRepository.
 */
const db = require('../config/db');
const productCategoryRepository = require('./productCategoryRepository');

const STATUSES = { pending: 'Pending', received: 'Received', approved: 'Approved' };

/** Every quality document the order needs, with its status and file. */
async function forOrder(orderId) {
  const ids = (await db.query(
    `SELECT DISTINCT COALESCE(op.category_id, (SELECT pc.id FROM product_categories pc WHERE pc.is_system = 1 ORDER BY pc.id LIMIT 1)) AS cid
       FROM order_products op
      WHERE op.order_id = :o AND op.line_type = 'goods' AND op.is_active = 1`, // fresh-eyes review L3: deleted lines ask for nothing
    { o: orderId },
  )).map((r) => Number(r.cid)).filter((n) => n > 0);
  if (ids.length === 0) return [];
  const stored = {};
  for (const r of await db.query(
    `SELECT q.*, fs.original_filename, u.name AS updated_by_name
       FROM order_quality_documents q
       LEFT JOIN file_store fs ON fs.id = q.file_store_id
       LEFT JOIN users u ON u.id = q.updated_by
      WHERE q.order_id = :o`,
    { o: orderId },
  )) {
    stored[`${Number(r.category_id)}|${String(r.document_name).toLowerCase()}`] = r;
  }
  const out = [];
  ids.sort((a, b) => a - b);
  for (const cid of ids) {
    const category = await productCategoryRepository.find(cid);
    if (!category) continue;
    for (const doc of productCategoryRepository.qualityDocuments(category)) {
      const r = stored[`${cid}|${doc.toLowerCase()}`] || null;
      out.push({
        category_id: cid,
        category_name: String(category.name),
        document_name: doc,
        status: r ? String(r.status) : 'pending',
        file_store_id: r && r.file_store_id !== null ? Number(r.file_store_id) : null,
        original_filename: r ? r.original_filename ?? null : null,
        note: r ? r.note ?? null : null,
        updated_at: r ? r.updated_at ?? null : null,
        updated_by_name: r ? r.updated_by_name ?? null : null,
      });
    }
  }
  return out;
}

/** The required document, or null when the order does not ask for it. */
async function required(orderId, categoryId, documentName) {
  const name = String(documentName ?? '').trim().toLowerCase();
  return (await forOrder(orderId)).find((d) => d.category_id === categoryId && d.document_name.toLowerCase() === name) || null;
}

async function save(orderId, categoryId, documentName, status, fileStoreId, note, userId) {
  await db.execute(
    `INSERT INTO order_quality_documents (order_id, category_id, document_name, status, file_store_id, note, updated_by, updated_at)
     VALUES (:o, :c, :d, :s, :f, :n, :u, NOW())
     ON DUPLICATE KEY UPDATE status = VALUES(status), file_store_id = COALESCE(VALUES(file_store_id), file_store_id),
                             note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)`,
    { o: orderId, c: categoryId, d: documentName, s: status, f: fileStoreId, n: note, u: userId },
  );
}

module.exports = { STATUSES, forOrder, required, save };
