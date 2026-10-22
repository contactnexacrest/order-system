'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * External QA report U-05/U-06, both on the public /quotation-details form:
 *
 * U-05: on phones the field placeholders for VAT/EORI and Certificate of
 * Origin Type were long enough to get visually truncated ("UK: EORI No. |
 * Others: Tax Reg. N…", "GSP Form A (preferential) / Non-pr…"). The long
 * guidance now lives in a <small> hint under the field, with a short
 * placeholder in the input itself.
 *
 * U-06: Incoterm and Country were free text, producing inconsistent data
 * ("fob", "F.O.B", "UK", "United Kingdom" for the same thing). Country
 * (billing_country, country_of_destination, consignee_country) is now a
 * dropdown (with "Not sure" and an "Other — specify" escape hatch), the
 * same pattern already used for Incoterm. Port of PHP's
 * QuotationIntakeFieldsTest.
 */
describe('Quotation intake form fields (External QA report U-05/U-06)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('VAT and COO Type hints moved out of the placeholder into help text', async () => {
    const res = await request(app).get('/quotation-details');

    expect(res.text).not.toContain('placeholder="UK: EORI No.');
    expect(res.text).toContain('UK: EORI No. | Others: Tax Reg. No.');
    expect(res.text).not.toContain('placeholder="GSP Form A (preferential)');
    expect(res.text).toContain('GSP Form A (preferential) / Non-preferential');
  });

  it('billing and destination country are dropdowns with Not sure and Other options', async () => {
    const res = await request(app).get('/quotation-details');

    for (const field of ['billing_country', 'country_of_destination', 'consignee_country']) {
      expect(res.text).toContain(`id="${field}_select"`);
      expect(res.text).toContain(`name="${field}"`);
    }
    expect(res.text).toContain('>Not sure<');
    expect(res.text).toContain('Other (not listed) — specify');
    expect(res.text).toContain('>United Kingdom<');
  });

  it('submitting a chosen country still saves correctly', async () => {
    const agent = request.agent(app);
    const formPage = await agent.get('/quotation-details');
    const csrfToken = extractCsrf(formPage.text);

    await agent
      .post('/quotation-details/submit')
      .type('form')
      .send({
        _csrf: csrfToken,
        company_legal_name: 'Country Dropdown Test Co',
        billing_address_line1: '1 Test Street',
        billing_city: 'Testville',
        billing_country: 'United Kingdom',
        vat_eori_tax_no: 'GB123456789',
        contact_person: 'Jane Test',
        email: `jane-${Math.random().toString(16).slice(2, 10)}@example.test`,
        country_of_destination: 'Not sure',
        incoterm_preference: 'FOB',
      });

    const row = await db.queryOne(
      'SELECT billing_country, country_of_destination FROM client_intake_submissions ORDER BY id DESC LIMIT 1'
    );

    expect(row.billing_country).toBe('United Kingdom');
    expect(row.country_of_destination).toBe('Not sure');
  });
});
