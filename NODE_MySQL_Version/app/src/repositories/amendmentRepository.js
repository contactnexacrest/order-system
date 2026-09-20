'use strict';

const db = require('../config/db');

async function create(amendmentReference, orderId, reason, requestedBy, originalTermsSnapshot, amendedAdvancePct, amendedAdvanceAmount, amendedBalanceTerms, amendedBalanceTriggerOption, amendedBalanceDays, amendedBalanceAmount, effectiveFrom) {
  const result = await db.execute(
    `INSERT INTO amendments
        (amendment_reference, order_id, reason, requested_by, original_terms_snapshot,
         amended_advance_pct, amended_advance_amount, amended_balance_terms,
         amended_balance_trigger_option, amended_balance_days, amended_balance_amount,
         effective_from, status)
     VALUES
        (:ref, :order_id, :reason, :requested_by, :snapshot,
         :amended_advance_pct, :amended_advance_amount, :amended_balance_terms,
         :amended_balance_trigger_option, :amended_balance_days, :amended_balance_amount,
         :effective_from, 'pending')`,
    {
      ref: amendmentReference, order_id: orderId, reason, requested_by: requestedBy,
      snapshot: JSON.stringify(originalTermsSnapshot),
      amended_advance_pct: amendedAdvancePct, amended_advance_amount: amendedAdvanceAmount,
      amended_balance_terms: amendedBalanceTerms, amended_balance_trigger_option: amendedBalanceTriggerOption,
      amended_balance_days: amendedBalanceDays, amended_balance_amount: amendedBalanceAmount,
      effective_from: effectiveFrom,
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
  await db.execute("UPDATE amendments SET status = 'active', signed_copy_file_id = :file_id WHERE id = :id", { file_id: signedCopyFileId, id });
}

module.exports = { create, find, forOrder, approveByMd, reject, attachDocument, activate };
