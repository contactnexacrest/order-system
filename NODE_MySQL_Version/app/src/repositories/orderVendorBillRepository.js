'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\OrderVendorBillRepository. Owner decisions
 * (2026-10-27): whoever asks NexaCrest for money sends a bill — the supplier
 * (Step 7e advance, Step 10a balance) and the logistics partners (CHA,
 * transport, freight forwarder). One row per bill, then the payment against
 * it. docs/schema.sql Section CE.
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

const PAYMENT_MODES = ['NEFT / RTGS', 'IMPS', 'UPI', 'Cheque'];

async function create(orderId, vendorKind, data, userId) {
  const result = await db.execute(
    `INSERT INTO order_vendor_bills (order_id, vendor_kind, supplier_po_id, logistics_partner_id, bill_for, payment_kind, bill_no, bill_date,
        taxable_inr, gst_inr, total_inr, note, bill_file_id, created_by)
     VALUES (:order_id, :vendor_kind, :supplier_po_id, :logistics_partner_id, :bill_for, :payment_kind, :bill_no, :bill_date,
        :taxable_inr, :gst_inr, :total_inr, :note, :bill_file_id, :created_by)`,
    {
      order_id: orderId,
      vendor_kind: vendorKind,
      supplier_po_id: data.supplier_po_id ?? null,
      logistics_partner_id: data.logistics_partner_id ?? null,
      bill_for: data.bill_for,
      payment_kind: data.payment_kind || 'final',
      bill_no: data.bill_no,
      bill_date: data.bill_date,
      taxable_inr: data.taxable_inr,
      gst_inr: data.gst_inr,
      total_inr: Math.round((Number(data.taxable_inr) + Number(data.gst_inr)) * 100) / 100,
      note: data.note ?? null,
      bill_file_id: data.bill_file_id ?? null,
      created_by: userId,
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_vendor_bills WHERE id = :id', { id });
}

/** Oldest first, with the logistics partner's name joined in. */
async function forOrder(orderId, vendorKind = null) {
  let sql = `SELECT b.*, lp.partner_name, lp.service_type AS partner_service_type
             FROM order_vendor_bills b
             LEFT JOIN logistics_partners lp ON lp.id = b.logistics_partner_id
             WHERE b.order_id = :order_id`;
  const params = { order_id: orderId };
  if (vendorKind !== null) {
    sql += ' AND b.vendor_kind = :kind';
    params.kind = vendorKind;
  }
  return db.query(`${sql} ORDER BY b.bill_date, b.id`, params);
}

async function markPaid(id, paidAt, amount, mode, reference, userId) {
  await db.execute(
    `UPDATE order_vendor_bills SET paid_at = :paid_at, paid_amount_inr = :amount, payment_mode = :mode, payment_ref = :ref, paid_by = :user_id
     WHERE id = :id AND paid_at IS NULL`,
    { paid_at: paidAt, amount, mode, ref: reference, user_id: userId, id }
  );
}

/** Only an unpaid bill can be removed — a paid one is part of the money record. */
async function deleteUnpaid(id) {
  const result = await db.execute('DELETE FROM order_vendor_bills WHERE id = :id AND paid_at IS NULL', { id });
  return result.affectedRows > 0;
}

/** [total paid, latest paid date] on one supplier leg. */
async function supplierLegPaid(supplierPoId, leg) {
  const row = await db.queryOne(
    `SELECT COALESCE(SUM(paid_amount_inr), 0) AS paid, MAX(paid_at) AS last_paid
     FROM order_vendor_bills WHERE vendor_kind = 'supplier' AND supplier_po_id = :po AND bill_for = :leg AND paid_at IS NOT NULL`,
    { po: supplierPoId, leg }
  );
  return [parseFloat(row.paid), row.last_paid !== null ? String(row.last_paid) : null];
}

module.exports = { SUPPLIER_LEGS, LOGISTICS_SERVICES, LOGISTICS_PAYMENT_KINDS, PAYMENT_MODES, create, find, forOrder, markPaid, deleteUnpaid, supplierLegPaid };
