'use strict';

const caRepository = require('../repositories/caRepository');
const crypto = require('crypto');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const zohoBooksService = require('../services/zohoBooksService');
const zohoGuard = require('../services/zohoGuard');
const zohoInvoiceData = require('../services/zohoInvoiceData');
const zohoRestoreService = require('../services/zohoRestoreService');
const paymentDescription = require('../services/paymentDescription');
const auditLogRepository = require('../repositories/auditLogRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const financialYear = require('../helpers/financialYear');
const db = require('../config/db');

/**
 * CA / Accounting module — orchestrates a Zoho Books sync run, from either
 * the manual "Sync Now" button (server.js's ca_module_manage-gated route)
 * or the scheduled job (jobs/zohoSync.js). Phase 3 added the revenue push;
 * Phase 4 the expense import — runFullSync() runs both in one pass, each
 * independent (one failing never stops the other). Deliberately the
 * single place that decides "not configured" is a routine no-op, not an
 * error — see point 5 of the CA module brief: "if zoho credentials are
 * not present, then it shouldn't be a blocker for our application."
 */
async function runFullSync(triggeredBy, triggeredByUserId) {
  // The day's full check of the records already in Books (zohoRestoreService.due()).
  const dailyCheck = triggeredBy !== 'manual' && (await zohoBooksService.isEnabled()) && (await zohoRestoreService.due(triggeredBy));
  const result = {
    revenue: await syncPendingRevenue(triggeredBy, triggeredByUserId),
    // Stage 3 runs before the expense import, so the order expenses it sends
    // are already marked and the import skips them.
    purchases: await syncPurchases(triggeredBy, triggeredByUserId),
    expenses: await importPendingExpenses(triggeredBy, triggeredByUserId),
    benefits: await syncBenefits(triggeredBy, triggeredByUserId),
    gst_refunds: await syncGstRefunds(triggeredBy, triggeredByUserId),
  };
  if (dailyCheck) {
    await zohoRestoreService.markScheduledCheckDone();
  }
  return result;
}

/**
 * Stage 2 (2026-10-29): first the Commercial Invoices and Debit Notes
 * (created, or updated when a revised one was approved), then the payments
 * — each applied to its invoice straight away when that is in Books
 * (advance and balance to the CI, freight to the Debit Note) — then any
 * payment held unapplied (the advance, pushed before its CI existed) is
 * applied once its invoice is there. Every item is logged on its own; one
 * failing never stops the others.
 *
 * @return {Promise<{synced: number, failed: number, skipped: number, invoices: number, applied: number}>}
 */
async function syncPendingRevenue(triggeredBy, triggeredByUserId) {
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('revenue_payment', null, null, null, 'skipped', null, `${offReason()} — nothing synced.`, triggeredBy, triggeredByUserId);
    return { synced: 0, failed: 0, skipped: 1, invoices: 0, applied: 0 };
  }
  const startDate = await zohoBooksService.syncStartDate();
  if (startDate === null && zohoGuard.isProduction()) {
    await zohoSyncLogRepository.log('revenue_payment', null, null, null, 'error', null, NO_START_DATE, triggeredBy, triggeredByUserId);
    return { synced: 0, failed: 1, skipped: 0, invoices: 0, applied: 0 };
  }

  // Owner decision 2026-10-29: put back what was deleted or changed in Books.
  const restore = await zohoRestoreService.revenue(startDate, triggeredBy, triggeredByUserId);
  let failed = restore.failed;
  let invoices = 0;
  for (const item of await orderZohoInvoiceRepository.documentsToSync(startDate)) {
    const label = zohoInvoiceData.KIND_LABELS[item.kind];
    try {
      const invoice = await zohoInvoiceData.build(item.order_id, item.kind, item.document_id);
      const known = await orderZohoInvoiceRepository.find(item.order_id, item.kind);
      if (known) {
        // The rate is frozen once the invoice is in Books: a rate recorded
        // later (the balance arriving) must not revalue an invoice that may
        // sit in a filed GST month. Each payment still goes in at its own
        // actual rate, and Books books the difference as exchange gain or
        // loss.
        invoice.exchange_rate = Number(known.exchange_rate);
      }
      const hash = zohoInvoiceData.fingerprint(invoice);
      if (known && known.pushed_hash === hash) {
        continue;
      }
      const pushed = await zohoBooksService.pushInvoice(invoice);
      await orderZohoInvoiceRepository.save(item.order_id, item.kind, item.document_id, {
        zoho_invoice_id: pushed.zoho_invoice_id,
        zoho_invoice_number: pushed.zoho_invoice_number,
        currency_code: invoice.currency_code,
        total: invoice.total,
        exchange_rate: invoice.exchange_rate,
        pushed_hash: hash,
      });
      const what = !known && pushed.created ? 'Sent' : 'Updated';
      await zohoSyncLogRepository.log('invoice', 'order', item.order_id, item.kind, 'success', pushed.zoho_invoice_id, `${zohoRestoreService.note(`invoice:${item.order_id}:${item.kind}`)}${what} ${label} ${invoice.invoice_number} as Zoho Books invoice (${invoice.currency_code} ${invoice.total.toFixed(2)} @ ${invoice.exchange_rate}).`, triggeredBy, triggeredByUserId);
      invoices += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('invoice', 'order', item.order_id, item.kind, 'error', null, e.message, triggeredBy, triggeredByUserId);
      failed += 1;
    }
  }

  let synced = restore.restored;
  let applied = 0;
  for (const leg of await caRepository.legsPendingZohoSync()) {
    if (startDate !== null && String(leg.cleared_at).slice(0, 10) < startDate) {
      continue; // before the go-live date: the CA books it as the opening position
    }
    const referenceNumber = `${leg.buyer_inquiry_ref}-${leg.leg}`;
    const target = await invoiceFor(leg.order_id, leg.leg);
    try {
      const result = await zohoBooksService.pushRevenuePayment(
        leg.client_id,
        leg.company_legal_name,
        leg.currency_code || 'INR',
        leg.foreign_amount,
        leg.inr_actual,
        String(leg.cleared_at).slice(0, 10),
        referenceNumber,
        target ? target.zoho_invoice_number : null,
        0,
        await paymentDescription.buyerPayment(Number(leg.order_id), String(leg.leg), target ? target.zoho_invoice_number : null)
      );
      const zohoReference = result.payment_id;
      await markSynced(leg.order_id, leg.leg, zohoReference);
      if (result.applied > 0) {
        await orderPaymentStatusRepository.setLegZohoApplied(leg.order_id, leg.leg);
        applied += 1;
      }
      await zohoSyncLogRepository.log('revenue_payment', 'order_payment_status', leg.order_id, leg.leg, 'success', zohoReference, `${zohoRestoreService.note(`leg:${leg.order_id}:${leg.leg}`)}Pushed as Zoho Books customer payment ${zohoReference}${appliedNote(leg.leg, target, result.applied)}`, triggeredBy, triggeredByUserId);
      synced += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('revenue_payment', 'order_payment_status', leg.order_id, leg.leg, 'error', null, e.message, triggeredBy, triggeredByUserId);
      failed += 1;
    }
  }

  for (const leg of await caRepository.legsHeldInZoho()) {
    const target = await invoiceFor(leg.order_id, leg.leg);
    if (!target) {
      continue; // still waiting for its invoice — nothing to do yet
    }
    try {
      const amount = await zohoBooksService.applyPaymentToInvoice(
        String(leg.zoho_reference),
        leg.client_id,
        leg.company_legal_name,
        leg.currency_code || 'INR',
        leg.foreign_amount,
        leg.inr_actual,
        String(leg.cleared_at).slice(0, 10),
        `${leg.buyer_inquiry_ref}-${leg.leg}`,
        target.zoho_invoice_number,
        0,
        await paymentDescription.buyerPayment(Number(leg.order_id), String(leg.leg), target.zoho_invoice_number)
      );
      await orderPaymentStatusRepository.setLegZohoApplied(leg.order_id, leg.leg);
      await zohoSyncLogRepository.log('payment_apply', 'order_payment_status', leg.order_id, leg.leg, 'success', String(leg.zoho_reference), `Applied ${amount.toFixed(2)} of payment ${leg.zoho_reference} to ${zohoInvoiceData.KIND_LABELS[LEG_INVOICE[leg.leg]]} ${target.zoho_invoice_number}.`, triggeredBy, triggeredByUserId);
      applied += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('payment_apply', 'order_payment_status', leg.order_id, leg.leg, 'error', String(leg.zoho_reference), e.message, triggeredBy, triggeredByUserId);
      failed += 1;
    }
  }

  // Owner decision 2026-10-29: the money the bank paid under an LC — a
  // customer payment applied to the CI (held until the CI is in Books, like
  // the advance), with the bank charges the bank kept.
  for (const lc of await orderLcDetailRepository.receiptsForZohoSync()) {
    if (lc.lc_zoho_synced_at === null && startDate !== null && String(lc.lc_received_at) < startDate) {
      continue; // before the go-live date: the CA books it as the opening position
    }
    const orderId = Number(lc.order_id);
    const reference = `${lc.buyer_inquiry_ref}-lc`;
    const target = await orderZohoInvoiceRepository.find(orderId, 'ci');
    const args = [Number(lc.client_id), String(lc.company_legal_name), String(lc.currency_code || 'INR'), Number(lc.lc_received_amount), Number(lc.lc_inr_credited), String(lc.lc_received_at), reference];
    try {
      if (lc.lc_zoho_synced_at === null) {
        const result = await zohoBooksService.pushRevenuePayment(...args, target ? target.zoho_invoice_number : null, Number(lc.lc_bank_charges_inr), await paymentDescription.buyerPayment(orderId, 'lc', target ? target.zoho_invoice_number : null));
        await orderLcDetailRepository.setZohoSynced(orderId, result.payment_id, result.applied > 0);
        applied += result.applied > 0 ? 1 : 0;
        await zohoSyncLogRepository.log('revenue_payment', 'order_lc_details', orderId, 'lc', 'success', result.payment_id, `${zohoRestoreService.note(`lc:${orderId}`)}Pushed LC payment as Zoho Books customer payment ${result.payment_id}${appliedNote('lc', target, result.applied)}`, triggeredBy, triggeredByUserId);
        synced += 1;
      } else if (target) {
        const amount = await zohoBooksService.applyPaymentToInvoice(String(lc.lc_zoho_payment_id), ...args, target.zoho_invoice_number, Number(lc.lc_bank_charges_inr), await paymentDescription.buyerPayment(orderId, 'lc', target.zoho_invoice_number));
        await orderLcDetailRepository.setZohoApplied(orderId);
        await zohoSyncLogRepository.log('payment_apply', 'order_lc_details', orderId, 'lc', 'success', String(lc.lc_zoho_payment_id), `Applied ${amount.toFixed(2)} of LC payment ${lc.lc_zoho_payment_id} to Commercial Invoice ${target.zoho_invoice_number}.`, triggeredBy, triggeredByUserId);
        applied += 1;
      }
    } catch (e) {
      await zohoSyncLogRepository.log(lc.lc_zoho_synced_at === null ? 'revenue_payment' : 'payment_apply', 'order_lc_details', orderId, 'lc', 'error', lc.lc_zoho_payment_id, e.message, triggeredBy, triggeredByUserId);
      failed += 1;
    }
  }

  if (synced === 0 && failed === 0 && invoices === 0 && applied === 0) {
    await zohoSyncLogRepository.log('revenue_payment', null, null, null, 'skipped', null, 'Nothing pending — every approved CI / Debit Note and every recorded INR actual is already in Zoho Books.', triggeredBy, triggeredByUserId);
    return { synced: 0, failed: 0, skipped: 1, invoices: 0, applied: 0 };
  }

  return { synced, failed, skipped: 0, invoices, applied };
}

// In production nothing goes to Books until the go-live date is set.
const NO_START_DATE = 'Nothing sent: set the Zoho Books go-live date (zoho_books_sync_start_date) in Admin -> Settings first. Only records dated on or after it go to Zoho Books; the CA books everything earlier as the opening position.';

// Which invoice each payment leg is paid against: the advance and the
// balance against the CI, freight against the Debit Note.
const LEG_INVOICE = { advance: 'ci', balance: 'ci', freight: 'dn', lc: 'ci' };

/** The leg's invoice, when it is already in Zoho Books. */
async function invoiceFor(orderId, leg) {
  return orderZohoInvoiceRepository.find(orderId, LEG_INVOICE[leg]);
}

function appliedNote(leg, target, applied) {
  const label = zohoInvoiceData.KIND_LABELS[LEG_INVOICE[leg]];
  if (!target) {
    return `, held unapplied until the ${label} is in Zoho Books.`;
  }
  return applied > 0
    ? `, applied ${applied.toFixed(2)} to ${label} ${target.zoho_invoice_number}.`
    : `, held unapplied: ${label} ${target.zoho_invoice_number} has nothing left to pay.`;
}

async function markSynced(orderId, leg, zohoReference) {
  if (leg === 'advance') return orderPaymentStatusRepository.setAdvanceZohoSync(orderId, zohoReference);
  if (leg === 'balance') return orderPaymentStatusRepository.setBalanceZohoSync(orderId, zohoReference);
  if (leg === 'freight') return orderPaymentStatusRepository.setFreightZohoSync(orderId, zohoReference);
  return undefined;
}

/**
 * Stage 3 (2026-10-29): what the app pays out. First the suppliers' and
 * logistics partners' tax invoices (as Books bills, under the vendor,
 * created on first use), then each payment made to them — applied to its
 * bill when that is in Books; a payment on a proforma is held as an advance
 * until the tax invoice that replaces it is in Books — then held payments
 * whose bill has now arrived, then the order's expenses. Every item is
 * logged on its own; one failing never stops the others.
 *
 * @return {Promise<{bills: number, payments: number, applied: number, expenses: number, failed: number, skipped: number}>}
 */
async function syncPurchases(triggeredBy, triggeredByUserId) {
  const result = { bills: 0, payments: 0, applied: 0, expenses: 0, failed: 0, skipped: 0 };
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('vendor_bill', null, null, null, 'skipped', null, `${offReason()} — no bills, vendor payments or expenses sent.`, triggeredBy, triggeredByUserId);
    result.skipped = 1;
    return result;
  }
  const startDate = await zohoBooksService.syncStartDate();
  if (startDate === null && zohoGuard.isProduction()) {
    await zohoSyncLogRepository.log('vendor_bill', null, null, null, 'error', null, NO_START_DATE, triggeredBy, triggeredByUserId);
    result.failed = 1;
    return result;
  }
  const log = (type, orderId, leg, status, reference, message) => zohoSyncLogRepository.log(type, 'order', orderId, leg, status, reference, message, triggeredBy, triggeredByUserId);
  const money = (n) => Number(n).toFixed(2);
  // Owner decision 2026-10-29: put back what was deleted or changed in Books.
  for (const [key, count] of Object.entries(await zohoRestoreService.purchases(startDate, triggeredBy, triggeredByUserId))) {
    result[key] += count;
  }

  for (const bill of await zohoPurchaseRepository.billsToSync(startDate)) {
    try {
      const zohoBillId = await zohoBooksService.pushBill(bill);
      await zohoPurchaseRepository.setBillSynced(bill.id, zohoBillId);
      await log('vendor_bill', bill.order_id, 'bill', 'success', zohoBillId, `${zohoRestoreService.note(`bill:${bill.id}`)}Sent bill ${bill.bill_no} from ${bill.vendor_name} (INR ${money(bill.total_inr)}${Number(bill.gst_reverse_charge) ? ', reverse charge' : ''}) as Zoho Books bill.`);
      result.bills += 1;
    } catch (e) {
      await log('vendor_bill', bill.order_id, 'bill', 'error', null, e.message);
      result.failed += 1;
    }
  }

  for (const { payment, bill } of await zohoPurchaseRepository.paymentsToSync(startDate)) {
    const target = await zohoPurchaseRepository.targetBillFor((await zohoPurchaseRepository.findBill(bill.id)) || bill);
    try {
      const pushed = await zohoBooksService.pushVendorPayment(payment, bill, target);
      await zohoPurchaseRepository.setPaymentSynced(payment.id, pushed.payment_id, pushed.applied > 0);
      let note;
      if (!target) note = `, held unapplied until the tax invoice for proforma ${bill.bill_no} is in Zoho Books.`;
      else note = pushed.applied > 0 ? `, applied ${money(pushed.applied)} to bill ${target.bill_no}.` : `, held unapplied: bill ${target.bill_no} has nothing left to pay.`;
      await log('vendor_payment', bill.order_id, 'payment', 'success', pushed.payment_id, `${zohoRestoreService.note(`vpay:${payment.id}`)}Pushed payment ${payment.payment_ref} (INR ${money(payment.amount_inr)}) to ${bill.vendor_name} as Zoho Books vendor payment${note}`);
      result.payments += 1;
      result.applied += pushed.applied > 0 ? 1 : 0;
    } catch (e) {
      await log('vendor_payment', bill.order_id, 'payment', 'error', null, e.message);
      result.failed += 1;
    }
  }

  for (const { payment, bill } of await zohoPurchaseRepository.heldPayments()) {
    const target = await zohoPurchaseRepository.targetBillFor(bill);
    if (!target) continue; // its tax invoice is not in Books yet — nothing to do
    try {
      const amount = await zohoBooksService.applyVendorPayment(payment, bill, target);
      await zohoPurchaseRepository.setPaymentApplied(payment.id);
      await log('vendor_payment_apply', bill.order_id, 'payment', 'success', String(payment.zoho_payment_id), `Applied ${money(amount)} of payment ${payment.payment_ref} to bill ${target.bill_no}.`);
      result.applied += 1;
    } catch (e) {
      await log('vendor_payment_apply', bill.order_id, 'payment', 'error', String(payment.zoho_payment_id), e.message);
      result.failed += 1;
    }
  }

  for (const expense of await zohoPurchaseRepository.expensesToSync(startDate)) {
    try {
      const zohoExpenseId = await zohoBooksService.pushExpense(expense);
      await zohoPurchaseRepository.setExpenseSynced(expense.id, zohoExpenseId);
      await log('order_expense', expense.order_id, 'expense', 'success', zohoExpenseId, `${zohoRestoreService.note(`exp:${expense.id}`)}Sent expense "${expense.details}" (INR ${money(expense.amount_inr)}) as Zoho Books expense.`);
      result.expenses += 1;
    } catch (e) {
      await log('order_expense', expense.order_id, 'expense', 'error', null, e.message);
      result.failed += 1;
    }
  }

  // Owner decision 2026-10-29: the order's real paid cost entries, as Books
  // expenses — sent once, and updated when the amount, date or "paid from"
  // changes afterwards. Counted with the expenses.
  for (const cost of await zohoPurchaseRepository.costEntriesToSync(startDate)) {
    const hash = crypto.createHash('sha256').update(JSON.stringify([cost.category, Math.round(Number(cost.amount_inr) * 100) / 100, String(cost.cost_date), cost.paid_from, cost.description])).digest('hex');
    if (cost.zoho_expense_id !== null && cost.zoho_pushed_hash === hash) {
      continue;
    }
    const label = orderCostEntryRepository.CATEGORIES[cost.category] || String(cost.category);
    try {
      const zohoExpenseId = await zohoBooksService.pushCostEntry(cost, cost.zoho_expense_id !== null ? String(cost.zoho_expense_id) : null);
      await zohoPurchaseRepository.setCostEntrySynced(cost.id, zohoExpenseId, hash);
      await log('order_cost', cost.order_id, 'cost', 'success', zohoExpenseId, `${zohoRestoreService.note(`cost:${cost.id}`)}${cost.zoho_expense_id !== null ? 'Updated' : 'Sent'} order cost "${label}" (INR ${money(cost.amount_inr)}) as Zoho Books expense.`);
      result.expenses += 1;
    } catch (e) {
      await log('order_cost', cost.order_id, 'cost', 'error', null, e.message);
      result.failed += 1;
    }
  }

  if (result.bills + result.payments + result.applied + result.expenses + result.failed === 0) {
    await zohoSyncLogRepository.log('vendor_bill', null, null, null, 'skipped', null, 'Nothing pending — every tax invoice, vendor payment, order expense and order cost is already in Zoho Books.', triggeredBy, triggeredByUserId);
    result.skipped = 1;
  }
  return result;
}

/**
 * The expenses the CA enters in Zoho Books come into the app's Expenses
 * register. Owner decisions 2026-10-29 — Books is the source of truth for
 * these: an expense changed in Books is changed here too, and one deleted in
 * Books is removed here (with the old values in the audit log). Not
 * automatically, but logged for the CA: one dated in a locked financial
 * year, and one matched to a bank statement line (unmatch it first). A new
 * expense whose Reference # names an order (its buyer enquiry or order
 * reference) is linked to that order. Expenses the app itself sent are never
 * imported.
 *
 * @return {Promise<{imported: number, updated: number, removed: number, failed: number, skipped: number}>}
 */
async function importPendingExpenses(triggeredBy, triggeredByUserId) {
  const none = { imported: 0, updated: 0, removed: 0, failed: 0, skipped: 0 };
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'skipped', null, `${offReason()} — nothing imported.`, triggeredBy, triggeredByUserId);
    return { ...none, skipped: 1 };
  }

  let expenses;
  try {
    expenses = await zohoBooksService.listExpenses();
  } catch (e) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'error', null, e.message, triggeredBy, triggeredByUserId);
    return { ...none, failed: 1 };
  }

  const result = { ...none };
  const money = (n) => Number(n).toFixed(2);
  const log = (id, status, zohoId, message) => zohoSyncLogRepository.log('expense_import', 'ca_expenses', id, null, status, zohoId, message, triggeredBy, triggeredByUserId);
  const locked = async (date) => {
    const fy = financialYear.label(String(date).slice(0, 10));
    return (await caFyLockRepository.isLocked(fy)) ? fy : null;
  };
  const logOnce = async (id, zohoId, message) => {
    const last = await db.queryOne("SELECT message FROM zoho_sync_log WHERE sync_type = 'expense_import' AND zoho_reference <=> :ref ORDER BY id DESC LIMIT 1", { ref: zohoId });
    if (!last || last.message !== message) await log(id, 'error', zohoId, message);
  };
  const known = await caExpenseRepository.allByZohoId();
  const seen = new Set();
  for (const expense of expenses) {
    const zohoId = expense.zohoExpenseId;
    seen.add(zohoId);
    // Stage 3: an order expense this app sent to Books is already here as
    // that order expense — never import it a second time.
    if (await zohoPurchaseRepository.isPushedExpense(zohoId)) {
      continue;
    }
    const mine = known.get(zohoId);
    if (!mine) {
      const id = await caExpenseRepository.insert(zohoId, expense.category, expense.description, expense.vendorName, expense.amount, expense.currencyCode, expense.expenseDate);
      const orderId = await caExpenseRepository.orderIdForReference(expense.referenceNumber);
      if (orderId !== null) await caExpenseRepository.linkToOrder(id, orderId);
      await log(id, 'success', zohoId, `Imported expense ${zohoId} (${expense.category}, ${expense.amount} ${expense.currencyCode})${orderId !== null ? `, linked to order #${orderId} by its Reference #` : ''}.`);
      result.imported += 1;
      continue;
    }
    const changes = {};
    for (const [col, field] of [['category', 'category'], ['description', 'description'], ['vendor_name', 'vendorName'], ['currency_code', 'currencyCode'], ['expense_date', 'expenseDate']]) {
      const before = String(mine[col] ?? '');
      const after = String(expense[field] ?? '');
      if (before !== after) changes[col] = [before, after];
    }
    if (Math.abs(Number(mine.amount) - Number(expense.amount)) > 0.004) {
      changes.amount = [money(mine.amount), money(expense.amount)];
    }
    const cols = Object.keys(changes);
    if (cols.length === 0) continue;
    const id = Number(mine.id);
    const what = cols.map((col) => `${col} ${changes[col][0]} → ${changes[col][1]}`).join(', ');
    const fy = (await locked(mine.expense_date)) ?? (await locked(expense.expenseDate));
    if (fy !== null) {
      await logOnce(id, zohoId, `Expense ${zohoId} was changed in Zoho Books (${what}), but it is dated in FY ${fy}, which is locked: not changed here — CA to decide.`);
      continue;
    }
    await caExpenseRepository.updateFromZoho(id, expense.category, expense.description, expense.vendorName, Number(expense.amount), expense.currencyCode, expense.expenseDate);
    if (mine.order_id === null) {
      const orderId = await caExpenseRepository.orderIdForReference(expense.referenceNumber);
      if (orderId !== null) await caExpenseRepository.linkToOrder(id, orderId);
    }
    await auditLogRepository.log(triggeredByUserId, 'CA_EXPENSE_UPDATED_FROM_ZOHO', 'ca_expenses', id, cols.join(','),
      JSON.stringify(Object.fromEntries(cols.map((c) => [c, changes[c][0]]))), JSON.stringify(Object.fromEntries(cols.map((c) => [c, changes[c][1]]))), 'Changed in Zoho Books');
    await log(id, 'success', zohoId, `Updated expense ${zohoId} from Zoho Books: ${what}.`);
    result.updated += 1;
  }

  // Deleted in Books. A whole list gone at once is far more likely a wrong
  // organisation or a Books problem than the CA's deletions: nothing is
  // removed then, and the CA is told.
  let gone = [...known.entries()].filter(([zohoId]) => !seen.has(zohoId));
  if (gone.length > 10 && gone.length > known.size / 2) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'error', null, `${gone.length} of the ${known.size} imported expenses are no longer in Zoho Books. Nothing was removed: check that the app is connected to the right Books organisation, then ask the CA.`, triggeredBy, triggeredByUserId);
    result.failed += 1;
    gone = [];
  }
  for (const [zohoId, mine] of gone) {
    const id = Number(mine.id);
    const label = `Expense ${zohoId} (${mine.category}, ${mine.amount} ${mine.currency_code}, ${mine.expense_date})`;
    const fy = await locked(mine.expense_date);
    if (fy !== null) {
      await logOnce(id, zohoId, `${label} was deleted in Zoho Books, but it is dated in FY ${fy}, which is locked: kept here — CA to decide.`);
      continue;
    }
    if (await caExpenseRepository.isBankMatched(id)) {
      await logOnce(id, zohoId, `${label} was deleted in Zoho Books, but it is matched to a bank statement line: kept here — unmatch it in Bank Statement first, then it is removed on the next sync.`);
      continue;
    }
    await caExpenseRepository.remove(id);
    await auditLogRepository.log(triggeredByUserId, 'CA_EXPENSE_REMOVED_FROM_ZOHO', 'ca_expenses', id, null, JSON.stringify(mine), null, 'Deleted in Zoho Books');
    await log(id, 'success', zohoId, `Removed ${label}: it was deleted in Zoho Books.`);
    result.removed += 1;
  }

  if (result.imported + result.updated + result.removed + result.failed === 0) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'skipped', null, 'Nothing new — every expense in Zoho Books is already imported and up to date.', triggeredBy, triggeredByUserId);
    result.skipped = 1;
  }
  return result;
}

function offReason() {
  return zohoGuard.integrationEnabled()
    ? 'Zoho Books is not enabled/configured'
    : 'Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED in .env)';
}

/**
 * Owner decision 2026-10-29: export benefits as Books journals when
 * received (caExportBenefitRepository.forZohoSync()) — sent once, updated
 * when the receipt is corrected, removed from Books when the receipt is
 * cleared. Each logged on its own.
 */
async function syncBenefits(triggeredBy, triggeredByUserId) {
  const result = { journals: 0, removed: 0, failed: 0, skipped: 0 };
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('export_benefit', null, null, null, 'skipped', null, `${offReason()} — nothing synced.`, triggeredBy, triggeredByUserId);
    result.skipped = 1;
    return result;
  }
  const startDate = await zohoBooksService.syncStartDate();
  if (startDate === null && zohoGuard.isProduction()) {
    await zohoSyncLogRepository.log('export_benefit', null, null, null, 'error', null, NO_START_DATE, triggeredBy, triggeredByUserId);
    result.failed = 1;
    return result;
  }
  // Owner decision 2026-10-29: put back what was deleted or changed in Books.
  result.failed += (await zohoRestoreService.benefits(startDate, triggeredBy, triggeredByUserId)).failed;
  for (const benefit of await caExportBenefitRepository.forZohoSync(startDate)) {
    const id = Number(benefit.id);
    const orderId = benefit.order_id !== null ? Number(benefit.order_id) : null;
    const journalId = benefit.zoho_journal_id !== null ? String(benefit.zoho_journal_id) : null;
    const received = benefit.received_amount !== null && Number(benefit.received_amount) > 0;
    const what = `${benefit.scheme_name}${received ? ` ${Number(benefit.received_amount).toFixed(2)}` : ''}`;
    try {
      if (!received) {
        await zohoBooksService.deleteRecord('journal', journalId);
        await caExportBenefitRepository.setZohoJournal(id, null, null);
        await zohoSyncLogRepository.log('export_benefit', 'order', orderId, 'journal', 'success', journalId, `Removed the Zoho Books journal of export benefit ${what} (claim #${id}): its receipt was cleared in the app.`, triggeredBy, triggeredByUserId);
        result.removed += 1;
        continue;
      }
      const hash = crypto.createHash('sha256').update(JSON.stringify([benefit.scheme_name, Math.round(Number(benefit.received_amount) * 100) / 100, String(benefit.received_at), benefit.reference_number, benefit.order_id])).digest('hex');
      if (journalId !== null && benefit.zoho_pushed_hash === hash) {
        continue;
      }
      const newId = await zohoBooksService.pushBenefitJournal(benefit, journalId);
      await caExportBenefitRepository.setZohoJournal(id, newId, hash);
      await zohoSyncLogRepository.log('export_benefit', 'order', orderId, 'journal', 'success', newId, `${zohoRestoreService.note(`ben:${id}`)}${journalId !== null ? 'Updated' : 'Sent'} export benefit ${what} (claim #${id}) received on ${benefit.received_at} as Zoho Books journal.`, triggeredBy, triggeredByUserId);
      result.journals += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('export_benefit', 'order', orderId, 'journal', 'error', journalId, e.message, triggeredBy, triggeredByUserId);
      result.failed += 1;
    }
  }
  if (result.journals + result.removed + result.failed === 0) {
    await zohoSyncLogRepository.log('export_benefit', null, null, null, 'skipped', null, 'Nothing pending — every received export benefit is already in Zoho Books.', triggeredBy, triggeredByUserId);
    result.skipped = 1;
  }
  return result;
}

/**
 * Owner decision 2026-10-31 (GST under LUT, option A): every GST refund the
 * bank credited goes to Books once, as a journal, at the next sync. Credits
 * before the go-live date stay with the CA (opening position). Port of
 * CaSyncService::syncGstRefunds().
 */
async function syncGstRefunds(triggeredBy, triggeredByUserId) {
  const gstRefundRepository = require('../repositories/gstRefundRepository');
  const result = { journals: 0, failed: 0, skipped: 0 };
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('gst_refund', null, null, null, 'skipped', null, `${offReason()} — nothing synced.`, triggeredBy, triggeredByUserId);
    result.skipped = 1;
    return result;
  }
  const startDate = await zohoBooksService.syncStartDate();
  if (startDate === null && zohoGuard.isProduction()) {
    await zohoSyncLogRepository.log('gst_refund', null, null, null, 'error', null, NO_START_DATE, triggeredBy, triggeredByUserId);
    result.failed = 1;
    return result;
  }
  for (const credit of await gstRefundRepository.creditsForZohoSync(startDate)) {
    try {
      const journalId = await zohoBooksService.pushGstRefundJournal(credit);
      await gstRefundRepository.setCreditZohoJournal(Number(credit.id), journalId);
      await zohoSyncLogRepository.log('gst_refund', 'gst_refund_credit', Number(credit.id), 'journal', 'success', journalId, `Sent as Zoho Books journal: ${paymentDescription.gstRefund(credit)}.`, triggeredBy, triggeredByUserId);
      result.journals += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('gst_refund', 'gst_refund_credit', Number(credit.id), 'journal', 'error', null, e.message, triggeredBy, triggeredByUserId);
      result.failed += 1;
    }
  }
  if (result.journals + result.failed === 0) {
    await zohoSyncLogRepository.log('gst_refund', null, null, null, 'skipped', null, 'Nothing pending — every GST refund credited is already in Zoho Books.', triggeredBy, triggeredByUserId);
    result.skipped = 1;
  }
  return result;
}

module.exports = { runFullSync, syncPendingRevenue, syncPurchases, importPendingExpenses, syncBenefits, syncGstRefunds };
