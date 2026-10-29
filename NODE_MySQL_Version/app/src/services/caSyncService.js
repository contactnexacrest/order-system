'use strict';

const caRepository = require('../repositories/caRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const zohoPurchaseRepository = require('../repositories/zohoPurchaseRepository');
const zohoBooksService = require('../services/zohoBooksService');
const zohoGuard = require('../services/zohoGuard');
const zohoInvoiceData = require('../services/zohoInvoiceData');

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
  return {
    revenue: await syncPendingRevenue(triggeredBy, triggeredByUserId),
    // Stage 3 runs before the expense import, so the order expenses it sends
    // are already marked and the import skips them.
    purchases: await syncPurchases(triggeredBy, triggeredByUserId),
    expenses: await importPendingExpenses(triggeredBy, triggeredByUserId),
  };
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

  let failed = 0;
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
      await zohoSyncLogRepository.log('invoice', 'order', item.order_id, item.kind, 'success', pushed.zoho_invoice_id, `${what} ${label} ${invoice.invoice_number} as Zoho Books invoice (${invoice.currency_code} ${invoice.total.toFixed(2)} @ ${invoice.exchange_rate}).`, triggeredBy, triggeredByUserId);
      invoices += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('invoice', 'order', item.order_id, item.kind, 'error', null, e.message, triggeredBy, triggeredByUserId);
      failed += 1;
    }
  }

  let synced = 0;
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
        target ? target.zoho_invoice_number : null
      );
      const zohoReference = result.payment_id;
      await markSynced(leg.order_id, leg.leg, zohoReference);
      if (result.applied > 0) {
        await orderPaymentStatusRepository.setLegZohoApplied(leg.order_id, leg.leg);
        applied += 1;
      }
      await zohoSyncLogRepository.log('revenue_payment', 'order_payment_status', leg.order_id, leg.leg, 'success', zohoReference, `Pushed as Zoho Books customer payment ${zohoReference}${appliedNote(leg.leg, target, result.applied)}`, triggeredBy, triggeredByUserId);
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
        target.zoho_invoice_number
      );
      await orderPaymentStatusRepository.setLegZohoApplied(leg.order_id, leg.leg);
      await zohoSyncLogRepository.log('payment_apply', 'order_payment_status', leg.order_id, leg.leg, 'success', String(leg.zoho_reference), `Applied ${amount.toFixed(2)} of payment ${leg.zoho_reference} to ${zohoInvoiceData.KIND_LABELS[LEG_INVOICE[leg.leg]]} ${target.zoho_invoice_number}.`, triggeredBy, triggeredByUserId);
      applied += 1;
    } catch (e) {
      await zohoSyncLogRepository.log('payment_apply', 'order_payment_status', leg.order_id, leg.leg, 'error', String(leg.zoho_reference), e.message, triggeredBy, triggeredByUserId);
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
const LEG_INVOICE = { advance: 'ci', balance: 'ci', freight: 'dn' };

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

  for (const bill of await zohoPurchaseRepository.billsToSync(startDate)) {
    try {
      const zohoBillId = await zohoBooksService.pushBill(bill);
      await zohoPurchaseRepository.setBillSynced(bill.id, zohoBillId);
      await log('vendor_bill', bill.order_id, 'bill', 'success', zohoBillId, `Sent bill ${bill.bill_no} from ${bill.vendor_name} (INR ${money(bill.total_inr)}) as Zoho Books bill.`);
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
      await log('vendor_payment', bill.order_id, 'payment', 'success', pushed.payment_id, `Pushed payment ${payment.payment_ref} (INR ${money(payment.amount_inr)}) to ${bill.vendor_name} as Zoho Books vendor payment${note}`);
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
      await log('order_expense', expense.order_id, 'expense', 'success', zohoExpenseId, `Sent expense "${expense.details}" (INR ${money(expense.amount_inr)}) as Zoho Books expense.`);
      result.expenses += 1;
    } catch (e) {
      await log('order_expense', expense.order_id, 'expense', 'error', null, e.message);
      result.failed += 1;
    }
  }

  if (result.bills + result.payments + result.applied + result.expenses + result.failed === 0) {
    await zohoSyncLogRepository.log('vendor_bill', null, null, null, 'skipped', null, 'Nothing pending — every tax invoice, vendor payment and order expense is already in Zoho Books.', triggeredBy, triggeredByUserId);
    result.skipped = 1;
  }
  return result;
}

async function importPendingExpenses(triggeredBy, triggeredByUserId) {
  if (!(await zohoBooksService.isEnabled())) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'skipped', null, `${offReason()} — nothing imported.`, triggeredBy, triggeredByUserId);
    return { imported: 0, failed: 0, skipped: 1 };
  }

  let expenses;
  try {
    expenses = await zohoBooksService.listExpenses();
  } catch (e) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'error', null, e.message, triggeredBy, triggeredByUserId);
    return { imported: 0, failed: 1, skipped: 0 };
  }

  let imported = 0;
  for (const expense of expenses) {
    // Stage 3: an order expense this app sent to Books is already here as
    // that order expense — never import it a second time.
    if ((await caExpenseRepository.existsByZohoId(expense.zohoExpenseId)) || (await zohoPurchaseRepository.isPushedExpense(expense.zohoExpenseId))) {
      continue;
    }
    const id = await caExpenseRepository.insert(
      expense.zohoExpenseId,
      expense.category,
      expense.description,
      expense.vendorName,
      expense.amount,
      expense.currencyCode,
      expense.expenseDate
    );
    await zohoSyncLogRepository.log(
      'expense_import',
      'ca_expenses',
      id,
      null,
      'success',
      expense.zohoExpenseId,
      `Imported expense ${expense.zohoExpenseId} (${expense.category}, ${expense.amount} ${expense.currencyCode}).`,
      triggeredBy,
      triggeredByUserId
    );
    imported += 1;
  }

  if (imported === 0) {
    await zohoSyncLogRepository.log('expense_import', null, null, null, 'skipped', null, 'Nothing new — every expense in Zoho Books is already imported.', triggeredBy, triggeredByUserId);
    return { imported: 0, failed: 0, skipped: 1 };
  }

  return { imported, failed: 0, skipped: 0 };
}

function offReason() {
  return zohoGuard.integrationEnabled()
    ? 'Zoho Books is not enabled/configured'
    : 'Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED in .env)';
}

module.exports = { runFullSync, syncPendingRevenue, syncPurchases, importPendingExpenses };
