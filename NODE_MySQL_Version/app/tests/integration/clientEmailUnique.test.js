'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const clientsController = require('../../src/controllers/clientsController');
const clientRepository = require('../../src/repositories/clientRepository');
const { createTestClient } = require('../support/fixtures');

/**
 * Port of ClientEmailUniqueTest.php. QA Round 5, R5-02 (owner decision
 * 2026-10-28): two clients may not share an email — the client portal
 * logs in by email. Refused on create, duplicate and edit, case- and
 * space-insensitively; a client keeps its own email when edited.
 */
describe('One email per client (QA Round 5, R5-02)', () => {
  let userId;

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-clientemail-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    userId = result.insertId;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const uniqueEmail = () => `unique-${Math.random().toString(16).slice(2, 10)}@example.test`;
  const clientCount = async () => Number((await db.queryOne('SELECT COUNT(*) AS c FROM clients')).c);
  const fakeRes = () => ({ redirect() {}, status() { return this; }, send() {}, renderView() {} });
  const fakeReq = (body, params = {}) => ({
    session: {}, user: { id: userId }, permissions: {}, params,
    body: { billing_address: '1 Test Street', consignee_same_as_buyer: '1', notify_party_same_as_consignee: '1', ...body },
  });

  it('refuses a second client with the same email', async () => {
    const email = uniqueEmail();
    await createTestClient(email);
    const before = await clientCount();

    const req = fakeReq({ company_legal_name: 'Second Buyer Ltd', email: `  ${email.toUpperCase()} ` });
    await clientsController.store(req, fakeRes());

    expect(await clientCount()).toBe(before);
    expect(flash.pull(req)[0].message).toContain('already uses the email');
  });

  it('accepts a different email', async () => {
    await createTestClient(uniqueEmail());
    const before = await clientCount();
    await clientsController.store(fakeReq({ company_legal_name: 'Third Buyer Ltd', email: uniqueEmail() }), fakeRes());
    expect(await clientCount()).toBe(before + 1);
  });

  it("refuses editing a client onto another client's email but lets it keep its own", async () => {
    const takenEmail = uniqueEmail();
    await createTestClient(takenEmail);
    const ownEmail = uniqueEmail();
    const clientId = await createTestClient(ownEmail);

    const refused = fakeReq({ company_legal_name: 'Edited Buyer Ltd', email: takenEmail }, { id: String(clientId) });
    await clientsController.update(refused, fakeRes());
    expect((await clientRepository.find(clientId)).email).toBe(ownEmail);
    expect(flash.pull(refused)[0].message).toContain('already uses the email');

    await clientsController.update(fakeReq({ company_legal_name: 'Edited Buyer Ltd', email: ownEmail }, { id: String(clientId) }), fakeRes());
    expect((await clientRepository.find(clientId)).company_legal_name).toBe('Edited Buyer Ltd');
  });
});
