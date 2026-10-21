'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Phase 2 item 9 of the approved remediation plan: "RBI Purpose Codes
 * incomplete in Admin Settings vs PI document." Root cause: the PI's own
 * PDF template hardcoded a static 7-code reference cheat-sheet (most of
 * them irrelevant to a PI, which only ever requests the advance payment),
 * while CI and FDN each correctly print their one admin-configured,
 * relevant code — and the PI's own DOCX builder already did the same
 * correct thing via rbi_purpose_code_advance. Made the PI PDF match.
 * Port of PHP's PiRbiPurposeCodeTest.php.
 */
describe('PI RBI Purpose Code (Phase 2 item 9)', () => {
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

  it('PI PDF shows the single admin-configured advance code, not the static seven-code list', async () => {
    await db.execute("UPDATE company_settings SET setting_value = 'P0103-TEST' WHERE setting_key = 'rbi_purpose_code_advance'");

    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'PI', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('P0103-TEST');
    expect(html).not.toContain('Inspection/Certificate Fees');
    expect(html).not.toContain('Freight/Insurance Recovery');
  });
});
