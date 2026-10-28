'use strict';

const db = require('../../src/config/db');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const ordersController = require('../../src/controllers/ordersController');
const { createTestOrder } = require('../support/fixtures');

/**
 * Port of IntakePrefillStopsAfterFirstOrderTest.php. 90-item backlog
 * "feedback-loop fix": the intake-preferences prefill (90-item backlog
 * #41/#46, see intakePreferencesPrefillOrderForm.test.js) only guarded
 * on "no validation-retry `old` data yet" — so it kept firing on every
 * later New Order form for the same client too, not just the first. A
 * second, third, Nth order for the same client all silently re-
 * prefilled from that client's very first intake, feeding the same
 * aging commercial terms back into the form indefinitely even once the
 * buyer's actual preference had moved on for a later order.
 */
describe('intake prefill stops after the client\'s first order ("feedback-loop fix")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-intake-feedback-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  async function createAndConvertIntake(incotermPreference) {
    const id = await clientIntakeRepository.create(
      {
        company_legal_name: 'Repeat Buyer Ltd',
        billing_address: '1 Repeat Street',
        billing_address_line1: null,
        billing_address_line2: null,
        billing_city: null,
        billing_postcode: null,
        billing_country: null,
        vat_eori_tax_no: null,
        contact_person: 'Jane Buyer',
        email: `jane-${Math.random().toString(16).slice(2, 10)}@repeat-buyer.example`, // one email per client (R5-02)
        phone: null,
        country_of_destination: 'Netherlands',
        port_of_discharge_text: 'Rotterdam',
        coo_type: null,
        incoterm_preference: incotermPreference,
        container_type_text: '40ft High Cube',
        buyer_own_reference: null,
        notes: null,
      },
      '127.0.0.1'
    );

    const userId = await createTestUser('Admin');
    const req = { params: { id: String(id) }, user: { id: userId }, session: {} };
    const res = fakeRes();
    await clientIntakeReviewController.accept(req, res);

    const submission = await clientIntakeRepository.find(id);
    return submission.converted_client_id;
  }

  it('still prefills on the client\'s genuinely first order', async () => {
    const clientId = await createAndConvertIntake('CIF');

    const req = { query: { client_id: String(clientId) }, session: {} };
    const res = fakeRes();
    await ordersController.create(req, res);

    const cif = await db.queryOne("SELECT id FROM incoterms WHERE code = 'CIF'");
    expect(res.renderedData.old.incoterm_id).toBe(cif.id);
  });

  it('does not keep re-prefilling once the client already has an order', async () => {
    const clientId = await createAndConvertIntake('CIF');
    await createTestOrder(clientId); // the client's first order now exists

    const req = { query: { client_id: String(clientId) }, session: {} };
    const res = fakeRes();
    await ordersController.create(req, res);

    expect(res.renderedData.old.incoterm_id).toBeUndefined();
  });
});
