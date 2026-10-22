'use strict';

const { defineConfig } = require('@playwright/test');

/**
 * Critical-path UI smoke suite for the NexaCrest order system. Run
 * against whichever stack's dev server is already up, via BASE_URL:
 *   BASE_URL=http://127.0.0.1:8080 npx playwright test   (PHP)
 *   BASE_URL=http://127.0.0.1:3000 npx playwright test   (Node)
 * Does not start either server itself — both stacks need their own real
 * MySQL-backed dev DB (schema + seed data), which a Playwright-managed
 * webServer can't set up, so bringing the server up is left to the
 * person/CI job running the suite (see e2e/README.md).
 */
module.exports = defineConfig({
  testDir: './tests',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false, // the smoke spec creates and walks one real order through the whole pipeline — must stay serial
  // Both dev servers are single-process (PHP's built-in server, Node's
  // single event loop) and Node's own document generation shells out to
  // a real headless Chrome per PDF — running multiple spec files
  // concurrently across workers reliably produces request-queueing
  // flakes under that load (confirmed: a spec that passes every time
  // alone intermittently times out when another heavy spec runs
  // alongside it). One worker trades a few seconds of wall-clock time
  // for a suite that isn't flaky by construction.
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL || 'http://127.0.0.1:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    launchOptions: {
      executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    },
  },
});
