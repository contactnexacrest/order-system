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
 * Port of AnnexureAddProductFormTest.php. 90-item backlog #69: the "Add
 * Product Entry" form was a single bare column of <label> elements —
 * unlike every other multi-field form in the app, which uses the sitewide
 * .field-grid 2-column layout — and had no way to attach an image at
 * creation time; an image could only be added in a separate step after the
 * product entry already existed. Fixed by wrapping the form in .field-grid
 * (textareas spanning both columns via label.full, matching the
 * established convention) and adding an optional image upload accepted
 * directly on product creation.
 */
describe('Annexure Add Product Entry form (90-item backlog #69)', () => {
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

  it('renders the Add Product Entry form using the 2-column field-grid layout', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}/annexure`);
    expect(res.status).toBe(200);

    const addFormPos = res.text.indexOf('Add Product Entry');
    expect(addFormPos).toBeGreaterThan(-1);
    const addFormSection = res.text.slice(addFormPos, addFormPos + 2000);

    expect(addFormSection).toContain('class="field-grid"');
    expect(addFormSection).toContain('<label class="full">Description');
    expect(addFormSection).toContain('<label class="full">Components');
    expect(addFormSection).toContain('<label class="full">Technical Notes');
  });

  it('renders an optional image upload field directly on the Add Product Entry form', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}/annexure`);
    const addFormPos = res.text.indexOf('Add Product Entry');
    const addFormSection = res.text.slice(addFormPos, addFormPos + 2000);

    expect(addFormSection).toContain('enctype="multipart/form-data"');
    expect(addFormSection).toContain('<input type="file" name="image"');
  });

  it('still creates a product entry successfully when no image is selected', async () => {
    // Regression guard: adding multer's upload.single('image') + the optional
    // image-upload branch to createProduct must not break the pre-existing
    // "no image" path that every prior Annexure product entry has used.
    const orderId = await createTestOrder(await createTestClient());

    const page = await agent.get(`/orders/${orderId}/annexure`);
    const csrf = extractCsrf(page.text);

    const res = await agent
      .post(`/orders/${orderId}/annexure/products`)
      .type('form')
      .send({ _csrf: csrf, name: 'Marble Side Table', description: 'Round top', dimensions: '60 x 60 x 45 cm', finish: 'Polished', components: '', technical_notes: '' });

    expect(res.status).toBe(302);
    expect(res.headers.location).toBe(`/orders/${orderId}/annexure`);

    const products = await orderAnnexureRepository.forOrder(orderId);
    expect(products).toHaveLength(1);
    expect(products[0].name).toBe('Marble Side Table');
    expect(products[0].images).toHaveLength(0);
  });
});
