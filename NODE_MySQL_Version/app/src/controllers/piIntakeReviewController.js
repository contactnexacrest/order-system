'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientRepository = require('../repositories/clientRepository');
const orderRepository = require('../repositories/orderRepository');
const piIntakeRepository = require('../repositories/piIntakeRepository');

/**
 * Port of App\Controllers\PiIntakeReviewController. Staff review queue
 * for PI-stage intake submissions (schema.sql Section AA) — a separate
 * queue from clientIntakeReviewController's Quotation-stage one.
 * Accepting is the authoritative correction point for the client's
 * identity fields (the PI Form spec explicitly says "must match exactly
 * as they appear on official documents"), so it overwrites the live
 * `clients` row with what the client confirmed, plus the order's
 * buyers_po_ref. Everything else on the submission (payment-terms
 * confirmation, formal quotation-acceptance reference, confirmed
 * Incoterm/port/COO, changes from quotation, special document
 * requirements) stays on the row itself as the record staff read before
 * generating the PI — never auto-written onto the order's own
 * structured/FK-driven columns.
 */

async function index(req, res) {
  const [pending, resolved] = await Promise.all([
    piIntakeRepository.pendingReview(),
    piIntakeRepository.recentResolved(),
  ]);
  res.renderView('pi_intake_review/index', { pending, resolved }, 'layout/base');
}

async function accept(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const submission = await piIntakeRepository.find(id);
  if (!submission || submission.status !== 'pending_review') {
    flash.set(req, 'error', 'Submission not found, or already resolved.');
    res.redirect('/pi-intake-review');
    return;
  }

  const order = await orderRepository.find(submission.order_id);
  if (!order) {
    flash.set(req, 'error', 'The order for this submission no longer exists.');
    res.redirect('/pi-intake-review');
    return;
  }

  const user = req.user;
  await clientRepository.update(order.client_id, {
    company_legal_name: submission.company_legal_name,
    billing_address: submission.billing_address,
    vat_eori_tax_no: submission.vat_eori_tax_no,
    contact_person: submission.contact_person,
    email: submission.email,
    phone: submission.phone,
    country_of_destination: submission.country_of_destination,
    coo_type: submission.coo_type,
    // Section BB — the client's own self-service Consignee/Notify Party
    // split from the PI-details form, carried through verbatim to the
    // exact same clientRepository.update() columns the admin Clients
    // edit screen writes to.
    consignee_same_as_buyer: submission.consignee_same_as_buyer,
    consignee_name: submission.consignee_name,
    consignee_address_line1: submission.consignee_address_line1,
    consignee_address_line2: submission.consignee_address_line2,
    consignee_city: submission.consignee_city,
    consignee_postcode: submission.consignee_postcode,
    consignee_country: submission.consignee_country,
    consignee_vat_eori_tax_no: submission.consignee_vat_eori_tax_no,
    consignee_contact_person: submission.consignee_contact_person,
    consignee_phone: submission.consignee_phone,
    consignee_email: submission.consignee_email,
    notify_party_same_as_consignee: submission.notify_party_same_as_consignee,
    notify_party: submission.notify_party,
    notify_party_address_line1: submission.notify_party_address_line1,
    notify_party_address_line2: submission.notify_party_address_line2,
    notify_party_city: submission.notify_party_city,
    notify_party_postcode: submission.notify_party_postcode,
    notify_party_country: submission.notify_party_country,
    notify_party_contact_person: submission.notify_party_contact_person,
    notify_party_phone: submission.notify_party_phone,
    notify_party_email: submission.notify_party_email,
  });
  if (submission.buyer_po_ref) {
    await orderRepository.setBuyersPoRef(order.id, submission.buyer_po_ref);
  }

  // docs/schema.sql Section AC — the client explicitly consented to this
  // exact data being locked when they checked the box on the PI-details
  // form; applying it here is the moment that consent takes effect. A
  // no-op if ordersController.recordAdvancePayment() already locked this
  // client first (clientRepository.lockData() only ever fires once).
  await clientRepository.lockData(order.client_id, `Client consented via PI-details form, applied by ${user.name}`);

  await piIntakeRepository.markApplied(id, user.id);
  await auditLogRepository.log(
    user.id, 'PI_INTAKE_APPLIED', 'orders', order.id,
    null, null, null, `PI-stage details confirmed by client and applied to client #${order.client_id}`
  );

  flash.set(req, 'success', 'Client details updated from the PI-stage confirmation. Review the confirmed Incoterm/port/COO/payment-terms on this page before generating the PI.');
  res.redirect(`/orders/${order.id}`);
}

async function reject(req, res) {
  const id = parseInt(req.params.id, 10) || 0;
  const submission = await piIntakeRepository.find(id);
  if (!submission || submission.status !== 'pending_review') {
    flash.set(req, 'error', 'Submission not found, or already resolved.');
    res.redirect('/pi-intake-review');
    return;
  }

  const reason = String(req.body.reason || '').trim();
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/pi-intake-review');
    return;
  }

  const user = req.user;
  await piIntakeRepository.markRejected(id, user.id, reason);
  flash.set(req, 'success', 'PI-stage submission rejected — the client can resubmit via the same link.');
  res.redirect('/pi-intake-review');
}

module.exports = { index, accept, reject };
