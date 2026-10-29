'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');

/**
 * Port of App\Repositories\CaExportBenefitRepository. CA / Accounting
 * module (Phase 8) — government export benefit/incentive claims (RODTEP
 * and any other scheme in dropdown_options('export_benefit_scheme')).
 * Unlike caExpenseRepository, this data is entered locally (there is no
 * Zoho Books import for it — see schema.sql's comment on ca_export_benefits).
 */

async function record(orderId, schemeName, referenceNumber, claimedAmount, claimedAt, currencyCode, notes, recordedBy) {
  const result = await db.execute(
    `INSERT INTO ca_export_benefits
        (order_id, scheme_name, reference_number, claimed_amount, claimed_at, currency_code, notes, recorded_by)
     VALUES
        (:order_id, :scheme_name, :reference_number, :claimed_amount, :claimed_at, :currency_code, :notes, :recorded_by)`,
    {
      order_id: orderId, scheme_name: schemeName, reference_number: referenceNumber,
      claimed_amount: claimedAmount, claimed_at: claimedAt, currency_code: currencyCode,
      notes, recorded_by: recordedBy,
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne(
    `SELECT ceb.*, o.order_reference
     FROM ca_export_benefits ceb
     LEFT JOIN orders o ON o.id = ceb.order_id
     WHERE ceb.id = :id`,
    { id }
  );
}

async function all() {
  return db.query(
    `SELECT ceb.*, o.order_reference
     FROM ca_export_benefits ceb
     LEFT JOIN orders o ON o.id = ceb.order_id
     ORDER BY ceb.claimed_at DESC, ceb.id DESC`
  );
}

/**
 * Benefits linked to one specific order — shown on that order's own
 * detail page (e.g. a RODTEP claim earned by this shipment) alongside
 * any linked expenses.
 *
 * @returns newest first
 */
async function forOrder(orderId) {
  return db.query('SELECT * FROM ca_export_benefits WHERE order_id = :order_id ORDER BY claimed_at DESC, id DESC', { order_id: orderId });
}

async function markReceived(id, receivedAmount, receivedAt) {
  await db.execute(
    'UPDATE ca_export_benefits SET received_amount = :amount, received_at = :received_at WHERE id = :id',
    { amount: receivedAmount, received_at: receivedAt, id }
  );
}

/**
 * Owner decision 2026-10-29: export benefits go to Zoho Books as a journal
 * when received. Every received claim (dated on or after startDate when
 * given) and every claim already in Books — so a corrected or cleared
 * receipt is updated or removed there too. Same production / test rule as
 * the rest of the sync: in production no claim of a test or sample order;
 * in test only claims of TEST- orders.
 */
async function forZohoSync(startDate = null) {
  const zohoGuard = require('../services/zohoGuard');
  const rows = await db.query(
    `SELECT ceb.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
            COALESCE(o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1, 0) AS is_test_or_sample
     FROM ca_export_benefits ceb
     LEFT JOIN orders o ON o.id = ceb.order_id
     LEFT JOIN clients c ON c.id = o.client_id
     WHERE (ceb.received_amount IS NOT NULL AND ceb.received_amount > 0) OR ceb.zoho_journal_id IS NOT NULL
     ORDER BY ceb.received_at, ceb.id`
  );
  return rows.filter((r) => (r.zoho_journal_id !== null || startDate === null || String(r.received_at) >= startDate)
    && (zohoGuard.isProduction()
      ? Number(r.is_test_or_sample) !== 1
      : String(r.buyer_name || '').startsWith(zohoGuard.TEST_PREFIX)));
}

async function setZohoJournal(id, journalId, hash) {
  await db.execute(`UPDATE ca_export_benefits SET zoho_journal_id = :journal, zoho_synced_at = ${journalId !== null ? 'NOW()' : 'NULL'}, zoho_pushed_hash = :hash WHERE id = :id`, { journal: journalId, hash, id });
}

async function totalClaimed() {
  const row = await db.queryOne('SELECT COALESCE(SUM(claimed_amount), 0) AS total FROM ca_export_benefits');
  return parseFloat(row.total);
}

async function totalReceived() {
  const row = await db.queryOne('SELECT COALESCE(SUM(received_amount), 0) AS total FROM ca_export_benefits');
  return parseFloat(row.total);
}

/** FY labels with at least one claim — for the FY Lock page's picker, same pattern as caExpenseRepository. */
async function availableFinancialYears() {
  const rows = await db.query('SELECT claimed_at FROM ca_export_benefits');
  return financialYear.labelsPresentIn(rows.map((r) => r.claimed_at));
}

/** Owner request (2026-10-27): fix a wrongly typed claim — every field at once; a null received amount clears the receipt. */
async function correct(id, referenceNumber, claimedAmount, claimedAt, receivedAmount, receivedAt) {
  await db.execute(
    `UPDATE ca_export_benefits
        SET reference_number = :ref, claimed_amount = :claimed, claimed_at = :claimed_at,
            received_amount = :received, received_at = :received_at
      WHERE id = :id`,
    { ref: referenceNumber, claimed: claimedAmount, claimed_at: claimedAt, received: receivedAmount, received_at: receivedAmount === null ? null : receivedAt, id }
  );
}

/** Owner request (2026-10-27): remove a claim recorded by mistake (the full row and the reason go to the audit log). */
async function remove(id) {
  await db.execute('DELETE FROM ca_export_benefits WHERE id = :id', { id });
}

module.exports = { correct, remove, record, find, all, forOrder, markReceived, forZohoSync, setZohoJournal, totalClaimed, totalReceived, availableFinancialYears };
