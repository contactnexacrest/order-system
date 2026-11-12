'use strict';

const db = require('../config/db');

async function create(amendmentReference, orderId, reason, requestedBy, originalTermsSnapshot, amendedAdvancePct, amendedAdvanceAmount, amendedBalanceTerms, amendedBalanceTriggerOption, amendedBalanceDays, amendedBalanceAmount, effectiveFrom, createdByUserId = null) {
  const result = await db.execute(
    `INSERT INTO amendments
        (amendment_reference, order_id, reason, requested_by, original_terms_snapshot,
         amended_advance_pct, amended_advance_amount, amended_balance_terms,
         amended_balance_trigger_option, amended_balance_days, amended_balance_amount,
         effective_from, created_by, status)
     VALUES
        (:ref, :order_id, :reason, :requested_by, :snapshot,
         :amended_advance_pct, :amended_advance_amount, :amended_balance_terms,
         :amended_balance_trigger_option, :amended_balance_days, :amended_balance_amount,
         :effective_from, :created_by, 'pending')`,
    {
      ref: amendmentReference, order_id: orderId, reason, requested_by: requestedBy,
      snapshot: JSON.stringify(originalTermsSnapshot),
      amended_advance_pct: amendedAdvancePct, amended_advance_amount: amendedAdvanceAmount,
      amended_balance_terms: amendedBalanceTerms, amended_balance_trigger_option: amendedBalanceTriggerOption,
      amended_balance_days: amendedBalanceDays, amended_balance_amount: amendedBalanceAmount,
      effective_from: effectiveFrom, created_by: createdByUserId,
    }
  );
  return result.insertId;
}

async function find(id) {
  const row = await db.queryOne(
    `SELECT a.*, o.order_reference, o.buyer_inquiry_ref
     FROM amendments a JOIN orders o ON o.id = a.order_id
     WHERE a.id = :id`,
    { id }
  );
  if (row && row.original_terms_snapshot) {
    try { row.original_terms_snapshot = JSON.parse(row.original_terms_snapshot); } catch { /* leave as string */ }
  }
  return row;
}

async function forOrder(orderId) {
  return db.query('SELECT * FROM amendments WHERE order_id = :order_id ORDER BY created_at DESC', { order_id: orderId });
}

async function approveByMd(id, mdUserId) {
  await db.execute("UPDATE amendments SET status = 'md_approved', md_approved_by = :md, md_approved_at = NOW() WHERE id = :id", { md: mdUserId, id });
}

async function reject(id) {
  await db.execute("UPDATE amendments SET status = 'rejected' WHERE id = :id", { id });
}

async function attachDocument(id, documentId) {
  await db.execute('UPDATE amendments SET document_id = :document_id WHERE id = :id', { document_id: documentId, id });
}

async function activate(id, signedCopyFileId) {
  await db.execute("UPDATE amendments SET status = 'active', signed_copy_file_id = :file_id, activated_at = NOW() WHERE id = :id", { file_id: signedCopyFileId, id });
}

// ---- Owner decision D-342: "Products & prices" amendments ----

/** Marks a just-created amendment as a products amendment, with its totals. */
async function setProducts(id, totals) {
  await db.execute("UPDATE amendments SET amendment_type = 'products', products_totals_json = :t WHERE id = :id", { t: JSON.stringify(totals), id });
}

const PRODUCT_LINE_COLUMNS = ['line_no', 'order_product_id', 'source_line_id', 'change_kind', 'description', 'unit',
  'old_length_cm', 'old_width_cm', 'old_thickness_cm', 'old_pieces', 'old_quantity', 'old_unit_price', 'old_line_total',
  'new_length_cm', 'new_width_cm', 'new_thickness_cm', 'new_pieces', 'new_quantity', 'new_unit_price', 'new_line_total', 'price_lowered'];

/** One line of productsAmendmentService.draft(). */
async function addProductLine(amendmentId, line) {
  const values = { amendment_id: amendmentId };
  for (const c of PRODUCT_LINE_COLUMNS) {
    const v = line[c];
    values[c] = v === undefined ? null : (typeof v === 'boolean' ? (v ? 1 : 0) : v);
  }
  values.price_lowered = Number(values.price_lowered || 0);
  await db.execute(
    `INSERT INTO amendment_product_lines (amendment_id, ${PRODUCT_LINE_COLUMNS.join(', ')}) VALUES (:amendment_id, :${PRODUCT_LINE_COLUMNS.join(', :')})`,
    values
  );
}

async function productLines(amendmentId) {
  return db.query('SELECT * FROM amendment_product_lines WHERE amendment_id = :a ORDER BY line_no, id', { a: amendmentId });
}

/** The products amendment of this order still on its way (pending, MD-approved or signed), or null. */
async function openProductsAmendment(orderId) {
  return db.queryOne(
    `SELECT * FROM amendments WHERE order_id = :o AND amendment_type = 'products' AND status IN ('pending', 'md_approved', 'signed')
      ORDER BY id DESC LIMIT 1`, { o: orderId });
}

/** The order's latest activated products amendment, or null. */
async function latestActiveProducts(orderId) {
  return db.queryOne(
    `SELECT * FROM amendments WHERE order_id = :o AND amendment_type = 'products' AND status = 'active'
      ORDER BY activated_at DESC, id DESC LIMIT 1`, { o: orderId });
}

async function setSupplierPoRevisionNeeded(id, needed) {
  await db.execute('UPDATE amendments SET supplier_po_revision_needed = :n WHERE id = :id', { n: needed ? 1 : 0, id });
}

module.exports = {
  create, find, forOrder, approveByMd, reject, attachDocument, activate,
  PRODUCT_LINE_COLUMNS, setProducts, addProductLine, productLines, openProductsAmendment, latestActiveProducts, setSupplierPoRevisionNeeded,
};
