'use strict';

const db = require('../config/db');

// Port of App\Repositories\OrderAnnexureTermsRepository. The single
// free-form "Additional Terms" rich-text block per order (one row, unlike
// order_annexure_products which is many-per-order) — see docs/schema.sql
// Section AT.

const { which } = require('./orderAnnexureRepository');

/** D-336: one block per annexure — Annexure A unless whichAnnexure is 'B'. */
async function find(orderId, whichAnnexure = 'A') {
  return db.queryOne('SELECT * FROM order_annexure_terms WHERE order_id = :order_id AND `which` = :which LIMIT 1', { order_id: orderId, which: which(whichAnnexure) });
}

async function upsert(orderId, contentHtml, updatedBy, whichAnnexure = 'A') {
  const w = which(whichAnnexure);
  const existing = await find(orderId, w);
  if (existing) {
    await db.execute(
      'UPDATE order_annexure_terms SET content_html = :content_html, updated_by = :updated_by WHERE order_id = :order_id AND `which` = :which',
      { content_html: contentHtml, updated_by: updatedBy, order_id: orderId, which: w }
    );
    return;
  }
  await db.execute(
    'INSERT INTO order_annexure_terms (order_id, `which`, content_html, updated_by) VALUES (:order_id, :which, :content_html, :updated_by)',
    { order_id: orderId, which: w, content_html: contentHtml, updated_by: updatedBy }
  );
}

module.exports = { find, upsert };
