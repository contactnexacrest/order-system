'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const documentController = require('../../src/controllers/documentController');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-ddw-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

/**
 * Port of DocumentRegenerationDownstreamWarningTest.php. 90-item backlog
 * Phase 6 Item D — regenerating an earlier-stage document after a
 * later-stage document already exists should warn staff that the later
 * document was built from data that existed before whatever just
 * changed. This behavior (documentGenerationService.downstreamDocumentsAtRisk()
 * + its wiring into documentController.generate()'s flash message) was
 * already implemented (2026-09-25) but had no dedicated regression test
 * and no SOP documentation — both added here, no production code change
 * was needed.
 */
describe('Document regeneration downstream warning (90-item backlog Phase 6 Item D)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  function fakeReq(user, body = {}, params = {}) {
    return { session: {}, user, permissions: { edit_locked_data: !!user.isSuperAdmin }, body, params };
  }

  it('no downstream risk when only the regenerated type exists', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    await documentGenerationService.generate(orderId, 'QT', userId);
    await documentGenerationService.generate(orderId, 'QT', userId); // regenerate

    expect(await documentGenerationService.downstreamDocumentsAtRisk(orderId, 'QT')).toEqual([]);
  });

  it('downstream risk lists a later-stage type that already exists', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    await documentGenerationService.generate(orderId, 'QT', userId);
    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);

    expect(await documentGenerationService.downstreamDocumentsAtRisk(orderId, 'QT')).toEqual(['CI']);
  });

  it('downstream risk excludes types at the same stage', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    // PL and BLI both sit at stage 7 — neither is "downstream" of the other.
    await documentGenerationService.generate(orderId, 'PL', userId, null, false, true);
    await documentGenerationService.generate(orderId, 'BLI', userId, null, false, true);

    expect(await documentGenerationService.downstreamDocumentsAtRisk(orderId, 'PL')).toEqual([]);
    expect(await documentGenerationService.downstreamDocumentsAtRisk(orderId, 'BLI')).toEqual([]);
  });

  it('downstream risk excludes earlier-stage types', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    await documentGenerationService.generate(orderId, 'QT', userId);
    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);

    expect(await documentGenerationService.downstreamDocumentsAtRisk(orderId, 'CI')).toEqual([]);
  });

  it('downstream risk returns empty for an unrecognised document type', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    expect(await documentGenerationService.downstreamDocumentsAtRisk(orderId, 'NOT-A-REAL-TYPE')).toEqual([]);
  });

  it('controller flashes no warning on first generation', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    const req = fakeReq({ id: userId, isSuperAdmin: true }, { document_type: 'QT' }, { id: String(orderId) });
    await documentController.generate(req, fakeRes());

    const flashMsgs = req.session._flash || [];
    const warnings = flashMsgs.filter((m) => m.type === 'warning');
    expect(warnings).toEqual([]);
  });

  it('controller flashes a warning when regenerating with downstream docs present', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');

    await documentGenerationService.generate(orderId, 'QT', userId);
    await documentGenerationService.generate(orderId, 'CI', userId, null, false, true);

    const req = fakeReq(
      { id: userId, isSuperAdmin: true },
      { document_type: 'QT', override_gate: '1', override_reason: 'Regenerating QT to fix a typo in Section 1.' },
      { id: String(orderId) }
    );
    await documentController.generate(req, fakeRes());

    const flashMsgs = req.session._flash || [];
    const warningMessages = flashMsgs.filter((m) => m.type === 'warning').map((m) => m.message);
    expect(warningMessages.length).toBeGreaterThan(0);
    expect(warningMessages[0]).toContain('CI');
    expect(warningMessages[0]).toContain('already has');
  });
});
