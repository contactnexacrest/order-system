'use strict';

const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const orderVendorBillRepository = require('../repositories/orderVendorBillRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');

/**
 * Port of App\Services\OrderLedgerService. Payment Overview → Ledger: every
 * money event recorded against one order, read from the order's own
 * records (nothing borrowed from a master list).
 *
 * Owner decision (2026-10-26): the full picture — buyer receipts with the
 * day's rate and INR value, supplier advance/balance paid, every expense
 * (ECGC, inspection, travel, CHA, ...), export benefits received, and the
 * net — only for holders of manage_order_financials. Everyone else sees
 * the buyer-receipt events, as before.
 */

const LEGS = { advance: 'Advance', freight: 'Freight', balance: 'Balance' };

const str = (value) => (value === null || value === undefined ? '' : String(value));

function info(date, event, amount, currency, source) {
  return { date: str(date), event, direction: 'info', amount, currency, rate: null, inr: null, inr_basis: null, source };
}

function out(date, event, amountInr, source) {
  return {
    date: str(date), event, direction: 'out', amount: amountInr, currency: 'INR', rate: null,
    inr: amountInr !== null && amountInr !== undefined ? parseFloat(amountInr) : 0, inr_basis: 'actual', source,
  };
}

// Stable sort on the calendar day — same-day events keep their recorded order
// (a remittance before its clearing), matching PHP's usort.
function sorted(rows) {
  return rows
    .map((row, i) => ({ row, i }))
    .sort((a, b) => a.row.date.slice(0, 10).localeCompare(b.row.date.slice(0, 10)) || a.i - b.i)
    .map(({ row }) => row);
}

/**
 * @returns {Promise<{rows: object[], totals: object|null}>} each row: date, event, direction
 *   ('in'|'out'|'info'), amount (in its currency), currency, rate, inr (counted in the totals, or null), source
 */
async function forOrder(orderId, orderCurrency, full) {
  let rows = [];
  const payment = (await orderPaymentStatusRepository.find(orderId)) || {};
  for (const [leg, label] of Object.entries(LEGS)) {
    const amount = payment[`${leg}_amount`] ?? null;
    const rate = payment[`${leg}_exchange_rate`] ?? null;
    const inrActual = payment[`${leg}_inr_actual`] ?? null;
    if (payment[`${leg}_remittance_received_at`]) {
      let inr = null;
      if (inrActual !== null) {
        inr = parseFloat(inrActual);
      } else if (rate !== null && amount !== null) {
        inr = parseFloat(amount) * parseFloat(rate);
      }
      rows.push({
        date: str(payment[`${leg}_remittance_received_at`]),
        event: `${label} remittance received from buyer`,
        direction: 'in',
        amount,
        currency: orderCurrency,
        rate,
        inr,
        inr_basis: inrActual !== null ? 'actual' : (inr !== null ? 'rate' : null),
        source: 'Staff-recorded',
      });
    }
    if (payment[`${leg}_cleared_at`]) {
      rows.push(info(payment[`${leg}_cleared_at`], `${label} cleared (funds confirmed)`, amount, orderCurrency, 'Staff-recorded'));
    }
  }
  for (const report of await clientPaymentReportRepository.forOrder(orderId)) {
    const type = str(report.payment_type);
    rows.push(info(
      report.payment_date || report.reported_at,
      `${type.charAt(0).toUpperCase()}${type.slice(1)} payment reported by the client`,
      report.amount,
      orderCurrency,
      report.status === 'reviewed' ? 'Client-reported (reviewed)' : 'Client-reported (new)'
    ));
  }

  if (!full) {
    return { rows: sorted(rows), totals: null };
  }

  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  if (supplierPo) {
    for (const [leg, label] of [['advance', 'Advance'], ['balance', 'Balance']]) {
      if (supplierPo[`${leg}_paid_at`]) {
        const paid = supplierPo[`${leg}_paid_amount_inr`] ?? supplierPo[`${leg}_amount_inr`] ?? null;
        rows.push(out(supplierPo[`${leg}_paid_at`], `Supplier ${label} paid${supplierPo.supplier_legal_name ? ` — ${supplierPo.supplier_legal_name}` : ''}`, paid, `Supplier PO ${supplierPo.supplier_po_reference || ''}`));
      }
    }
  }
  // Owner decisions (2026-10-27): logistics partners' bills paid, and the
  // order's actual expenses. Real travel expenses replace the provisional
  // travel % line.
  for (const bill of await orderVendorBillRepository.forOrder(orderId, 'logistics')) {
    if (bill.paid_at !== null) {
      rows.push(out(
        bill.paid_at,
        `Logistics paid — ${bill.partner_name || 'Partner'} (${orderVendorBillRepository.LOGISTICS_SERVICES[bill.bill_for] || bill.bill_for}, bill ${bill.bill_no})`,
        bill.paid_amount_inr,
        `Logistics bill · ${bill.payment_mode} ${bill.payment_ref}`
      ));
    }
  }
  const expenseSummary = await orderExpenseRepository.summary(orderId);
  for (const expense of await orderExpenseRepository.forOrder(orderId)) {
    rows.push(out(
      expense.expense_date,
      `Expense — ${orderExpenseRepository.CATEGORIES[expense.category] || expense.category}: ${expense.details}`,
      expense.amount_inr,
      expense.receipt_file_id ? 'Order expense (receipt)' : 'Order expense (no receipt)'
    ));
  }
  for (const entry of await orderCostEntryRepository.forOrder(orderId)) {
    if (entry.category === 'travel_expenses' && expenseSummary.has_travel) continue;
    const label = orderCostEntryRepository.CATEGORIES[entry.category] || entry.category;
    rows.push(out(entry.incurred_at || entry.created_at, `Expense — ${label}${entry.description ? `: ${entry.description}` : ''}`, entry.amount_inr, 'Order cost entry'));
  }
  for (const benefit of await caExportBenefitRepository.forOrder(orderId)) {
    rows.push(info(benefit.claimed_at, `Export benefit claimed — ${benefit.scheme_name}`, benefit.claimed_amount, str(benefit.currency_code), 'Export benefit claim'));
    if (benefit.received_amount !== null) {
      const isInr = str(benefit.currency_code).toUpperCase() === 'INR';
      rows.push({
        date: str(benefit.received_at || benefit.claimed_at),
        event: `Export benefit received — ${benefit.scheme_name}`,
        direction: 'in',
        amount: benefit.received_amount,
        currency: str(benefit.currency_code),
        rate: null,
        inr: isInr ? parseFloat(benefit.received_amount) : null,
        inr_basis: isInr ? 'actual' : null,
        source: 'Export benefit',
      });
    }
  }

  rows = sorted(rows);
  const totals = { buyer_foreign: 0, buyer_inr: 0, buyer_without_rate: 0, benefits_inr: 0, supplier_inr: 0, logistics_inr: 0, expenses_inr: 0 };
  for (const row of rows) {
    if (row.direction === 'in' && row.source === 'Staff-recorded') {
      totals.buyer_foreign += parseFloat(row.amount) || 0;
      if (row.inr === null) {
        totals.buyer_without_rate++;
      } else {
        totals.buyer_inr += row.inr;
      }
    } else if (row.direction === 'in' && row.inr !== null) {
      totals.benefits_inr += row.inr;
    } else if (row.direction === 'out') {
      totals[row.event.startsWith('Supplier ') ? 'supplier_inr' : (row.event.startsWith('Logistics ') ? 'logistics_inr' : 'expenses_inr')] += row.inr;
    }
  }
  totals.net_inr = totals.buyer_inr + totals.benefits_inr - totals.supplier_inr - totals.logistics_inr - totals.expenses_inr;
  return { rows, totals };
}

module.exports = { forOrder };
