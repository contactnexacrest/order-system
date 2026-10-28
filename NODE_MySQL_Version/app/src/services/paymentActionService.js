'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const documentRepository = require('../repositories/documentRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderStorage = require('../helpers/orderStorage');
const documentGenerationService = require('./documentGenerationService');
const fileUploadService = require('./fileUploadService');

/**
 * Port of App\Services\PaymentActionService. Owner request (2026-10-27):
 * the no-login Payment Action Link. The client gets it with the Proforma
 * Invoice (advance), the Debit Note (freight & insurance, CFR/CIF only) and
 * the Commercial Invoice (balance), and sends their payment details there —
 * transaction ID, amount, date and a screenshot. It shows payment only, one
 * payment at a time, never the Buyer PO or anything else. Like every client
 * self-report it is purely informational: staff still verify the bank
 * statement and record the remittance themselves (docs/schema.sql Section AD).
 */

/** Each payment the client can make, in order, with the document it is paid against. */
const LEGS = {
  advance: { doc: 'PI', label: 'Advance Payment', document: 'Proforma Invoice' },
  freight: { doc: 'FDN', label: 'Freight & Insurance', document: 'Debit Note' },
  balance: { doc: 'CI', label: 'Balance Payment', document: 'Commercial Invoice' },
};

/** The document a leg is paid against, once it is issued to the buyer (approved, or sent while email is on). */
async function issuedDocument(orderId, leg) {
  const code = LEGS[leg] ? LEGS[leg].doc : null;
  const latest = code ? await documentRepository.findLatestForOrderAndTypeCode(orderId, code) : null;
  if (!latest || !latest.pdf_file_id) {
    return null;
  }
  const document = await documentRepository.find(latest.id);
  // Required here, not at the top: emailDispatchService requires services that require this one.
  const mailEnabled = await require('./emailDispatchService').mailSendingEnabled();
  if (!document || document.status === 'superseded'
    || document.document_type_category !== 'customer_facing'
    || Number(document.document_type_never_shown_to_buyer)
    || !documentGenerationService.isDocumentComplete(document, mailEnabled)) {
    return null;
  }
  return document;
}

/** The legs this order has: no advance on an LC order, no freight on an FOB one. */
function legsFor(order) {
  const legs = [];
  if ((order.payment_method || 'CASH_ADVANCE') !== 'LC') legs.push('advance');
  if (String(order.incoterm_code || 'FOB').toUpperCase() !== 'FOB') legs.push('freight');
  legs.push('balance');
  return legs;
}

/** What the client owes on a leg — the advance due by the terms, the Debit Note amount, or FOB minus the advance actually received. */
async function amountDue(order, leg) {
  const fob = parseFloat(await orderProductRepository.totalFobValue(order.id)) || 0;
  if (leg === 'advance') {
    return Math.round(fob * (parseFloat(order.advance_pct) || 0)) / 100;
  }
  if (leg === 'freight') {
    const freight = await orderFreightRepository.find(order.id);
    return freight && freight.confirmed_freight_rate !== null
      // Q-R5-2: only a CIF seller insures; on CFR the Debit Note is freight only.
      ? Math.round((parseFloat(freight.confirmed_freight_rate) + (String(order.incoterm_code || '').toUpperCase() === 'CIF' ? (parseFloat(freight.insurance_amount) || 0) : 0)) * 100) / 100
      : null;
  }
  return orderPaymentStatusRepository.balanceDue(fob, order.advance_pct, await orderPaymentStatusRepository.find(order.id));
}

/**
 * The one payment the page shows now: the first leg not yet cleared.
 * 'due' while its document is issued and nothing is reported yet,
 * 'reported' once the client (or staff) has sent the details, 'waiting'
 * while its document is not issued yet, 'done' when every leg cleared.
 */
async function current(order) {
  const payment = (await orderPaymentStatusRepository.find(order.id)) || {};
  const reports = await clientPaymentReportRepository.forOrder(order.id);
  const legs = {};
  let leg = null;
  let state = 'done';
  for (const l of legsFor(order)) {
    if (payment[`${l}_cleared_at`]) {
      legs[l] = 'done';
      continue;
    }
    if (leg !== null) {
      legs[l] = 'todo';
      continue;
    }
    leg = l;
    legs[l] = 'current';
    const reported = !!payment[`${l}_remittance_received_at`]
      || reports.some((r) => r.payment_type === l && r.status === 'new');
    state = reported ? 'reported' : ((await issuedDocument(order.id, l)) ? 'due' : 'waiting');
  }
  return { leg, state, legs };
}

async function report(req, order) {
  const orderId = order.id;
  const now = await current(order);
  if (now.state !== 'due') {
    return { ok: false, message: 'There is no payment waiting for your details on this order right now.' };
  }
  const leg = now.leg;

  const transactionRef = String(req.body.transaction_ref || '').trim();
  if (transactionRef === '') {
    return { ok: false, message: 'Enter the transaction ID / UTR of your payment.' };
  }
  const amount = String(req.body.amount || '').trim();
  if (amount === '' || !(parseFloat(amount) > 0) || Number.isNaN(Number(amount))) {
    return { ok: false, message: 'Enter the amount you paid.' };
  }
  const paymentDate = String(req.body.payment_date || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(paymentDate) || Number.isNaN(new Date(paymentDate).getTime())) {
    return { ok: false, message: 'Enter the date you paid.' };
  }
  if (!req.file) {
    return { ok: false, message: 'Attach the payment screenshot or bank remittance advice.' };
  }
  let screenshotFileId;
  try {
    screenshotFileId = await fileUploadService.handleUpload(
      req,
      'screenshot',
      'received_remittance',
      orderStorage.folder(order, 'payments'),
      order.client_id,
      orderId,
      null,
      null,
      'Client (self-reported)',
      'Client payment self-report screenshot'
    );
  } catch (e) {
    return { ok: false, message: e.message };
  }

  await clientPaymentReportRepository.create(
    orderId,
    leg,
    transactionRef,
    String(req.body.payer_bank_details || '').trim() || null,
    parseFloat(amount),
    paymentDate,
    screenshotFileId
  );
  const label = LEGS[leg].label.toLowerCase();
  await notificationRepository.notifyOrderTeam(orderId, 'client_payment_reported', `The buyer sent their ${label} details on order ${order.order_reference || `#${orderId}`} through the Payment Action Link — verify it against the bank statement.`);
  await auditLogRepository.log(null, 'CLIENT_PAYMENT_REPORTED', 'orders', orderId, 'payment_type', null, leg, `Client sent ${label} details through the Payment Action Link: ref ${transactionRef}.`);

  return { ok: true, message: 'Thank you — we have your payment details. Our team will check them against our bank statement and confirm.' };
}

module.exports = { LEGS, issuedDocument, legsFor, amountDue, current, report };
