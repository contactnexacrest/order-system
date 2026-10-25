'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestDocument(orderId, typeCode, status) {
  const docType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
  await db.execute(
    `INSERT INTO documents (order_id, document_type_id, document_reference, status)
     VALUES (:order_id, :dt, :ref, :status)`,
    { order_id: orderId, dt: docType.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status }
  );
}

/**
 * Port of OrderProcessStepperTest.php. Point 2 (2026-10-01): "on order
 * screen -> on the top -> must show the process/sequence like a line with
 * dots and a step below, indicative of process." This stepper used to sit
 * above a second, more detailed "Stage N" chip grid that repeated the
 * same 9-stage status in box form — removed (QA feedback: showing the
 * same status three times, once here and again via every step-card's own
 * status icon, reads as duplication rather than detail) so the dots are
 * now the only stage-level indicator.
 */
describe('Order-page process stepper (Point 2)', () => {
  let agent;

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

  it('renders all nine stages in order with their names', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('process-stepper');
    expect(res.text).toContain('process-step-dot');

    // A freshly-created order's first stage is unlocked (in_progress); the
    // rest start locked.
    expect(res.text).toMatch(/process-step in_progress[\s\S]*?process-step-dot">1<[\s\S]*?Enquiry &amp; Quotation/);
    expect(res.text).toMatch(/process-step locked[\s\S]*?process-step-dot">9<[\s\S]*?Document Despatch &amp; Closure/);
  });

  /**
   * Batch 3 #14, updated for the UI-polish round that made every
   * .step-group its own collapsible `<details>` with a unique id
   * (step-group-stage-N, N = DB stage_number 2-9): the stepper's
   * click-to-jump script now resolves a clicked stage number to that
   * one parent group box — opening and scrolling to the group itself,
   * never diving into one of its sub-item cards — via its own
   * `stageToGroupId` lookup object instead of a per-card list. Stage 1
   * has no group (standalone Quotation cards), so it still targets the
   * first of those. This pins that every stage number the stepper
   * exposes still resolves to a real id present in the page (kept in
   * lock-step with the same object in show.njk's own script).
   */
  it('stepper steps and their target panels carry matching data-stage attributes', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    const stageToGroupId = {
      1: 'step-1-quotation-prepare',
      2: 'step-group-stage-2',
      3: 'step-group-stage-3',
      4: 'step-group-stage-4',
      5: 'step-group-stage-5',
      6: 'step-group-stage-6',
      7: 'step-group-stage-7',
      8: 'step-group-stage-8',
      9: 'step-group-stage-9',
    };

    for (const stage of [1, 2, 3, 4, 5, 6, 7, 8, 9]) {
      expect(res.text).toContain(`role="button" tabindex="0" data-stage="${stage}"`);
      expect(res.text).toContain(`id="${stageToGroupId[stage]}"`);
    }
  });

  it('marks a gate_passed stage distinctly from a locked one', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const stage1 = await orderStageRepository.findByOrderAndStageNumber(orderId, 1);
    await orderStageRepository.passGate(stage1.id, null);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toMatch(/process-step gate_passed[\s\S]*?process-step-dot">1</);
  });

  /**
   * The Step 8 group box (8a Production + 8b Compliance Checklist) never
   * carried its own `open` marker, unlike every other .step-group — so
   * when 8b was the order's current step, the server-opened checklist
   * card sat inside a closed group, invisible, and the on-load
   * scroll-to-current-card had nothing to scroll to (page stayed at the
   * top). Steps 1-7 done + checklist outstanding is exactly that state.
   */
  it('opens the Step 8 group when the Compliance Checklist is the current step', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'QT', 'sent');
    await createTestDocument(orderId, 'BUYERPO', 'draft');
    for (const stageNumber of [1, 2, 3, 4, 5]) {
      const stage = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber);
      await orderStageRepository.passGate(stage.id, null);
    }
    await orderComplianceTaskRepository.initializeForOrder(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('id="compliance-checklist" open');
    expect(res.text).toContain('id="step-group-stage-production" open');
  });
});
