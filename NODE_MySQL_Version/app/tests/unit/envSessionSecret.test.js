'use strict';

const env = require('../../src/config/env');

/**
 * 90-item backlog ".env fallbacks": env.sessionSecret() must never hand
 * express-session the hardcoded 'change-this-in-.env' placeholder outside
 * local development — see env.js's own doc-comment for why a leaked
 * placeholder secret lets anyone forge a signed session cookie.
 */
describe('env.sessionSecret()', () => {
  const originalEnv = process.env.APP_ENV;
  const originalSecret = process.env.SESSION_SECRET_KEY;

  afterEach(() => {
    process.env.APP_ENV = originalEnv;
    process.env.SESSION_SECRET_KEY = originalSecret;
  });

  it('returns the real .env value when one is set, in any environment', () => {
    process.env.APP_ENV = 'production';
    process.env.SESSION_SECRET_KEY = 'a-real-random-secret';
    expect(env.sessionSecret()).toBe('a-real-random-secret');
  });

  it('falls back to the placeholder only in local development', () => {
    process.env.APP_ENV = 'local';
    delete process.env.SESSION_SECRET_KEY;
    expect(env.sessionSecret()).toBe('change-this-in-.env');
  });

  it('refuses to start with a guessable secret in production', () => {
    process.env.APP_ENV = 'production';
    delete process.env.SESSION_SECRET_KEY;
    expect(() => env.sessionSecret()).toThrow(/SESSION_SECRET_KEY is not set/);
  });

  it('refuses to start with a guessable secret on a staging/UAT deployment', () => {
    process.env.APP_ENV = 'staging';
    delete process.env.SESSION_SECRET_KEY;
    expect(() => env.sessionSecret()).toThrow(/SESSION_SECRET_KEY is not set/);
  });

  it('refuses to start when SESSION_SECRET_KEY is set but empty, outside local', () => {
    process.env.APP_ENV = 'production';
    process.env.SESSION_SECRET_KEY = '';
    expect(() => env.sessionSecret()).toThrow(/SESSION_SECRET_KEY is not set/);
  });
});
