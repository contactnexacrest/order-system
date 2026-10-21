'use strict';

const db = require('../../src/config/db');
const userController = require('../../src/controllers/userController');
const userRepository = require('../../src/repositories/userRepository');
const auditLogRepository = require('../../src/repositories/auditLogRepository');

/**
 * Phase 3 item 10 of the approved remediation plan: "User 3-way status
 * filter (active/inactive/archive) + delete-if-unreferenced (only a
 * 2-way active/inactive toggle exists)." Archive is a third, distinct
 * state from a merely deactivated account. Delete is a real DELETE,
 * left to MySQL's own FK constraints (57+ tables reference users.id,
 * all ON DELETE RESTRICT) to decide "is this user referenced anywhere"
 * rather than a hand-maintained check. Port of PHP's
 * UserArchiveAndDeleteTest.php.
 */
describe('User archive and delete (Phase 3 item 10)', () => {
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

  function fakeReq(params, actorId) {
    return { params, body: {}, query: {}, user: { id: actorId }, session: {} };
  }
  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function callAsAdmin(action, userId) {
    const actorId = await createTestUser('Admin');
    await userController[action](fakeReq({ id: String(userId) }, actorId), fakeRes());
  }

  it('statusCounts reflects active/inactive/archived buckets', async () => {
    const activeUser = await createTestUser('Admin');
    const inactiveUser = await createTestUser('Admin');
    await userRepository.setActive(inactiveUser, false);
    const archivedUser = await createTestUser('Admin');
    await callAsAdmin('archive', archivedUser);

    const counts = await userRepository.statusCounts();
    expect(counts.active).toBeGreaterThanOrEqual(1);
    expect(counts.inactive).toBeGreaterThanOrEqual(1);
    expect(counts.archived).toBeGreaterThanOrEqual(1);

    const activeList = (await userRepository.listAllForAdmin('active')).map((u) => u.id);
    const inactiveList = (await userRepository.listAllForAdmin('inactive')).map((u) => u.id);
    const archivedList = (await userRepository.listAllForAdmin('archived')).map((u) => u.id);

    expect(activeList).toContain(activeUser);
    expect(inactiveList).toContain(inactiveUser);
    expect(archivedList).toContain(archivedUser);
    expect(activeList).not.toContain(archivedUser);
    expect(inactiveList).not.toContain(archivedUser);
  });

  it('archiving a user forces them inactive and records who and when', async () => {
    const targetId = await createTestUser('Admin');
    const actorId = await createTestUser('Admin');

    await userController.archive(fakeReq({ id: String(targetId) }, actorId), fakeRes());

    const target = await userRepository.findById(targetId);
    expect(Number(target.is_archived)).toBe(1);
    expect(Number(target.is_active)).toBe(0);
    expect(Number(target.archived_by)).toBe(actorId);
    expect(target.archived_at).not.toBeNull();
  });

  it('unarchive clears archive fields but leaves the account inactive', async () => {
    const targetId = await createTestUser('Admin');
    await callAsAdmin('archive', targetId);

    await callAsAdmin('unarchive', targetId);

    const target = await userRepository.findById(targetId);
    expect(Number(target.is_archived)).toBe(0);
    expect(target.archived_at).toBeNull();
    expect(target.archived_by).toBeNull();
    expect(Number(target.is_active)).toBe(0);
  });

  it('archive refuses a protected account', async () => {
    const protectedId = await createTestUser('Admin');
    await db.execute('UPDATE users SET is_protected_account = 1 WHERE id = :id', { id: protectedId });

    await callAsAdmin('archive', protectedId);

    const target = await userRepository.findById(protectedId);
    expect(Number(target.is_archived)).toBe(0);
  });

  it('delete succeeds for a user with no referencing history', async () => {
    const targetId = await createTestUser('Admin');

    await callAsAdmin('deleteUser', targetId);

    expect(await userRepository.findById(targetId)).toBeNull();
  });

  it('delete refuses a user with audit log history and leaves them intact', async () => {
    const targetId = await createTestUser('Admin');
    await auditLogRepository.log(targetId, 'LOGIN_SUCCESS', 'users', targetId);

    await callAsAdmin('deleteUser', targetId);

    expect(await userRepository.findById(targetId)).not.toBeNull();
  });
});
