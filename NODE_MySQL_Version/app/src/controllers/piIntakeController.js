'use strict';

const flash = require('../helpers/flash');
const piIntakeRepository = require('../repositories/piIntakeRepository');

/**
 * Port of App\Controllers\PiIntakeController. Public, unauthenticated
 * PI-stage intake form — a SEPARATE second stage from
 * clientIntakeController's Quotation-stage form, per the business's own
 * Client_Forms.xlsx spec. Staff generate the link (one per order, from
 * the order screen) once the Quotation is out; the client confirms
 * their details here "exactly as they appear on official documents" and
 * adds fields the Quotation stage never asked for. Submitting never
 * writes to the client/order directly — it only ever lands in the staff
 * review queue (piIntakeReviewController).
 */

async function show(req, res) {
  const submission = await piIntakeRepository.findValidByToken(req.params.token || '');
  if (!submission) {
    res.renderView('pi_intake/link_expired', {}, 'layout/bare');
    return;
  }
  res.renderView('pi_intake/form', { submission, token: req.params.token }, 'layout/bare');
}

async function submit(req, res) {
  const token = req.params.token || '';
  const submission = await piIntakeRepository.findValidByToken(token);
  if (!submission) {
    res.renderView('pi_intake/link_expired', {}, 'layout/bare');
    return;
  }

  const data = {
    company_legal_name: String(req.body.company_legal_name || '').trim(),
    billing_address: String(req.body.billing_address || '').trim(),
    consignee_name: String(req.body.consignee_name || '').trim(),
    consignee_address: String(req.body.consignee_address || '').trim(),
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim(),
    contact_person: String(req.body.contact_person || '').trim(),
    email: String(req.body.email || '').trim(),
    phone: String(req.body.phone || '').trim(),
    notify_party: String(req.body.notify_party || '').trim(),
    port_of_discharge_text: String(req.body.port_of_discharge_text || '').trim(),
    country_of_destination: String(req.body.country_of_destination || '').trim(),
    incoterm_confirmed: String(req.body.incoterm_confirmed || '').trim(),
    container_type_text: String(req.body.container_type_text || '').trim(),
    payment_terms_confirmation: String(req.body.payment_terms_confirmation || '').trim(),
    quotation_acceptance_reference: String(req.body.quotation_acceptance_reference || '').trim(),
    coo_type: String(req.body.coo_type || '').trim(),
    buyer_po_ref: String(req.body.buyer_po_ref || '').trim(),
    changes_from_quotation: String(req.body.changes_from_quotation || '').trim(),
    special_document_requirements: String(req.body.special_document_requirements || '').trim(),
  };

  // Required set per the business's own PI Form spec (Client_Forms.xlsx).
  const required = [
    'company_legal_name', 'billing_address', 'consignee_name', 'consignee_address',
    'vat_eori_tax_no', 'contact_person', 'email', 'phone',
    'port_of_discharge_text', 'country_of_destination', 'incoterm_confirmed',
    'payment_terms_confirmation', 'quotation_acceptance_reference', 'coo_type',
  ];
  for (const field of required) {
    if (data[field] === '') {
      flash.set(req, 'error', 'Please fill in all required fields (marked *).');
      res.redirect(`/pi-details/${token}`);
      return;
    }
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
    flash.set(req, 'error', `"${data.email}" doesn't look like a valid email address.`);
    res.redirect(`/pi-details/${token}`);
    return;
  }

  await piIntakeRepository.submit(submission.id, data, req.ip || null);
  res.renderView('pi_intake/thank_you', {}, 'layout/bare');
}

module.exports = { show, submit };
