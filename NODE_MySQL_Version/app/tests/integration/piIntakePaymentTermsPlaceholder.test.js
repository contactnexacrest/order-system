'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PiIntakePaymentTermsPlaceholderTest.php. 90-item backlog #16:
 * the "Payment Terms Confirmation" field on the PI-details public form is
 * required, but piIntakeController's buildPaymentTermsPlaceholder()
 * computed sentence was only ever passed into the HTML placeholder
 * attribute (ghost text that disappears on focus and is never
 * submitted) — never into the textarea's actual value/body. Since the
 * field is NULL on a fresh, never-submitted submission, the buyer saw an
 * empty required field with only a vanishing hint, not an actual
 * prefilled value to review and confirm. Fixed by rendering the computed
 * sentence as the real prefilled value whenever the submission has no
 * answer of its own yet.
 */
describe('PI intake payment-terms placeholder (backlog #16)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function textareaBody(html) {
    const start = html.indexOf('name="payment_terms_confirmation"');
    if (start === -1) return null;
    const tagFragment = html.slice(start, start + 600);
    const closeTagIdx = tagFragment.indexOf('</textarea>');
    const innerStart = tagFragment.indexOf('>') + 1;
    return tagFragment.slice(innerStart, closeTagIdx);
  }

  it('prefills the real computed sentence on a fresh submission, not just a placeholder', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);

    const res = await request(app).get(`/pi-details/${token}`);
    expect(res.status).toBe(200);

    const body = textareaBody(res.text);
    expect(body).not.toBeNull();
    expect(body.trim()).not.toBe('');
    expect(body).toContain('advance T/T on FOB Value');
  });

  it("shows the client's own previous answer verbatim, not the computed default", async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(submission.id, {
      company_legal_name: submission.company_legal_name,
      billing_address: submission.billing_address,
      billing_address_line1: submission.billing_address_line1,
      billing_address_line2: submission.billing_address_line2,
      billing_city: submission.billing_city,
      billing_postcode: submission.billing_postcode,
      billing_country: submission.billing_country,
      vat_eori_tax_no: submission.vat_eori_tax_no,
      contact_person: submission.contact_person,
      email: submission.email,
      phone: submission.phone,
      port_of_discharge_text: submission.port_of_discharge_text,
      country_of_destination: submission.country_of_destination,
      incoterm_confirmed: submission.incoterm_confirmed,
      container_type_text: submission.container_type_text,
      payment_terms_confirmation: 'CONFIRMED — My own custom wording',
      quotation_acceptance_reference: 'We accept Quotation X',
      coo_type: submission.coo_type,
      buyer_po_ref: null,
      changes_from_quotation: null,
      special_document_requirements: null,
      consignee_same_as_buyer: 1,
      consignee_name: null, consignee_address_line1: null, consignee_address_line2: null,
      consignee_city: null, consignee_postcode: null, consignee_country: null,
      consignee_vat_eori_tax_no: null, consignee_contact_person: null,
      consignee_phone: null, consignee_email: null,
      notify_party_same_as_consignee: 1,
      notify_party: null, notify_party_address_line1: null, notify_party_address_line2: null,
      notify_party_city: null, notify_party_postcode: null, notify_party_country: null,
      notify_party_contact_person: null, notify_party_phone: null, notify_party_email: null,
    }, '127.0.0.1');
    await db.execute("UPDATE pi_intake_submissions SET status = 'rejected' WHERE id = :id", { id: submission.id });

    const res = await request(app).get(`/pi-details/${token}`);
    expect(res.text).toContain('CONFIRMED — My own custom wording');
  });
});
