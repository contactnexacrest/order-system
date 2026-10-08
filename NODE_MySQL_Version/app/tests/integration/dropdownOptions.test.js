'use strict';

const db = require('../../src/config/db');
const dropdownOptionRepository = require('../../src/repositories/dropdownOptionRepository');
const dropdownOptionController = require('../../src/controllers/dropdownOptionController');

/**
 * Item 7 — admin CRUD for dropdown_options (docs/schema.sql Section BD),
 * the generic small-option-list table (container_type, coo_type, etc.)
 * that previously had no admin screen. Options are never hard-deleted —
 * only deactivated — since an existing order/submission may still carry
 * the exact text in a plain VARCHAR column with no FK to this table.
 */
describe('Dropdown Options admin CRUD (Item 7)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestStaffUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test Staff', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-staff-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function uniqueListKey() {
    return `jest_test_list_${Math.random().toString(16).slice(2, 10)}`;
  }

  function fakeReq(staffUserId, body = {}, params = {}) {
    return { user: { id: staffUserId }, body, params, session: {} };
  }

  function fakeRes() {
    const res = { redirectedTo: null, statusCode: 200, sentBody: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.sentBody = body; };
    return res;
  }

  test('create() inserts a new option with is_active=1 and is_default=0', async () => {
    const listKey = uniqueListKey();
    const id = await dropdownOptionRepository.create(listKey, 'First Option', 1);

    const grouped = await dropdownOptionRepository.allGrouped(true);
    const row = grouped[listKey].find((r) => r.id === id);
    expect(row.option_value).toBe('First Option');
    expect(row.is_active).toBeTruthy();
    expect(row.is_default).toBeFalsy();
  });

  test('allGrouped(false) excludes inactive options; allGrouped(true) includes them', async () => {
    const listKey = uniqueListKey();
    const activeId = await dropdownOptionRepository.create(listKey, 'Active One', 1);
    const inactiveId = await dropdownOptionRepository.create(listKey, 'Inactive One', 2);
    await dropdownOptionRepository.toggleActive(inactiveId);

    const activeOnly = await dropdownOptionRepository.allGrouped(false);
    const activeIds = activeOnly[listKey].map((r) => r.id);
    expect(activeIds).toContain(activeId);
    expect(activeIds).not.toContain(inactiveId);

    const everything = await dropdownOptionRepository.allGrouped(true);
    const allIds = everything[listKey].map((r) => r.id);
    expect(allIds).toContain(activeId);
    expect(allIds).toContain(inactiveId);
  });

  test('update() changes the value and sort order', async () => {
    const listKey = uniqueListKey();
    const id = await dropdownOptionRepository.create(listKey, 'Original', 5);

    await dropdownOptionRepository.update(id, 'Renamed', 9);

    const grouped = await dropdownOptionRepository.allGrouped(true);
    const row = grouped[listKey].find((r) => r.id === id);
    expect(row.option_value).toBe('Renamed');
    expect(row.sort_order).toBe(9);
  });

  test('setDefault() unsets the previous default and sets the new one', async () => {
    const listKey = uniqueListKey();
    const firstId = await dropdownOptionRepository.create(listKey, 'First', 1);
    const secondId = await dropdownOptionRepository.create(listKey, 'Second', 2);
    await dropdownOptionRepository.setDefault(firstId, listKey);

    let grouped = await dropdownOptionRepository.allGrouped(true);
    expect(grouped[listKey].find((r) => r.id === firstId).is_default).toBeTruthy();
    expect(grouped[listKey].find((r) => r.id === secondId).is_default).toBeFalsy();

    await dropdownOptionRepository.setDefault(secondId, listKey);

    grouped = await dropdownOptionRepository.allGrouped(true);
    expect(grouped[listKey].find((r) => r.id === firstId).is_default).toBeFalsy();
    expect(grouped[listKey].find((r) => r.id === secondId).is_default).toBeTruthy();
  });

  test('toggleActive() flips is_active each call', async () => {
    const listKey = uniqueListKey();
    const id = await dropdownOptionRepository.create(listKey, 'Toggle Me', 1);

    await dropdownOptionRepository.toggleActive(id);
    let row = (await dropdownOptionRepository.allGrouped(true))[listKey].find((r) => r.id === id);
    expect(row.is_active).toBeFalsy();

    await dropdownOptionRepository.toggleActive(id);
    row = (await dropdownOptionRepository.allGrouped(true))[listKey].find((r) => r.id === id);
    expect(row.is_active).toBeTruthy();
  });

  test('controller.create() rejects an empty value without touching the database', async () => {
    const listKey = uniqueListKey();
    const staffId = await createTestStaffUser();

    await dropdownOptionController.create(fakeReq(staffId, { option_value: '   ' }, { listKey }), fakeRes());

    const grouped = await dropdownOptionRepository.allGrouped(true);
    expect(grouped[listKey]).toBeUndefined();
  });

  test('controller.create() assigns the next sort_order after existing options', async () => {
    const listKey = uniqueListKey();
    await dropdownOptionRepository.create(listKey, 'Existing 1', 1);
    await dropdownOptionRepository.create(listKey, 'Existing 5', 5);
    const staffId = await createTestStaffUser();

    await dropdownOptionController.create(fakeReq(staffId, { option_value: 'New One' }, { listKey }), fakeRes());

    const grouped = await dropdownOptionRepository.allGrouped(true);
    const newRow = grouped[listKey].find((r) => r.option_value === 'New One');
    expect(newRow.sort_order).toBe(6);
  });

  test('controller.update() bulk-applies value/sort_order/active changes and sets the default', async () => {
    const listKey = uniqueListKey();
    const id1 = await dropdownOptionRepository.create(listKey, 'Row One', 1);
    const id2 = await dropdownOptionRepository.create(listKey, 'Row Two', 2);
    const staffId = await createTestStaffUser();

    await dropdownOptionController.update(fakeReq(staffId, {
      option_value: { [id1]: 'Row One Renamed', [id2]: 'Row Two' },
      sort_order: { [id1]: '1', [id2]: '2' },
      is_active: { [id1]: '1' }, // id2 omitted -> deactivated
      default_id: String(id2),
    }, { listKey }), fakeRes());

    const grouped = await dropdownOptionRepository.allGrouped(true);
    const row1 = grouped[listKey].find((r) => r.id === id1);
    const row2 = grouped[listKey].find((r) => r.id === id2);
    expect(row1.option_value).toBe('Row One Renamed');
    expect(row1.is_active).toBeTruthy();
    expect(row2.is_active).toBeFalsy();
    expect(row2.is_default).toBeTruthy();
  });

  test('controller.update() redirects with an error for an unknown list_key', async () => {
    const staffId = await createTestStaffUser();
    const res = fakeRes();

    await dropdownOptionController.update(fakeReq(staffId, {}, { listKey: 'totally_unknown_list_key_xyz' }), res);

    expect(res.redirectedTo).toBe('/admin/dropdown-options');
  });
});
