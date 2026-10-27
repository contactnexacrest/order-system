'use strict';

/**
 * Owner decisions (2026-10-27): small lookups the order page needs for the
 * vendor bills (supplier 7e / 10a, logistics partners in Group C) — Nunjucks
 * can't filter by several fields or sum inside a loop. Mirrors the inline
 * array_filter / array_sum calls in PHP views/orders/show.php.
 */
function supplierLeg(bills, supplierPoId, leg) {
  return (bills || []).filter((b) => b.vendor_kind === 'supplier' && Number(b.supplier_po_id) === Number(supplierPoId) && b.bill_for === leg);
}

function logistics(bills) {
  return (bills || []).filter((b) => b.vendor_kind === 'logistics');
}

function unpaid(bills) {
  return (bills || []).filter((b) => b.paid_at === null);
}

function totals(bills) {
  let total = 0;
  let paid = 0;
  for (const b of bills || []) {
    total += parseFloat(b.total_inr) || 0;
    paid += parseFloat(b.paid_amount_inr) || 0;
  }
  return { total, paid, due: total - paid > 0.004 ? total - paid : 0 };
}

module.exports = { supplierLeg, logistics, unpaid, totals };
