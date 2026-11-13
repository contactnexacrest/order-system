'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const { createTestClient } = require('../support/fixtures');

/**
 * Port of ClientBillingAddressPartsTest.php. Owner decision D-346 (12 Nov
 * 2026): the New / Edit / Duplicate Client forms no longer have the single
 * "Billing Address" box. Line 1, City / Town and Billing Country are
 * required; clients.billing_address is composed from the parts ("Line 1,
 * Line 2, City Postcode, Country"). A legacy client with only the old single
 * box shows it under the address fields on Edit.
 */
describe('Client billing address from its parts (D-346)', () => {
  let admin;

  afterAll(async () => {
    await db.pool.end();
  });

  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-d346-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D-346 Admin', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestD346pass!', 10), role_id: role.id }
    );
    admin = request.agent(app);
    const csrf = csrfOf((await admin.get('/login')).text);
    await admin.post('/login').type('form').send({ _csrf: csrf, email, password: 'JestD346pass!' });
  });

  async function post(path, fields, page) {
    const csrf = csrfOf((await admin.get(page)).text);
    return admin.post(path).type('form').send({ _csrf: csrf, ...fields });
  }

  async function flashAfter(res) {
    const m = (await admin.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim() } : { type: null, message: '' };
  }

  async function clientCount() {
    return Number((await db.queryOne('SELECT COUNT(*) AS n FROM clients')).n);
  }

  it('refuses a new client without Line 1, City / Town or Billing Country, with the message', async () => {
    for (const missing of ['billing_address_line1', 'billing_city', 'billing_country']) {
      const fields = {
        company_legal_name: 'D-346 Missing Part Ltd',
        billing_address_line1: '1 Test Street',
        billing_city: 'Birmingham',
        billing_country: 'United Kingdom',
        billing_address: 'An old single-box address is ignored',
      };
      fields[missing] = '';
      const before = await clientCount();
      const res = await post('/clients', fields, '/clients/create');
      expect(await clientCount()).toBe(before);
      expect((await flashAfter(res)).message).toBe('Company legal name, Billing Address Line 1, City / Town and Billing Country are required.');
    }
  });

  it('stores the composed address when a new client is created with the parts', async () => {
    const res = await post('/clients', {
      company_legal_name: 'D-346 Composed Address Ltd',
      billing_address_line1: '1 Test Street',
      billing_address_line2: 'Unit 4',
      billing_city: 'Birmingham',
      billing_postcode: 'B1 1AA',
      billing_country: 'United Kingdom',
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, '/clients/create');
    const id = parseInt(res.headers.location.match(/\/clients\/(\d+)$/)[1], 10);
    const client = await clientRepository.find(id);
    expect(client.company_legal_name).toBe('D-346 Composed Address Ltd');
    expect(client.billing_address).toBe('1 Test Street, Unit 4, Birmingham B1 1AA, United Kingdom');
    expect(client.billing_city).toBe('Birmingham');
  });

  it('recomposes the address from the parts on edit', async () => {
    const clientId = await createTestClient();
    await post(`/clients/${clientId}/update`, {
      company_legal_name: 'Jest Test Buyer Ltd',
      billing_address_line1: '9 New Road',
      billing_city: 'Leeds',
      billing_country: 'United Kingdom',
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, `/clients/${clientId}/edit`);
    expect((await clientRepository.find(clientId)).billing_address).toBe('9 New Road, Leeds, United Kingdom');
  });

  it('shows the old single-box address on the edit form of a legacy client', async () => {
    const clientId = await createTestClient(); // billing_address only, no parts
    const html = (await admin.get(`/clients/${clientId}/edit`)).text;
    expect(html).toContain('Address on file (old single box): 1 Test Street, Test City — copy it into the boxes above.');
    expect(html).not.toContain('name="billing_address"');
    expect(html).toMatch(/<input type="text" name="billing_address_line1"[^>]*required[^>]*>/);
    expect(html).toMatch(/<input type="text" name="billing_city"[^>]*required[^>]*>/);
    expect(html).toMatch(/<input type="text" name="billing_country"[^>]*required[^>]*>/);
  });

  it('shows no old-address note for a client with its parts', async () => {
    const clientId = await createTestClient();
    await db.execute("UPDATE clients SET billing_address_line1 = '1 Test Street', billing_city = 'Test City', billing_country = 'United Kingdom' WHERE id = :id", { id: clientId });
    const html = (await admin.get(`/clients/${clientId}/edit`)).text;
    expect(html).not.toContain('Address on file (old single box)');
  });

  it('the New Client form has no single Billing Address box', async () => {
    const html = (await admin.get('/clients/create')).text;
    expect(html).not.toContain('name="billing_address"');
    expect(html).toContain('Billing Address Line 1 *');
    expect(html).toContain('City / Town *');
    expect(html).toContain('Billing Country *');
  });
});
