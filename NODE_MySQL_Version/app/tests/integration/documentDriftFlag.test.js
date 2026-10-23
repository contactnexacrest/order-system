'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-ddf-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

function fakeRes() {
  const res = { statusCode: 200 };
  res.status = (code) => { res.statusCode = code; return res; };
  res.send = () => {};
  res.redirect = () => {};
  res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
  return res;
}

/**
 * External QA report M-08: "Regenerating an earlier-stage document after
 * later stages passed: mandatory confirmation, automatic cancellation of
 * later stages, and 'drift' notices on affected stages. App only warns."
 * This covers the persistent-drift-notice half (documentRepository.
 * flagDrift(), wired into documentGenerationService.generate()) and the
 * mandatory-confirmation half (ordersController.show()'s regenerationRisk
 * map, consumed by the page's own JS). "Automatic cancellation of later
 * stages" is deliberately not covered — see the fix commit for why that
 * part needs an owner decision. Port of PHP's DocumentDriftFlagTest.
 */
describe('Document drift flag (External QA report M-08)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('regenerating an earlier-stage document flags an existing downstream document', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await documentGenerationService.generate(orderId, 'QT', userId);
    const ci = await documentGenerationService.generate(orderId, 'CI', userId, null, false, true); // override: out of sequence in a fresh test order
    expect((await documentRepository.find(ci.document_id)).drift_flagged_at).toBeNull();

    await documentGenerationService.generate(orderId, 'QT', userId); // regenerate QT

    const flagged = await documentRepository.find(ci.document_id);
    expect(flagged.drift_flagged_at).not.toBeNull();
    expect(flagged.drift_flagged_reason).toContain('QT');
  });

  it('first-ever generation never flags anything', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await documentGenerationService.generate(orderId, 'QT', userId);
    const ci = await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);

    expect((await documentRepository.find(ci.document_id)).drift_flagged_at).toBeNull();
  });

  it('the flag naturally clears once the flagged document is itself regenerated', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await documentGenerationService.generate(orderId, 'QT', userId);
    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);
    await documentGenerationService.generate(orderId, 'QT', userId); // regenerate QT — flags CI
    expect((await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CI')).drift_flagged_at).not.toBeNull();

    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true); // regenerate CI itself

    const currentCi = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'CI');
    expect(currentCi.drift_flagged_at).toBeNull();
  });

  it('the order page passes the drift-flagged document and a regenerationRisk map to the view', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await documentGenerationService.generate(orderId, 'QT', userId);
    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);
    await documentGenerationService.generate(orderId, 'QT', userId); // regenerate QT — flags CI

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    const ciDoc = res.renderedData.documents.find((d) => d.document_type_code === 'CI');
    expect(ciDoc.drift_flagged_at).not.toBeNull();
    expect(res.renderedData.regenerationRisk.QT).toEqual(['CI']);
  });
});
