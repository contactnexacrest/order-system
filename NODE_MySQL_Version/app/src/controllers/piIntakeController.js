'use strict';

const flash = require('../helpers/flash');
const piIntakeRepository = require('../repositories/piIntakeRepository');
const { balanceTriggerSentence } = require('../services/documentDataAssembler');

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

// Point 6 — the Payment Terms Confirmation field's placeholder used to be a
// generic made-up example; it now mirrors this specific order's own payment
// preset wording (the same sentence documentDataAssembler prints on the PI
// itself), so the client sees exactly what they're expected to confirm.
function buildPaymentTermsPlaceholder(submission) {
  const advancePct = String(parseFloat(submission.order_advance_pct).toFixed(2)).replace(/\.?0+$/, '');
  const balancePct = String(parseFloat(submission.order_balance_pct).toFixed(2)).replace(/\.?0+$/, '');
  const balanceTerms = balanceTriggerSentence(
    submission.order_balance_trigger_option || null,
    submission.order_balance_days != null ? parseInt(submission.order_balance_days, 10) : null,
    submission.order_balance_trigger_wording || null
  );
  return `CONFIRMED — ${advancePct}% advance T/T on FOB Value ${submission.order_advance_trigger_text} + ${balancePct}% balance ${balanceTerms}`;
}

async function show(req, res) {
  const submission = await piIntakeRepository.findValidByToken(req.params.token || '');
  if (!submission) {
    res.renderView('pi_intake/link_expired', {}, 'layout/bare');
    return;
  }
  res.renderView('pi_intake/form', {
    submission,
    token: req.params.token,
    wrapClass: 'intake-wrap',
    paymentTermsPlaceholder: buildPaymentTermsPlaceholder(submission),
  }, 'layout/bare');
}

async function submit(req, res) {
  const token = req.params.token || '';
  const submission = await piIntakeRepository.findValidByToken(token);
  if (!submission) {
    res.renderView('pi_intake/link_expired', {}, 'layout/bare');
    return;
  }

  const data = Object.assign({
    company_legal_name: String(req.body.company_legal_name || '').trim(),
    billing_address: String(req.body.billing_address || '').trim(),
    vat_eori_tax_no: String(req.body.vat_eori_tax_no || '').trim(),
    contact_person: String(req.body.contact_person || '').trim(),
    email: String(req.body.email || '').trim(),
    phone: String(req.body.phone || '').trim(),
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
  }, collectPartyFields(req));

  // Required set per the business's own PI Form spec (Client_Forms.xlsx).
  // Consignee/Notify Party are never in this list — they're self-service
  // structured fields gated by their own "Same as X?" checkbox (see
  // collectPartyFields()), not free-text requireds.
  const required = [
    'company_legal_name', 'billing_address',
    'vat_eori_tax_no', 'contact_person', 'email', 'phone',
    'port_of_discharge_text', 'country_of_destination', 'incoterm_confirmed',
    'payment_terms_confirmation', 'quotation_acceptance_reference',
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
  if (!req.body.confirm_lock) {
    flash.set(req, 'error', 'Please check the confirmation box — the details above must be confirmed as correct before this form can be submitted.');
    res.redirect(`/pi-details/${token}`);
    return;
  }

  await piIntakeRepository.submit(submission.id, data, req.ip || null);
  res.renderView('pi_intake/thank_you', {}, 'layout/bare');
}

/**
 * Section BB — the client's own self-service Consignee/Notify Party
 * "Same as X?" split on the PI-details form, mirroring
 * clientController.collectPartyFields() field-for-field so the values
 * land in pi_intake_submissions using the exact same column names
 * clientRepository.update() already expects (see
 * piIntakeReviewController.accept()).
 */
function collectPartyFields(req) {
  const consigneeSameAsBuyer = Boolean(req.body.consignee_same_as_buyer);
  const notifySameAsConsignee = Boolean(req.body.notify_party_same_as_consignee);

  const fields = {
    consignee_same_as_buyer: consigneeSameAsBuyer ? 1 : 0,
    notify_party_same_as_consignee: notifySameAsConsignee ? 1 : 0,
  };

  if (consigneeSameAsBuyer) {
    fields.consignee_name = null;
    fields.consignee_address_line1 = null;
    fields.consignee_address_line2 = null;
    fields.consignee_city = null;
    fields.consignee_postcode = null;
    fields.consignee_country = null;
    fields.consignee_vat_eori_tax_no = null;
    fields.consignee_contact_person = null;
    fields.consignee_phone = null;
    fields.consignee_email = null;
  } else {
    fields.consignee_name = String(req.body.consignee_name || '').trim() || null;
    fields.consignee_address_line1 = String(req.body.consignee_address_line1 || '').trim() || null;
    fields.consignee_address_line2 = String(req.body.consignee_address_line2 || '').trim() || null;
    fields.consignee_city = String(req.body.consignee_city || '').trim() || null;
    fields.consignee_postcode = String(req.body.consignee_postcode || '').trim() || null;
    fields.consignee_country = String(req.body.consignee_country || '').trim() || null;
    fields.consignee_vat_eori_tax_no = String(req.body.consignee_vat_eori_tax_no || '').trim() || null;
    fields.consignee_contact_person = String(req.body.consignee_contact_person || '').trim() || null;
    fields.consignee_phone = String(req.body.consignee_phone || '').trim() || null;
    fields.consignee_email = String(req.body.consignee_email || '').trim() || null;
  }

  if (notifySameAsConsignee) {
    fields.notify_party = null;
    fields.notify_party_address_line1 = null;
    fields.notify_party_address_line2 = null;
    fields.notify_party_city = null;
    fields.notify_party_postcode = null;
    fields.notify_party_country = null;
    fields.notify_party_contact_person = null;
    fields.notify_party_phone = null;
    fields.notify_party_email = null;
  } else {
    fields.notify_party = String(req.body.notify_party || '').trim() || null;
    fields.notify_party_address_line1 = String(req.body.notify_party_address_line1 || '').trim() || null;
    fields.notify_party_address_line2 = String(req.body.notify_party_address_line2 || '').trim() || null;
    fields.notify_party_city = String(req.body.notify_party_city || '').trim() || null;
    fields.notify_party_postcode = String(req.body.notify_party_postcode || '').trim() || null;
    fields.notify_party_country = String(req.body.notify_party_country || '').trim() || null;
    fields.notify_party_contact_person = String(req.body.notify_party_contact_person || '').trim() || null;
    fields.notify_party_phone = String(req.body.notify_party_phone || '').trim() || null;
    fields.notify_party_email = String(req.body.notify_party_email || '').trim() || null;
  }

  return fields;
}

module.exports = { show, submit };
