'use strict';

const db = require('../../src/config/db');
const clientsController = require('../../src/controllers/clientsController');
const clientRepository = require('../../src/repositories/clientRepository');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-clientdup-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

/**
 * Port of ClientDuplicateTest.php. 90-item backlog Phase 6: a "Duplicate"
 * action for a CLIENT — re-onboard a near-identical but genuinely
 * distinct entity (a sister company, a corrected re-entry) under a
 * brand-new Buyer Inquiry Ref (client_unique_number), never the
 * source's own. duplicateStore() refuses the save unless at least one
 * field was actually changed from the source.
 */
describe('Client duplicate (90-item backlog Phase 6)', () => {
  let userId;

  beforeAll(async () => {
    userId = await createTestUser('Admin');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  function fakeReq(body = {}, params = {}) {
    return { session: {}, user: { id: userId }, body, params };
  }

  async function createSourceClient() {
    const uniqueNumber = `NC/SC/TEST/${Math.random().toString(16).slice(2, 10)}`;
    const result = await db.execute(
      `INSERT INTO clients (client_unique_number, company_legal_name, billing_address, contact_person, email, phone, country_of_destination, coo_type, consignee_same_as_buyer, notify_party_same_as_consignee, created_by)
       VALUES (:unique_number, 'Acme Exports Ltd', '1 Test Street, Testville', 'Jane Buyer', 'jane@example.test', '+1 555 0000', 'United Kingdom', 'To Be Confirmed', 1, 1, :created_by)`,
      { unique_number: uniqueNumber, created_by: userId }
    );
    return clientRepository.find(result.insertId);
  }

  it('duplicateForm 404s for a missing client', async () => {
    const res = fakeRes();
    await clientsController.duplicateForm(fakeReq({}, { id: '999999999' }), res);
    expect(res.statusCode).toBe(404);
  });

  it('duplicateForm prefills from the source client', async () => {
    const source = await createSourceClient();
    const res = fakeRes();

    await clientsController.duplicateForm(fakeReq({}, { id: String(source.id) }), res);

    expect(res.locals.old.company_legal_name).toBe('Acme Exports Ltd');
    expect(res.locals.old.email).toBe('jane@example.test');
    expect(res.locals.duplicateOf.id).toBe(source.id);
  });

  it('duplicateStore refuses an exact copy with no changes', async () => {
    const source = await createSourceClient();
    const countBefore = (await db.queryOne('SELECT COUNT(*) AS c FROM clients')).c;

    const res = fakeRes();
    await clientsController.duplicateStore(fakeReq({
      company_legal_name: source.company_legal_name,
      billing_address: source.billing_address,
      contact_person: source.contact_person,
      email: source.email,
      phone: source.phone,
      country_of_destination: source.country_of_destination,
      coo_type: source.coo_type,
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, { id: String(source.id) }), res);

    const countAfter = (await db.queryOne('SELECT COUNT(*) AS c FROM clients')).c;
    expect(countAfter).toBe(countBefore);
  });

  it('duplicateStore succeeds when at least one field differs', async () => {
    const source = await createSourceClient();

    const res = fakeRes();
    await clientsController.duplicateStore(fakeReq({
      company_legal_name: 'Acme Exports (Europe) Ltd',
      billing_address: source.billing_address,
      contact_person: source.contact_person,
      // QA Round 5, R5-02: a duplicate needs its own email too.
      email: `europe-${Math.random().toString(16).slice(2, 8)}@example.test`,
      phone: source.phone,
      country_of_destination: source.country_of_destination,
      coo_type: source.coo_type,
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, { id: String(source.id) }), res);

    const newId = (await db.queryOne('SELECT MAX(id) AS id FROM clients')).id;
    const created = await clientRepository.find(newId);

    expect(created.company_legal_name).toBe('Acme Exports (Europe) Ltd');
    expect(created.duplicate_of_client_id).toBe(source.id);
    expect(created.client_unique_number).not.toBe(source.client_unique_number);
  });

  it('duplicateStore logs the audit trail', async () => {
    const source = await createSourceClient();

    const res = fakeRes();
    await clientsController.duplicateStore(fakeReq({
      company_legal_name: source.company_legal_name,
      billing_address: 'A genuinely different address, 2 New Road',
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, { id: String(source.id) }), res);

    const newId = (await db.queryOne('SELECT MAX(id) AS id FROM clients')).id;
    const log = await db.queryOne(
      "SELECT * FROM audit_log WHERE action_type = 'CLIENT_DUPLICATED' AND entity_type = 'clients' AND entity_id = :id",
      { id: newId }
    );

    expect(log).not.toBeNull();
    expect(log.new_value).toBe(String(source.id));
  });

  it('duplicateStore 404s for a missing client', async () => {
    const res = fakeRes();
    await clientsController.duplicateStore(fakeReq({ company_legal_name: 'X', billing_address: 'Y' }, { id: '999999999' }), res);
    expect(res.statusCode).toBe(404);
  });

  it('duplicateStore still requires company name and billing address', async () => {
    const source = await createSourceClient();
    const countBefore = (await db.queryOne('SELECT COUNT(*) AS c FROM clients')).c;

    const res = fakeRes();
    await clientsController.duplicateStore(fakeReq({ company_legal_name: '', billing_address: '' }, { id: String(source.id) }), res);

    const countAfter = (await db.queryOne('SELECT COUNT(*) AS c FROM clients')).c;
    expect(countAfter).toBe(countBefore);
  });

  it('findDuplicatesOf returns every client duplicated from it', async () => {
    const source = await createSourceClient();
    const res = fakeRes();
    await clientsController.duplicateStore(fakeReq({
      company_legal_name: 'Acme Exports (Branch 2)',
      billing_address: source.billing_address,
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, { id: String(source.id) }), res);

    const duplicates = await clientRepository.findDuplicatesOf(source.id);

    expect(duplicates.length).toBe(1);
    expect(duplicates[0].company_legal_name).toBe('Acme Exports (Branch 2)');
  });

  it('show page links both directions of the duplicate relationship', async () => {
    const source = await createSourceClient();
    const createRes = fakeRes();
    await clientsController.duplicateStore(fakeReq({
      company_legal_name: 'Acme Exports (Branch 3)',
      billing_address: source.billing_address,
      consignee_same_as_buyer: '1',
      notify_party_same_as_consignee: '1',
    }, { id: String(source.id) }), createRes);
    const newId = (await db.queryOne('SELECT MAX(id) AS id FROM clients')).id;

    const sourceRes = fakeRes();
    await clientsController.show(fakeReq({}, { id: String(source.id) }), sourceRes);
    expect(sourceRes.locals.duplicatedInto.map((d) => d.company_legal_name)).toContain('Acme Exports (Branch 3)');

    const newRes = fakeRes();
    await clientsController.show(fakeReq({}, { id: String(newId) }), newRes);
    expect(newRes.locals.duplicateOfClient.company_legal_name).toBe('Acme Exports Ltd');
  });
});
