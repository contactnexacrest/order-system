'use strict';

const db = require('../../src/config/db');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const ordersController = require('../../src/controllers/ordersController');

/**
 * Port of IntakePreferencesPrefillOrderFormTest.php. 90-item backlog, P1
 * (#41/#46): the buyer's commercial preferences given at Quotation-intake
 * (Incoterm, container type, discharge port) were never carried into the
 * New Order form — staff had to remember to re-enter them from scratch.
 * Confirmed via screenshots: a buyer's public QT intake genuinely
 * submitted Incoterm=CIF, but the New Order form silently defaulted to
 * FOB. This covers ordersController.create() now prefilling those fields
 * from the client's originating intake submission.
 */
describe('order create form prefills from the client\'s originating intake (#41/#46)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-intake-prefill-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  async function createAndConvertIntake(incotermPreference, containerType, portText) {
    const id = await clientIntakeRepository.create(
      {
        company_legal_name: 'Intake Prefill Buyer Ltd',
        billing_address: '1 Intake Street',
        billing_address_line1: null,
        billing_address_line2: null,
        billing_city: null,
        billing_postcode: null,
        billing_country: null,
        vat_eori_tax_no: null,
        contact_person: 'Jane Buyer',
        email: `jane-${Math.random().toString(16).slice(2, 10)}@intake-prefill.example`, // one email per client (R5-02)
        phone: null,
        country_of_destination: 'Netherlands',
        port_of_discharge_text: portText,
        coo_type: null,
        incoterm_preference: incotermPreference,
        container_type_text: containerType,
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
    expect(submission.status).toBe('converted');
    return submission.converted_client_id;
  }

  it('prefills Incoterm, container type and port from the intake', async () => {
    const clientId = await createAndConvertIntake('CIF', '40ft High Cube', 'Rotterdam');

    const req = { query: { client_id: String(clientId) }, session: {} };
    const res = fakeRes();
    await ordersController.create(req, res);

    const cif = await db.queryOne("SELECT id FROM incoterms WHERE code = 'CIF'");

    expect(res.renderedData.old.incoterm_id).toBe(cif.id);
    expect(res.renderedData.old.container_type).toBe('40ft High Cube');
    expect(res.renderedData.old.port_of_discharge_text).toBe('Rotterdam');
  });

  it('does not prefill when the intake left fields blank', async () => {
    const clientId = await createAndConvertIntake('', '', '');

    const req = { query: { client_id: String(clientId) }, session: {} };
    const res = fakeRes();
    await ordersController.create(req, res);

    expect(res.renderedData.old.incoterm_id).toBeUndefined();
    expect(res.renderedData.old.container_type).toBeUndefined();
  });
});
