'use strict';

const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');

/**
 * The PI-details form used to start completely blank even though the
 * client had already given most of this same information at the
 * Quotation-stage intake — company name, address, consignee, VAT/tax no,
 * contact person, email, phone, country, COO type (all on the `clients`
 * row by the time a PI link is generated) and the shipping basics
 * (incoterm, port of discharge, container type) staff already set on the
 * order. findValidByToken() now folds those in as defaults for whichever
 * fields the client hasn't answered themselves yet.
 */
describe('PI intake prefill from client/order data', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setClientContactFields(clientId) {
    await db.execute(
      `UPDATE clients SET
          consignee_name = :consignee_name, consignee_address = :consignee_address,
          vat_eori_tax_no = :vat, contact_person = :contact, email = :email, phone = :phone,
          notify_party = :notify, country_of_destination = :country, coo_type = :coo
       WHERE id = :id`,
      {
        consignee_name: 'Same',
        consignee_address: 'Same',
        vat: 'NL123456789B01',
        contact: 'Jane Buyer',
        email: 'jane@buyer.example',
        phone: '+31-6-12345678',
        notify: 'Acme Freight Forwarders',
        country: 'Netherlands',
        coo: 'Non-preferential',
        id: clientId,
      }
    );
  }

  it('first visit prefills from client and order data', async () => {
    const clientId = await createTestClient();
    await setClientContactFields(clientId);
    const orderId = await createTestOrder(clientId, 'FOB');
    await db.execute(
      "UPDATE orders SET port_of_discharge_text = 'Rotterdam, Netherlands', container_type = '1x20ft' WHERE id = :id",
      { id: orderId }
    );

    const token = await piIntakeRepository.createLink(orderId, null);
    const row = await piIntakeRepository.findValidByToken(token);

    expect(row).toBeTruthy();
    expect(row.company_legal_name).toBe('Jest Test Buyer Ltd');
    expect(row.billing_address).toBe('1 Test Street, Test City');
    expect(row.consignee_name).toBe('Same');
    expect(row.consignee_address).toBe('Same');
    expect(row.vat_eori_tax_no).toBe('NL123456789B01');
    expect(row.contact_person).toBe('Jane Buyer');
    expect(row.email).toBe('jane@buyer.example');
    expect(row.phone).toBe('+31-6-12345678');
    expect(row.notify_party).toBe('Acme Freight Forwarders');
    expect(row.country_of_destination).toBe('Netherlands');
    expect(row.coo_type).toBe('Non-preferential');
    expect(row.port_of_discharge_text).toBe('Rotterdam, Netherlands');
    expect(row.incoterm_confirmed).toBe('FOB');
    expect(row.container_type_text).toBe('1x20ft');
  });

  it("the client's own prior answer is never overwritten by the default", async () => {
    const clientId = await createTestClient();
    await setClientContactFields(clientId);
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);

    const submission = await piIntakeRepository.findValidByToken(token);
    await piIntakeRepository.submit(
      submission.id,
      {
        company_legal_name: 'Buyer Trading Co (corrected)',
        billing_address: submission.billing_address,
        consignee_name: submission.consignee_name,
        consignee_address: submission.consignee_address,
        vat_eori_tax_no: submission.vat_eori_tax_no,
        contact_person: submission.contact_person,
        email: submission.email,
        phone: submission.phone,
        notify_party: submission.notify_party,
        port_of_discharge_text: submission.port_of_discharge_text,
        country_of_destination: submission.country_of_destination,
        incoterm_confirmed: submission.incoterm_confirmed,
        container_type_text: submission.container_type_text,
        payment_terms_confirmation: 'CONFIRMED',
        quotation_acceptance_reference: 'We accept Quotation X',
        coo_type: submission.coo_type,
        buyer_po_ref: null,
        changes_from_quotation: null,
        special_document_requirements: null,
      },
      '127.0.0.1'
    );
    await db.execute("UPDATE pi_intake_submissions SET status = 'rejected' WHERE id = :id", { id: submission.id });

    const reloaded = await piIntakeRepository.findValidByToken(token);
    expect(reloaded.company_legal_name).toBe('Buyer Trading Co (corrected)');
  });
});
