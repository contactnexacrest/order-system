'use strict';

const db = require('../../src/config/db');
const { assertOneWorker } = require('../support/globalSetup');

/**
 * QA Round 7, R7-N05: `npx jest --maxWorkers=2` gave false failures in the
 * Zoho Books suites (shared test database) and held 116 of MySQL's 151
 * connections. A parallel run is now refused with a clear message, and each
 * test file's pools stay small.
 */
describe('Jest run isolation (R7-N05)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('refuses a parallel run with a clear message', () => {
    expect(() => assertOneWorker({ maxWorkers: 2 })).toThrow('This suite shares one test database and must run one file at a time (maxWorkers is 2). Run "npm test" or "npx jest --runInBand".');
    expect(() => assertOneWorker({ maxWorkers: 1 })).not.toThrow();
  });

  it('keeps each test file to a small database pool', () => {
    expect(db.pool.pool.config.connectionLimit).toBe(10);
    expect(process.env.SESSION_DB_POOL_SIZE).toBe('2');
  });
});
