'use strict';

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
