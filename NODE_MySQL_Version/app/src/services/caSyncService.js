'use strict';

const caRepository = require('../repositories/caRepository');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderZohoInvoiceRepository = require('../repositories/orderZohoInvoiceRepository');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
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

  let failed = 0;
  let invoices = 0;
  for (const item of await orderZohoInvoiceRepository.documentsToSync()) {
    const label = zohoInvoiceData.KIND_LABELS[item.kind];
    try {
      const invoice = await zohoInvoiceData.build(item.order_id, item.kind, item.document_id);
      const hash = zohoInvoiceData.fingerprint(invoice);
      const known = await orderZohoInvoiceRepository.find(item.order_id, item.kind);
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
    if (await caExpenseRepository.existsByZohoId(expense.zohoExpenseId)) {
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

module.exports = { runFullSync, syncPendingRevenue, importPendingExpenses };
