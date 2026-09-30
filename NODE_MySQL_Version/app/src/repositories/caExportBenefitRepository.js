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

module.exports = { record, find, all, forOrder, markReceived, totalClaimed, totalReceived, availableFinancialYears };
