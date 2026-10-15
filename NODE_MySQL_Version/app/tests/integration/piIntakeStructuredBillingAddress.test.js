'use strict';

const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PiIntakeStructuredBillingAddressTest.php. 90-item backlog #45
 * (schema.sql Section BI): the PI-details public form's buyer
 * billing_address was the one remaining flat free-text box on either
 * public intake form — its own Consignee and Notify Party sections
 * (Section BB) were already fully structured, and the Quotation-stage
 * form got the same billing-address treatment in Section BF, but
 * pi_intake_submissions' own billing_address was left behind. Exercises
 * the repository layer directly (the controller's extractAndValidate()-
 * equivalent in piIntakeController.submit() just builds this same object
 * from req.body, which these tests have no HTTP context to simulate
 * against — same convention as the PHP port).
 */
describe('PI intake structured billing address (backlog #45)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function submitData(overrides = {}) {
    return Object.assign({
      company_legal_name: 'PI Billing Test Ltd',
      billing_address: '10 Billing Street, Suite 2, Rotterdam, 3011 AD, Netherlands',
      billing_address_line1: '10 Billing Street',
      billing_address_line2: 'Suite 2',
      billing_city: 'Rotterdam',
      billing_postcode: '3011 AD',
      billing_country: 'Netherlands',
      vat_eori_tax_no: 'NL123456789B01',
      contact_person: 'Jamie Buyer',
      email: 'jamie@buyer.example',
      phone: '+31 10 123 4567',
      port_of_discharge_text: 'Rotterdam',
      country_of_destination: 'Netherlands',
      incoterm_confirmed: 'FOB',
      container_type_text: null,
      payment_terms_confirmation: 'CONFIRMED',
      quotation_acceptance_reference: 'We accept Quotation Z',
      coo_type: 'Non-preferential',
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
    }, overrides);
  }

  it('submit persists the structured billing address columns', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(submission.id, submitData(), '127.0.0.1');

    const reloaded = await piIntakeRepository.find(submission.id);
    expect(reloaded.billing_address_line1).toBe('10 Billing Street');
    expect(reloaded.billing_address_line2).toBe('Suite 2');
    expect(reloaded.billing_city).toBe('Rotterdam');
    expect(reloaded.billing_postcode).toBe('3011 AD');
    expect(reloaded.billing_country).toBe('Netherlands');
    expect(reloaded.billing_address).toBe('10 Billing Street, Suite 2, Rotterdam, 3011 AD, Netherlands');
  });

  it('submit composes the flat billing address from structured parts, omitting a blank Line 2', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(submission.id, submitData({
      billing_address: '10 Billing Street, Rotterdam, 3011 AD, Netherlands',
      billing_address_line2: null,
    }), '127.0.0.1');

    const reloaded = await piIntakeRepository.find(submission.id);
    expect(reloaded.billing_address_line2).toBeNull();
    expect(reloaded.billing_address).not.toContain(', , ');
  });

  it('accept carries the structured billing address through to the client row', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(submission.id, submitData(), '127.0.0.1');
    const reloadedSubmission = await piIntakeRepository.find(submission.id);

    // Mirrors piIntakeReviewController.accept() exactly (that controller
    // needs a real HTTP/session context these tests have no superglobal
    // equivalent for, so the clientRepository.update() call it makes is
    // exercised directly here).
    await clientRepository.update(clientId, {
      company_legal_name: reloadedSubmission.company_legal_name,
      billing_address: reloadedSubmission.billing_address,
      billing_address_line1: reloadedSubmission.billing_address_line1,
      billing_address_line2: reloadedSubmission.billing_address_line2,
      billing_city: reloadedSubmission.billing_city,
      billing_postcode: reloadedSubmission.billing_postcode,
      billing_country: reloadedSubmission.billing_country,
      vat_eori_tax_no: reloadedSubmission.vat_eori_tax_no,
      contact_person: reloadedSubmission.contact_person,
      email: reloadedSubmission.email,
      phone: reloadedSubmission.phone,
      country_of_destination: reloadedSubmission.country_of_destination,
      coo_type: reloadedSubmission.coo_type,
    });

    const client = await clientRepository.find(clientId);
    expect(client.billing_address_line1).toBe('10 Billing Street');
    expect(client.billing_city).toBe('Rotterdam');
    expect(client.billing_country).toBe('Netherlands');
  });
});
