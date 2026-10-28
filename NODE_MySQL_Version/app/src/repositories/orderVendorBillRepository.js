'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\OrderVendorBillRepository. Owner decisions
 * (2026-10-27): whoever asks NexaCrest for money sends a bill — the supplier
 * (Step 7e advance, Step 10a balance) and the logistics partners (CHA,
 * transport, freight forwarder). One row per bill, then the payments against
 * it. docs/schema.sql Sections CE and CH.
 *
 * Owner decision (2026-10-28, Option B): a bill is a Proforma (an advance
 * request — asks for requested_amount_inr, not a cost) or a Tax invoice (the
 * real bill — the cost). A tax invoice can replace an earlier proforma from
 * the same vendor, so what was paid on the proforma counts towards it. A
 * bill can take several payments (order_vendor_bill_payments).
 */
const SUPPLIER_LEGS = { advance: 'Advance', balance: 'Balance' };

const LOGISTICS_SERVICES = {
  cha: 'CHA charges',
  transport: 'Transport',
  cha_transport: 'CHA + Transport (one bill)',
  ocean_freight: 'Ocean freight',
  other: 'Other',
};

/** Owner decision (2026-10-27): a logistics bill is an Advance (payable any time) or the Final bill (on CFR/CIF, after Step 9d). */
const LOGISTICS_PAYMENT_KINDS = { advance: 'Advance', final: 'Final' };

/** Owner decision (2026-10-28, Option B). */
const DOC_KINDS = { proforma: 'Proforma', invoice: 'Tax invoice' };

const PAYMENT_MODES = ['NEFT / RTGS', 'IMPS', 'UPI', 'Cheque'];

const round2 = (n) => Math.round(n * 100) / 100;

async function create(orderId, vendorKind, data, userId) {
  const result = await db.execute(
    `INSERT INTO order_vendor_bills (order_id, vendor_kind, supplier_po_id, logistics_partner_id, bill_for, payment_kind, doc_kind, requested_amount_inr, replaces_bill_id,
        bill_no, bill_date, taxable_inr, gst_inr, total_inr, note, bill_file_id, created_by)
     VALUES (:order_id, :vendor_kind, :supplier_po_id, :logistics_partner_id, :bill_for, :payment_kind, :doc_kind, :requested_amount_inr, :replaces_bill_id,
        :bill_no, :bill_date, :taxable_inr, :gst_inr, :total_inr, :note, :bill_file_id, :created_by)`,
    {
      order_id: orderId,
      vendor_kind: vendorKind,
      supplier_po_id: data.supplier_po_id ?? null,
      logistics_partner_id: data.logistics_partner_id ?? null,
      bill_for: data.bill_for,
      payment_kind: data.payment_kind || 'final',
      doc_kind: data.doc_kind || 'invoice',
      requested_amount_inr: data.requested_amount_inr ?? null,
      replaces_bill_id: data.replaces_bill_id ?? null,
      bill_no: data.bill_no,
      bill_date: data.bill_date,
      taxable_inr: data.taxable_inr,
      gst_inr: data.gst_inr,
      total_inr: round2(Number(data.taxable_inr) + Number(data.gst_inr)),
      note: data.note ?? null,
      bill_file_id: data.bill_file_id ?? null,
      created_by: userId,
    }
  );
  await refreshOrder(orderId);
  return result.insertId;
}

async function find(id) {
  const row = await db.queryOne('SELECT order_id FROM order_vendor_bills WHERE id = :id', { id });
  if (!row) return null;
  return (await forOrder(row.order_id)).find((b) => Number(b.id) === Number(id)) || null;
}

/** Payments by bill id, oldest first. */
async function paymentsForOrder(orderId) {
  const rows = await db.query(
    `SELECT p.* FROM order_vendor_bill_payments p
     JOIN order_vendor_bills b ON b.id = p.bill_id
     WHERE b.order_id = :order_id
     ORDER BY p.paid_at, p.id`,
    { order_id: orderId }
  );
  const byBill = {};
  for (const p of rows) (byBill[p.bill_id] = byBill[p.bill_id] || []).push(p);
  return byBill;
}

function enrich(bills, paymentsByBill) {
  const byId = new Map();
  for (const row of bills) {
    const b = { ...row, doc_kind: row.doc_kind || 'invoice', payments: paymentsByBill[row.id] || [] };
    b.own_paid = round2(b.payments.reduce((sum, p) => sum + Number(p.amount_inr), 0));
    byId.set(Number(b.id), b);
  }
  const replacedBy = new Map();
  for (const b of byId.values()) {
    if (b.doc_kind === 'invoice' && b.replaces_bill_id && byId.has(Number(b.replaces_bill_id))) {
      replacedBy.set(Number(b.replaces_bill_id), b);
    }
  }
  for (const [id, b] of byId) {
    const replacing = replacedBy.get(id) || null;
    b.replaced_by = replacing ? String(replacing.bill_no) : null;
    b.replaced_by_id = replacing ? Number(replacing.id) : null;
    const replaces = b.replaces_bill_id ? byId.get(Number(b.replaces_bill_id)) : undefined;
    b.replaces_bill_no = replaces ? String(replaces.bill_no) : null;
    b.carried_payments = [];
    let credited = b.own_paid;
    if (b.doc_kind === 'invoice' && replaces) {
      b.carried_payments = replaces.payments;
      credited += replaces.own_paid;
    }
    b.credited = round2(credited);
    b.amount_due = b.doc_kind === 'proforma' ? Number(b.requested_amount_inr ?? b.total_inr) : Number(b.total_inr);
    b.remaining = replacing ? 0 : round2(Math.max(0, b.amount_due - b.credited));
    b.settled = b.remaining <= 0.004;
    b.cost_inr = b.doc_kind === 'invoice' ? Number(b.total_inr) : (replacing ? 0 : b.own_paid);
  }
  return [...byId.values()];
}

/**
 * Oldest first, with the logistics partner's name joined in, each bill's
 * payments, and its worked-out figures:
 *  - payments / own_paid: the payments made against this bill itself;
 *  - credited: own_paid, plus — for a tax invoice — what was paid on the
 *    proforma(s) it replaces;
 *  - amount_due: a proforma's requested amount, a tax invoice's total;
 *  - remaining: what is still owed on it (0 once a proforma is replaced);
 *  - replaced_by: the tax invoice's bill no., when a proforma is replaced;
 *  - cost_inr: what it adds to the order's cost — a tax invoice's total,
 *    or what was paid on a proforma not (yet) replaced by an invoice.
 */
async function forOrder(orderId, vendorKind = null) {
  const rows = await db.query(
    `SELECT b.*, lp.partner_name, lp.service_type AS partner_service_type
     FROM order_vendor_bills b
     LEFT JOIN logistics_partners lp ON lp.id = b.logistics_partner_id
     WHERE b.order_id = :order_id
     ORDER BY b.bill_date, b.id`,
    { order_id: orderId }
  );
  const bills = enrich(rows, await paymentsForOrder(orderId));
  return vendorKind === null ? bills : bills.filter((b) => b.vendor_kind === vendorKind);
}

/**
 * Records one payment against a bill. leg is the supplier step it was paid
 * from ('advance' — Step 7e, 'balance' — Step 10a); null for a logistics bill.
 */
async function addPayment(billId, leg, paidAt, amount, mode, reference, userId) {
  await db.execute(
    `INSERT INTO order_vendor_bill_payments (bill_id, leg, paid_at, amount_inr, payment_mode, payment_ref, paid_by)
     VALUES (:bill_id, :leg, :paid_at, :amount, :mode, :ref, :user_id)`,
    { bill_id: billId, leg, paid_at: paidAt, amount, mode, ref: reference, user_id: userId }
  );
  const row = await db.queryOne('SELECT order_id FROM order_vendor_bills WHERE id = :id', { id: billId });
  await refreshOrder(row.order_id);
}

/**
 * Keeps each bill's summary columns in step with its payments: paid_at is
 * the day it was settled (NULL while anything is still owed),
 * paid_amount_inr the total paid on the bill itself, payment_mode /
 * payment_ref / paid_by the latest payment's.
 */
async function refreshOrder(orderId) {
  const bills = await forOrder(orderId);
  const byId = new Map(bills.map((b) => [Number(b.id), b]));
  for (const b of bills) {
    const last = b.payments.length ? b.payments[b.payments.length - 1] : null;
    let settledOn = null;
    if (b.settled) {
      const dates = [...b.payments, ...b.carried_payments].map((p) => String(p.paid_at));
      if (b.replaced_by_id !== null) dates.push(String(byId.get(b.replaced_by_id).bill_date));
      settledOn = dates.length ? dates.sort()[dates.length - 1] : null;
    }
    await db.execute(
      'UPDATE order_vendor_bills SET paid_at = :paid_at, paid_amount_inr = :paid, payment_mode = :mode, payment_ref = :ref, paid_by = :paid_by WHERE id = :id',
      {
        paid_at: settledOn,
        paid: b.own_paid > 0 ? b.own_paid : null,
        mode: last ? last.payment_mode : null,
        ref: last ? last.payment_ref : null,
        paid_by: last ? last.paid_by : null,
        id: b.id,
      }
    );
  }
}

/** Only a bill with no payment on it can be removed — a paid one is part of the money record. */
async function deleteUnpaid(id) {
  const row = await db.queryOne('SELECT order_id FROM order_vendor_bills WHERE id = :id', { id });
  const result = await db.execute(
    'DELETE FROM order_vendor_bills WHERE id = :id AND NOT EXISTS (SELECT 1 FROM order_vendor_bill_payments p WHERE p.bill_id = :id2)',
    { id, id2: id }
  );
  if (result.affectedRows === 0) return false;
  await refreshOrder(row.order_id);
  return true;
}

/** [total paid, latest paid date] on one supplier leg (the step the payments were made from). */
async function supplierLegPaid(supplierPoId, leg) {
  const row = await db.queryOne(
    `SELECT COALESCE(SUM(p.amount_inr), 0) AS paid, MAX(p.paid_at) AS last_paid
     FROM order_vendor_bill_payments p
     JOIN order_vendor_bills b ON b.id = p.bill_id
     WHERE b.vendor_kind = 'supplier' AND b.supplier_po_id = :po AND p.leg = :leg`,
    { po: supplierPoId, leg }
  );
  return [parseFloat(row.paid), row.last_paid !== null ? String(row.last_paid) : null];
}

/** The proformas a new tax invoice from the same vendor can replace — not already replaced. */
async function replaceableProformas(orderId, vendorKind) {
  return (await forOrder(orderId, vendorKind)).filter((b) => b.doc_kind === 'proforma' && b.replaced_by === null);
}

/** Every bill (supplier or logistics) on the order that still has something owed. */
async function unpaidForOrder(orderId) {
  return (await forOrder(orderId)).filter((b) => !b.settled);
}

module.exports = {
  SUPPLIER_LEGS, LOGISTICS_SERVICES, LOGISTICS_PAYMENT_KINDS, DOC_KINDS, PAYMENT_MODES,
  create, find, forOrder, addPayment, refreshOrder, deleteUnpaid, supplierLegPaid, replaceableProformas, unpaidForOrder,
};
