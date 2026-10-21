'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const clientIntakeController = require('../../src/controllers/clientIntakeController');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of IncotermDropdownWithOtherFallbackTest.php. User-reported batch
 * item 2: "On client side also, we should give dropdown for Incoterms +
 * free text if not available there" — the client-facing Quotation-details
 * form (incoterm_preference) and PI-details form (incoterm_confirmed)
 * both used a bare free-text input. Fixed with a <select> of the
 * admin-managed incoterms list plus an "Other (not listed)" option that
 * reveals a free-text fallback input — the submitted field name/column is
 * unchanged on both ends, so a value not in the list still submits and
 * saves exactly as before.
 */
describe('Incoterm dropdown with free-text fallback (user-reported batch, item 2)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('quotation-details form offers an incoterm dropdown with an Other fallback', async () => {
    const res = await request(app).get('/quotation-details');
    expect(res.status).toBe(200);

    expect(res.text).toContain('id="incoterm_select"');
    expect(res.text).toContain('>FOB<');
    expect(res.text).toContain('Other (not listed)');
    expect(res.text).toContain('id="incoterm_preference_input"');
  });

  it("pi-details form offers an incoterm dropdown prefilled from the order's own incoterm", async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);

    const res = await request(app).get(`/pi-details/${token}`);
    expect(res.status).toBe(200);

    const selectStart = res.text.indexOf('id="incoterm_select"');
    expect(selectStart).toBeGreaterThan(-1);
    const selectTag = res.text.slice(selectStart, selectStart + 2000);
    expect(selectTag).toMatch(/value="FOB"\s+selected/);

    const otherInputStart = res.text.indexOf('id="incoterm_confirmed_input"');
    expect(otherInputStart).toBeGreaterThan(-1);
    const otherInputTag = res.text.slice(otherInputStart, otherInputStart + 300);
    expect(otherInputTag).toContain('display:none');
    expect(otherInputTag).toContain('readonly');
  });

  it('pi-details form falls back to free text when the stored value is not a listed incoterm', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    // Simulate a stored value that doesn't match any listed incoterm code
    // without running the full submit()/lock workflow, which would move
    // the row past the 'awaiting_client' status this view needs.
    await db.execute('UPDATE pi_intake_submissions SET incoterm_confirmed = :v WHERE id = :id', {
      v: 'DDP Custom Terms Not Listed',
      id: submission.id,
    });

    const res = await request(app).get(`/pi-details/${token}`);
    expect(res.status).toBe(200);

    const selectStart = res.text.indexOf('id="incoterm_select"');
    const selectTag = res.text.slice(selectStart, selectStart + 3000);
    expect(selectTag).toMatch(/value="__other__"\s+selected/);

    const otherInputStart = res.text.indexOf('id="incoterm_confirmed_input"');
    const otherInputTag = res.text.slice(otherInputStart, otherInputStart + 300);
    expect(otherInputTag).toContain('value="DDP Custom Terms Not Listed"');
    expect(otherInputTag).not.toContain('display:none');
  });

  it('quotation-details edit form falls back to free text when the stored value is not a listed incoterm', async () => {
    const id = await clientIntakeRepository.create(
      {
        company_legal_name: 'Test Buyer', billing_address: '1 Test Street',
        billing_address_line1: '1 Test Street', billing_address_line2: null,
        billing_city: 'Testville', billing_postcode: null, billing_country: 'Testland',
        vat_eori_tax_no: 'VAT123', contact_person: 'Test Contact', email: 'buyer@example.test',
        phone: null, country_of_destination: 'Testland', port_of_discharge_text: null,
        coo_type: null, incoterm_preference: 'My Own Custom Incoterm Wording',
        container_type_text: null, buyer_own_reference: null, notes: null,
        consignee_same_as_buyer: 1, consignee_name: null, consignee_address_line1: null,
        consignee_address_line2: null, consignee_city: null, consignee_postcode: null,
        consignee_country: null, consignee_vat_eori_tax_no: null, consignee_contact_person: null,
        consignee_phone: null, consignee_email: null,
      },
      '127.0.0.1'
    );
    const link = await clientIntakeController.issueCorrectionLink(id, 'buyer@example.test');
    const rawToken = link.slice(link.lastIndexOf('/') + 1);

    const res = await request(app).get(`/quotation-details/edit/${rawToken}`);
    expect(res.status).toBe(200);

    const selectStart = res.text.indexOf('id="incoterm_select"');
    const selectTag = res.text.slice(selectStart, selectStart + 3000);
    expect(selectTag).toMatch(/value="__other__"\s+selected/);

    const otherInputStart = res.text.indexOf('id="incoterm_preference_input"');
    const otherInputTag = res.text.slice(otherInputStart, otherInputStart + 300);
    expect(otherInputTag).toContain('value="My Own Custom Incoterm Wording"');
  });
});
