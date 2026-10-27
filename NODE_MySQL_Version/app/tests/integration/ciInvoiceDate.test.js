'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const documentController = require('../../src/controllers/documentController');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

// An Export Executive, not an Admin: the alerts job (checkAlerts) notifies
// every Admin/MD once per expired order, so each extra Admin test user makes
// expiryAlerts.test.js slower in the full run.
async function createTestUser(roleName = 'Export Executive') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-cid-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

function daysFromToday(n) {
  const d = new Date(`${todayIst()}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

/**
 * Owner request (2026-10-27): the Commercial Invoice can be dated today or
 * earlier (e.g. to match the BL). The picked date is what the CI and the
 * COO prep sheet print; left as today, nothing changes. Port of PHP's
 * CiInvoiceDateTest.
 */
describe('Commercial Invoice date (owner request 2026-10-27)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('stores a picked date and uses it wherever the CI date is printed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Export Executive');
    const date = daysFromToday(-3);

    const ci = await documentGenerationService.generate(orderId, 'CI', userId, null, false, true, date); // override: out of sequence in a fresh test order

    const document = await documentRepository.find(ci.document_id);
    expect(document.document_date).toBe(date);
    expect(documentRepository.printedDate(document)).toBe(date);
    const data = await documentDataAssembler.assemble(orderId, 'COOPREP');
    expect(data.order.ci_date).toBe(documentDataAssembler.formatDate(date));
  });

  it('left as today, the CI carries the day it was generated', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Export Executive');

    const ci = await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);

    const document = await documentRepository.find(ci.document_id);
    expect(document.document_date).toBeNull();
    expect(documentRepository.printedDate(document)).toBe(document.generated_at);
  });

  it('refuses a future invoice date', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Export Executive');
    const req = { params: { id: String(orderId) }, body: { document_type: 'CI', document_date: daysFromToday(1) }, user: { id: userId }, permissions: {}, session: {} };
    const res = { redirect: () => {}, status() { return this; }, send() {} };

    await documentController.generate(req, res);

    expect(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CI')).toBeNull();
  });
});
