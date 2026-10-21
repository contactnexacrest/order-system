'use strict';

const db = require('../../src/config/db');
const portRepository = require('../../src/repositories/portRepository');

/**
 * Port of PortRepositoryTest.php. 90-item backlog #59: `ports` already
 * existed (docs/schema.sql) and already backed lookupRepository.ports(),
 * but had no admin CRUD at all — only the single seeded Chennai (loading)
 * row existed, so the New Order form's Port of Discharge dropdown was
 * genuinely empty. This covers the new CRUD surface plus setDefault()'s
 * role-scoping: a 'both'-role port appears in BOTH the loading and
 * discharge dropdowns (lookupRepository.ports() matches `port_role =
 * :role OR port_role = 'both'`), so setting a 'both' port as default must
 * clear every other port's default flag, or a filtered query could
 * return two rows both marked is_default and the view would mark two
 * <option>s "selected" simultaneously.
 */
describe('PortRepository (90-item backlog #59)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('create then find returns all fields', async () => {
    const id = await portRepository.create('Jest Test Port', 'Testville', 'Testland', 'discharge', 5);

    const row = await portRepository.find(id);

    expect(row).not.toBeNull();
    expect(row.name).toBe('Jest Test Port');
    expect(row.city).toBe('Testville');
    expect(row.country).toBe('Testland');
    expect(row.port_role).toBe('discharge');
    expect(row.sort_order).toBe(5);
    expect(row.is_default).toBe(0);
    expect(row.is_active).toBe(1);
  });

  it('country is optional', async () => {
    const id = await portRepository.create('Jest No-Country Port', null, null, 'loading', 1);

    expect((await portRepository.find(id)).country).toBeNull();
  });

  // 90-item backlog "ports city field": city is independent of country/
  // name — a port can have one without the other, and it must never be
  // confused with either in storage.
  it('city is optional and independent of country', async () => {
    const id = await portRepository.create('Jest No-City Port', null, 'Testland', 'loading', 1);

    const row = await portRepository.find(id);
    expect(row.city).toBeNull();
    expect(row.country).toBe('Testland');
  });

  it('update overwrites fields', async () => {
    const id = await portRepository.create('Jest Original Port', 'Oldville', 'Testland', 'loading', 1);

    await portRepository.update(id, 'Jest Renamed Port', 'Newville', 'Newland', 'both', 2);

    const row = await portRepository.find(id);
    expect(row.name).toBe('Jest Renamed Port');
    expect(row.city).toBe('Newville');
    expect(row.country).toBe('Newland');
    expect(row.port_role).toBe('both');
    expect(row.sort_order).toBe(2);
  });

  it('toggleActive flips both ways', async () => {
    const id = await portRepository.create('Jest Toggle Port', null, null, 'discharge', 1);
    expect((await portRepository.find(id)).is_active).toBe(1);

    await portRepository.toggleActive(id);
    expect((await portRepository.find(id)).is_active).toBe(0);

    await portRepository.toggleActive(id);
    expect((await portRepository.find(id)).is_active).toBe(1);
  });

  it('all() excludes inactive by default but includes on request', async () => {
    const activeId = await portRepository.create('Jest Active Port', null, null, 'discharge', 1);
    const inactiveId = await portRepository.create('Jest Inactive Port', null, null, 'discharge', 2);
    await portRepository.toggleActive(inactiveId);

    const activeOnly = (await portRepository.all(false)).map((p) => p.id);
    expect(activeOnly).toContain(activeId);
    expect(activeOnly).not.toContain(inactiveId);

    const withInactive = (await portRepository.all(true)).map((p) => p.id);
    expect(withInactive).toContain(activeId);
    expect(withInactive).toContain(inactiveId);
  });

  it('setDefault on a loading port clears other loading and both ports only', async () => {
    const loadingA = await portRepository.create('Jest Loading A', null, null, 'loading', 1);
    const loadingB = await portRepository.create('Jest Loading B', null, null, 'loading', 2);
    const bothPort = await portRepository.create('Jest Both Port', null, null, 'both', 3);
    const dischargeOnly = await portRepository.create('Jest Discharge Only', null, null, 'discharge', 4);
    await portRepository.setDefault(loadingA);
    await portRepository.setDefault(bothPort);
    // bothPort being default cleared loadingA's flag too (it appears in the
    // loading list) — set dischargeOnly default next so there's a
    // non-'both' default to prove the targeted (non-'both') branch below.
    await portRepository.setDefault(dischargeOnly);

    await portRepository.setDefault(loadingB);

    expect((await portRepository.find(loadingB)).is_default).toBe(1);
    expect((await portRepository.find(bothPort)).is_default).toBe(0);
    expect((await portRepository.find(dischargeOnly)).is_default).toBe(1);
  });

  it("setDefault on a both-role port clears every other port", async () => {
    const loading = await portRepository.create('Jest Loading X', null, null, 'loading', 1);
    const discharge = await portRepository.create('Jest Discharge X', null, null, 'discharge', 2);
    const bothPort = await portRepository.create('Jest Both X', null, null, 'both', 3);
    await portRepository.setDefault(loading);
    await portRepository.setDefault(discharge);

    await portRepository.setDefault(bothPort);

    expect((await portRepository.find(bothPort)).is_default).toBe(1);
    expect((await portRepository.find(loading)).is_default).toBe(0);
    expect((await portRepository.find(discharge)).is_default).toBe(0);
  });
});
