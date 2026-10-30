'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const fieldValidator = require('../helpers/fieldValidator');
const orderStorage = require('../helpers/orderStorage');
const clientPaymentReportRepository = require('../repositories/clientPaymentReportRepository');
const orderBuyerPoDocumentRepository = require('../repositories/orderBuyerPoDocumentRepository');
const orderOcAcknowledgmentRepository = require('../repositories/orderOcAcknowledgmentRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const documentRepository = require('../repositories/documentRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');
const stageGateService = require('./stageGateService');
const documentGenerationService = require('./documentGenerationService');
const fileUploadService = require('./fileUploadService');
const { todayIst } = require('../helpers/dbDateTime');


/**
 * Port of App\Services\BuyerActionService. 90-item backlog #75: the
 * OC-acknowledgment and Buyer-PO-upload write paths used to live only
 * inside clientPortalController, reachable only from a logged-in portal
 * session. This extracts the order-id-scoped core of each (neither ever
 * actually needed the client's own id — only the order's) so both the
 * session-gated client-portal route
 * (clientPortalController.acknowledgeOc()/uploadBuyerPo()) and the new
 * no-login, tokenized buyerActionController route call the exact same
 * logic rather than maintaining two copies of it.
 *
 * 90-item backlog #19/#48 added two more pieces here: uploadBuyerPo() now
 * refuses once the advance payment has cleared (the Buyer PO's job is done
 * by then, on either channel — see advanceAlreadyCleared()), and
 * reportAdvancePayment() gives the no-login channel a narrow self-report
 * option for the one payment leg clientPortalController.reportPayment()
 * can never actually reach — the advance itself, since the portal login
 * isn't provisioned until that very payment clears.
 */
async function advanceAlreadyCleared(orderId) {
  const status = await orderPaymentStatusRepository.find(orderId);
  return !!status && status.advance_cleared_at !== null;
}
async function acknowledgeOc(orderId) {
  let ack = await orderOcAcknowledgmentRepository.find(orderId);
  if (!ack) {
    // User-reported batch item 10: no OC send has ever completed for this
    // order (still awaiting Level-2 email approval, mail sending
    // misconfigured, or staff generated the Buyer Action Link before the
    // OC ever went out by email at all) — there is nothing yet for
    // emailDispatchService.dispatch()'s own recordSent() to have created.
    // Same lazy fallback ordersController.recordOcAcknowledgment() already
    // uses for the staff-side "Record Buyer's Acknowledgement" path.
    // Only once the OC counts as issued (approved while email is off, sent
    // while it is on) — the same rule the Stage 4 gate itself applies.
    const ocDoc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'OC');
    if (ocDoc && !(await documentGenerationService.stageDocumentBlockedBy(orderId, 4)).length) {
      await orderOcAcknowledgmentRepository.ensureRowExists(orderId, ocDoc.id);
      ack = await orderOcAcknowledgmentRepository.find(orderId);
    }
  }
  if (!ack || ack.acknowledged_at !== null) {
    return { ok: false, message: 'There is nothing awaiting your acknowledgement on this order.' };
  }
  if (!(await stageGateService.isUnlocked(orderId, 4))) {
    return { ok: false, message: 'This order is not currently awaiting acknowledgement.' };
  }
  // Owner decision (2026-10-25): the stage completes only once the OC is
  // approved (email off) / sent (email on) — same rule as the staff path.
  if ((await documentGenerationService.stageDocumentBlockedBy(orderId, 4)).length) {
    return { ok: false, message: 'Your Order Confirmation has not been issued yet — please wait for it to be sent to you, then acknowledge it.' };
  }

  if (!(await orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'client_portal', null, null))) {
    // QA-5 EML-06: lost the race to another acknowledgment path
    // (staff-recorded, or the 48h auto-confirm job) between this action's
    // own pre-check above and this write.
    return { ok: true, message: 'This order has already been acknowledged and is moving to production.' };
  }
  await stageGateService.passAndUnlockNext(orderId, 4, null);
  // External QA report L-30/M-07: nothing here ever told staff a buyer
  // had acknowledged their OC — the only way to notice was to happen to
  // reopen the order.
  const order = await orderRepository.find(orderId);
  await notificationRepository.notifyOrderTeam(orderId, 'buyer_oc_acknowledged', `The buyer acknowledged the Order Confirmation on order ${(order && order.order_reference) || `#${orderId}`} — Step 7 (Supplier PO) is now open.`);
  await auditLogRepository.log(null, 'BUYER_OC_ACKNOWLEDGED', 'orders', orderId, null, null, null, 'Order Confirmation acknowledged by the client (self-service).');
  return { ok: true, message: 'Thank you — your acknowledgement has been recorded and your order is moving to production.' };
}

async function uploadBuyerPo(req, order) {
  const orderId = order.id;

  // 90-item backlog #19: this used to be callable "at any time" on both
  // the client-portal and no-login channels — real-world use is always
  // around Stage 2, well before the advance clears, so once it has, a
  // further self-upload serves no purpose and risks the buyer silently
  // replacing the copy staff already confirmed and built later documents
  // against.
  if (await advanceAlreadyCleared(orderId)) {
    return { ok: false, message: 'The advance payment has already cleared, so self-service Buyer PO upload is now closed. Contact your NexaCrest representative if you need to submit an updated copy.' };
  }

  // Owner request (2026-10-26): while Step 4 is still open the buyer gives
  // their PO number with the signed copy, and Step 4 completes by itself —
  // the same as staff attaching (4c) and confirming (4d).
  const awaitingPo = await stageGateService.isUnlocked(orderId, 2);
  const poRef = String((req.body && req.body.buyers_po_ref) || '').trim();
  if (awaitingPo && poRef === '') {
    return { ok: false, message: 'Please enter your Purchase Order number together with the signed copy.' };
  }

  try {
    const fileId = await fileUploadService.handleUpload(
      req,
      'document',
      'buyer_po_copy',
      orderStorage.forReceived(order, 'buyer_po'),
      order.client_id,
      orderId,
      null,
      null,
      'Client (self-uploaded)',
      'Buyer PO copy (client-uploaded)'
    );
    await orderBuyerPoDocumentRepository.attach(orderId, fileId);
  } catch (e) {
    return { ok: false, message: e.message };
  }
  await auditLogRepository.log(null, 'BUYER_PO_UPLOADED_BY_CLIENT', 'orders', orderId, null, null, poRef !== '' ? poRef : null, 'Buyer PO copy uploaded by the client.');
  const reference = order.order_reference || `#${orderId}`;

  if (awaitingPo && (await documentGenerationService.stageDocumentBlockedBy(orderId, 2)).length === 0) {
    await orderRepository.setBuyersPoRef(orderId, poRef, todayIst()); // the day the buyer sent it
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await auditLogRepository.log(null, 'BUYER_PO_CONFIRMED_BY_CLIENT', 'orders', orderId, 'buyers_po_ref', null, poRef, 'The buyer uploaded their signed PO with its number — Step 4 completed.');
    await notificationRepository.notifyOrderTeam(orderId, 'buyer_po_uploaded_by_client', `The buyer uploaded their signed PO (${poRef}) on order ${reference} — Step 4 is complete and Stage 3 (PI) is open.`);
    return { ok: true, message: `Thank you — your signed Purchase Order (${poRef}) has been received. We'll be in touch with your Proforma Invoice.` };
  }

  await notificationRepository.notifyOrderTeam(orderId, 'buyer_po_uploaded_by_client', `The buyer uploaded their signed PO copy${poRef !== '' ? ` (${poRef})` : ''} on order ${reference} — check it and confirm the Buyer PO (Step 4d).`);
  return { ok: true, message: 'Your Buyer PO copy has been uploaded.' };
}

/**
 * Owner request (2026-10-26): a document the buyer can download from the
 * Buyer Action Link — the Buyer PO generated in Step 4a (to sign) or the
 * Proforma Invoice (to pay against) — once it is issued (approved, or sent
 * while email is on). Null until then, and for any other type.
 */
async function issuedBuyerDocument(orderId, typeCode) {
  const stage = { BUYERPO: 2, PI: 3, OC: 4 }[typeCode];
  const latest = stage ? await documentRepository.findLatestForOrderAndTypeCode(orderId, typeCode) : null;
  if (!latest || !latest.pdf_file_id || (await documentGenerationService.stageDocumentBlockedBy(orderId, stage)).length > 0) {
    return null;
  }
  const document = await documentRepository.find(latest.id);
  if (!document || !['approved', 'sent'].includes(document.status)
    || document.document_type_category !== 'customer_facing'
    || Number(document.document_type_never_shown_to_buyer)) {
    return null;
  }
  return document;
}

/**
 * Owner request (2026-10-26): the Buyer Action Link shows the client one
 * clear step at a time — the Purchase Order while Step 4 is open, then the
 * advance payment once the PI is issued, then the Order Confirmation —
 * never a later step before its turn. One of: buyer_po, waiting_pi,
 * advance, advance_reported, waiting, oc, done.
 */
async function currentTask(order, ocAcknowledgment) {
  const orderId = order.id;
  const passed = async (n) => ((await orderStageRepository.findByOrderAndStageNumber(orderId, n)) || {}).status === 'gate_passed';
  if (!(await passed(2))) {
    return 'buyer_po';
  }
  if (!(await passed(3)) && !(await advanceAlreadyCleared(orderId))) {
    if ((order.payment_method || 'CASH_ADVANCE') === 'LC') {
      return 'waiting';
    }
    if (!(await issuedBuyerDocument(orderId, 'PI'))) {
      return 'waiting_pi';
    }
    const reports = await clientPaymentReportRepository.forOrder(orderId);
    if (reports.some((r) => r.payment_type === 'advance' && r.status === 'new')) {
      return 'advance_reported';
    }
    return 'advance';
  }
  if (ocAcknowledgment && ocAcknowledgment.acknowledged_at === null) {
    return 'oc';
  }
  return (await passed(4)) ? 'done' : 'waiting';
}

/**
 * 90-item backlog #48: clientPortalController.reportPayment() lets a
 * logged-in buyer self-report any payment leg, but the portal login
 * itself isn't provisioned until the advance payment is marked cleared
 * (docs/SOP/12-client-portal.md) — so the advance leg is the one payment
 * a buyer could never actually self-report through it ("the very first
 * payment a buyer could self-report through the portal is a later one...
 * not this one" per that same SOP page). This gives the no-login Buyer
 * Action Link a narrow equivalent, scoped to the advance leg only —
 * freight/balance already have a working self-report channel once the
 * portal exists from Stage 4 onward, so they don't need a second one
 * here.
 */
async function reportAdvancePayment(req, order) {
  const orderId = order.id;

  if (await advanceAlreadyCleared(orderId)) {
    return { ok: false, message: 'The advance payment has already been marked cleared on this order — no further report is needed.' };
  }
  // QA Round 6, R6-N24: same rule as the portal.
  const refusal = await require('./clientPortalService').paymentReportRefusal(order);
  if (refusal) {
    return { ok: false, message: refusal };
  }

  const transactionRef = String(req.body.transaction_ref || '').trim();
  if (transactionRef === '') {
    return { ok: false, message: 'Enter the transaction ID/UTR for your advance payment.' };
  }
  // QA Round 6, R6-N19/N20/N21: same amount and date checks as the portal.
  const reportError = fieldValidator.reportedPayment(req.body.amount, req.body.payment_date);
  if (reportError) {
    return { ok: false, message: reportError };
  }

  let screenshotFileId = null;
  if (req.file) {
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
  }

  const amount = String(req.body.amount || '').trim();
  const paymentDate = String(req.body.payment_date || '').trim();
  await clientPaymentReportRepository.create(
    orderId,
    'advance',
    transactionRef,
    String(req.body.payer_bank_details || '').trim() || null,
    amount !== '' ? parseFloat(amount) : null,
    paymentDate !== '' ? paymentDate : null,
    screenshotFileId
  );
  // External QA report L-30/M-07: a client payment self-report never
  // notified staff at all — the "our team will verify this" promise
  // below depended entirely on staff happening to reopen this order. See
  // acknowledgeOc()'s own comment on the same gap.
  await notificationRepository.notifyOrderTeam(orderId, 'client_payment_reported', `The buyer reported an advance payment on order ${order.order_reference || `#${orderId}`} — verify it against the bank statement.`);
  await auditLogRepository.log(null, 'CLIENT_PAYMENT_REPORTED', 'orders', orderId, 'payment_type', null, 'advance', `Client self-reported an advance payment: ref ${transactionRef}.`);

  return { ok: true, message: 'Thank you — we have noted your advance payment details. Our team will verify this against our bank statement and update your order.' };
}

module.exports = { acknowledgeOc, uploadBuyerPo, issuedBuyerDocument, currentTask, reportAdvancePayment };
