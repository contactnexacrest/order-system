'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderStageRepository = require('../../src/repositories/orderStageRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderProcessStepperTest.php. Point 2 (2026-10-01): "on order
 * screen -> on the top -> must show the process/sequence like a line with
 * dots and a step below, indicative of process." The existing stage-chip
 * grid gives full per-stage detail but reads as a grid of boxes, not a
 * sequence — this adds a horizontal line-with-dots stepper above it
 * (process-stepper/process-step markup), purely an at-a-glance summary;
 * the detailed grid is untouched below it.
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
   * Batch 3 #14 — the stepper is clickable: each step carries
   * data-stage="N". The order-detail-page rebuild (step-card sequence)
   * replaced every stage's own data-stage-carrying `<div class="section">`
   * with one or more `<details class="step-card">` step-cards (one DB
   * stage can now map to more than one card, e.g. Stage 3 splits into
   * two cards) — the stepper's click-to-jump script now resolves a
   * clicked stage number to its card id via its own `stageToCardId`
   * lookup object instead of a shared data-stage attribute. This pins
   * that every stage number the stepper exposes still resolves to a
   * real step-card id present in the page.
   */
  it('stepper steps and their target panels carry matching data-stage attributes', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    const stageToCardId = {
      1: ['documents-approvals'],
      2: ['step-buyer-po'],
      3: ['step-pi-advance', 'step-pi-intake'],
      4: ['step-oc'],
      5: ['step-supplier-po'],
      6: ['step-freight'],
      7: ['step-packing-bl'],
      8: ['step-ci-balance'],
      9: ['step-despatch'],
    };

    for (const stage of [1, 2, 3, 4, 5, 6, 7, 8, 9]) {
      expect(res.text).toContain(`role="button" tabindex="0" data-stage="${stage}"`);
      for (const cardId of stageToCardId[stage]) {
        expect(res.text).toContain(`id="${cardId}"`);
      }
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
});
