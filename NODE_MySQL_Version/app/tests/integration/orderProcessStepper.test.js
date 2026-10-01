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

  it('marks a gate_passed stage distinctly from a locked one', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const stage1 = await orderStageRepository.findByOrderAndStageNumber(orderId, 1);
    await orderStageRepository.passGate(stage1.id, null);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toMatch(/process-step gate_passed[\s\S]*?process-step-dot">1</);
  });
});
