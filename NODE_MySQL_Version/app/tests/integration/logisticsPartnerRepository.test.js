'use strict';

const db = require('../../src/config/db');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');

/**
 * CHA / Transportation partner directory (docs/schema.sql Section AQ) —
 * covers the CRUD surface plus the one piece of real logic: a partner
 * with service_type 'both' must show up when filtering for either 'cha'
 * or 'transportation' alone, since that's the whole point of the flag
 * (the same company sometimes does one, sometimes the other, sometimes
 * both).
 */
describe('LogisticsPartnerRepository', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function minimalData(overrides = {}) {
    return {
      partner_name: 'Jest Test Logistics Co',
      service_type: 'cha',
      address: '1 Test Port Road',
      city: 'Test City',
      state: 'Test State',
      phone: '9999900000',
      whatsapp_number: '9999900000',
      email: 'ops@jest-test-logistics.test',
      contact_person_name: 'Test Contact',
      contact_person_phone: '9999900001',
      contact_person_whatsapp: '9999900001',
      gstin: '27TESTG1234F1Z5',
      pan: 'TESTG1234F',
      notes: 'Jest fixture',
      ...overrides,
    };
  }

  it('create then find returns all fields', async () => {
    const userId = await createTestUser('Admin');
    const id = await logisticsPartnerRepository.create(minimalData(), userId);

    const row = await logisticsPartnerRepository.find(id);

    expect(row.partner_name).toBe('Jest Test Logistics Co');
    expect(row.service_type).toBe('cha');
    expect(row.gstin).toBe('27TESTG1234F1Z5');
    expect(row.is_active).toBe(1);
    expect(row.created_by).toBe(userId);
  });

  it('update overwrites fields', async () => {
    const userId = await createTestUser('Admin');
    const id = await logisticsPartnerRepository.create(minimalData(), userId);

    await logisticsPartnerRepository.update(id, minimalData({
      partner_name: 'Jest Renamed Logistics Co',
      service_type: 'both',
      gstin: '27TESTG9999F1Z5',
    }));

    const row = await logisticsPartnerRepository.find(id);
    expect(row.partner_name).toBe('Jest Renamed Logistics Co');
    expect(row.service_type).toBe('both');
    expect(row.gstin).toBe('27TESTG9999F1Z5');
  });

  it('toggleActive flips both ways', async () => {
    const userId = await createTestUser('Admin');
    const id = await logisticsPartnerRepository.create(minimalData(), userId);
    expect((await logisticsPartnerRepository.find(id)).is_active).toBe(1);

    await logisticsPartnerRepository.toggleActive(id);
    expect((await logisticsPartnerRepository.find(id)).is_active).toBe(0);

    await logisticsPartnerRepository.toggleActive(id);
    expect((await logisticsPartnerRepository.find(id)).is_active).toBe(1);
  });

  it('remove deletes the row', async () => {
    const userId = await createTestUser('Admin');
    const id = await logisticsPartnerRepository.create(minimalData(), userId);

    await logisticsPartnerRepository.remove(id);

    expect(await logisticsPartnerRepository.find(id)).toBeNull();
  });

  it('all excludes inactive by default but includes them on request', async () => {
    const userId = await createTestUser('Admin');
    const activeId = await logisticsPartnerRepository.create(minimalData({ partner_name: 'Jest Active Co' }), userId);
    const inactiveId = await logisticsPartnerRepository.create(minimalData({ partner_name: 'Jest Inactive Co' }), userId);
    await logisticsPartnerRepository.toggleActive(inactiveId);

    const activeOnly = await logisticsPartnerRepository.all(false);
    const activeOnlyIds = activeOnly.map((r) => r.id);
    expect(activeOnlyIds).toContain(activeId);
    expect(activeOnlyIds).not.toContain(inactiveId);

    const withInactive = await logisticsPartnerRepository.all(true);
    const withInactiveIds = withInactive.map((r) => r.id);
    expect(withInactiveIds).toContain(activeId);
    expect(withInactiveIds).toContain(inactiveId);
  });

  it('service_type filter matches its own type and both', async () => {
    const userId = await createTestUser('Admin');
    const chaOnlyId = await logisticsPartnerRepository.create(
      minimalData({ partner_name: 'Jest CHA Only Co', service_type: 'cha' }), userId
    );
    const transportOnlyId = await logisticsPartnerRepository.create(
      minimalData({ partner_name: 'Jest Transport Only Co', service_type: 'transportation' }), userId
    );
    const bothId = await logisticsPartnerRepository.create(
      minimalData({ partner_name: 'Jest Both Co', service_type: 'both' }), userId
    );

    const chaFiltered = (await logisticsPartnerRepository.all(false, 'cha')).map((r) => r.id);
    expect(chaFiltered).toContain(chaOnlyId);
    expect(chaFiltered).toContain(bothId);
    expect(chaFiltered).not.toContain(transportOnlyId);

    const transportFiltered = (await logisticsPartnerRepository.all(false, 'transportation')).map((r) => r.id);
    expect(transportFiltered).toContain(transportOnlyId);
    expect(transportFiltered).toContain(bothId);
    expect(transportFiltered).not.toContain(chaOnlyId);
  });

  it('optional fields default to null', async () => {
    const userId = await createTestUser('Admin');
    const id = await logisticsPartnerRepository.create(
      { partner_name: 'Jest Minimal Co', service_type: 'transportation' }, userId
    );

    const row = await logisticsPartnerRepository.find(id);
    expect(row.address).toBeNull();
    expect(row.gstin).toBeNull();
    expect(row.contact_person_name).toBeNull();
    expect(row.notes).toBeNull();
  });
});
