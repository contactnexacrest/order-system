'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');
const clientRepository = require('../repositories/clientRepository');
const referenceNumberService = require('../services/referenceNumberService');

/**
 * Port of App\Controllers\ClientIntakeReviewController. Staff review queue
 * for public quotation-details submissions (client_intake_submissions).
 * Accepting a submission creates the real `clients` row through the exact
 * same clientRepository.create() path a manually-entered walk-in client
 * goes through — never a separate, parallel creation path — then hands
 * off to the normal /clients/{id} screen so staff proceeds through order
 * creation and Quotation generation exactly as they always have. Per the
 * confirmed flow, this never auto-generates a Quotation.
 */

async function index(req, res) {
  res.renderView('client_intake_review/index', {
    pending: await clientIntakeRepository.pending(),
    resolved: await clientIntakeRepository.recentResolved(),
  }, 'layout/base');
}

async function accept(req, res) {
  const user = req.user;
  const id = parseInt(req.params.id, 10) || 0;
  const submission = await clientIntakeRepository.find(id);

  if (!submission || submission.status !== 'pending') {
    flash.set(req, 'error', 'Submission not found, or already resolved.');
    res.redirect('/client-intake');
    return;
  }

  const clientUniqueNumber = await referenceNumberService.generateClientUniqueNumber();
  const consigneeSameAsBuyer = parseInt(submission.consignee_same_as_buyer ?? 1, 10) === 1;
  const clientId = await clientRepository.create(
    {
      company_legal_name: submission.company_legal_name,
      billing_address: submission.billing_address,
      vat_eori_tax_no: submission.vat_eori_tax_no,
      contact_person: submission.contact_person,
      email: submission.email,
      phone: submission.phone,
      country_of_destination: submission.country_of_destination,
      coo_type: submission.coo_type || 'To Be Confirmed',
      // Section BB — the client's own self-service Consignee split from
      // the quotation-details form; Notify Party is never collected at
      // this stage (see clientIntakeController), so it stays at its
      // default (same-as-consignee) here.
      consignee_same_as_buyer: consigneeSameAsBuyer ? 1 : 0,
      consignee_name: consigneeSameAsBuyer ? null : submission.consignee_name,
      consignee_address_line1: consigneeSameAsBuyer ? null : submission.consignee_address_line1,
      consignee_address_line2: consigneeSameAsBuyer ? null : submission.consignee_address_line2,
      consignee_city: consigneeSameAsBuyer ? null : submission.consignee_city,
      consignee_postcode: consigneeSameAsBuyer ? null : submission.consignee_postcode,
      consignee_country: consigneeSameAsBuyer ? null : submission.consignee_country,
      consignee_vat_eori_tax_no: consigneeSameAsBuyer ? null : submission.consignee_vat_eori_tax_no,
      consignee_contact_person: consigneeSameAsBuyer ? null : submission.consignee_contact_person,
      consignee_phone: consigneeSameAsBuyer ? null : submission.consignee_phone,
      consignee_email: consigneeSameAsBuyer ? null : submission.consignee_email,
    },
    user.id,
    clientUniqueNumber
  );

  await clientIntakeRepository.markConverted(id, clientId, user.id);

  flash.set(req, 'success', `Client created — Buyer Inquiry Ref ${clientUniqueNumber}. Proceed to create the order and generate the Quotation.`);
  res.redirect(`/clients/${clientId}`);
}

async function reject(req, res) {
  const user = req.user;
  const id = parseInt(req.params.id, 10) || 0;
  const reason = String(req.body.reason || '').trim();

  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/client-intake');
    return;
  }

  await clientIntakeRepository.markRejected(id, user.id, reason);
  flash.set(req, 'success', 'Request rejected.');
  res.redirect('/client-intake');
}

module.exports = { index, accept, reject };
