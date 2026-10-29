'use strict';

const db = require('../config/db');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const financialYear = require('../helpers/financialYear');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoBooksService = require('./zohoBooksService');
const zohoGuard = require('./zohoGuard');
const { KIND_LABELS } = require('./zohoInvoiceData');

/**
 * Owner decision 2026-10-29: the app is the source of truth for what it
 * sends to Zoho Books. At the start of each sync, every record the app has
 * already sent (dated on or after the go-live date) is checked against
 * Books:
 *
 * - deleted in Books: the app forgets the Books link, so the same sync
 *   sends it again ("Re-created … — it had been deleted in Zoho Books");
 * - a different amount in Books: it is put back to the app's values
 *   ("Restored … — it had been changed in Zoho Books").
 *
 * A record dated in a financial year the CA has locked in the app is never
 * changed automatically: it is logged for the CA to decide. Books is read a
 * list at a time (per customer, per vendor, all expenses, all journals),
 * never one call per record, except to confirm a record is really gone.
 * Port of ZohoRestoreService.php.
 */
const RECREATED = 'Re-created in Zoho Books — it had been deleted there. ';
const RESTORED = "Restored to the app's values — it had been changed in Zoho Books. ";

// record key => the note its next Sync Log row starts with
const notes = new Map();

// whether this process's scheduled run is the day's full check
let dailyDue = null;

const pad = (n) => String(n).padStart(2, '0');
const localDateTime = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;

/**
 * Owner decision 2026-10-29: checking every record already in Books
 * reads Books for every customer and vendor, and Zoho limits API calls
 * per day — so a scheduled run does it at most once every 23 hours,
 * while Sync Now (manual) always does.
 */
async function due(triggeredBy) {
  if (triggeredBy === 'manual') return true;
  if (dailyDue === null) {
    const last = String((await companySettingsRepository.get('zoho_books_restore_checked_at')) || '');
    const lastMs = last === '' ? NaN : new Date(last.replace(' ', 'T')).getTime();
    dailyDue = Number.isNaN(lastMs) || lastMs <= Date.now() - 23 * 3600 * 1000;
  }
  return dailyDue;
}

/** A scheduled run finished the day's full check: remember when. */
async function markScheduledCheckDone() {
  await companySettingsRepository.set('zoho_books_restore_checked_at', localDateTime(new Date()));
  dailyDue = null;
}

/** Test helper: forget the cached answer (PHP resets the static property by reflection). */
function resetDailyDue() {
  dailyDue = null;
}

/** The note for a record the sync is about to send again or update (once), else ''. */
function note(key) {
  const value = notes.get(key) || '';
  notes.delete(key);
  return value;
}

const fmt = (n) => Number(n).toFixed(2);
const ucfirst = (s) => s.charAt(0).toUpperCase() + s.slice(1);
const byId = (rows, idKey) => new Map(rows.map((r) => [String(r[idKey]), r]));

/**
 * The CI / Debit Notes, the buyer's payments and the LC payments.
 *
 * @return {Promise<{restored: number, failed: number}>}
 */
async function revenue(startDate, triggeredBy, userId) {
  if (!(await due(triggeredBy))) return { restored: 0, failed: 0 };
  const out = { restored: 0, failed: 0 };
  const allowed = (r) => (zohoGuard.isProduction() ? Number(r.is_test_or_sample) !== 1 : String(r.company_legal_name || '').startsWith(zohoGuard.TEST_PREFIX));
  const flags = '(o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample';

  const invoices = (await db.query(`SELECT z.*, o.client_id, o.company_legal_name, c.zoho_contact_id, COALESCE(d.document_date, DATE(d.generated_at), DATE(z.pushed_at)) AS record_date, ${flags}
    FROM order_zoho_invoices z JOIN orders o ON o.id = z.order_id JOIN clients c ON c.id = o.client_id LEFT JOIN documents d ON d.id = z.document_id`)).filter(allowed);
  const payments = [];
  for (const ops of await db.query(`SELECT ops.*, o.client_id, o.company_legal_name, o.buyer_inquiry_ref, o.currency_code, c.zoho_contact_id, ${flags}
    FROM order_payment_status ops JOIN orders o ON o.id = ops.order_id JOIN clients c ON c.id = o.client_id`)) {
    if (!allowed(ops)) continue;
    for (const leg of ['advance', 'balance', 'freight']) {
      if (ops[`${leg}_zoho_reference`] === null) continue;
      const currency = String(ops.currency_code || 'INR');
      const date = String(ops[`${leg}_cleared_at`] || '').slice(0, 10);
      payments.push({
        key: `leg:${ops.order_id}:${leg}`, leg, order_id: Number(ops.order_id), contact: ops.zoho_contact_id, id: String(ops[`${leg}_zoho_reference`]), date,
        amount: currency === 'INR' ? Number(ops[`${leg}_inr_actual`]) : Number(ops[`${leg}_amount`]), applied: ops[`${leg}_zoho_applied_at`] !== null,
        args: [Number(ops.client_id), String(ops.company_legal_name), currency, ops[`${leg}_amount`] !== null ? Number(ops[`${leg}_amount`]) : null, Number(ops[`${leg}_inr_actual`]), date, `${ops.buyer_inquiry_ref}-${leg}`],
        charges: 0,
      });
    }
  }
  for (const lc of await db.query(`SELECT l.*, o.client_id, o.company_legal_name, o.buyer_inquiry_ref, o.currency_code, c.zoho_contact_id, ${flags}
    FROM order_lc_details l JOIN orders o ON o.id = l.order_id JOIN clients c ON c.id = o.client_id WHERE l.lc_zoho_payment_id IS NOT NULL`)) {
    if (!allowed(lc)) continue;
    const currency = String(lc.currency_code || 'INR');
    payments.push({
      key: `lc:${lc.order_id}`, leg: 'lc', order_id: Number(lc.order_id), contact: lc.zoho_contact_id, id: String(lc.lc_zoho_payment_id), date: String(lc.lc_received_at),
      amount: currency === 'INR' ? Number(lc.lc_inr_credited) + Number(lc.lc_bank_charges_inr) : Number(lc.lc_received_amount), applied: lc.lc_zoho_applied_at !== null,
      args: [Number(lc.client_id), String(lc.company_legal_name), currency, Number(lc.lc_received_amount), Number(lc.lc_inr_credited), String(lc.lc_received_at), `${lc.buyer_inquiry_ref}-lc`],
      charges: Number(lc.lc_bank_charges_inr),
    });
  }

  const books = new Map();
  const read = async (contactId) => {
    if (contactId === null || contactId === undefined) return { invoices: [], customerpayments: [] };
    if (!books.has(contactId)) books.set(contactId, await zohoBooksService.contactRecords(contactId, false));
    return books.get(contactId);
  };
  const inScope = (date) => startDate === null || date >= startDate;

  for (const inv of invoices) {
    if (!inScope(String(inv.record_date))) continue;
    const label = `${KIND_LABELS[inv.kind]} ${inv.zoho_invoice_number}`;
    const key = `invoice:${inv.order_id}:${inv.kind}`;
    const orderId = Number(inv.order_id);
    try {
      const row = byId((await read(inv.zoho_contact_id)).invoices, 'invoice_id').get(String(inv.zoho_invoice_id))
        || await zohoBooksService.invoiceByNumber(String(inv.zoho_invoice_number));
      if (!row) {
        if (await locked(String(inv.record_date), 'invoice', orderId, inv.kind, String(inv.zoho_invoice_id), `${label} was deleted in Zoho Books`, triggeredBy, userId)) continue;
        await orderZohoInvoiceRepository.forgetZohoInvoice(String(inv.zoho_invoice_id));
        for (const leg of inv.kind === 'ci' ? ['advance', 'balance'] : ['freight']) {
          await db.execute(`UPDATE order_payment_status SET ${leg}_zoho_applied_at = NULL WHERE order_id = :id AND ${leg}_zoho_synced_at IS NOT NULL`, { id: orderId });
        }
        if (inv.kind === 'ci') {
          await db.execute('UPDATE order_lc_details SET lc_zoho_applied_at = NULL WHERE order_id = :id AND lc_zoho_synced_at IS NOT NULL', { id: orderId });
        }
        notes.set(key, RECREATED);
      } else if (Math.abs(Number(row.total) - Number(inv.total)) > 0.01) {
        if (await locked(String(inv.record_date), 'invoice', orderId, inv.kind, String(inv.zoho_invoice_id), `${label} was changed in Zoho Books`, triggeredBy, userId)) continue;
        await db.execute("UPDATE order_zoho_invoices SET pushed_hash = '' WHERE zoho_invoice_id = :id", { id: inv.zoho_invoice_id });
        notes.set(key, `${RESTORED}(Books had ${fmt(row.total)}) `);
      }
    } catch (e) {
      await zohoSyncLogRepository.log('invoice', 'order', orderId, inv.kind, 'error', String(inv.zoho_invoice_id), `Could not check ${label} in Zoho Books: ${e.message}`, triggeredBy, userId);
      out.failed += 1;
    }
  }

  for (const p of payments) {
    if (!inScope(p.date)) continue;
    const what = `${p.leg === 'lc' ? 'LC payment' : `${ucfirst(p.leg)} payment`} ${p.args[6]}`;
    try {
      const row = byId((await read(p.contact)).customerpayments, 'payment_id').get(p.id)
        || await zohoBooksService.readRecord('customerpayment', p.id);
      if (!row) {
        if (await locked(p.date, 'revenue_payment', p.order_id, p.leg, p.id, `${what} was deleted in Zoho Books`, triggeredBy, userId)) continue;
        if (p.leg === 'lc') {
          await orderLcDetailRepository.forgetZohoPayment(p.id);
        } else {
          await db.execute(`UPDATE order_payment_status SET ${p.leg}_zoho_synced_at = NULL, ${p.leg}_zoho_reference = NULL, ${p.leg}_zoho_applied_at = NULL WHERE order_id = :id`, { id: p.order_id });
        }
        notes.set(p.key, RECREATED);
      } else if (Math.abs(Number(row.amount) - p.amount) > 0.01) {
        if (await locked(p.date, 'revenue_payment', p.order_id, p.leg, p.id, `${what} was changed in Zoho Books`, triggeredBy, userId)) continue;
        const invoice = p.applied ? await orderZohoInvoiceRepository.find(p.order_id, p.leg === 'freight' ? 'dn' : 'ci') : null;
        await zohoBooksService.restoreRevenuePayment(p.id, ...p.args, invoice ? String(invoice.zoho_invoice_id) : null, p.charges);
        await zohoSyncLogRepository.log('revenue_payment', 'order', p.order_id, p.leg, 'success', p.id, `${RESTORED}${what} put back to ${fmt(p.amount)} (Books had ${fmt(row.amount)}).`, triggeredBy, userId);
        out.restored += 1;
      }
    } catch (e) {
      await zohoSyncLogRepository.log('revenue_payment', 'order', p.order_id, p.leg, 'error', p.id, `Could not check ${what} in Zoho Books: ${e.message}`, triggeredBy, userId);
      out.failed += 1;
    }
  }
  return out;
}

/**
 * Bills, vendor payments, order expenses and order costs.
 *
 * @return {Promise<{bills: number, payments: number, expenses: number, failed: number}>}
 */
async function purchases(startDate, triggeredBy, userId) {
  if (!(await due(triggeredBy))) return { bills: 0, payments: 0, expenses: 0, failed: 0 };
  const out = { bills: 0, payments: 0, expenses: 0, failed: 0 };
  const inScope = (date) => startDate === null || String(date).slice(0, 10) >= startDate;
  const vendors = new Map();
  const read = async (vendorId) => {
    if (vendorId === null || vendorId === undefined) return { bills: [], vendorpayments: [] };
    if (!vendors.has(vendorId)) vendors.set(vendorId, await zohoBooksService.contactRecords(vendorId, true));
    return vendors.get(vendorId);
  };
  const log = (type, orderId, leg, status, ref, message) => zohoSyncLogRepository.log(type, 'order', orderId, leg, status, ref, message, triggeredBy, userId);

  for (const bill of await zohoPurchaseRepository.sentBills()) {
    if (!inScope(bill.bill_date)) continue;
    const what = `Bill ${bill.bill_no} from ${bill.vendor_name}`;
    const zohoId = String(bill.zoho_bill_id);
    const orderId = Number(bill.order_id);
    try {
      const row = byId((await read(bill.vendor_zoho_contact_id)).bills, 'bill_id').get(zohoId) || await zohoBooksService.readRecord('bill', zohoId);
      if (!row) {
        if (await locked(String(bill.bill_date), 'vendor_bill', orderId, 'bill', zohoId, `${what} was deleted in Zoho Books`, triggeredBy, userId)) continue;
        await zohoPurchaseRepository.forgetZohoRecord('bill', zohoId);
        await zohoPurchaseRepository.resetPaymentsAppliedTo(Number(bill.id));
        notes.set(`bill:${bill.id}`, RECREATED);
      } else if (Math.abs(Number(row.total) - Number(bill.total_inr)) > 0.01) {
        if (await locked(String(bill.bill_date), 'vendor_bill', orderId, 'bill', zohoId, `${what} was changed in Zoho Books`, triggeredBy, userId)) continue;
        await zohoBooksService.pushBill(bill, zohoId);
        await log('vendor_bill', orderId, 'bill', 'success', zohoId, `${RESTORED}${what} put back to INR ${fmt(bill.total_inr)} (Books had ${fmt(row.total)}).`);
        out.bills += 1;
      }
    } catch (e) {
      await log('vendor_bill', orderId, 'bill', 'error', zohoId, `Could not check ${what} in Zoho Books: ${e.message}`);
      out.failed += 1;
    }
  }

  for (const { payment, bill } of await zohoPurchaseRepository.sentPayments()) {
    if (!inScope(payment.paid_at)) continue;
    const what = `Payment ${payment.payment_ref} to ${bill.vendor_name}`;
    const zohoId = String(payment.zoho_payment_id);
    const orderId = Number(bill.order_id);
    try {
      const row = byId((await read(bill.vendor_zoho_contact_id)).vendorpayments, 'payment_id').get(zohoId) || await zohoBooksService.readRecord('vendorpayment', zohoId);
      if (!row) {
        if (await locked(String(payment.paid_at), 'vendor_payment', orderId, 'payment', zohoId, `${what} was deleted in Zoho Books`, triggeredBy, userId)) continue;
        await zohoPurchaseRepository.forgetZohoRecord('vendorpayment', zohoId);
        notes.set(`vpay:${payment.id}`, RECREATED);
      } else if (Math.abs(Number(row.amount) - Number(payment.amount_inr)) > 0.01) {
        if (await locked(String(payment.paid_at), 'vendor_payment', orderId, 'payment', zohoId, `${what} was changed in Zoho Books`, triggeredBy, userId)) continue;
        const target = payment.zoho_applied_at !== null ? await zohoPurchaseRepository.targetBillFor((await zohoPurchaseRepository.findBill(Number(bill.id))) || bill) : null;
        await zohoBooksService.restoreVendorPayment(payment, bill, target ? String(target.zoho_bill_id) : null);
        await log('vendor_payment', orderId, 'payment', 'success', zohoId, `${RESTORED}${what} put back to INR ${fmt(payment.amount_inr)} (Books had ${fmt(row.amount)}).`);
        out.payments += 1;
      }
    } catch (e) {
      await log('vendor_payment', orderId, 'payment', 'error', zohoId, `Could not check ${what} in Zoho Books: ${e.message}`);
      out.failed += 1;
    }
  }

  const costs = (await zohoPurchaseRepository.costEntriesToSync(startDate)).filter((c) => c.zoho_expense_id !== null);
  const expenses = (await zohoPurchaseRepository.sentExpenses()).filter((e) => inScope(e.expense_date));
  if (expenses.length === 0 && costs.length === 0) return out;
  let totals;
  try {
    totals = await zohoBooksService.totalsById('expense');
  } catch (e) {
    await zohoSyncLogRepository.log('order_expense', null, null, null, 'error', null, `Could not read the expenses in Zoho Books to check them: ${e.message}`, triggeredBy, userId);
    out.failed += 1;
    return out;
  }
  const totalOf = async (zohoId) => {
    if (zohoId in totals) return totals[zohoId];
    const r = await zohoBooksService.readRecord('expense', zohoId);
    return r ? Number(r.total ?? r.amount ?? 0) : null;
  };
  for (const expense of expenses) {
    const zohoId = String(expense.zoho_expense_id);
    const what = `Expense "${expense.details}"`;
    const orderId = Number(expense.order_id);
    try {
      const total = await totalOf(zohoId);
      if (total === null) {
        if (await locked(String(expense.expense_date), 'order_expense', orderId, 'expense', zohoId, `${what} was deleted in Zoho Books`, triggeredBy, userId)) continue;
        await zohoPurchaseRepository.forgetZohoRecord('expense', zohoId);
        notes.set(`exp:${expense.id}`, RECREATED);
      } else if (Math.abs(total - Number(expense.amount_inr)) > 0.01) {
        if (await locked(String(expense.expense_date), 'order_expense', orderId, 'expense', zohoId, `${what} was changed in Zoho Books`, triggeredBy, userId)) continue;
        await zohoBooksService.pushExpense(expense, zohoId);
        await log('order_expense', orderId, 'expense', 'success', zohoId, `${RESTORED}${what} put back to INR ${fmt(expense.amount_inr)} (Books had ${fmt(total)}).`);
        out.expenses += 1;
      }
    } catch (e) {
      await log('order_expense', orderId, 'expense', 'error', zohoId, `Could not check ${what} in Zoho Books: ${e.message}`);
      out.failed += 1;
    }
  }
  for (const cost of costs) {
    const zohoId = String(cost.zoho_expense_id);
    const what = `Order cost "${orderCostEntryRepository.CATEGORIES[cost.category] || cost.category}"`;
    const orderId = Number(cost.order_id);
    try {
      const total = await totalOf(zohoId);
      const deleted = total === null;
      if (!deleted && Math.abs(total - Number(cost.amount_inr)) <= 0.01) continue;
      if (await locked(String(cost.cost_date), 'order_cost', orderId, 'cost', zohoId, what + (deleted ? ' was deleted in Zoho Books' : ' was changed in Zoho Books'), triggeredBy, userId)) continue;
      if (deleted) {
        await zohoPurchaseRepository.forgetZohoRecord('expense', zohoId);
        notes.set(`cost:${cost.id}`, RECREATED);
      } else {
        await db.execute('UPDATE order_cost_entries SET zoho_pushed_hash = NULL WHERE id = :id', { id: cost.id });
        notes.set(`cost:${cost.id}`, `${RESTORED}(Books had ${fmt(total)}) `);
      }
    } catch (e) {
      await log('order_cost', orderId, 'cost', 'error', zohoId, `Could not check ${what} in Zoho Books: ${e.message}`);
      out.failed += 1;
    }
  }
  return out;
}

/**
 * Export benefit journals.
 *
 * @return {Promise<{failed: number}>}
 */
async function benefits(startDate, triggeredBy, userId) {
  if (!(await due(triggeredBy))) return { failed: 0 };
  const out = { failed: 0 };
  const sent = (await caExportBenefitRepository.forZohoSync(startDate)).filter((b) => b.zoho_journal_id !== null && Number(b.received_amount || 0) > 0
    && (startDate === null || String(b.received_at) >= startDate));
  if (sent.length === 0) return out;
  let totals;
  try {
    totals = await zohoBooksService.totalsById('journal');
  } catch (e) {
    await zohoSyncLogRepository.log('export_benefit', null, null, null, 'error', null, `Could not read the journals in Zoho Books to check them: ${e.message}`, triggeredBy, userId);
    return { failed: 1 };
  }
  for (const b of sent) {
    const zohoId = String(b.zoho_journal_id);
    const orderId = b.order_id !== null ? Number(b.order_id) : null;
    const what = `Export benefit ${b.scheme_name} (claim #${b.id})`;
    try {
      let total = totals[zohoId];
      if (total === undefined) {
        const r = await zohoBooksService.readRecord('journal', zohoId);
        total = r ? Number(r.total || 0) : null;
      }
      const deleted = total === null;
      if (!deleted && Math.abs(total - Number(b.received_amount)) <= 0.01) continue;
      if (await locked(String(b.received_at), 'export_benefit', orderId, 'journal', zohoId, what + (deleted ? ' was deleted in Zoho Books' : ' was changed in Zoho Books'), triggeredBy, userId)) continue;
      if (deleted) {
        await caExportBenefitRepository.setZohoJournal(Number(b.id), null, null);
        notes.set(`ben:${b.id}`, RECREATED);
      } else {
        await db.execute('UPDATE ca_export_benefits SET zoho_pushed_hash = NULL WHERE id = :id', { id: b.id });
        notes.set(`ben:${b.id}`, `${RESTORED}(Books had ${fmt(total)}) `);
      }
    } catch (e) {
      await zohoSyncLogRepository.log('export_benefit', 'order', orderId, 'journal', 'error', zohoId, `Could not check ${what} in Zoho Books: ${e.message}`, triggeredBy, userId);
      out.failed += 1;
    }
  }
  return out;
}

/**
 * A record dated in a financial year the CA has locked is never changed
 * automatically: it is logged (once — not again while the same message is
 * the record's latest) for the CA to decide. True when locked.
 */
async function locked(date, type, orderId, leg, zohoId, what, triggeredBy, userId) {
  const fy = financialYear.label(String(date).slice(0, 10));
  if (!(await caFyLockRepository.isLocked(fy))) return false;
  const message = `${what}, but it is dated in FY ${fy}, which is locked: not restored — CA to decide.`;
  const last = await db.queryOne('SELECT message FROM zoho_sync_log WHERE sync_type = :t AND zoho_reference <=> :ref ORDER BY id DESC LIMIT 1', { t: type, ref: zohoId });
  if (!last || last.message !== message) {
    await zohoSyncLogRepository.log(type, 'order', orderId, leg, 'error', zohoId, message, triggeredBy, userId);
  }
  return true;
}

module.exports = { due, markScheduledCheckDone, resetDailyDue, note, revenue, purchases, benefits };
