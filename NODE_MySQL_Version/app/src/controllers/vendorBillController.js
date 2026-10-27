'use strict';

const flash = require('../helpers/flash');
const orderStorage = require('../helpers/orderStorage');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const logisticsPartnerRepository = require('../repositories/logisticsPartnerRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderRepository = require('../repositories/orderRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../repositories/orderVendorBillRepository');
const caFyLockGuard = require('../services/caFyLockGuard');
const fileUploadService = require('../services/fileUploadService');
const stageGateService = require('../services/stageGateService');

/**
 * Port of App\Controllers\VendorBillController. Owner decisions (2026-10-27):
 * bill first, then pay. The supplier's bills (Step 7e advance, Step 10a
 * balance) and the logistics partners' bills (CHA, transport, freight
 * forwarder — Group C, every incoterm). On CFR/CIF orders a logistics bill can
 * be paid only after the buyer's Debit Note payment has cleared (Step 9d).
 */
const ANCHORS = { advance: 'step-7e-supplier-advance-paid', balance: 'step-10a-supplier-balance-paid', logistics: 'logistics-bills' };

const str = (v) => (v === undefined || v === null ? '' : String(v).trim());
const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function back(req, res, orderId, anchor, type, message) {
  flash.set(req, type, message);
  res.redirect(`/orders/${orderId}#${anchor}`);
}

function validDate(date) {
  const m = date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return false;
  const d = new Date(`${date}T00:00:00Z`);
  return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() + 1 === Number(m[2]) && d.getUTCDate() === Number(m[3]);
}

/** The bill fields both kinds share — the cleaned fields, or an error message (string). */
function billFields(req) {
  const billNo = str(req.body.bill_no);
  if (billNo === '') return 'Enter the bill / invoice number.';
  const billDate = str(req.body.bill_date);
  if (!validDate(billDate) || billDate > todayIst()) return 'Enter the bill date (not in the future).';
  const taxable = str(req.body.taxable_inr);
  if (taxable === '' || Number.isNaN(Number(taxable)) || Number(taxable) <= 0) return 'Enter the taxable value of the bill (₹).';
  const gst = str(req.body.gst_inr) || '0';
  if (Number.isNaN(Number(gst)) || Number(gst) < 0) return 'Enter the GST on the bill (₹), or 0.';
  if (!req.file) return 'Attach the bill / invoice.';
  const note = str(req.body.note);
  return {
    bill_no: billNo.slice(0, 100),
    bill_date: billDate,
    taxable_inr: Math.round(Number(taxable) * 100) / 100,
    gst_inr: Math.round(Number(gst) * 100) / 100,
    note: note !== '' ? note.slice(0, 500) : null,
  };
}

async function addSupplierBill(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  const leg = str(req.body.bill_for);
  const anchor = ANCHORS[leg] || ANCHORS.advance;
  const supplierPo = order ? await orderSupplierPoRepository.findLatestForOrder(orderId) : null;
  if (!order || !supplierPo || !(await stageGateService.isReachedOrPassed(orderId, 5))) {
    back(req, res, orderId, anchor, 'error', "Save the Supplier PO terms (Step 7a) before adding the supplier's bill.");
    return;
  }
  if (!orderVendorBillRepository.SUPPLIER_LEGS[leg]) {
    back(req, res, orderId, anchor, 'error', 'Choose whether this bill is for the advance or the balance.');
    return;
  }
  const fields = billFields(req);
  if (typeof fields === 'string') {
    back(req, res, orderId, anchor, 'error', fields);
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'bill_file', 'vendor_bill', orderStorage.forReceived(order, 'supplier_po'), order.client_id, orderId, req.user.id, null, supplierPo.supplier_legal_name || 'Supplier', 'Supplier bill / invoice');
  } catch (e) {
    back(req, res, orderId, anchor, 'error', e.message);
    return;
  }
  const billId = await orderVendorBillRepository.create(orderId, 'supplier', { ...fields, supplier_po_id: supplierPo.id, bill_for: leg, bill_file_id: fileId }, req.user.id);
  await auditLogRepository.log(req.user.id, 'SUPPLIER_BILL_ADDED', 'order_vendor_bills', billId, 'bill_no', null, fields.bill_no, `${orderVendorBillRepository.SUPPLIER_LEGS[leg]} bill ₹${money(fields.taxable_inr + fields.gst_inr)}`);
  back(req, res, orderId, anchor, 'success', `Supplier bill ${fields.bill_no} added — mark it paid once the payment goes out.`);
}

async function addLogisticsBill(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  const anchor = ANCHORS.logistics;
  if (!order) {
    res.status(404).send('Order not found.');
    return;
  }
  const partner = await logisticsPartnerRepository.find(parseInt(req.body.logistics_partner_id, 10) || 0);
  if (!partner || !Number(partner.is_active)) {
    back(req, res, orderId, anchor, 'error', 'Choose the logistics partner from the directory.');
    return;
  }
  const service = str(req.body.bill_for);
  if (!orderVendorBillRepository.LOGISTICS_SERVICES[service]) {
    back(req, res, orderId, anchor, 'error', 'Choose what the bill is for.');
    return;
  }
  const fields = billFields(req);
  if (typeof fields === 'string') {
    back(req, res, orderId, anchor, 'error', fields);
    return;
  }
  let fileId;
  try {
    fileId = await fileUploadService.handleUpload(req, 'bill_file', 'vendor_bill', orderStorage.forReceived(order, 'freight'), order.client_id, orderId, req.user.id, null, partner.partner_name, 'Logistics partner bill');
  } catch (e) {
    back(req, res, orderId, anchor, 'error', e.message);
    return;
  }
  const billId = await orderVendorBillRepository.create(orderId, 'logistics', { ...fields, logistics_partner_id: partner.id, bill_for: service, bill_file_id: fileId }, req.user.id);
  await auditLogRepository.log(req.user.id, 'LOGISTICS_BILL_ADDED', 'order_vendor_bills', billId, 'bill_no', null, fields.bill_no, `${partner.partner_name} — ${orderVendorBillRepository.LOGISTICS_SERVICES[service]} ₹${money(fields.taxable_inr + fields.gst_inr)}`);
  back(req, res, orderId, anchor, 'success', `Bill ${fields.bill_no} from ${partner.partner_name} added.`);
}

/** Why a logistics bill can't be paid yet, or null: on a CFR/CIF order, only once the buyer's Debit Note payment has cleared (9d). */
async function logisticsPaymentBlockedReason(order) {
  const incoterm = String(order.incoterm_code || 'FOB').toUpperCase();
  if (incoterm === 'FOB') return null;
  const payment = await orderPaymentStatusRepository.find(order.id);
  return payment && payment.freight_cleared_at
    ? null
    : `Logistics bills on this ${incoterm} order can be paid once the buyer's Debit Note payment has cleared (Step 9d).`;
}

async function payBill(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const order = await orderRepository.find(orderId);
  const bill = await orderVendorBillRepository.find(parseInt(req.params.billId, 10));
  if (!order || !bill || Number(bill.order_id) !== orderId) {
    res.status(404).send('Bill not found.');
    return;
  }
  const anchor = bill.vendor_kind === 'supplier' ? (ANCHORS[bill.bill_for] || ANCHORS.advance) : ANCHORS.logistics;
  if (bill.paid_at !== null) {
    back(req, res, orderId, anchor, 'error', `Bill ${bill.bill_no} is already marked paid.`);
    return;
  }
  let supplierPo = null;
  if (bill.vendor_kind === 'supplier') {
    supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
    if (!supplierPo || Number(supplierPo.id) !== Number(bill.supplier_po_id)) {
      back(req, res, orderId, anchor, 'error', 'This bill belongs to an earlier Supplier PO version — add it again against the current Supplier PO.');
      return;
    }
    // Owner report (2026-10-27): the balance waits for the advance whenever the terms plan one.
    if (bill.bill_for === 'balance' && Number(supplierPo.advance_amount_inr || 0) > 0 && !supplierPo.advance_paid_at) {
      back(req, res, orderId, anchor, 'error', `Pay the supplier advance (Step 7e) first — the Supplier PO terms plan an advance of ₹${money(supplierPo.advance_amount_inr)}.`);
      return;
    }
  } else {
    const blocked = await logisticsPaymentBlockedReason(order);
    if (blocked !== null) {
      back(req, res, orderId, anchor, 'error', blocked);
      return;
    }
  }

  const paidAt = str(req.body.paid_at);
  if (!validDate(paidAt) || paidAt > todayIst()) {
    back(req, res, orderId, anchor, 'error', 'Enter the date it was paid (not in the future).');
    return;
  }
  const amount = str(req.body.paid_amount_inr);
  if (amount === '' || Number.isNaN(Number(amount)) || Number(amount) <= 0) {
    back(req, res, orderId, anchor, 'error', 'Enter the amount paid (₹).');
    return;
  }
  const mode = str(req.body.payment_mode);
  if (!orderVendorBillRepository.PAYMENT_MODES.includes(mode)) {
    back(req, res, orderId, anchor, 'error', 'Choose how it was paid.');
    return;
  }
  const reference = str(req.body.payment_ref);
  if (reference === '') {
    back(req, res, orderId, anchor, 'error', 'Enter the UTR / payment reference.');
    return;
  }
  if (!(await caFyLockGuard.allow(req, paidAt, 'order_vendor_bills', bill.id, 'mark_paid'))) {
    res.redirect(`/orders/${orderId}#${anchor}`);
    return;
  }

  const paid = Math.round(Number(amount) * 100) / 100;
  await orderVendorBillRepository.markPaid(bill.id, paidAt, paid, mode, reference.slice(0, 100), req.user.id);
  if (supplierPo !== null) {
    // The Supplier PO's own advance / balance paid figures (Steps 7e / 10a,
    // reports, the ledger) follow the bills paid on that leg.
    const [legPaid, lastPaid] = await orderVendorBillRepository.supplierLegPaid(supplierPo.id, bill.bill_for);
    if (bill.bill_for === 'advance') {
      await orderSupplierPoRepository.markAdvancePaid(supplierPo.id, lastPaid, req.user.id, legPaid);
    } else {
      await orderSupplierPoRepository.markBalancePaid(supplierPo.id, lastPaid, req.user.id, legPaid);
    }
    await auditLogRepository.log(req.user.id, bill.bill_for === 'advance' ? 'SUPPLIER_ADVANCE_PAID' : 'SUPPLIER_BALANCE_PAID', 'order_supplier_po', supplierPo.id, `${bill.bill_for}_paid_date`, null, paidAt, `Against bill ${bill.bill_no} — ${mode} ${reference}`);
  }
  await auditLogRepository.log(req.user.id, 'VENDOR_BILL_PAID', 'order_vendor_bills', bill.id, 'paid_at', null, paidAt, `₹${money(paid)} — ${mode} ${reference}`);
  back(req, res, orderId, anchor, 'success', `Payment of ₹${money(paid)} against bill ${bill.bill_no} recorded.`);
}

async function deleteBill(req, res) {
  const orderId = parseInt(req.params.id, 10);
  const bill = await orderVendorBillRepository.find(parseInt(req.params.billId, 10));
  if (!bill || Number(bill.order_id) !== orderId) {
    res.status(404).send('Bill not found.');
    return;
  }
  const anchor = bill.vendor_kind === 'supplier' ? (ANCHORS[bill.bill_for] || ANCHORS.advance) : ANCHORS.logistics;
  if (!(await orderVendorBillRepository.deleteUnpaid(bill.id))) {
    back(req, res, orderId, anchor, 'error', 'A paid bill stays on record and cannot be removed.');
    return;
  }
  await auditLogRepository.log(req.user.id, 'VENDOR_BILL_REMOVED', 'order_vendor_bills', bill.id, 'bill_no', bill.bill_no, null, 'Unpaid bill removed.');
  back(req, res, orderId, anchor, 'success', `Bill ${bill.bill_no} removed.`);
}

module.exports = { ANCHORS, addSupplierBill, addLogisticsBill, logisticsPaymentBlockedReason, payBill, deleteBill };
