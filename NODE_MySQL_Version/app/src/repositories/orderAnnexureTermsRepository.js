'use strict';

const db = require('../config/db');

// Port of App\Repositories\OrderAnnexureTermsRepository. The single
// free-form "Additional Terms" rich-text block per order (one row, unlike
// order_annexure_products which is many-per-order) — see docs/schema.sql
// Section AT.

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_annexure_terms WHERE order_id = :order_id LIMIT 1', { order_id: orderId });
}

async function upsert(orderId, contentHtml, updatedBy) {
  const existing = await find(orderId);
  if (existing) {
    await db.execute(
      'UPDATE order_annexure_terms SET content_html = :content_html, updated_by = :updated_by WHERE order_id = :order_id',
      { content_html: contentHtml, updated_by: updatedBy, order_id: orderId }
    );
    return;
  }
  await db.execute(
    'INSERT INTO order_annexure_terms (order_id, content_html, updated_by) VALUES (:order_id, :content_html, :updated_by)',
    { order_id: orderId, content_html: contentHtml, updated_by: updatedBy }
  );
}

module.exports = { find, upsert };
