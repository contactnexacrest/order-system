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

/**
 * Owner request (2 Nov 2026): "I am not sure what to put as taxable value — can you prefill
 * taxable value / amount asked now". The supplier bill forms start from the Supplier PO: its
 * value before GST, its GST rate and, on the advance, what is still to ask for (planned advance
 * less what was already paid on this PO's advance proformas). Staff change it when the bill shows
 * a different amount. Mirrors the $__prefill block in PHP views/orders/show.php.
 */
function supplierPrefill(bills, supplierPo, leg) {
  const ref = String(supplierPo.supplier_po_reference || '');
  const basic = Number(supplierPo.basic_value_inr);
  let paidOnProformas = 0;
  for (const b of supplierBills(bills, supplierPo.id)) {
    if (b.bill_for === 'advance' && b.doc_kind === 'proforma') paidOnProformas += Number(b.own_paid || 0);
  }
  const askLeft = leg === 'advance' ? Number(supplierPo.advance_amount_inr || 0) - paidOnProformas : 0;
  return {
    taxable: supplierPo.basic_value_inr,
    gst_rate: supplierPo.gst_rate_pct,
    hint: Number.isFinite(basic) && basic > 0
      ? `Pre-filled from Supplier PO ${ref} — change it if the bill shows a different amount.`
      : "The amount before GST printed on the supplier's bill.",
    asked: askLeft > 0.004 ? askLeft : null,
    asked_hint: `Pre-filled with the advance still to ask for under Supplier PO ${ref} — change it if the proforma asks for a different amount.`,
  };
}

/** The figures the bill form renders from a prefill (same rounding as the PHP view and app.js). */
function prefillFigures(gstRates, prefill) {
  const p = prefill || {};
  const taxable = p.taxable !== null && p.taxable !== undefined && Number(p.taxable) > 0 ? Math.round(Number(p.taxable) * 100) / 100 : null;
  const wanted = p.gst_rate !== null && p.gst_rate !== undefined && p.gst_rate !== '' && Number.isFinite(Number(p.gst_rate)) ? Number(p.gst_rate) : null;
  const known = wanted !== null && (gstRates || []).some((o) => Math.abs(Number(o.option_value) - wanted) < 0.001);
  let rateUsed = known ? wanted : null;
  if (rateUsed === null) for (const o of gstRates || []) if (o.is_default) rateUsed = Number(o.option_value);
  const gst = taxable !== null ? Math.round(taxable * Number(rateUsed || 0)) / 100 : 0;
  const asked = p.asked !== null && p.asked !== undefined && Number(p.asked) > 0 ? Math.round(Number(p.asked) * 100) / 100 : null;
  return {
    taxable: taxable !== null ? taxable.toFixed(2) : null,
    rateKnown: known,
    isSelected: (o) => (known ? Math.abs(Number(o.option_value) - wanted) < 0.001 : !!o.is_default),
    gst: gst.toFixed(2),
    total: ((taxable || 0) + gst).toFixed(2),
    asked: asked !== null ? asked.toFixed(2) : null,
    hint: p.hint || null,
    askedHint: p.asked_hint || null,
  };
}

/**
 * Owner request (2 Nov 2026): Step 9a's Freight Forwarder drop-down starts on the partner already
 * used on this order — the forwarder already saved on the order; else the one partner the order's
 * logistics bills come from; else, among several, the only one whose service is freight
 * forwarding. Null when none of these picks exactly one (still changeable). Mirrors PHP
 * LogisticsPartnerRepository::suggestedForwarderId.
 */
function suggestedForwarderId(freight, orderBills, partners) {
  const offered = new Map((partners || []).map((p) => [Number(p.id), p]));
  const saved = Number((freight && freight.freight_forwarder_partner_id) || 0);
  if (saved > 0 && offered.has(saved)) return saved;
  const used = [...new Set((orderBills || [])
    .filter((b) => b.vendor_kind === 'logistics' && Number(b.logistics_partner_id) > 0 && offered.has(Number(b.logistics_partner_id)))
    .map((b) => Number(b.logistics_partner_id)))];
  if (used.length === 1) return used[0];
  const forwarders = used.filter((id) => offered.get(id).service_type === 'freight_forwarder');
  return forwarders.length === 1 ? forwarders[0] : null;
}

module.exports = { supplierBills, supplierLeg, supplierCarried, replaceable, ids, logistics, unpaid, totals, supplierPrefill, prefillFigures, suggestedForwarderId };
