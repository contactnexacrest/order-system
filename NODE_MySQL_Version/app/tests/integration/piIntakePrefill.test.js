'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');

// QA Round 6, R6-N16: clients.email is unique, and Jest runs files in
// parallel on one database — this file's fixed test emails are its own.
const JANE_EMAIL = `jane-${process.pid}-${Date.now()}@buyer.example`;
const INTAKE_EMAIL = `intake-${process.pid}-${Date.now()}@buyer.test`;

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
  beforeEach(async () => {
    await db.execute(`UPDATE clients SET email = NULL WHERE email IN (${[JANE_EMAIL, INTAKE_EMAIL].map((e, i) => `:e${i}`).join(', ')})`, Object.fromEntries([JANE_EMAIL, INTAKE_EMAIL].map((e, i) => [`e${i}`, e])));
  });

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
        email: JANE_EMAIL,
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
    expect(row.email).toBe(JANE_EMAIL);
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

  // Owner report (2026-10-25, a regression of the fix above): the PI form
  // was later split into structured billing / consignee / notify-party
  // fields that this prefill never covered, so they opened empty even
  // though the Quotation-stage intake had already captured them — and the
  // "same as" checkboxes always started ticked, hiding a consignee that
  // differs from the buyer. Checked on the rendered form, not just the row.
  it('prefills structured addresses and same-as choices on the rendered form', async () => {
    const clientId = await createTestClient();
    await db.execute(
      `UPDATE clients SET
         billing_address_line1 = '12 Harbour Road', billing_address_line2 = 'Unit 4',
         billing_city = 'Rotterdam', billing_postcode = '3011 AA', billing_country = 'Netherlands',
         consignee_same_as_buyer = 0, consignee_name = 'Delta Warehousing BV',
         consignee_address_line1 = '8 Dock Street', consignee_city = 'Antwerp',
         consignee_postcode = '2000', consignee_country = 'Belgium',
         consignee_contact_person = 'Piet Jansen', consignee_phone = '+32-3-1234567',
         consignee_email = 'piet@delta.example', consignee_vat_eori_tax_no = 'BE0123456789',
         notify_party_same_as_consignee = 0, notify_party = 'Acme Freight Forwarders',
         notify_party_address_line1 = '1 Port Lane', notify_party_city = 'Hamburg',
         notify_party_country = 'Germany', notify_party_contact_person = 'Anna Schulz'
       WHERE id = :id`,
      { id: clientId }
    );
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);

    const { text } = await request(app).get(`/pi-details/${token}`);

    const expected = {
      billing_address_line1: '12 Harbour Road', billing_address_line2: 'Unit 4', billing_city: 'Rotterdam',
      billing_postcode: '3011 AA', billing_country: 'Netherlands',
      consignee_name: 'Delta Warehousing BV', consignee_address_line1: '8 Dock Street', consignee_city: 'Antwerp',
      consignee_postcode: '2000', consignee_country: 'Belgium', consignee_contact_person: 'Piet Jansen',
      consignee_phone: '+32-3-1234567', consignee_email: 'piet@delta.example', consignee_vat_eori_tax_no: 'BE0123456789',
      notify_party_address_line1: '1 Port Lane', notify_party_city: 'Hamburg', notify_party_country: 'Germany',
      notify_party_contact_person: 'Anna Schulz',
    };
    for (const [field, value] of Object.entries(expected)) {
      expect(text).toContain(`name="${field}" value="${value}"`);
    }
    expect(text).toMatch(/name="consignee_same_as_buyer" value="1"\s*>/);
    expect(text).toMatch(/name="notify_party_same_as_consignee" value="1"\s*>/);
  });

  // The client's own Quotation-stage port/container answers live on their
  // accepted intake, not the client record — the PI form falls back to them
  // when the order itself has none (e.g. an order created by picking the
  // client from the dropdown rather than from the intake).
  it("falls back to the client's Quotation-stage port and container", async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB');
    await db.execute('UPDATE orders SET port_of_discharge_text = NULL, port_of_discharge_name = NULL, container_type = NULL WHERE id = :id', { id: orderId });
    await db.execute(
      `INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, port_of_discharge_text, container_type_text, status, converted_client_id, access_token_hash, access_token_expires_at)
       VALUES ('Jest Test Buyer', '1 Test Street', 'Test Contact', :intake_email, 'Belgium', 'Antwerp, Belgium', '1x40ft HC', 'converted', :client_id, :hash, NOW() + INTERVAL 1 DAY)`,
      { intake_email: INTAKE_EMAIL, client_id: clientId, hash: require('crypto').randomBytes(32).toString('hex') }
    );

    const row = await piIntakeRepository.findValidByToken(await piIntakeRepository.createLink(orderId, null));

    expect(row.port_of_discharge_text).toBe('Antwerp, Belgium');
    expect(row.container_type_text).toBe('1x40ft HC');
  });

  it('keeps a resubmitting client\'s own same-as choice', async () => {
    const clientId = await createTestClient();
    await db.execute('UPDATE clients SET consignee_same_as_buyer = 0 WHERE id = :id', { id: clientId });
    const orderId = await createTestOrder(clientId, 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);
    // Submitted once with "same as buyer" ticked, then rejected by staff.
    await db.execute(
      "UPDATE pi_intake_submissions SET consignee_same_as_buyer = 1, submitted_at = NOW(), status = 'rejected' WHERE order_id = :id",
      { id: orderId }
    );

    const row = await piIntakeRepository.findValidByToken(token);

    expect(Number(row.consignee_same_as_buyer)).toBe(1);
  });
});
