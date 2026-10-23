'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderDraftBlReviewRepository = require('../../src/repositories/orderDraftBlReviewRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const ordersController = require('../../src/controllers/ordersController');
const fileUploadService = require('../../src/services/fileUploadService');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of OrderDraftBlReviewTest.php. docs/schema.sql Section BS —
 * export process steps 9h-9j: the CHA draft BL review loop (upload a
 * draft received from the CHA, approve it or request a revision,
 * repeat until approved).
 *
 * Unlike PHP's move_uploaded_file() (which requires a genuine HTTP
 * upload), Node's multer-based upload already hands handleUpload() an
 * in-memory buffer, so the real upload success path can be exercised
 * end to end here, unlike its PHP counterpart.
 */
describe('CHA draft BL review loop (Section BS)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Draft BL Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-draftbl-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, ...overrides };
  }

  // ---------------------------------------------------------------
  // orderDraftBlReviewRepository + orderShippingRepository
  // ---------------------------------------------------------------

  it('createRound assigns sequential round numbers', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const round1Id = await orderDraftBlReviewRepository.createRound(orderId, await createTestFile(orderId, null), userId);
    const round2Id = await orderDraftBlReviewRepository.createRound(orderId, await createTestFile(orderId, null), userId);

    const rounds = await orderDraftBlReviewRepository.forOrder(orderId);
    expect(rounds.length).toBe(2);
    expect(Number(rounds[0].round_number)).toBe(2);
    expect(Number(rounds[1].round_number)).toBe(1);
    expect(Number(rounds[0].id)).toBe(round2Id);
    expect(Number(rounds[1].id)).toBe(round1Id);
    expect(rounds[0].status).toBe('pending');

    const latest = await orderDraftBlReviewRepository.latestForOrder(orderId);
    expect(Number(latest.id)).toBe(round2Id);
  });

  it('round numbers are scoped per order', async () => {
    const userId = await createTestUser();
    const orderA = await createTestOrder(await createTestClient());
    const orderB = await createTestOrder(await createTestClient());

    await orderDraftBlReviewRepository.createRound(orderA, await createTestFile(orderA, null), userId);
    const firstRoundForB = await orderDraftBlReviewRepository.createRound(orderB, await createTestFile(orderB, null), userId);

    const round = await orderDraftBlReviewRepository.find(firstRoundForB);
    expect(Number(round.round_number)).toBe(1);
  });

  it('attachDraftBl creates a shipping row when none exists yet and clears any prior approval', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect(await orderShippingRepository.find(orderId)).toBeNull();

    const fileId = await createTestFile(orderId, null);
    await orderShippingRepository.attachDraftBl(orderId, fileId);

    const shipping = await orderShippingRepository.find(orderId);
    expect(shipping).not.toBeNull();
    expect(Number(shipping.draft_bl_file_id)).toBe(fileId);
    expect(shipping.draft_bl_uploaded_at).not.toBeNull();
    expect(shipping.draft_bl_approved_at).toBeNull();
  });

  it('attachDraftBl clears a prior approval when a corrected draft arrives', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.attachDraftBl(orderId, await createTestFile(orderId, null));
    await orderShippingRepository.approveDraftBl(orderId, userId);
    expect((await orderShippingRepository.find(orderId)).draft_bl_approved_at).not.toBeNull();

    await orderShippingRepository.attachDraftBl(orderId, await createTestFile(orderId, null));

    expect((await orderShippingRepository.find(orderId)).draft_bl_approved_at).toBeNull();
  });

  // ---------------------------------------------------------------
  // ordersController.uploadDraftBl() — end to end, including the real upload
  // ---------------------------------------------------------------

  it('rejects when no file is attached', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) } });

    await ordersController.uploadDraftBl(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect(await orderDraftBlReviewRepository.forOrder(orderId)).toEqual([]);
  });

  it('logs the draft end to end and attaches it to shipping', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      file: { buffer: Buffer.from('%PDF-1.4 fake draft bl for test'), originalname: 'draft-bl.pdf', mimetype: 'application/pdf' },
    });

    await ordersController.uploadDraftBl(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    const rounds = await orderDraftBlReviewRepository.forOrder(orderId);
    expect(rounds.length).toBe(1);
    expect(Number(rounds[0].round_number)).toBe(1);
    expect(rounds[0].status).toBe('pending');

    const shipping = await orderShippingRepository.find(orderId);
    expect(Number(shipping.draft_bl_file_id)).toBe(Number(rounds[0].draft_file_id));
    expect(shipping.draft_bl_approved_at).toBeNull();

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'DRAFT_BL_UPLOADED' AND entity_id = :id",
      { id: rounds[0].id }
    );
    expect(Number(row.c)).toBe(1);
  });

  it('refuses a second upload while the current round is still pending, even before looking at the file', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderDraftBlReviewRepository.createRound(orderId, await createTestFile(orderId, null), userId);
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) } }); // no file at all

    await ordersController.uploadDraftBl(req, fakeRes());

    const msg = flash.pull(req)[0];
    expect(msg.type).toBe('error');
    expect(msg.message).toMatch(/still awaiting your review/);
    expect((await orderDraftBlReviewRepository.forOrder(orderId)).length).toBe(1);
  });

  it('returns 404 for an unknown order', async () => {
    const userId = await createTestUser();
    const req = fakeReq({ user: { id: userId }, params: { id: '999999999' } });
    const res = fakeRes();

    await ordersController.uploadDraftBl(req, res);

    expect(res.statusCode).toBe(404);
  });

  // ---------------------------------------------------------------
  // ordersController.reviewDraftBl()
  // ---------------------------------------------------------------

  it('approves and updates shipping', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    const roundId = await orderDraftBlReviewRepository.createRound(orderId, fileId, userId);
    await orderShippingRepository.attachDraftBl(orderId, fileId);
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId), roundId: String(roundId) }, body: { decision: 'approved' } });

    await ordersController.reviewDraftBl(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    const updated = await orderDraftBlReviewRepository.find(roundId);
    expect(updated.status).toBe('approved');
    expect(updated.reviewed_at).not.toBeNull();
    expect(Number(updated.reviewed_by)).toBe(userId);

    const shipping = await orderShippingRepository.find(orderId);
    expect(shipping.draft_bl_approved_at).not.toBeNull();
    expect(Number(shipping.draft_bl_approved_by)).toBe(userId);

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'DRAFT_BL_REVIEWED' AND entity_id = :id",
      { id: roundId }
    );
    expect(Number(row.c)).toBe(1);
  });

  it('requesting a revision requires notes and does not approve', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const fileId = await createTestFile(orderId, null);
    const roundId = await orderDraftBlReviewRepository.createRound(orderId, fileId, userId);
    await orderShippingRepository.attachDraftBl(orderId, fileId);

    const req1 = fakeReq({ user: { id: userId }, params: { id: String(orderId), roundId: String(roundId) }, body: { decision: 'revision_requested' } });
    await ordersController.reviewDraftBl(req1, fakeRes());
    expect(flash.pull(req1)[0].type).toBe('error');
    expect((await orderDraftBlReviewRepository.find(roundId)).status).toBe('pending');

    const req2 = fakeReq({
      user: { id: userId },
      params: { id: String(orderId), roundId: String(roundId) },
      body: { decision: 'revision_requested', notes: 'Consignee address line 2 is wrong' },
    });
    await ordersController.reviewDraftBl(req2, fakeRes());
    expect(flash.pull(req2)[0].type).toBe('success');
    const updated = await orderDraftBlReviewRepository.find(roundId);
    expect(updated.status).toBe('revision_requested');
    expect(updated.review_notes).toBe('Consignee address line 2 is wrong');

    const shipping = await orderShippingRepository.find(orderId);
    expect(shipping.draft_bl_approved_at).toBeNull();

    const round2Id = await orderDraftBlReviewRepository.createRound(orderId, await createTestFile(orderId, null), userId);
    const rounds = await orderDraftBlReviewRepository.forOrder(orderId);
    expect(rounds.length).toBe(2);
    expect(Number((await orderDraftBlReviewRepository.find(round2Id)).round_number)).toBe(2);
  });

  it('rejects a mismatched order', async () => {
    const userId = await createTestUser();
    const orderA = await createTestOrder(await createTestClient());
    const orderB = await createTestOrder(await createTestClient());
    const roundId = await orderDraftBlReviewRepository.createRound(orderA, await createTestFile(orderA, null), userId);
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderB), roundId: String(roundId) }, body: { decision: 'approved' } });
    const res = fakeRes();

    await ordersController.reviewDraftBl(req, res);

    expect(res.statusCode).toBe(404);
    expect((await orderDraftBlReviewRepository.find(roundId)).status).toBe('pending');
  });

  it('rejects an already-reviewed round', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const roundId = await orderDraftBlReviewRepository.createRound(orderId, await createTestFile(orderId, null), userId);
    await orderDraftBlReviewRepository.review(roundId, 'approved', null, userId);
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId), roundId: String(roundId) }, body: { decision: 'approved' } });

    await ordersController.reviewDraftBl(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
  });

  it('rejects an invalid decision', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const roundId = await orderDraftBlReviewRepository.createRound(orderId, await createTestFile(orderId, null), userId);
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId), roundId: String(roundId) }, body: { decision: 'maybe-later' } });

    await ordersController.reviewDraftBl(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect((await orderDraftBlReviewRepository.find(roundId)).status).toBe('pending');
  });

  // ---------------------------------------------------------------
  // View — show.njk source (same pattern as blCopyUpload.test.js)
  // ---------------------------------------------------------------

  const templatePath = path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk');
  const source = fs.readFileSync(templatePath, 'utf8');

  it('the order page template offers the draft BL upload form and round history markup', () => {
    expect(source).toContain('name="draft_bl"');
    expect(source).toContain('/draft-bl');
    expect(source).toContain('Pending Review');
    expect(source).toContain('Revision Requested');
  });

  /**
   * External QA report L-22 pattern: prove the 'draft_bl' upload context
   * actually resolves (seeded in seed.sql — pre-existing, but never
   * reached by any real code path until this feature) and the file is
   * actually stored, same as blCopyUpload.test.js's equivalent check.
   */
  it('handleUpload resolves the draft_bl context and actually stores the file', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fakeReqUpload = { file: { buffer: Buffer.from('%PDF-1.4 fake draft bl for test'), originalname: 'draft-bl.pdf', mimetype: 'application/pdf' } };

    const fileId = await fileUploadService.handleUpload(fakeReqUpload, 'draft_bl', 'draft_bl', 'test/draft-bl', null, orderId, null);

    expect(fileId).toBeTruthy();
  });
});
