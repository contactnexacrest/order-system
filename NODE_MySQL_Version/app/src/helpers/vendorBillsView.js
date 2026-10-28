'use strict';

/**
 * Owner decisions (2026-10-27): small lookups the order page needs for the
 * vendor bills (supplier 7e / 10a, logistics partners in Group C) — Nunjucks
 * can't filter by several fields or sum inside a loop. Mirrors the inline
 * array_filter / array_sum calls in PHP views/orders/show.php. The bills are
 * the enriched ones from orderVendorBillRepository.forOrder.
 */
function supplierBills(bills, supplierPoId) {
  return (bills || []).filter((b) => b.vendor_kind === 'supplier' && Number(b.supplier_po_id) === Number(supplierPoId));
}

/**
 * The bills shown on one supplier leg. Owner decision (2026-10-28, Option B):
 * a 7e tax invoice not yet fully paid is shown in 10a as well (first), so its
 * balance is paid from there.
 */
function supplierLeg(bills, supplierPoId, leg) {
  const po = supplierBills(bills, supplierPoId);
  return [...supplierCarried(bills, supplierPoId, leg), ...po.filter((b) => b.bill_for === leg)];
}

/** The 7e tax invoices carried into 10a until fully paid. */
function supplierCarried(bills, supplierPoId, leg) {
  if (leg !== 'balance') return [];
  return supplierBills(bills, supplierPoId).filter((b) => b.bill_for === 'advance' && b.doc_kind === 'invoice' && !b.settled);
}

/** The proformas a new tax invoice can replace — not already replaced. */
function replaceable(bills) {
  return (bills || []).filter((b) => b.doc_kind === 'proforma' && b.replaced_by === null);
}

function ids(bills) {
  return (bills || []).map((b) => Number(b.id));
}

function logistics(bills) {
  return (bills || []).filter((b) => b.vendor_kind === 'logistics');
}

function unpaid(bills) {
  return (bills || []).filter((b) => !b.settled);
}

/** cost: tax invoices, plus what is paid on a proforma not yet replaced; paid: on the bills themselves; due: what is still owed. */
function totals(bills) {
  let cost = 0;
  let paid = 0;
  let due = 0;
  for (const b of bills || []) {
    cost += b.cost_inr;
    paid += b.own_paid;
    due += b.remaining;
  }
  return { cost, paid, due: due > 0.004 ? due : 0 };
}

module.exports = { supplierBills, supplierLeg, supplierCarried, replaceable, ids, logistics, unpaid, totals };
