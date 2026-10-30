'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const annexureController = require('../../src/controllers/annexureController');
const emailDispatchController = require('../../src/controllers/emailDispatchController');
const flash = require('../../src/helpers/flash');
const { annexureConfirmAttr } = require('../../src/helpers/annexureConfirmAttr');
const { createTestClient, createTestOrder } = require('../support/fixtures');

// QA Round 6, R6-N16: clients.email is unique, and Jest runs files in
// parallel on one database — this file's fixed test emails are its own.
const BUYER_EMAIL = `buyer-${process.pid}-${Date.now()}@example.test`;

/**
 * Port of AnnexureLockWarningTest.php. 90-item backlog #73/#74: once an
 * order's Annexure A document has been approved/sent, nothing told staff
 * that editing the Product Entries or Additional Terms below wouldn't
 * change what the buyer already received, and resending an already-sent
 * Annexure went through the exact same flow as a first-time send.
 * Investigation found no documented "staff-trust" precedent covering
 * this screen (unlike Stage 9's Close Order, which does have one) — this
 * is a real, previously-unhandled gap, not an intentional behavior.
 * Fixed as a warning + confirm(), never a hard block, matching the house
 * style used everywhere else.
 */
describe('Annexure lock warning + resend confirmation (90-item backlog #73/#74)', () => {
  beforeEach(async () => {
    await db.execute(`UPDATE clients SET email = NULL WHERE email IN (${[BUYER_EMAIL].map((e, i) => `:e${i}`).join(', ')})`, Object.fromEntries([BUYER_EMAIL].map((e, i) => [`e${i}`, e])));
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocument(orderId, typeCode = 'QT', status = 'draft') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-ANNEX-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    res.status = () => res;
    res.send = () => res;
    return res;
  }

  // ---------------------------------------------------------------
  // annexureConfirmAttr (pure helper, unit-level)
  // ---------------------------------------------------------------

  it('returns no attribute when not locked and no extra message', () => {
    expect(annexureConfirmAttr(null)).toBe('');
    expect(annexureConfirmAttr({ status: 'draft' })).toBe('');
  });

  it('preserves the pre-existing confirm text untouched when not locked', () => {
    expect(annexureConfirmAttr(null, 'Remove this product entry and its images?'))
      .toBe('onsubmit="return confirm(&quot;Remove this product entry and its images?&quot;);"');
  });

  it('prefixes the lock warning when approved', () => {
    const attr = annexureConfirmAttr({ status: 'approved' });
    expect(attr).toContain('This Annexure was already approved');
    expect(attr).toContain('Continue?');
  });

  it('prefixes the lock warning onto an existing message when sent', () => {
    const attr = annexureConfirmAttr({ status: 'sent' }, 'Remove this image from Annexure A?');
    expect(attr).toContain('This Annexure was already sent to the buyer');
    expect(attr).toContain('Remove this image from Annexure A?');
    expect(attr).toContain('Continue?');
  });

  // ---------------------------------------------------------------
  // annexureController.index — annexaDocument passed to the view
  // ---------------------------------------------------------------

  it('index() passes a null annexaDocument when none exists yet', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const res = fakeRes();

    await annexureController.index({ params: { id: String(orderId) } }, res);

    expect(res.renderedData.annexaDocument).toBeNull();
  });

  it('index() passes the latest ANNEXA document with its real status', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'ANNEXA', 'sent');
    const res = fakeRes();

    await annexureController.index({ params: { id: String(orderId) } }, res);

    expect(res.renderedData.annexaDocument).not.toBeNull();
    expect(res.renderedData.annexaDocument.status).toBe('sent');
  });

  // ---------------------------------------------------------------
  // Template wiring — structural checks (same approach as
  // bliCoopPrepSection.test.js: asserting against the shipped template
  // source rather than rendering full Nunjucks, which would need a huge
  // context to avoid throwing on unrelated sections).
  // ---------------------------------------------------------------

  it('annexure/index.njk calls annexureConfirmAttr on every mutating form', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', '..', 'views', 'annexure', 'index.njk'), 'utf8');

    expect(source).toContain('annexaIsLocked');
    expect((source.match(/annexureConfirmAttr\(/g) || []).length).toBeGreaterThanOrEqual(6);
    expect(source).toContain('already been');
  });

  it('email/compose.njk gates the resend warning on ANNEXA + sent', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', '..', 'views', 'email', 'compose.njk'), 'utf8');

    expect(source).toContain("document.document_type_code == 'ANNEXA'");
    expect(source).toContain("document.status == 'sent'");
    expect(source).toContain('already sent to the buyer once');
  });

  // ---------------------------------------------------------------
  // emailDispatchController.compose — document data the resend
  // warning in the template keys off
  // ---------------------------------------------------------------

  it('compose() exposes document_type_code and status for the resend check', async () => {
    const orderId = await createTestOrder(await createTestClient(BUYER_EMAIL));
    const documentId = await createTestDocument(orderId, 'ANNEXA', 'sent');
    const res = fakeRes();

    await emailDispatchController.compose({ params: { id: String(orderId), documentId: String(documentId) }, query: {}, user: { id: 1 } }, res);

    expect(res.renderedData.document.document_type_code).toBe('ANNEXA');
    expect(res.renderedData.document.status).toBe('sent');
  });

  it('compose() for a non-Annexure sent document does not report ANNEXA', async () => {
    const orderId = await createTestOrder(await createTestClient(BUYER_EMAIL));
    const documentId = await createTestDocument(orderId, 'QT', 'sent');
    const res = fakeRes();

    await emailDispatchController.compose({ params: { id: String(orderId), documentId: String(documentId) }, query: {}, user: { id: 1 } }, res);

    expect(res.renderedData.document.document_type_code).not.toBe('ANNEXA');
  });
});
