'use strict';

const db = require('../config/db');
const zohoGuard = require('../services/zohoGuard');

// Zoho integration Stage 3 (2026-10-29) — what the app pays out, waiting to
// go to Zoho Books: suppliers' and logistics partners' tax invoices (bills),
// the payments made to them, and the order's expenses. Same rule as the
// revenue side: in production, test and sample orders (and test or sample
// suppliers) never go; in test, only TEST- orders do, and a bill or payment
// only when its vendor's name starts with TEST- too, so the clean-up script
// finds everything testing made. Written only by caSyncService. Port of
// ZohoPurchaseRepository.php.

// The vendor behind each bill: the supplier of the bill's supplier PO, or
// the logistics partner. Joined into every bill and payment query.
const BILL_SELECT = `
  SELECT b.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
         (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1
          OR COALESCE(s.is_test_data, 0) = 1 OR COALESCE(s.is_sample_data, 0) = 1) AS is_test_or_sample,
         COALESCE(s.id, lp.id) AS vendor_id,
         COALESCE(s.supplier_legal_name, lp.partner_name) AS vendor_name,
         COALESCE(s.gstin, lp.gstin) AS vendor_gstin,
         COALESCE(s.pan, lp.pan) AS vendor_pan,
         COALESCE(s.zoho_contact_id, lp.zoho_contact_id) AS vendor_zoho_contact_id
  FROM order_vendor_bills b
  JOIN orders o ON o.id = b.order_id
  JOIN clients c ON c.id = o.client_id
  LEFT JOIN order_supplier_po po ON po.id = b.supplier_po_id
  LEFT JOIN suppliers s ON s.id = po.supplier_id AND b.vendor_kind = 'supplier'
  LEFT JOIN logistics_partners lp ON lp.id = b.logistics_partner_id AND b.vendor_kind = 'logistics'`;

function allowed(row) {
  if (zohoGuard.isProduction()) return Number(row.is_test_or_sample) !== 1;
  return String(row.buyer_name || '').startsWith(zohoGuard.TEST_PREFIX) && String(row.vendor_name || '').startsWith(zohoGuard.TEST_PREFIX);
}

/** Tax invoices not yet in Books (dated on or after startDate, when given). */
async function billsToSync(startDate = null) {
  const rows = await db.query(`${BILL_SELECT} WHERE b.doc_kind = 'invoice' AND b.zoho_bill_id IS NULL ORDER BY b.bill_date, b.id`);
  return rows.filter((r) => allowed(r) && (startDate === null || String(r.bill_date) >= startDate));
}

async function findBill(billId) {
  return db.queryOne(`${BILL_SELECT} WHERE b.id = :id`, { id: billId });
}

/**
 * The Books bill a payment counts towards: its own bill when that is a tax
 * invoice, or — for a payment on a proforma — the tax invoice that replaced
 * the proforma. Null while that bill is not in Books yet.
 */
async function targetBillFor(bill) {
  if (bill.doc_kind === 'invoice') return bill.zoho_bill_id !== null ? bill : null;
  return db.queryOne(`${BILL_SELECT} WHERE b.replaces_bill_id = :id AND b.doc_kind = 'invoice' AND b.zoho_bill_id IS NOT NULL LIMIT 1`, { id: bill.id });
}

async function payments(where) {
  const rows = await db.query(`SELECT p.* FROM order_vendor_bill_payments p WHERE ${where} ORDER BY p.paid_at, p.id`);
  const out = [];
  for (const payment of rows) {
    const bill = await findBill(payment.bill_id);
    if (bill && allowed(bill)) out.push({ payment, bill });
  }
  return out;
}

/** Payments to vendors not yet in Books (paid on or after startDate, when given), each with its bill. */
const paymentsToSync = async (startDate = null) => (await payments('p.zoho_payment_id IS NULL'))
  .filter((p) => startDate === null || String(p.payment.paid_at) >= startDate);
/** Payments in Books but not yet applied to a bill (advances on a proforma). */
const heldPayments = () => payments('p.zoho_payment_id IS NOT NULL AND p.zoho_applied_at IS NULL');

/**
 * Owner decision 2026-10-29 (the app is the source of truth): what the app
 * has already sent — tax invoices, vendor payments (each with its bill) and
 * order expenses — so a sync can check Books still matches.
 */
async function sentBills() {
  const rows = await db.query(`${BILL_SELECT} WHERE b.doc_kind = 'invoice' AND b.zoho_bill_id IS NOT NULL ORDER BY b.bill_date, b.id`);
  return rows.filter(allowed);
}

const sentPayments = () => payments('p.zoho_payment_id IS NOT NULL');

async function sentExpenses() {
  const rows = await db.query(
    `SELECT e.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
            (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
     FROM order_expenses e
     JOIN orders o ON o.id = e.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE e.zoho_expense_id IS NOT NULL
     ORDER BY e.expense_date, e.id`
  );
  return rows.filter((r) => (zohoGuard.isProduction()
    ? Number(r.is_test_or_sample) !== 1
    : String(r.buyer_name || '').startsWith(zohoGuard.TEST_PREFIX)));
}

/** A bill sent again after it was deleted in Books: its payments (and those on the proforma it replaces) must be applied to it again. */
async function resetPaymentsAppliedTo(billId) {
  const row = await db.queryOne('SELECT replaces_bill_id FROM order_vendor_bills WHERE id = :id', { id: billId });
  const ids = [Number(billId), row && row.replaces_bill_id !== null ? Number(row.replaces_bill_id) : null].filter(Boolean);
  await db.execute(`UPDATE order_vendor_bill_payments SET zoho_applied_at = NULL WHERE zoho_payment_id IS NOT NULL AND bill_id IN (${ids.join(',')})`);
}

/** Order expenses not yet in Books (dated on or after startDate, when given). */
async function expensesToSync(startDate = null) {
  const rows = await db.query(
    `SELECT e.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
            (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
     FROM order_expenses e
     JOIN orders o ON o.id = e.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE e.zoho_expense_id IS NULL
     ORDER BY e.expense_date, e.id`
  );
  return rows.filter((r) => (startDate === null || String(r.expense_date) >= startDate)
    && (zohoGuard.isProduction()
      ? Number(r.is_test_or_sample) !== 1
      : String(r.buyer_name || '').startsWith(zohoGuard.TEST_PREFIX)));
}

/**
 * Owner decision 2026-10-29: the order cost entries that go to Books
 * (orderCostEntryRepository.ZOHO_CATEGORIES), dated on or after startDate
 * when given — those not yet in Books and those already there (caSyncService
 * updates one whose amount, date or "paid from" changed since, e.g. a
 * corrected inspection fee).
 */
async function costEntriesToSync(startDate = null) {
  const { ZOHO_CATEGORIES } = require('./orderCostEntryRepository');
  const categories = ZOHO_CATEGORIES.map((c) => `'${c}'`).join(',');
  const rows = await db.query(
    `SELECT ce.*, COALESCE(ce.incurred_at, DATE(ce.created_at)) AS cost_date, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
            (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
     FROM order_cost_entries ce
     JOIN orders o ON o.id = ce.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE ce.category IN (${categories})
       -- a team incentive / associate commission line goes once it is paid (settled), not when it is fixed
       AND (ce.incentive_kind IS NULL OR ce.paid_from IS NOT NULL)
     ORDER BY cost_date, ce.id`
  );
  return rows.filter((r) => (startDate === null || String(r.cost_date) >= startDate)
    && (zohoGuard.isProduction()
      ? Number(r.is_test_or_sample) !== 1
      : String(r.buyer_name || '').startsWith(zohoGuard.TEST_PREFIX)));
}

async function setCostEntrySynced(costEntryId, zohoExpenseId, hash) {
  await db.execute('UPDATE order_cost_entries SET zoho_expense_id = :zoho, zoho_synced_at = NOW(), zoho_pushed_hash = :hash WHERE id = :id', { zoho: zohoExpenseId, hash, id: costEntryId });
}

/** Zoho expense ids this app created — the expense import must not bring them back. */
async function isPushedExpense(zohoExpenseId) {
  return Boolean(await db.queryOne('SELECT 1 AS x FROM order_expenses WHERE zoho_expense_id = :id UNION ALL SELECT 1 FROM order_cost_entries WHERE zoho_expense_id = :id2', { id: zohoExpenseId, id2: zohoExpenseId }));
}

async function setVendorContactId(vendorKind, vendorId, contactId) {
  const table = vendorKind === 'supplier' ? 'suppliers' : 'logistics_partners';
  await db.execute(`UPDATE ${table} SET zoho_contact_id = :zoho WHERE id = :id`, { zoho: contactId, id: vendorId });
}

async function setBillSynced(billId, zohoBillId) {
  await db.execute('UPDATE order_vendor_bills SET zoho_bill_id = :zoho, zoho_synced_at = NOW() WHERE id = :id', { zoho: zohoBillId, id: billId });
}

async function setPaymentSynced(paymentId, zohoPaymentId, applied) {
  await db.execute(`UPDATE order_vendor_bill_payments SET zoho_payment_id = :zoho, zoho_synced_at = NOW(), zoho_applied_at = ${applied ? 'NOW()' : 'NULL'} WHERE id = :id`, { zoho: zohoPaymentId, id: paymentId });
}

async function setPaymentApplied(paymentId) {
  await db.execute('UPDATE order_vendor_bill_payments SET zoho_applied_at = NOW() WHERE id = :id', { id: paymentId });
}

async function setExpenseSynced(expenseId, zohoExpenseId) {
  await db.execute('UPDATE order_expenses SET zoho_expense_id = :zoho, zoho_synced_at = NOW() WHERE id = :id', { zoho: zohoExpenseId, id: expenseId });
}

/** The clean-up script removed this record from Books: forget it here too. */
async function forgetZohoRecord(kind, zohoId) {
  const sql = {
    contact: ['UPDATE suppliers SET zoho_contact_id = NULL WHERE zoho_contact_id = :id', 'UPDATE logistics_partners SET zoho_contact_id = NULL WHERE zoho_contact_id = :id'],
    bill: ['UPDATE order_vendor_bills SET zoho_bill_id = NULL, zoho_synced_at = NULL WHERE zoho_bill_id = :id'],
    vendorpayment: ['UPDATE order_vendor_bill_payments SET zoho_payment_id = NULL, zoho_synced_at = NULL, zoho_applied_at = NULL WHERE zoho_payment_id = :id'],
    expense: ['UPDATE order_expenses SET zoho_expense_id = NULL, zoho_synced_at = NULL WHERE zoho_expense_id = :id', 'UPDATE order_cost_entries SET zoho_expense_id = NULL, zoho_synced_at = NULL, zoho_pushed_hash = NULL WHERE zoho_expense_id = :id'],
  }[kind] || [];
  for (const statement of sql) await db.execute(statement, { id: zohoId });
}

/** Every Books record this database created on the purchase side (clean-up --from-sync-log). */
async function pushedRecords() {
  const out = [];
  for (const r of await db.query('SELECT zoho_payment_id, payment_ref, amount_inr FROM order_vendor_bill_payments WHERE zoho_payment_id IS NOT NULL')) {
    out.push({ kind: 'vendorpayment', id: String(r.zoho_payment_id), label: `vendor payment ${r.payment_ref} INR ${r.amount_inr} (created by this database)` });
  }
  for (const r of await db.query('SELECT zoho_expense_id, details, amount_inr FROM order_expenses WHERE zoho_expense_id IS NOT NULL')) {
    out.push({ kind: 'expense', id: String(r.zoho_expense_id), label: `expense ${r.details} INR ${r.amount_inr} (created by this database)` });
  }
  for (const r of await db.query('SELECT zoho_expense_id, category, amount_inr FROM order_cost_entries WHERE zoho_expense_id IS NOT NULL')) {
    out.push({ kind: 'expense', id: String(r.zoho_expense_id), label: `order cost ${r.category} INR ${r.amount_inr} (created by this database)` });
  }
  for (const r of await db.query('SELECT zoho_bill_id, bill_no, total_inr FROM order_vendor_bills WHERE zoho_bill_id IS NOT NULL')) {
    out.push({ kind: 'bill', id: String(r.zoho_bill_id), label: `bill ${r.bill_no} INR ${r.total_inr} (created by this database)` });
  }
  for (const r of await db.query('SELECT supplier_legal_name AS name, zoho_contact_id FROM suppliers WHERE zoho_contact_id IS NOT NULL UNION ALL SELECT partner_name, zoho_contact_id FROM logistics_partners WHERE zoho_contact_id IS NOT NULL')) {
    out.push({ kind: 'contact', id: String(r.zoho_contact_id), label: `vendor ${r.name} (created by this database)` });
  }
  return out;
}

module.exports = {
  billsToSync, findBill, targetBillFor, paymentsToSync, heldPayments, expensesToSync, costEntriesToSync, setCostEntrySynced, isPushedExpense,
  setVendorContactId, setBillSynced, setPaymentSynced, setPaymentApplied, setExpenseSynced, forgetZohoRecord, pushedRecords,
  sentBills, sentPayments, sentExpenses, resetPaymentsAppliedTo,
};
