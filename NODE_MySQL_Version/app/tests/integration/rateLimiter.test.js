'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const rateLimiter = require('../../src/helpers/rateLimiter');

/**
 * QA-5 INT-05: pins rateLimiter's sliding-window contract directly against
 * the real rate_limit_hits table. A unique bucket key per test avoids any
 * collision with concurrent tests or leftover rows from a previous run.
 */
describe('rateLimiter (QA-5 INT-05)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('allows up to the limit then blocks', async () => {
    const bucket = `jest-${crypto.randomBytes(8).toString('hex')}`;
    const ip = '10.0.0.1';

    for (let i = 1; i <= 5; i++) {
      expect(await rateLimiter.tooManyRequests(bucket, ip, 5, 15)).toBe(false);
    }
    expect(await rateLimiter.tooManyRequests(bucket, ip, 5, 15)).toBe(true);
    expect(await rateLimiter.tooManyRequests(bucket, ip, 5, 15)).toBe(true);
  });

  it('isolates limits per IP', async () => {
    const bucket = `jest-${crypto.randomBytes(8).toString('hex')}`;
    for (let i = 1; i <= 5; i++) {
      await rateLimiter.tooManyRequests(bucket, '10.0.0.2', 5, 15);
    }
    expect(await rateLimiter.tooManyRequests(bucket, '10.0.0.2', 5, 15)).toBe(true);
    expect(await rateLimiter.tooManyRequests(bucket, '10.0.0.3', 5, 15)).toBe(false);
  });

  it('isolates limits per bucket', async () => {
    const ip = '10.0.0.4';
    const bucketA = `jest-a-${crypto.randomBytes(8).toString('hex')}`;
    const bucketB = `jest-b-${crypto.randomBytes(8).toString('hex')}`;
    for (let i = 1; i <= 5; i++) {
      await rateLimiter.tooManyRequests(bucketA, ip, 5, 15);
    }
    expect(await rateLimiter.tooManyRequests(bucketA, ip, 5, 15)).toBe(true);
    expect(await rateLimiter.tooManyRequests(bucketB, ip, 5, 15)).toBe(false);
  });
});
