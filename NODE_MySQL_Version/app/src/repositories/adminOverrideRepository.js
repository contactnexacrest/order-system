'use strict';

const db = require('../config/db');

// Port of App\Repositories\AdminOverrideRepository — Spec Section 13
// "Admin and users with specific individual permission can edit EVERY
// SINGLE FIELD in the entire system." Covers the specific named fields
// that had no other edit path: document ref formats, T&C clause text,
// payment preset values, a client's unique number, an order's status/lock
// flags, and an amendment's reference.

async function documentTypes() {
  return db.query('SELECT id, code, name, ref_format, min_reviewers_default FROM document_types ORDER BY code');
}

async function updateDocumentTypeRefFormat(id, refFormat, minReviewers) {
  await db.execute(
    'UPDATE document_types SET ref_format = :ref_format, min_reviewers_default = :min_reviewers WHERE id = :id',
    { ref_format: refFormat, min_reviewers: minReviewers, id }
  );
}

async function tcClauses() {
  return db.query('SELECT id, clause_number, clause_order, clause_title, clause_text, status, is_locked, is_protected FROM tc_clauses ORDER BY clause_order');
}

async function updateTcClause(id, title, text, userId) {
  await db.execute(
    'UPDATE tc_clauses SET clause_title = :title, clause_text = :text, modified_by = :user_id WHERE id = :id',
    { title, text, user_id: userId, id }
  );
}

async function paymentPresets() {
  return db.query('SELECT pp.*, cur.code AS currency_code FROM payment_presets pp JOIN currencies cur ON cur.id = pp.currency_id ORDER BY pp.preset_name');
}

async function updatePaymentPreset(id, advancePct, advanceTriggerText, balancePct, balanceTriggerOption, balanceDays) {
  await db.execute(
    `UPDATE payment_presets
     SET advance_pct = :advance_pct, advance_trigger_text = :advance_trigger_text,
         balance_pct = :balance_pct, balance_trigger_option = :balance_trigger_option, balance_days = :balance_days
     WHERE id = :id`,
    { advance_pct: advancePct, advance_trigger_text: advanceTriggerText, balance_pct: balancePct, balance_trigger_option: balanceTriggerOption, balance_days: balanceDays, id }
  );
}

async function updateClientUniqueNumber(clientId, newNumber) {
  await db.execute('UPDATE clients SET client_unique_number = :n WHERE id = :id', { n: newNumber, id: clientId });
}

async function updateOrderStatusLock(orderId, status, isLocked) {
  await db.execute('UPDATE orders SET status = :status, is_locked = :is_locked WHERE id = :id', { status, is_locked: isLocked ? 1 : 0, id: orderId });
}

async function updateAmendmentReference(amendmentId, newReference) {
  await db.execute('UPDATE amendments SET amendment_reference = :ref WHERE id = :id', { ref: newReference, id: amendmentId });
}

module.exports = {
  documentTypes, updateDocumentTypeRefFormat, tcClauses, updateTcClause, paymentPresets,
  updatePaymentPreset, updateClientUniqueNumber, updateOrderStatusLock, updateAmendmentReference,
};
