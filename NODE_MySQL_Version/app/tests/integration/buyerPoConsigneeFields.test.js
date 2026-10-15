'use strict';

// documentGenerationService.generate() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// deliberately stubs out — mocked here instead, same pattern as
// annexureDocumentNumber.test.js. The mock also captures the HTML it was
// asked to convert, which is exactly what this test needs to inspect.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const docxDocumentBuilder = require('../../src/services/docx/docxDocumentBuilder');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * 90-item backlog #11: documentDataAssembler.resolveConsignee() already
 * computed all 7 consignee fields correctly, but only 2 of them
 * (consignee_name/consignee_address) actually routed through it on
 * generated documents — the other 5 (vat_eori_tax_no, contact_person,
 * phone, email, country_of_destination) read the BUYER's own raw columns
 * regardless of consignee_same_as_buyer, on every document type that
 * labels these as "Consignee" fields: BUYERPO, BLI, and COOPREP
 * (docxDocumentBuilder.js + the corresponding .njk PDF templates). Port of
 * PHP's BuyerPoConsigneeFieldsTest.php.
 */
describe('Buyer PO / BLI / COOPREP show the consignee\'s own details (backlog #11)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0];
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createIndependentConsigneeClient() {
    const clientId = await createTestClient();
    await db.execute(
      `UPDATE clients SET
          consignee_same_as_buyer = 0,
          consignee_name = :name,
          consignee_address_line1 = :addr1,
          consignee_city = :city,
          consignee_country = :country,
          consignee_vat_eori_tax_no = :vat,
          consignee_contact_person = :contact,
          consignee_phone = :phone,
          consignee_email = :email
       WHERE id = :id`,
      {
        name: 'Independent Consignee Co',
        addr1: '5 Consignee Avenue',
        city: 'Manchester',
        country: 'United Kingdom',
        vat: 'GB-CONSIGNEE-VAT-999',
        contact: 'Priya Consignee-Contact',
        phone: '+44-7700-900111',
        email: 'consignee-contact@example.test',
        id: clientId,
      }
    );
    return clientId;
  }

  function extractDocxText(buffer) {
    const tmpPath = path.join(os.tmpdir(), `jest-buyerpo-consignee-${Math.random().toString(16).slice(2)}.docx`);
    fs.writeFileSync(tmpPath, buffer);
    try {
      const xml = execFileSync('unzip', ['-p', tmpPath, 'word/document.xml']).toString('utf8');
      return xml.replace(/<[^>]+>/g, ' ');
    } finally {
      fs.unlinkSync(tmpPath);
    }
  }

  it('Buyer PO PDF shows the consignee\'s own details, not the buyer\'s', async () => {
    const clientId = await createIndependentConsigneeClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'BUYERPO', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('GB-CONSIGNEE-VAT-999');
    expect(html).toContain('Priya Consignee-Contact');
    expect(html).toContain('+44-7700-900111');
    expect(html).toContain('consignee-contact@example.test');
    expect(html).toContain('United Kingdom');
  });

  it('Buyer PO DOCX shows the consignee\'s own details, not the buyer\'s', async () => {
    const clientId = await createIndependentConsigneeClient();
    const orderId = await createTestOrder(clientId);

    const data = await documentDataAssembler.assemble(orderId);
    const context = { ...data, meta: { document_reference: 'JEST-TEST', revision_label: 'Rev.00', generated_date: '15 Oct 2026' }, watermark: {} };
    const buffer = await docxDocumentBuilder.render('BUYERPO', context);
    const text = extractDocxText(buffer);

    expect(text).toContain('GB-CONSIGNEE-VAT-999');
    expect(text).toContain('Priya Consignee-Contact');
    expect(text).toContain('+44-7700-900111');
    expect(text).toContain('consignee-contact@example.test');
    expect(text).toContain('United Kingdom');
  });

  it('BL Instruction Sheet PDF shows the consignee\'s own details, not the buyer\'s', async () => {
    const clientId = await createIndependentConsigneeClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'BLI', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('Independent Consignee Co');
    expect(html).toContain('5 Consignee Avenue');
    expect(html).toContain('United Kingdom');
    expect(html).toContain('GB-CONSIGNEE-VAT-999');
  });

  it('COO Prep Sheet PDF shows the consignee\'s own details, not the buyer\'s', async () => {
    const clientId = await createIndependentConsigneeClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'COOPREP', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('Independent Consignee Co');
    expect(html).toContain('5 Consignee Avenue');
    expect(html).toContain('United Kingdom');
  });

  it('Buyer PO still shows the buyer\'s own details when consignee is same as buyer', async () => {
    // Regression guard for the common case (consignee_same_as_buyer=1, the
    // seeded default) — must still resolve correctly post-fix.
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'BUYERPO', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('Jest Test Buyer Ltd');
    expect(html).not.toContain('Independent Consignee Co');
  });
});
