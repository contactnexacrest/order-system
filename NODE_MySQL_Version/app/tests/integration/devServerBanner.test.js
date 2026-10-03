'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const env = require('../../src/config/env');

/**
 * "would it be quick??? ... DEV_SERVER ... on all pages there will be a kind
 * of header or strip saying DEV SERVER/DEV Environment in red color" — and,
 * once Test Mode already existed as a separate sitewide banner, the explicit
 * follow-up: "test mode and test environment banner both serve as per the
 * need - not replace of each other". DEV_SERVER is independent of both
 * APP_ENV and the DB-driven Test Mode flag (see env.isDevServer()'s own
 * docblock), so this only needs to prove the env toggle itself and that the
 * banner actually reaches a real page through the shared layouts — the
 * additive, non-interfering relationship with the Test Mode banner is
 * structural (two unconditionally-independent `{% if %}` blocks, never a
 * shared one) and was also confirmed live on both stacks.
 */
describe('DEV_SERVER banner', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  afterEach(() => {
    delete process.env.DEV_SERVER;
  });

  it('isDevServer defaults to false', () => {
    delete process.env.DEV_SERVER;
    expect(env.isDevServer()).toBe(false);
  });

  it('isDevServer is true when the env var is set', () => {
    process.env.DEV_SERVER = 'true';
    expect(env.isDevServer()).toBe(true);
  });

  it('appears on a real page (login, bare layout) when enabled', async () => {
    process.env.DEV_SERVER = 'true';
    const res = await request(app).get('/login');
    expect(res.text).toContain('dev-server-banner');
    expect(res.text).toContain('DEV / TEST ENVIRONMENT');
  });

  it('is absent when disabled', async () => {
    process.env.DEV_SERVER = 'false';
    const res = await request(app).get('/login');
    expect(res.text).not.toContain('dev-server-banner');
  });
});
