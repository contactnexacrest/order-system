'use strict';

const db = require('../config/db');
const zohoGuard = require('../services/zohoGuard');

// Zoho integration Stage 2 (2026-10-29) — which Zoho Books invoice each
// order's Commercial Invoice ('ci') and freight Debit Note ('dn') became,
// with a fingerprint of what was sent so a revised document updates the
// same Books invoice instead of creating a second one. Written only by
// caSyncService.

// Our document type code for each kind.
const DOCUMENT_TYPES = { ci: 'CI', dn: 'FDN' };

async function find(orderId, kind) {
  return db.queryOne('SELECT * FROM order_zoho_invoices WHERE order_id = :order_id AND kind = :kind', { order_id: orderId, kind });
}

async function save(orderId, kind, documentId, pushed) {
  await db.execute(
    `INSERT INTO order_zoho_invoices
        (order_id, kind, document_id, zoho_invoice_id, zoho_invoice_number, currency_code, total, exchange_rate, pushed_hash)
     VALUES (:order_id, :kind, :document_id, :zoho_invoice_id, :zoho_invoice_number, :currency_code, :total, :exchange_rate, :pushed_hash)
     ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), zoho_invoice_id = VALUES(zoho_invoice_id),
        zoho_invoice_number = VALUES(zoho_invoice_number), currency_code = VALUES(currency_code), total = VALUES(total),
        exchange_rate = VALUES(exchange_rate), pushed_hash = VALUES(pushed_hash), updated_at = NOW()`,
    {
      order_id: orderId,
      kind,
      document_id: documentId,
      zoho_invoice_id: pushed.zoho_invoice_id,
      zoho_invoice_number: pushed.zoho_invoice_number,
      currency_code: pushed.currency_code,
      total: Math.round(pushed.total * 100) / 100,
      exchange_rate: Math.round(pushed.exchange_rate * 1e6) / 1e6,
      pushed_hash: pushed.pushed_hash,
    }
  );
}

/** The clean-up script cleared this invoice in Books: forget it here too. */
async function forgetZohoInvoice(zohoInvoiceId) {
  await db.execute('DELETE FROM order_zoho_invoices WHERE zoho_invoice_id = :id', { id: zohoInvoiceId });
}

/**
 * Every order's latest Commercial Invoice and Debit Note that has been
 * approved or sent — the ones that may go to Zoho Books. While a newer
 * revision is in draft or review the order is left alone (documents read
 * the order's live figures, which may already hold the revision's edits),
 * so Books keeps the last approved version until the revision is
 * approved. Same rule as the payment legs (caRepository.legsPendingZohoSync):
 * in production, test and sample orders never go; in test, only TEST-
 * customers do. With startDate (zoho_books_sync_start_date), only documents
 * dated on or after it.
 *
 * @return {Promise<Array<{order_id: number, kind: string, document_id: number}>>}
 */
async function documentsToSync(startDate = null) {
  const rows = await db.query(
    `SELECT d.id AS document_id, d.order_id, dt.code, o.company_legal_name, COALESCE(d.document_date, DATE(d.generated_at)) AS printed_date,
            (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
     FROM documents d
     JOIN document_types dt ON dt.id = d.document_type_id
     JOIN orders o ON o.id = d.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE dt.code IN ('CI', 'FDN') AND d.status IN ('approved', 'sent')
       AND d.revision_number = (
           SELECT MAX(d2.revision_number) FROM documents d2
           WHERE d2.order_id = d.order_id AND d2.document_type_id = d.document_type_id AND d2.status IN ('approved', 'sent'))
       AND NOT EXISTS (
           SELECT 1 FROM documents d3
           WHERE d3.order_id = d.order_id AND d3.document_type_id = d.document_type_id
             AND d3.revision_number > d.revision_number AND d3.status IN ('draft', 'in_review'))
     ORDER BY d.order_id, dt.code`
  );
  const kinds = Object.fromEntries(Object.entries(DOCUMENT_TYPES).map(([kind, code]) => [code, kind]));
  return rows
    .filter((row) => (zohoGuard.isProduction()
      ? Number(row.is_test_or_sample) !== 1
      : String(row.company_legal_name || '').startsWith(zohoGuard.TEST_PREFIX)))
    .filter((row) => startDate === null || String(row.printed_date).slice(0, 10) >= startDate)
    .map((row) => ({ order_id: Number(row.order_id), kind: kinds[row.code], document_id: Number(row.document_id) }));
}

module.exports = { DOCUMENT_TYPES, find, save, forgetZohoInvoice, documentsToSync };
