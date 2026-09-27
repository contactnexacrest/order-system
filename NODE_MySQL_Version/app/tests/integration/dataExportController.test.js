'use strict';

const { Writable } = require('stream');
const db = require('../../src/config/db');
const dataExportController = require('../../src/controllers/dataExportController');
const { createTestClient } = require('../support/fixtures');

/**
 * Port of DataExportControllerTest.php. Point 6 — end-to-end check that
 * the controller actually streams a real dump (not a stub) and, just as
 * importantly, logs every export to the audit log — this is the one
 * feature in the app that can hand someone every record in the database,
 * including password hashes, so a silent, untracked download would be
 * its own gap.
 */
describe('dataExportController', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  /** A minimal writable "response" that a real fs stream can .pipe() into, collecting the body for assertions. */
  function fakeStreamRes() {
    const chunks = [];
    const res = new Writable({
      write(chunk, encoding, callback) {
        chunks.push(chunk);
        callback();
      },
    });
    res.setHeader = () => {};
    res.body = () => Buffer.concat(chunks).toString('utf8');
    return res;
  }

  it('index renders the data_export view with the current DB name', async () => {
    let rendered = null;
    const req = {};
    const res = {
      renderView(view, data, layout) {
        rendered = { view, data, layout };
      },
    };

    await dataExportController.index(req, res);

    expect(rendered.view).toBe('data_export/index');
    expect(rendered.layout).toBe('layout/base');
    expect(typeof rendered.data.dbName).toBe('string');
    expect(rendered.data.dbName.length).toBeGreaterThan(0);
  });

  it('downloadSchema streams a real dump and logs audit', async () => {
    const userId = await createTestUser();
    await createTestClient();
    const req = { user: { id: userId } };
    const res = fakeStreamRes();

    await dataExportController.downloadSchema(req, res);
    await new Promise((resolve) => res.on('finish', resolve));

    expect(res.body()).toContain('CREATE TABLE');

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'DATA_EXPORT_SCHEMA' AND user_id = :userId",
      { userId }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });

  it('downloadData streams a real dump and logs audit', async () => {
    const userId = await createTestUser();
    await createTestClient();
    const req = { user: { id: userId } };
    const res = fakeStreamRes();

    await dataExportController.downloadData(req, res);
    await new Promise((resolve) => res.on('finish', resolve));

    expect(res.body()).toContain('INSERT INTO');

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'DATA_EXPORT_DATA' AND user_id = :userId",
      { userId }
    );
    expect(parseInt(row.c, 10)).toBe(1);
  });
});
