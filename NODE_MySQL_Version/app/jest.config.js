'use strict';

// QA-5 DEF-04: must be set before Jest spawns its worker process(es) — by
// the time a worker's own require() chain reaches config/env.js (which
// also sets this, for the real server), that worker's V8 isolate has
// already resolved and cached its default ICU timezone from whatever was
// in its environment at process start, and a later in-process
// process.env.TZ mutation does not invalidate that cache. Setting it here,
// before jest-worker forks anything, means every worker inherits
// TZ=Asia/Kolkata in its environment from birth.
process.env.TZ = 'Asia/Kolkata';

module.exports = {
  testEnvironment: 'node',
  rootDir: __dirname,
  setupFiles: ['<rootDir>/tests/support/testEnv.js'],
  moduleNameMapper: {
    '^puppeteer-core$': '<rootDir>/tests/support/mocks/puppeteerCoreStub.js',
  },
  globalSetup: '<rootDir>/tests/support/globalSetup.js',
  globalTeardown: '<rootDir>/tests/support/globalTeardown.js',
  testMatch: ['<rootDir>/tests/**/*.test.js'],
  testTimeout: 20000,
  // Integration tests share one disposable database and mutate real rows in
  // it (stage-gate transitions, etc.) — running them one at a time avoids
  // cross-test races over the same order rows.
  maxWorkers: 1,
  verbose: true,
  // requiring server.js pulls in connect-session-knex's session-cleanup
  // setInterval, which has no reference to anything test code can close
  // and would otherwise keep the process alive for minutes after the
  // suite finishes.
  forceExit: true,
};
