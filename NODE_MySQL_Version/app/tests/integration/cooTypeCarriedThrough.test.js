'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const piIntakeReviewController = require('../../src/controllers/piIntakeReviewController');
const clientRepository = require('../../src/repositories/clientRepository');
const lookupRepository = require('../../src/repositories/lookupRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const userRepository = require('../../src/repositories/userRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

/**
 * Port of CooTypeCarriedThroughTest.php. Owner report (2026-10-26): the
 * Certificate of Origin Type a client gave on the Quotation details form
 * was lost — the form took free text ("GSP Form A") but only an exact
 * match to an admin option ("GSP Form A (Preferential)") was kept. It now
 * is a dropdown, loose spellings are matched, and the value follows the
 * client to the client record, the New Order form, the order and the
 * PI-details confirmation.
 */
describe('Certificate of Origin Type follows the client', () => {
  let adminId;
  let staff;

  beforeAll(async () => {
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
    staff = request.agent(app);
    const login = await staff.get('/login');
    await staff.post('/login').type('form').send({ _csrf: csrfOf(login.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const fakeReq = (params) => ({ params, body: {}, query: {}, session: {}, user: { id: adminId, name: 'Jest Admin' } });
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {} });

  it('matches loose spellings to the admin options', () => {
    const options = ['GSP Form A (Preferential)', 'Non-Preferential'];
    expect(lookupRepository.matchOption(options, 'GSP Form A')).toBe('GSP Form A (Preferential)');
    expect(lookupRepository.matchOption(options, '  gsp form a (preferential) ')).toBe('GSP Form A (Preferential)');
    expect(lookupRepository.matchOption(options, 'non-preferential')).toBe('Non-Preferential');
    expect(lookupRepository.matchOption(options, 'Something else')).toBe('');
    expect(lookupRepository.matchOption(options, '')).toBe('');
  });

  it("carries the client's Quotation answer to the client record and the New Order form", async () => {
    const buyer = request.agent(app);
    const form = await buyer.get('/quotation-details');
    expect(form.text).toContain('<select name="coo_type">');
    expect(form.text).toContain('>GSP Form A (Preferential)<');

    await buyer.post('/quotation-details/submit').type('form').send({
      _csrf: csrfOf(form.text),
      company_legal_name: `COO Carry Test Co ${Math.random().toString(16).slice(2, 8)}`,
      billing_address_line1: '1 Test Street',
      billing_city: 'Testville',
      billing_country: 'United Kingdom',
      vat_eori_tax_no: 'GB123456789',
      contact_person: 'Jane Test',
      email: `coo-${Math.random().toString(16).slice(2, 10)}@example.test`,
      country_of_destination: 'United Kingdom',
      incoterm_preference: 'FOB',
      coo_type: 'GSP Form A',
    });
    const submission = await db.queryOne('SELECT id, coo_type FROM client_intake_submissions ORDER BY id DESC LIMIT 1');
    expect(submission.coo_type).toBe('GSP Form A (Preferential)');

    await clientIntakeReviewController.accept(fakeReq({ id: String(submission.id) }), fakeRes());
    const { converted_client_id: clientId } = await db.queryOne('SELECT converted_client_id FROM client_intake_submissions WHERE id = :id', { id: submission.id });
    expect((await clientRepository.find(clientId)).coo_type).toBe('GSP Form A (Preferential)');

    const clientPage = await staff.get(`/clients/${clientId}`);
    expect(clientPage.text).toContain('Certificate of Origin Type');
    expect(clientPage.text).toContain('GSP Form A (Preferential)');

    const newOrder = await staff.get(`/orders/create?client_id=${clientId}`);
    expect(newOrder.text).toMatch(/<option value="GSP Form A \(Preferential\)"\s+selected>/);
  });

  it('updates the order from the PI confirmation, and a blank answer keeps the known value', async () => {
    for (const [answer, expectedClient] of [['GSP Form A (Preferential)', 'GSP Form A (Preferential)'], ['', 'Non-Preferential']]) {
      const clientId = await createTestClient();
      await db.execute('UPDATE clients SET coo_type = :c WHERE id = :id', { c: 'Non-Preferential', id: clientId });
      const orderId = await createTestOrder(clientId, 'FOB');
      const token = await piIntakeRepository.createLink(orderId, null);
      const submission = await piIntakeRepository.findValidByToken(token);
      expect((await request(app).get(`/pi-details/${token}`)).text).toContain('<select name="coo_type">');

      await piIntakeRepository.submit(submission.id, {
        company_legal_name: submission.company_legal_name, billing_address: submission.billing_address,
        vat_eori_tax_no: submission.vat_eori_tax_no, contact_person: submission.contact_person,
        email: submission.email, phone: submission.phone,
        port_of_discharge_text: null, country_of_destination: submission.country_of_destination,
        incoterm_confirmed: null, container_type_text: null, payment_terms_confirmation: null,
        quotation_acceptance_reference: null, coo_type: answer || null, buyer_po_ref: null,
        changes_from_quotation: null, special_document_requirements: null,
      }, '127.0.0.1');
      await piIntakeReviewController.accept(fakeReq({ id: String(submission.id) }), fakeRes());

      expect([answer, (await clientRepository.find(clientId)).coo_type]).toEqual([answer, expectedClient]);
      if (answer) {
        expect((await orderRepository.find(orderId)).coo_type).toBe(answer);
      }
    }
  });
});
