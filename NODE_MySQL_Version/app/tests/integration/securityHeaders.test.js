'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');

/**
 * QA-5 UP-02: every file-download action (dispute/amendment/BL/PO evidence,
 * chat attachments, client-portal payment screenshots) served back a
 * Content-Type trusted from whatever the browser declared at upload time,
 * with no X-Content-Type-Options header anywhere — letting a browser sniff
 * an uploaded "invoice.pdf" that's actually HTML/JS and render/execute it
 * instead of downloading it. A single middleware in server.js now sets
 * X-Content-Type-Options: nosniff on every response, ahead of every route
 * and express.static, so this is exercised via real HTTP requests rather
 * than the middleware in isolation.
 */
describe('X-Content-Type-Options: nosniff is set sitewide (QA-5 UP-02)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('is set on an ordinary page response', async () => {
    const res = await request(app).get('/login');
    expect(res.headers['x-content-type-options']).toBe('nosniff');
  });

  it('is set on a 404 response', async () => {
    const res = await request(app).get('/this-route-does-not-exist');
    expect(res.headers['x-content-type-options']).toBe('nosniff');
  });

  it('is set on a static asset served from /public', async () => {
    const res = await request(app).get('/css/app.css');
    expect(res.status).toBe(200);
    expect(res.headers['x-content-type-options']).toBe('nosniff');
  });
});
