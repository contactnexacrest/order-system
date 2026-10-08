'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

function validSubmission(csrfToken) {
  return {
    _csrf: csrfToken,
    company_legal_name: 'Rate Limit Test Co',
    billing_address_line1: '1 Test Street',
    billing_city: 'Testville',
    billing_country: 'Testland',
    vat_eori_tax_no: 'VAT123',
    contact_person: 'Jane Test',
    email: `jane-${Math.random().toString(16).slice(2, 10)}@example.test`,
    country_of_destination: 'Testland',
    incoterm_preference: 'FOB',
  };
}

async function submissionCount() {
  const row = await db.queryOne('SELECT COUNT(*) AS c FROM client_intake_submissions');
  return parseInt(row.c, 10);
}

async function seedHits(bucket, ip, count) {
  for (let i = 0; i < count; i++) {
    await db.execute('INSERT INTO rate_limit_hits (bucket_key, ip_address) VALUES (:bucket, :ip)', { bucket, ip });
  }
}

/**
 * QA-5 INT-05: the public quotation-intake form (no auth, no CAPTCHA, no
 * per-order token) accepted unlimited submissions from a single IP — the QA
 * report's own reproduction sent 30 requests in a burst and all 30 were
 * accepted into the staff review queue. Pre-seeds rate_limit_hits with the
 * bucket already at its limit so a single real request exercises the
 * blocking path directly, rather than needing 5 real submissions. Uses
 * X-Forwarded-For to control req.ip deterministically (trust proxy is set
 * in server.js), with a distinct fake IP per test to avoid collisions.
 */
describe('Quotation intake submit is rate-limited (QA-5 INT-05)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('blocks a submission once the limit is already reached', async () => {
    const ip = '203.0.113.10';
    await seedHits('quotation_intake_submit', ip, 5);

    const before = await submissionCount();
    const agent = request.agent(app);
    const formPage = await agent.get('/quotation-details').set('X-Forwarded-For', ip);
    const csrfToken = extractCsrf(formPage.text);

    const res = await agent
      .post('/quotation-details/submit')
      .set('X-Forwarded-For', ip)
      .type('form')
      .send(validSubmission(csrfToken));

    expect(res.status).toBe(302);
    expect(res.headers.location).toBe('/quotation-details');
    expect(await submissionCount()).toBe(before);
  });

  it('accepts a submission when under the limit', async () => {
    const ip = '203.0.113.11';
    await seedHits('quotation_intake_submit', ip, 3); // under the limit of 5

    const before = await submissionCount();
    const agent = request.agent(app);
    const formPage = await agent.get('/quotation-details').set('X-Forwarded-For', ip);
    const csrfToken = extractCsrf(formPage.text);

    const res = await agent
      .post('/quotation-details/submit')
      .set('X-Forwarded-For', ip)
      .type('form')
      .send(validSubmission(csrfToken));

    expect(res.status).toBe(200); // renders the thank-you page directly, no redirect
    expect(await submissionCount()).toBe(before + 1);
  });
});
