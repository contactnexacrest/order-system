'use strict';

const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const clientRepository = require('../../src/repositories/clientRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');

/**
 * Section BB — the client's own self-service Consignee (QT stage) and
 * Consignee+Notify Party (PI stage) "Same as X?" split on the public
 * intake forms, mirroring the structured fields staff already had on the
 * admin Clients form (Section BA). These tests exercise the repository
 * layer directly, mirroring IntakeConsigneeNotifyPartySplitTest.php.
 */
describe('Section BB — intake Consignee/Notify Party structured split', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('client intake submission stores an independent consignee', async () => {
    const id = await clientIntakeRepository.create(
      {
        company_legal_name: 'QT Test Buyer Ltd',
        billing_address: '1 Buyer Street',
        vat_eori_tax_no: 'GB123456789',
        contact_person: 'John Buyer',
        email: 'john@buyer.example',
        phone: '+44 20 1234 5678',
        country_of_destination: 'United Kingdom',
        port_of_discharge_text: 'Felixstowe',
        coo_type: 'Non-preferential',
        incoterm_preference: 'FOB',
        container_type_text: null,
        buyer_own_reference: null,
        notes: null,
        consignee_same_as_buyer: 0,
        consignee_name: 'ABC Memorial Stones Ltd',
        consignee_address_line1: '99 Consignee Road',
        consignee_address_line2: null,
        consignee_city: 'Leeds',
        consignee_postcode: 'LS1 1AA',
        consignee_country: 'United Kingdom',
        consignee_vat_eori_tax_no: 'GB987654321',
        consignee_contact_person: 'Alice Consignee',
        consignee_phone: '+44 20 9999 0000',
        consignee_email: 'alice@consignee.example',
      },
      '127.0.0.1'
    );

    const row = await clientIntakeRepository.find(id);
    expect(parseInt(row.consignee_same_as_buyer, 10)).toBe(0);
    expect(row.consignee_name).toBe('ABC Memorial Stones Ltd');
    expect(row.consignee_city).toBe('Leeds');
    expect(row.consignee_email).toBe('alice@consignee.example');
  });

  it('client intake submission defaults consignee same-as-buyer', async () => {
    const id = await clientIntakeRepository.create(
      {
        company_legal_name: 'QT Test Buyer 2 Ltd',
        billing_address: '2 Buyer Street',
        vat_eori_tax_no: 'GB111111111',
        contact_person: 'Jo Buyer',
        email: 'jo@buyer2.example',
        phone: null,
        country_of_destination: 'United Kingdom',
        port_of_discharge_text: null,
        coo_type: null,
        incoterm_preference: 'FOB',
        container_type_text: null,
        buyer_own_reference: null,
        notes: null,
        consignee_same_as_buyer: 1,
      },
      null
    );

    const row = await clientIntakeRepository.find(id);
    expect(parseInt(row.consignee_same_as_buyer, 10)).toBe(1);
    expect(row.consignee_name).toBeNull();
  });

  it('accepting a quotation intake with an independent consignee creates a structured client row', async () => {
    const id = await clientIntakeRepository.create(
      {
        company_legal_name: 'QT Accept Test Ltd',
        billing_address: '3 Buyer Street',
        vat_eori_tax_no: 'GB222222222',
        contact_person: 'Sam Buyer',
        email: 'sam@buyer3.example',
        phone: null,
        country_of_destination: 'United Kingdom',
        port_of_discharge_text: null,
        coo_type: 'Non-preferential',
        incoterm_preference: 'FOB',
        container_type_text: null,
        buyer_own_reference: null,
        notes: null,
        consignee_same_as_buyer: 0,
        consignee_name: 'Independent Consignee Co',
        consignee_address_line1: '5 Consignee Ave',
        consignee_address_line2: null,
        consignee_city: 'Manchester',
        consignee_postcode: 'M1 1AA',
        consignee_country: 'United Kingdom',
        consignee_vat_eori_tax_no: 'GB333333333',
        consignee_contact_person: 'Pat Consignee',
        consignee_phone: '+44 161 000 0000',
        consignee_email: 'pat@consignee3.example',
      },
      null
    );

    const submission = await clientIntakeRepository.find(id);
    const user = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });

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
      'NC/SC/TEST/' + Math.random().toString(16).slice(2, 8)
    );

    const client = await clientRepository.find(clientId);
    expect(parseInt(client.consignee_same_as_buyer, 10)).toBe(0);
    expect(client.consignee_name).toBe('Independent Consignee Co');
    expect(client.consignee_city).toBe('Manchester');
    expect(parseInt(client.notify_party_same_as_consignee, 10)).toBe(1);
  });

  it('PI intake submit persists Consignee and Notify Party structured fields', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(
      submission.id,
      {
        company_legal_name: 'PI Test Buyer Ltd',
        billing_address: '1 Buyer Street',
        vat_eori_tax_no: 'GB444444444',
        contact_person: 'Kim Buyer',
        email: 'kim@buyer.example',
        phone: '+44 20 1111 2222',
        port_of_discharge_text: 'Felixstowe',
        country_of_destination: 'United Kingdom',
        incoterm_confirmed: 'FOB',
        container_type_text: null,
        payment_terms_confirmation: 'CONFIRMED',
        quotation_acceptance_reference: 'We accept Quotation X',
        coo_type: 'Non-preferential',
        buyer_po_ref: null,
        changes_from_quotation: null,
        special_document_requirements: null,
        consignee_same_as_buyer: 0,
        consignee_name: 'PI Consignee Co',
        consignee_address_line1: '7 Consignee Lane',
        consignee_address_line2: null,
        consignee_city: 'Bristol',
        consignee_postcode: 'BS1 1AA',
        consignee_country: 'United Kingdom',
        consignee_vat_eori_tax_no: 'GB555555555',
        consignee_contact_person: 'Robin Consignee',
        consignee_phone: '+44 117 000 0000',
        consignee_email: 'robin@consignee.example',
        notify_party_same_as_consignee: 0,
        notify_party: 'Acme Freight Forwarders',
        notify_party_address_line1: '9 Freight Way',
        notify_party_address_line2: null,
        notify_party_city: 'Southampton',
        notify_party_postcode: 'SO1 1AA',
        notify_party_country: 'United Kingdom',
        notify_party_contact_person: 'Taylor Notify',
        notify_party_phone: '+44 23 000 0000',
        notify_party_email: 'taylor@notify.example',
      },
      '127.0.0.1'
    );

    const reloaded = await piIntakeRepository.find(submission.id);
    expect(parseInt(reloaded.consignee_same_as_buyer, 10)).toBe(0);
    expect(reloaded.consignee_name).toBe('PI Consignee Co');
    expect(reloaded.consignee_city).toBe('Bristol');
    expect(parseInt(reloaded.notify_party_same_as_consignee, 10)).toBe(0);
    expect(reloaded.notify_party).toBe('Acme Freight Forwarders');
    expect(reloaded.notify_party_city).toBe('Southampton');
    expect(reloaded.notify_party_email).toBe('taylor@notify.example');
  });

  it('PI intake accept applies the full structured split to the client row', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await piIntakeRepository.submit(
      submission.id,
      {
        company_legal_name: 'PI Accept Buyer Ltd',
        billing_address: '1 Buyer Street',
        vat_eori_tax_no: 'GB666666666',
        contact_person: 'Lee Buyer',
        email: 'lee@buyer.example',
        phone: '+44 20 3333 4444',
        port_of_discharge_text: 'Felixstowe',
        country_of_destination: 'United Kingdom',
        incoterm_confirmed: 'FOB',
        container_type_text: null,
        payment_terms_confirmation: 'CONFIRMED',
        quotation_acceptance_reference: 'We accept Quotation Y',
        coo_type: 'Non-preferential',
        buyer_po_ref: null,
        changes_from_quotation: null,
        special_document_requirements: null,
        consignee_same_as_buyer: 0,
        consignee_name: 'Accept Consignee Co',
        consignee_address_line1: '11 Consignee Close',
        consignee_address_line2: null,
        consignee_city: 'Cardiff',
        consignee_postcode: 'CF1 1AA',
        consignee_country: 'United Kingdom',
        consignee_vat_eori_tax_no: 'GB777777777',
        consignee_contact_person: 'Morgan Consignee',
        consignee_phone: '+44 29 000 0000',
        consignee_email: 'morgan@consignee.example',
        notify_party_same_as_consignee: 1,
      },
      '127.0.0.1'
    );

    await db.execute("UPDATE pi_intake_submissions SET status = 'pending_review' WHERE id = :id", { id: submission.id });
    const reloadedSubmission = await piIntakeRepository.find(submission.id);

    await clientRepository.update(clientId, {
      company_legal_name: reloadedSubmission.company_legal_name,
      billing_address: reloadedSubmission.billing_address,
      vat_eori_tax_no: reloadedSubmission.vat_eori_tax_no,
      contact_person: reloadedSubmission.contact_person,
      email: reloadedSubmission.email,
      phone: reloadedSubmission.phone,
      country_of_destination: reloadedSubmission.country_of_destination,
      coo_type: reloadedSubmission.coo_type,
      consignee_same_as_buyer: reloadedSubmission.consignee_same_as_buyer,
      consignee_name: reloadedSubmission.consignee_name,
      consignee_address_line1: reloadedSubmission.consignee_address_line1,
      consignee_address_line2: reloadedSubmission.consignee_address_line2,
      consignee_city: reloadedSubmission.consignee_city,
      consignee_postcode: reloadedSubmission.consignee_postcode,
      consignee_country: reloadedSubmission.consignee_country,
      consignee_vat_eori_tax_no: reloadedSubmission.consignee_vat_eori_tax_no,
      consignee_contact_person: reloadedSubmission.consignee_contact_person,
      consignee_phone: reloadedSubmission.consignee_phone,
      consignee_email: reloadedSubmission.consignee_email,
      notify_party_same_as_consignee: reloadedSubmission.notify_party_same_as_consignee,
      notify_party: reloadedSubmission.notify_party,
      notify_party_address_line1: reloadedSubmission.notify_party_address_line1,
      notify_party_address_line2: reloadedSubmission.notify_party_address_line2,
      notify_party_city: reloadedSubmission.notify_party_city,
      notify_party_postcode: reloadedSubmission.notify_party_postcode,
      notify_party_country: reloadedSubmission.notify_party_country,
      notify_party_contact_person: reloadedSubmission.notify_party_contact_person,
      notify_party_phone: reloadedSubmission.notify_party_phone,
      notify_party_email: reloadedSubmission.notify_party_email,
    });

    const client = await clientRepository.find(clientId);
    expect(parseInt(client.consignee_same_as_buyer, 10)).toBe(0);
    expect(client.consignee_name).toBe('Accept Consignee Co');
    expect(client.consignee_city).toBe('Cardiff');
    expect(parseInt(client.notify_party_same_as_consignee, 10)).toBe(1);
    expect(client.notify_party).toBeNull();
  });
});
