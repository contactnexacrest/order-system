'use strict';

const flash = require('../helpers/flash');
const clientIntakeRepository = require('../repositories/clientIntakeRepository');

/**
 * Port of App\Controllers\ClientIntakeController. Public, unauthenticated
 * quotation-stage intake form — the actual entry point into this system,
 * per the confirmed scope: a Zoho-qualified prospect is sent this form's
 * link by staff; filling it out is their onboarding. Submitting never
 * creates a client or an order — it only ever lands in the staff review
 * queue (clientIntakeReviewController).
 */

function show(req, res) {
  res.renderView('client_intake/form', {}, 'layout/bare');
}

async function submit(req, res) {
  const companyLegalName = String(req.body.company_legal_name || '').trim();
  const billingAddress = String(req.body.billing_address || '').trim();
  const contactPerson = String(req.body.contact_person || '').trim();
  const email = String(req.body.email || '').trim();
  const countryOfDestination = String(req.body.country_of_destination || '').trim();

  if (companyLegalName === '' || billingAddress === '' || contactPerson === '' || email === '' || countryOfDestination === '') {
    flash.set(req, 'error', 'Please fill in all required fields (marked *).');
    res.redirect('/quotation-request');
    return;
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    flash.set(req, 'error', `"${email}" doesn't look like a valid email address.`);
    res.redirect('/quotation-request');
    return;
  }

  await clientIntakeRepository.create(
    {
      company_legal_name: companyLegalName,
      billing_address: billingAddress,
      vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim(),
      contact_person: contactPerson,
      email,
      phone: String(req.body.phone || '').trim(),
      country_of_destination: countryOfDestination,
      port_of_discharge_text: String(req.body.port_of_discharge_text || '').trim(),
      coo_type: String(req.body.coo_type || '').trim(),
      incoterm_preference: String(req.body.incoterm_preference || '').trim(),
      container_type_text: String(req.body.container_type_text || '').trim(),
      buyer_own_reference: String(req.body.buyer_own_reference || '').trim(),
      notes: String(req.body.notes || '').trim(),
    },
    req.ip || null
  );

  res.renderView('client_intake/thank_you', {}, 'layout/bare');
}

module.exports = { show, submit };
