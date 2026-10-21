'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const orderAnnexureRepository = require('../../src/repositories/orderAnnexureRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of AnnexureEditProductFormTest.php. User-reported batch item 5:
 * "why product table is single row format - wach and every input form
 * must be 2 column per row format wherever possible". 90-item backlog
 * #69 (annexureAddProductForm.test.js) already fixed the "Add Product
 * Entry" form below this one to the sitewide .field-grid 2-column
 * layout, but left the per-entry EDIT form (one per already-saved
 * product, in the "Product Entries" section above it) as the original
 * bare single column of <label> elements — the exact screenshot-
 * confirmed gap this closes.
 */
describe('Annexure per-entry edit form (user-reported batch, item 5)', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    await userRepository.findByEmail(TEST_ADMIN_EMAIL);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('renders the per-entry edit form using the 2-column field-grid layout', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderAnnexureRepository.createProduct(orderId, {
      name: 'Marble Side Table',
      description: 'Round top',
      dimensions: '60 x 60 x 45 cm',
      finish: 'Polished',
      components: null,
      technical_notes: null,
    });

    const res = await agent.get(`/orders/${orderId}/annexure`);
    expect(res.status).toBe(200);

    const entriesPos = res.text.indexOf('Product Entries');
    const addFormPos = res.text.indexOf('Add Product Entry');
    expect(entriesPos).toBeGreaterThan(-1);
    expect(addFormPos).toBeGreaterThan(-1);
    const editFormSection = res.text.slice(entriesPos, addFormPos);

    expect(editFormSection).toContain('class="field-grid"');
    expect(editFormSection).toContain('<label class="full">Description');
    expect(editFormSection).toContain('<label class="full">Components');
    expect(editFormSection).toContain('<label class="full">Technical Notes');
  });
});
