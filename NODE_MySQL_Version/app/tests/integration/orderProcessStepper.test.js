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
      1: ['step-1-quotation-prepare', 'step-2-quotation-send', 'step-3-quotation-accept', 'document-review-approval'],
      2: ['step-4a-buyer-po-generate', 'step-4b-buyer-po-confirm', 'step-4c-buyer-po-attach'],
      3: ['step-5a-pi-generate', 'step-5b-advance-record', 'step-5c-advance-clear', 'step-4d-pi-intake-link', 'step-4e-pi-intake-apply', 'step-5-lc-bank-loan'],
      4: ['step-6a-oc-generate', 'step-6b-oc-acknowledge', 'step-6c-buyer-action-link'],
      5: ['step-7a-supplier-po-terms', 'step-7b-supplier-po-generate', 'step-7c-supplier-po-signed', 'step-7d-supplier-po-attach'],
      6: ['step-9a-freight-terms', 'step-9b-freight-debit-note', 'step-9c-freight-remittance', 'step-9d-freight-clear'],
      7: ['step-9e-packing', 'step-9f-packing-list', 'step-9g-shipping-details', 'step-9h-bl-instruction', 'step-9i-bl-issued'],
      8: ['step-ci-generate', 'step-ci-scanned-bl-sent', 'step-balance-record', 'step-balance-clear'],
      9: ['step-11a-coo-prep', 'step-11b-bl-originals-received', 'step-11c-bl-endorsed-mark', 'step-11d-bl-endorsement-save', 'step-11f-bl-endorsement-generate', 'step-11g-close-order', 'step-despatch'],
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
