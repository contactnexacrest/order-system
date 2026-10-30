'use strict';

const db = require('../config/db');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderRepository = require('../repositories/orderRepository');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const zohoBooksService = require('./zohoBooksService');
const zohoInvoiceData = require('./zohoInvoiceData');

/**
 * Owner decision 2026-10-29: the per-order "App vs Zoho" check. Reads the
 * order's records in the app and what Zoho Books holds for them, and says
 * for each one whether they match. Reads only — it never changes the app
 * or Books. Port of OrderZohoMatchService.php.
 *
 * Statuses: match, not_sent (not in Books yet — the last sync error, if
 * any, says why), before_go_live (dated before the go-live date: the CA
 * books it by hand), different (in Books with another amount), missing
 * (the app sent it, but Books no longer has it), books_only (in Books for
 * this order, but not in the app).
 */
const STATUS_LABELS = {
  match: 'Matches',
  not_sent: 'Not in Zoho Books yet',
  before_go_live: 'Before the go-live date',
  different: 'Different in Zoho Books',
  missing: 'Missing in Zoho Books',
  books_only: 'Only in Zoho Books',
};

const MISSING_NOTE = 'Sent by the app, but Books no longer has it.';

async function check(orderId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order #${orderId} not found.`);
  }
  const ref = String(order.buyer_inquiry_ref);
  const currency = String(order.currency_code || 'INR');
  const start = await zohoBooksService.syncStartDate();
  const errors = await lastErrors(orderId);
  const rows = [];
  const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const add = (group, item, app, books, status, note = '', unit = 'INR') => {
    const fmt = (v) => (v === null ? null : `${unit} ${money(v)}`);
    rows.push({ group, item, app: fmt(app), books: fmt(books), status, note });
  };
  const compare = (app, books) => (Math.abs(app - books) <= 0.01 ? 'match' : 'different');
  const notSent = (date) => (start !== null && date && String(date).slice(0, 10) < start ? 'before_go_live' : 'not_sent');
  const errorFor = (type, leg) => errors[`${type}:${leg}`] || '';

  // --- Sales: the CI and Debit Note, the buyer's payments, the LC receipt.
  const client = await db.queryOne('SELECT zoho_contact_id FROM clients WHERE id = :id', { id: order.client_id });
  const contactId = client && client.zoho_contact_id ? String(client.zoho_contact_id) : null;
  const books = contactId !== null ? await zohoBooksService.contactRecords(contactId, false) : { invoices: [], customerpayments: [] };
  const invoicesById = new Map(books.invoices.map((r) => [String(r.invoice_id), r]));
  const paymentsById = new Map(books.customerpayments.map((r) => [String(r.payment_id), r]));
  const seenInvoices = [];
  const seenPayments = [];
  const pending = (await orderZohoInvoiceRepository.documentsToSync(null)).filter((d) => Number(d.order_id) === orderId).map((d) => d.kind);
  for (const [kind, label] of Object.entries(zohoInvoiceData.KIND_LABELS)) {
    const known = await orderZohoInvoiceRepository.find(orderId, kind);
    if (known) {
      seenInvoices.push(String(known.zoho_invoice_id));
      const inBooks = invoicesById.get(String(known.zoho_invoice_id));
      const item = `${label} ${known.zoho_invoice_number}`;
      if (!inBooks) {
        add('Sales', item, Number(known.total), null, 'missing', MISSING_NOTE, currency);
      } else {
        add('Sales', item, Number(known.total), Number(inBooks.total), compare(Number(known.total), Number(inBooks.total)), `Rate ${Number(known.exchange_rate)}`, currency);
      }
    } else if (pending.includes(kind)) {
      add('Sales', label, null, null, 'not_sent', errorFor('invoice', kind), currency);
    }
  }
  const payment = (await db.queryOne('SELECT * FROM order_payment_status WHERE order_id = :id', { id: orderId })) || {};
  const salesPayment = (item, amount, zohoId, notSentStatus, error) => {
    if (zohoId === null || zohoId === undefined) {
      add('Sales', item, amount, null, notSentStatus, error, currency);
      return;
    }
    seenPayments.push(String(zohoId));
    const row = paymentsById.get(String(zohoId));
    if (!row) add('Sales', item, amount, null, 'missing', MISSING_NOTE, currency);
    else add('Sales', item, amount, Number(row.amount), compare(amount, Number(row.amount)), '', currency);
  };
  for (const [leg, label] of [['advance', 'Advance'], ['balance', 'Balance'], ['freight', 'Freight']]) {
    if (payment[`${leg}_inr_actual`] === null || payment[`${leg}_inr_actual`] === undefined) continue;
    const amount = currency === 'INR' ? Number(payment[`${leg}_inr_actual`]) : Number(payment[`${leg}_amount`]);
    salesPayment(`${label} payment`, amount, payment[`${leg}_zoho_reference`], notSent(payment[`${leg}_cleared_at`]), errorFor('revenue_payment', leg));
  }
  const lc = await orderLcDetailRepository.find(orderId);
  if (lc && lc.lc_received_at !== null) {
    const amount = currency === 'INR' ? Number(lc.lc_inr_credited) + Number(lc.lc_bank_charges_inr) : Number(lc.lc_received_amount);
    salesPayment('LC payment', amount, lc.lc_zoho_payment_id, notSent(lc.lc_received_at), errorFor('revenue_payment', 'lc'));
  }
  for (const row of books.invoices) {
    if (String(row.reference_number || '') === ref && !seenInvoices.includes(String(row.invoice_id))) {
      add('Sales', `Invoice ${row.invoice_number}`, null, Number(row.total), 'books_only', 'In Books under this order, but not sent by the app.', String(row.currency_code || currency));
    }
  }
  for (const row of books.customerpayments) {
    if (String(row.reference_number || '').startsWith(`${ref}-`) && !seenPayments.includes(String(row.payment_id))) {
      add('Sales', `Payment ${row.reference_number}`, null, Number(row.amount), 'books_only', 'In Books under this order, but not sent by the app.', String(row.currency_code || currency));
    }
  }

  // --- Purchases: suppliers' and logistics partners' tax invoices and the payments made to them.
  const vendorBooks = new Map();
  const vendorRecords = async (vendorId) => {
    if (!vendorId) return { bills: [], vendorpayments: [] };
    if (!vendorBooks.has(String(vendorId))) vendorBooks.set(String(vendorId), await zohoBooksService.contactRecords(String(vendorId), true));
    return vendorBooks.get(String(vendorId));
  };
  const seenBills = [];
  for (const r of await db.query("SELECT id FROM order_vendor_bills WHERE order_id = :id AND doc_kind = 'invoice' ORDER BY bill_date, id", { id: orderId })) {
    const bill = await zohoPurchaseRepository.findBill(r.id);
    const item = `Bill ${bill.bill_no} — ${bill.vendor_name}`;
    if (bill.zoho_bill_id === null) {
      add('Purchases', item, Number(bill.total_inr), null, notSent(bill.bill_date), errorFor('vendor_bill', 'bill'));
      continue;
    }
    seenBills.push(String(bill.zoho_bill_id));
    const inBooks = (await vendorRecords(bill.vendor_zoho_contact_id)).bills.find((b) => String(b.bill_id) === String(bill.zoho_bill_id));
    if (!inBooks) add('Purchases', item, Number(bill.total_inr), null, 'missing', MISSING_NOTE);
    else add('Purchases', item, Number(bill.total_inr), Number(inBooks.total), compare(Number(bill.total_inr), Number(inBooks.total)));
  }
  for (const p of await db.query('SELECT p.*, b.id AS bill_id FROM order_vendor_bill_payments p JOIN order_vendor_bills b ON b.id = p.bill_id WHERE b.order_id = :id ORDER BY p.paid_at, p.id', { id: orderId })) {
    const bill = await zohoPurchaseRepository.findBill(p.bill_id);
    const item = `Payment ${p.payment_ref} — ${bill.vendor_name}`;
    if (p.zoho_payment_id === null) {
      add('Purchases', item, Number(p.amount_inr), null, notSent(p.paid_at), errorFor('vendor_payment', 'payment'));
      continue;
    }
    const inBooks = (await vendorRecords(bill.vendor_zoho_contact_id)).vendorpayments.find((v) => String(v.payment_id) === String(p.zoho_payment_id));
    if (!inBooks) add('Purchases', item, Number(p.amount_inr), null, 'missing', MISSING_NOTE);
    else add('Purchases', item, Number(p.amount_inr), Number(inBooks.amount), compare(Number(p.amount_inr), Number(inBooks.amount)));
  }
  for (const records of vendorBooks.values()) {
    for (const row of records.bills) {
      if (String(row.reference_number || '') === ref && !seenBills.includes(String(row.bill_id))) {
        add('Purchases', `Bill ${row.bill_number} — ${row.vendor_name}`, null, Number(row.total), 'books_only', 'In Books under this order, but not in the app.');
      }
    }
  }

  // --- Expenses: the order's expenses and its real paid cost entries.
  const expense = async (item, amount, zohoId, notSentStatus, error) => {
    if (zohoId === null || zohoId === undefined) {
      add('Expenses', item, amount, null, notSentStatus, error);
      return;
    }
    const row = await zohoBooksService.readRecord('expense', String(zohoId));
    if (row === null) {
      add('Expenses', item, amount, null, 'missing', MISSING_NOTE);
      return;
    }
    const total = Number(row.total ?? row.amount ?? 0);
    add('Expenses', item, amount, total, compare(amount, total));
  };
  for (const e of await orderExpenseRepository.forOrder(orderId)) {
    const label = orderExpenseRepository.CATEGORIES[e.category] || String(e.category);
    await expense(`${label} — ${e.details}`, Number(e.amount_inr), e.zoho_expense_id, notSent(e.expense_date), errorFor('order_expense', 'expense'));
  }
  for (const c of await orderCostEntryRepository.forOrder(orderId)) {
    // An incentive line goes to Books once it is paid, not when it is fixed.
    if (!orderCostEntryRepository.ZOHO_CATEGORIES.includes(c.category) || (c.incentive_kind !== null && c.paid_from === null)) continue;
    const label = orderCostEntryRepository.CATEGORIES[c.category];
    await expense(`Cost: ${label}${c.description ? ` — ${c.description}` : ''}`, Number(c.amount_inr), c.zoho_expense_id, notSent(c.incurred_at || c.created_at), errorFor('order_cost', 'cost'));
  }

  // --- Export benefits received.
  for (const b of await db.query('SELECT * FROM ca_export_benefits WHERE order_id = :id AND received_amount > 0 ORDER BY received_at, id', { id: orderId })) {
    const item = `${b.scheme_name} received`;
    if (b.zoho_journal_id === null) {
      add('Export benefits', item, Number(b.received_amount), null, notSent(b.received_at), errorFor('export_benefit', 'journal'));
      continue;
    }
    const journal = await zohoBooksService.readRecord('journal', String(b.zoho_journal_id));
    if (journal === null) add('Export benefits', item, Number(b.received_amount), null, 'missing', MISSING_NOTE);
    else add('Export benefits', item, Number(b.received_amount), Number(journal.total || 0), compare(Number(b.received_amount), Number(journal.total || 0)));
  }

  const counts = Object.fromEntries(Object.keys(STATUS_LABELS).map((k) => [k, 0]));
  for (const row of rows) counts[row.status] += 1;
  return { rows, counts };
}

/** "sync_type:leg" => the latest error message for this order, when it is newer than the latest success. */
async function lastErrors(orderId) {
  const out = {};
  for (const r of await db.query('SELECT sync_type, leg, status, message FROM zoho_sync_log WHERE entity_id = :id ORDER BY id', { id: orderId })) {
    const key = `${r.sync_type}:${r.leg}`;
    if (r.status === 'error') out[key] = String(r.message);
    else if (r.status === 'success') delete out[key];
  }
  return out;
}

module.exports = { STATUS_LABELS, check };
