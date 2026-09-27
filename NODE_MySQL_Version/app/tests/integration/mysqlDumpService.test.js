'use strict';

const fs = require('fs');
const db = require('../../src/config/db');
const mysqlDumpService = require('../../src/services/mysqlDumpService');
const { createTestClient } = require('../support/fixtures');

/**
 * Point 6 — the data-export/migration tool, mirroring
 * MysqlDumpServiceTest.php. These tests actually shell out to the real
 * mysqldump binary against the disposable nexacrest_node_test database
 * (never a mock) — if mysqldump isn't on PATH or the command-building is
 * wrong, this fails loudly instead of a mock silently agreeing with
 * whatever the service claims to have done.
 */
describe('mysqlDumpService', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('dumpSchema contains structure but no row data', async () => {
    await createTestClient();

    const path = await mysqlDumpService.dumpSchema();
    try {
      expect(fs.existsSync(path)).toBe(true);
      const contents = fs.readFileSync(path, 'utf8');

      expect(contents).toContain('CREATE TABLE');
      expect(contents).toContain('`clients`');
      expect(contents).not.toContain('INSERT INTO');
    } finally {
      fs.unlinkSync(path);
    }
  });

  it('dumpData contains row data but no CREATE TABLE', async () => {
    await createTestClient();

    const path = await mysqlDumpService.dumpData();
    try {
      expect(fs.existsSync(path)).toBe(true);
      const contents = fs.readFileSync(path, 'utf8');

      expect(contents).toContain('INSERT INTO');
      expect(contents).not.toContain('CREATE TABLE');
    } finally {
      fs.unlinkSync(path);
    }
  });

  it('dumpData uses --complete-insert with explicit column names', async () => {
    const path = await mysqlDumpService.dumpData();
    try {
      const contents = fs.readFileSync(path, 'utf8');
      expect(contents).toMatch(/INSERT INTO `clients` \([^)]+\) VALUES/);
    } finally {
      fs.unlinkSync(path);
    }
  });
});
