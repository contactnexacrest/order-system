'use strict';

// Owner decision 2 Nov 2026 (point 4): the Agreements register — clients, suppliers, logistics
// partners, associates and staff; status; reminders 30 / 7 days before expiry and on the day, once
// each; staff agreements only for manage_agreements; the client and associate agreements kept on
// their own pages are listed too, and the client footer on documents is unchanged.
// PHP twin: AgreementsRegisterTest.php.

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const agreementRepository = require('../../src/repositories/agreementRepository');
const agreementAlertService = require('../../src/services/agreementAlertService');
const agreementController = require('../../src/controllers/agreementController');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

describe('Agreements register (owner point 4, 2 Nov 2026)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const tag = () => crypto.randomBytes(3).toString('hex');

  async function user(roleName, name = null) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-agr-${tag()}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES (:name, :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { name: name || `Jest ${roleName}`, email, hash: await bcrypt.hash('JestAgr5!', 10), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function signIn(u) {
    const agent = request.agent(app);
    const page = await agent.get('/login');
    const csrf = (page.text.match(/name="_csrf"\s+value="([^"]+)"/) || [])[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: 'JestAgr5!' });
    return agent;
  }

  async function supplier(name) {
    return (await db.execute('INSERT INTO suppliers (supplier_legal_name, is_active) VALUES (:n, 1)', { n: `${name} ${tag()}` })).insertId;
  }

  async function agreement(type, partyId, expires, agreementType = 'Supply Agreement (Quarry/Factory)', userId = null) {
    const u = userId || (await user('Admin')).id;
    return agreementRepository.create({ party_type: type, party_id: partyId, agreement_type: agreementType, signed_on: '2026-01-10', expires_on: expires, file_id: await createTestFile(null, null) }, u);
  }

  const countNotes = async (like) => Number((await db.queryOne(
    "SELECT COUNT(*) AS n FROM notifications WHERE type = 'agreement_expiry' AND message LIKE :m", { m: `%${like}%` }
  )).n);

  it('status follows the dates', () => {
    const t = '2026-11-02';
    expect(agreementRepository.statusOf({ expires_on: '2027-01-01' }, t)).toBe('active');
    expect(agreementRepository.statusOf({ expires_on: '2026-12-02' }, t)).toBe('expiring');
    expect(agreementRepository.statusOf({ expires_on: '2026-11-02' }, t)).toBe('expiring');
    expect(agreementRepository.statusOf({ expires_on: '2026-11-01' }, t)).toBe('expired');
    expect(agreementRepository.statusOf({ expires_on: null }, t)).toBe('no_expiry');
    expect(agreementRepository.statusOf({ expires_on: '2027-01-01', terminated_on: '2026-10-01' }, t)).toBe('terminated');
    expect(agreementRepository.statusOf({ expires_on: '2026-11-10', renewed: 1 }, t)).toBe('renewed');
  });

  it('add, renew and terminate; the form is checked on the server', async () => {
    const supplierId = await supplier('Register Test Quarry');
    const id = await agreement('supplier', supplierId, '2026-11-20');
    const row = (await agreementRepository.forParty('supplier', supplierId, '2026-11-02'))[0];
    expect(row.status).toBe('expiring');
    expect(row.source).toBe('register');

    const newId = await agreementRepository.create({ party_type: 'supplier', party_id: supplierId, agreement_type: 'Supply Agreement (Quarry/Factory)', signed_on: '2026-10-30', expires_on: '2028-06-30', renews_agreement_id: id }, (await user('Admin')).id);
    let byId = Object.fromEntries((await agreementRepository.forParty('supplier', supplierId)).map((r) => [r.id, r]));
    expect(byId[id].status).toBe('renewed');
    expect(byId[newId].status).toBe('active');
    await agreementRepository.terminate(newId, '2026-11-02', 'Supplier closed the quarry');
    byId = Object.fromEntries((await agreementRepository.forParty('supplier', supplierId)).map((r) => [r.id, r]));
    expect(byId[newId].status).toBe('terminated');

    const [, errors] = await agreementController.validate({ party_type: 'supplier', party_id_supplier: '999999999', agreement_type: 'Nonsense', signed_on: '2099-01-01', expires_on: '2098-01-01' }, true);
    const text = errors.join(' ');
    expect(text).toContain('Choose the supplier from the list.');
    expect(text).toContain('Choose the type of agreement.');
    expect(text).toContain('The signing date cannot be in the future.');
    expect(text).toContain('The agreement must expire after it starts');
    const [data, ok] = await agreementController.validate({ party_type: 'supplier', party_id_supplier: String(supplierId), agreement_type: 'Commercial NDA', signed_on: '2026-10-01', expires_on: '' }, true);
    expect(ok).toEqual([]);
    expect(data.expires_on).toBeNull();
  });

  it('reminders go out at 30 and 7 days and on the day, once each', async () => {
    const supplierId = await supplier('Reminder Quarry');
    await agreement('supplier', supplierId, '2026-12-02');
    const name = await agreementRepository.partyName('supplier', supplierId);
    await agreementAlertService.run('2026-11-01');
    expect(await countNotes(name)).toBe(0);
    await agreementAlertService.run('2026-11-02');
    const after30 = await countNotes(name);
    expect(after30).toBeGreaterThan(0);
    await agreementAlertService.run('2026-11-02');
    await agreementAlertService.run('2026-11-10');
    expect(await countNotes(name)).toBe(after30);
    await agreementAlertService.run('2026-11-25');
    expect(await countNotes(name)).toBe(2 * after30);
    await agreementAlertService.run('2026-12-02');
    expect(await countNotes(name)).toBe(3 * after30);
    const last = await db.queryOne("SELECT message FROM notifications WHERE type = 'agreement_expiry' AND message LIKE :m ORDER BY id DESC LIMIT 1", { m: `%${name}%` });
    expect(last.message).toContain('expires TODAY (2026-12-02)');
    await agreementAlertService.run('2026-12-03');
    expect(await countNotes(name)).toBe(3 * after30);
  });

  it('a terminated agreement gets no reminder', async () => {
    const supplierId = await supplier('Ended Quarry');
    const id = await agreement('supplier', supplierId, '2026-11-09');
    await agreementRepository.terminate(id, '2026-10-01', 'Ended early by agreement');
    await agreementAlertService.run('2026-11-02');
    expect(await countNotes(await agreementRepository.partyName('supplier', supplierId))).toBe(0);
  });

  it('staff agreements only for manage_agreements', async () => {
    const staff = await user('Export Executive', `Staff NDA Person ${tag()}`);
    const staffName = await agreementRepository.partyName('staff', staff.id);
    const id = await agreement('staff', staff.id, null, 'Staff NDA');

    const md = await signIn(await user('Managing Director'));
    expect((await md.get('/agreements?view=list')).text).toContain(staffName);

    const viewer = await signIn(await user('Finance Director'));
    const page = await viewer.get('/agreements?view=list');
    expect(page.status).toBe(200);
    expect(page.text).not.toContain(staffName);
    expect(page.text).not.toContain('/agreements/new');
    const dl = await viewer.get(`/agreements/${id}/file`);
    expect(dl.status).toBe(403);
  });

  it('client and associate agreements are listed, and the client footer is unchanged', async () => {
    const clientId = await createTestClient();
    await db.execute(
      `UPDATE clients SET company_legal_name = 'Footer Client Ltd', agreement_file_path = '/tmp/x.pdf', agreement_file_original_name = 'signed.pdf',
              agreement_uploaded_at = '2026-01-05 10:00:00', agreement_expiry_date = '2026-11-20', agreement_force_expired = 0,
              agreement_footer_text = 'Subject to the Sales & Supply Agreement dated 5 Jan 2026' WHERE id = :id`, { id: clientId }
    );
    const assocId = await require('../../src/repositories/associateRepository').create({ name: `Register Associate ${tag()}`, based_in: 'india', currency_code: 'INR', status: 'active' }, (await user('Admin')).id);
    await db.execute("UPDATE associates SET agreement_file_id = :f, agreement_signed_on = '2026-02-01', agreement_ends_on = '2027-02-01' WHERE id = :id", { f: await createTestFile(null, null), id: assocId });

    const client = await agreementRepository.forParty('client', clientId, '2026-11-02');
    expect(client).toHaveLength(1);
    expect(client[0].source).toBe('client');
    expect(client[0].status).toBe('expiring');
    const assoc = await agreementRepository.forParty('associate', assocId, '2026-11-02');
    expect(assoc[0].agreement_type).toBe('Associate Agreement');
    expect(assoc[0].status).toBe('active');

    const orderId = await createTestOrder(clientId);
    const data = await documentDataAssembler.assemble(orderId, 'QT');
    expect(JSON.stringify(data)).toContain('Subject to the Sales & Supply Agreement dated 5 Jan 2026');

    await agreementAlertService.run('2026-11-13');
    expect(await countNotes('Footer Client Ltd')).toBeGreaterThan(0);
  });

  it('filters and the party card', async () => {
    const supplierId = await supplier('Filter Quarry');
    const plus = (d) => new Date(Date.now() + d * 86400000).toISOString().slice(0, 10);
    await agreement('supplier', supplierId, plus(10));
    await agreement('supplier', supplierId, plus(200), 'Commercial NDA');
    const name = await agreementRepository.partyName('supplier', supplierId);
    const admin = await signIn(await user('Admin'));
    const strip = (html) => html.replace(/<select[\s\S]*?<\/select>/g, '');
    const within = strip((await admin.get(`/agreements?view=list&within=30&q=${encodeURIComponent(name)}`)).text);
    expect(within).toContain('Supply Agreement (Quarry/Factory)');
    expect(within).not.toContain('Commercial NDA');
    const nda = strip((await admin.get(`/agreements?view=list&type=${encodeURIComponent('Commercial NDA')}&q=${encodeURIComponent(name)}`)).text);
    expect(nda).toContain('Commercial NDA');
    expect(nda).not.toContain('Supply Agreement (Quarry/Factory)<');

    const card = (await admin.get(`/suppliers/${supplierId}/edit`)).text;
    const section = card.slice(card.indexOf('id="agreements"'));
    expect(section).toContain('Add agreement');
    expect((section.match(/<tr>/g) || []).length - 1).toBe(2);
  });
});
