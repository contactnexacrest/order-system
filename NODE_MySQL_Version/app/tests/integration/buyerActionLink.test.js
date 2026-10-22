'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const stageGateService = require('../../src/services/stageGateService');
const buyerActionController = require('../../src/controllers/buyerActionController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const ordersController = require('../../src/controllers/ordersController');
const flash = require('../../src/helpers/flash');
const encryptionService = require('../../src/services/encryptionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of BuyerActionLinkTest.php. 90-item backlog #75: acknowledging the
 * Order Confirmation and uploading a signed Buyer PO copy
 * (clientPortalController.acknowledgeOc()/uploadBuyerPo()) used to only be
 * reachable by logging into the client portal, which itself is only
 * provisioned from Stage 3->4 onward and still requires consuming a
 * one-time set-password link first. This adds a public, no-login
 * counterpart (buyerActionController, docs/schema.sql Section BG) modeled
 * on the existing /quotation-details and /pi-details tokenized links,
 * sharing its write logic with the session-gated portal route via
 * buyerActionService so the two paths can never drift apart.
 */
describe('Buyer Action Link — public no-login OC-ack + Buyer-PO-upload (90-item backlog #75)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-buyeraction-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode = 'OC', status = 'sent') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-BUYERACTION-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function unlockThroughStage3(orderId) {
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await stageGateService.passAndUnlockNext(orderId, 3, null);
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null, renderedView: null, renderedData: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  function fakeReq(params) {
    return { params, body: {}, session: {} };
  }

  // ---------------------------------------------------------------
  // orderRepository.generateBuyerActionToken / findByValidBuyerActionToken
  // ---------------------------------------------------------------

  it('a generated token validates back to the same order', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const token = await orderRepository.generateBuyerActionToken(orderId);
    const found = await orderRepository.findByValidBuyerActionToken(token);

    expect(found).not.toBeNull();
    expect(found.id).toBe(orderId);
  });

  it('a bogus token never validates', async () => {
    expect(await orderRepository.findByValidBuyerActionToken('not-a-real-token')).toBeNull();
  });

  it('an expired token no longer validates', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await orderRepository.generateBuyerActionToken(orderId, -1);

    expect(await orderRepository.findByValidBuyerActionToken(token)).toBeNull();
  });

  it('regenerating invalidates the previous token', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const oldToken = await orderRepository.generateBuyerActionToken(orderId);
    const newToken = await orderRepository.generateBuyerActionToken(orderId);

    expect(await orderRepository.findByValidBuyerActionToken(oldToken)).toBeNull();
    expect(await orderRepository.findByValidBuyerActionToken(newToken)).not.toBeNull();
  });

  // ---------------------------------------------------------------
  // buyerActionController.show
  // ---------------------------------------------------------------

  it('show() renders link-expired for an invalid token', async () => {
    const res = fakeRes();

    await buyerActionController.show(fakeReq({ token: 'not-a-real-token' }), res);

    expect(res.renderedView).toBe('buyer_action/link_expired');
  });

  it('show() renders the OC-ack banner when acknowledgment is pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage3(orderId);
    const documentId = await createTestDocument(orderId, 'OC', 'sent');
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, new Date(), new Date(Date.now() + 48 * 3600 * 1000));
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const res = fakeRes();

    await buyerActionController.show(fakeReq({ token }), res);

    expect(res.renderedView).toBe('buyer_action/show');
    expect(res.renderedData.ocAcknowledgment).not.toBeNull();
    expect(res.renderedData.ocAcknowledgment.acknowledged_at).toBeNull();
  });

  it('show() omits the OC-ack banner data when nothing is pending', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const res = fakeRes();

    await buyerActionController.show(fakeReq({ token }), res);

    expect(res.renderedView).toBe('buyer_action/show');
    expect(res.renderedData.ocAcknowledgment).toBeNull();
  });

  /**
   * User-reported batch item 10: the acknowledgement banner used to
   * depend on an order_oc_acknowledgments row already existing — but
   * that row is only ever created by emailDispatchService.dispatch()
   * when the OC email actually goes out. Staff generating the Buyer
   * Action Link before that email was sent (still awaiting Level-2
   * approval, or mail sending misconfigured entirely) got a page with
   * nothing to acknowledge, even though an approved OC document
   * genuinely existed. The page must lazily create that row itself, the
   * same way the staff-side "Record Buyer's Acknowledgement" fallback
   * already does.
   */
  it('show() renders the OC-ack banner data when OC is approved but never emailed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage3(orderId);
    await createTestDocument(orderId, 'OC', 'approved');
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const res = fakeRes();

    await buyerActionController.show(fakeReq({ token }), res);

    expect(res.renderedData.ocAcknowledgment).not.toBeNull();
    expect(res.renderedData.ocAcknowledgment.acknowledged_at).toBeNull();
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack).not.toBeNull();
  });

  it('acknowledgeOc() succeeds when OC was approved but never emailed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage3(orderId);
    await createTestDocument(orderId, 'OC', 'approved');
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token });
    const res = fakeRes();

    await buyerActionController.acknowledgeOc(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack.acknowledged_at).not.toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(true);
  });

  // ---------------------------------------------------------------
  // buyerActionController.acknowledgeOc
  // ---------------------------------------------------------------

  it('acknowledgeOc() via token records it and unlocks Stage 5', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage3(orderId);
    const documentId = await createTestDocument(orderId, 'OC', 'sent');
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, new Date(), new Date(Date.now() + 48 * 3600 * 1000));
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token });
    const res = fakeRes();

    await buyerActionController.acknowledgeOc(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack.acknowledged_at).not.toBeNull();
    expect(await stageGateService.isUnlocked(orderId, 5)).toBe(true);
  });

  it('acknowledgeOc() with an invalid token never touches any order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage3(orderId);
    const documentId = await createTestDocument(orderId, 'OC', 'sent');
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, new Date(), new Date(Date.now() + 48 * 3600 * 1000));

    await buyerActionController.acknowledgeOc(fakeReq({ token: 'not-a-real-token' }), fakeRes());

    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack.acknowledged_at).toBeNull();
  });

  // ---------------------------------------------------------------
  // buyerActionController.uploadBuyerPo
  // ---------------------------------------------------------------

  it('uploadBuyerPo() via token reaches the same upload path as the portal', async () => {
    // No req.file set — same as clientBuyerPoUpload.test.js's missing-file
    // case, this exercises fileUploadService's own "No file was uploaded"
    // guard. The point here is solely that a valid token reaches that code
    // path with no session at all, proving the no-login route actually works.
    const orderId = await createTestOrder(await createTestClient());
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token });
    const res = fakeRes();

    await buyerActionController.uploadBuyerPo(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('No file was uploaded');
  });

  it('uploadBuyerPo() with an invalid token is refused', async () => {
    const res = fakeRes();

    await buyerActionController.uploadBuyerPo(fakeReq({ token: 'not-a-real-token' }), res);

    expect(res.renderedView).toBe('buyer_action/link_expired');
  });

  // ---------------------------------------------------------------
  // Regression: the session-gated client-portal path must still behave
  // identically now that it delegates to the shared buyerActionService.
  // ---------------------------------------------------------------

  /**
   * User-reported batch item 10 — same lazy-create fix applies to the
   * session-gated client-portal order page, not just the no-login Buyer
   * Action Link: a client who logs in before the OC email went out must
   * also see something real to acknowledge.
   */
  it('clientPortalController.showOrder renders the OC-ack banner data when OC is approved but never emailed', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await unlockThroughStage3(orderId);
    await createTestDocument(orderId, 'OC', 'approved');
    const req = { params: { id: String(orderId) }, body: {}, session: { _client_portal_client_id: clientId } };
    const res = fakeRes();

    await clientPortalController.showOrder(req, res);

    expect(res.renderedData.ocAcknowledgment).not.toBeNull();
    expect(res.renderedData.ocAcknowledgment.acknowledged_at).toBeNull();
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack).not.toBeNull();
  });

  it('clientPortalController.acknowledgeOc still works after the refactor', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await unlockThroughStage3(orderId);
    const documentId = await createTestDocument(orderId, 'OC', 'sent');
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, new Date(), new Date(Date.now() + 48 * 3600 * 1000));
    const req = { params: { id: String(orderId) }, body: {}, session: { _client_portal_client_id: clientId } };
    const res = fakeRes();

    await clientPortalController.acknowledgeOc(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');
    expect(flashMessages[0].message).toContain('Thank you');
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack.acknowledged_at).not.toBeNull();
  });

  // ---------------------------------------------------------------
  // ordersController.generateBuyerActionLink (staff-initiated)
  // ---------------------------------------------------------------

  it('generateBuyerActionLink persists a working token', async () => {
    const userId = await createTestUser('Admin');
    const orderId = await createTestOrder(await createTestClient());
    const req = { params: { id: String(orderId) }, body: {}, session: {}, user: { id: userId } };
    const res = fakeRes();

    await ordersController.generateBuyerActionLink(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');
    expect(flashMessages[0].message).toContain('/buyer-action/');

    const order = await orderRepository.find(orderId);
    expect(order.buyer_action_token_plain).toBeTruthy();
    // QA-5 L-24: the column holds ciphertext now (encryptionService),
    // never the raw token — decrypt it first, exactly as
    // ordersController.js does before ever using it.
    const decryptedToken = encryptionService.decrypt(order.buyer_action_token_plain);
    expect(decryptedToken).not.toBeNull();
    expect(decryptedToken).not.toBe(order.buyer_action_token_plain);
    expect(await orderRepository.findByValidBuyerActionToken(decryptedToken)).not.toBeNull();
  });
});
