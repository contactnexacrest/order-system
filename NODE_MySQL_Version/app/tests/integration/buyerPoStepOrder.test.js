'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderBuyerPoDocumentRepository = require('../../src/repositories/orderBuyerPoDocumentRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of BuyerPoStepOrderTest.php. Owner decision (2026-10-25): Step 4
 * runs 4a Generate Buyer's PO -> (4b Buyer PO Action Link, 2026-10-26) -> 4c Attach Buyer's PO Copy (staff upload,
 * or the buyer's own portal/Buyer Action Link upload) -> 4d Confirm
 * Buyer's PO Received, which completes the step. The confirm gate already
 * refused without a copy (wet_signature_required_buyer_po, on by default),
 * but the page used to show Confirm (4b) before Attach (4c), walking staff
 * into that error.
 */
describe('Step 4 Buyer PO sub-step order', () => {
  let agent;

  async function orderAtStage2() {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    return orderId;
  }

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('shows Attach Copy (4c) before Confirm Received (4d)', async () => {
    const res = await agent.get(`/orders/${await orderAtStage2()}`);

    const attach = res.text.indexOf('id="step-4c-buyer-po-attach"');
    const confirm = res.text.indexOf('id="step-4d-buyer-po-confirm"');
    expect(attach).toBeGreaterThan(-1);
    expect(confirm).toBeGreaterThan(attach);
  });

  it('asks for the copy first when none is attached', async () => {
    const res = await agent.get(`/orders/${await orderAtStage2()}`);

    expect(res.text).toContain("Attach the buyer's signed PO copy in Step 4c first");
    expect(res.text).not.toContain('Confirm Buyer PO Received</button>');
  });

  it('shows the confirm form once the buyer has uploaded a copy', async () => {
    const orderId = await orderAtStage2();
    // file_store.uploaded_by NULL = the buyer's own portal/Buyer Action Link
    // upload (buyerActionService.uploadBuyerPo()).
    await orderBuyerPoDocumentRepository.attach(orderId, await createTestFile(orderId, null));

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('Confirm Buyer PO Received</button>');
    expect(res.text).toContain('The buyer (self-uploaded)');
  });

  it('labels a staff upload as Staff', async () => {
    const orderId = await orderAtStage2();
    const fileId = await createTestFile(orderId, null);
    const user = await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1');
    await db.execute('UPDATE file_store SET uploaded_by = :uid WHERE id = :id', { uid: user.id, id: fileId });
    await orderBuyerPoDocumentRepository.attach(orderId, fileId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toMatch(/test-file\.pdf<\/td>\s*<td>[^<]*<\/td>\s*<td>Staff<\/td>/);
  });
});
