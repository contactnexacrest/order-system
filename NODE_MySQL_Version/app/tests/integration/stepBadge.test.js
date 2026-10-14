'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of StepBadgeTest.php. 90-item backlog #80: "sequential numbered
 * step-tracking (1, 2, 3a, 3b...) across the whole order pipeline so staff
 * can't skip or forget a step." Scoped (per the user's own choice between
 * the candidate interpretations) to a small sub-step badge — e.g. "5a" —
 * next to each stage's own primary staff action buttons on the order page,
 * so staff see the literal order of operations within a stage. Purely a
 * display label (stepBadge() in orders/show.njk): it carries no gating
 * logic of its own — the actions it labels already gate exactly as they
 * did before this change.
 */
describe('Step badges on the order detail page (90-item backlog #80)', () => {
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

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  // 3b (PI Form Link) and 4c (Buyer Action Link) are deliberate exceptions
  // — both are optional, never-blocking alternate channels available from
  // the very first render regardless of stage ("entirely optional and
  // never blocks anything" per docs/SOP), not part of the gated sequence —
  // so they're stripped out before the leak-invariant checks below.
  function stripUngatedExceptions(html) {
    return html.split('title="Step 3b"').join('').split('title="Step 4c"').join('');
  }

  it("shows only Stage 1's badge (plus the two documented exceptions) on a fresh order", async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('<span class="step-badge" title="Step 1a">1a</span>');
    expect(res.text).toContain('title="Step 3b"');
    expect(res.text).toContain('title="Step 4c"');

    const stripped = stripUngatedExceptions(res.text);
    for (let stage = 2; stage <= 9; stage++) {
      expect(stripped).not.toContain(`title="Step ${stage}`);
    }
  });

  it('never shows a badge for a stage further along than what has actually unlocked', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    // Driving the gate chain directly with passAndUnlockNext() (rather than
    // the real "Confirm BL Issued" action) still leaves blIssued true once
    // Stage 7 passes, so a real BL record is needed here too or the order
    // page reads order_shipping.bl_number/bl_date off a row that was never
    // actually recorded.
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');

    for (let reachedStage = 1; reachedStage <= 9; reachedStage++) {
      await unlockThrough(orderId, reachedStage - 1);

      const res = await agent.get(`/orders/${orderId}`);
      expect(res.status).toBe(200);
      const html = stripUngatedExceptions(res.text);

      const matches = [...html.matchAll(/class="step-badge" title="Step (\d)[a-z]"/g)];
      for (const match of matches) {
        const badgeStage = parseInt(match[1], 10);
        expect(badgeStage).toBeLessThanOrEqual(reachedStage);
      }
    }
  });

  it('badges appear in ascending order as they first become actionable', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 4);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    const pos1a = res.text.indexOf('title="Step 1a"');
    const pos2a = res.text.indexOf('title="Step 2a"');
    const pos3a = res.text.indexOf('title="Step 3a"');
    const pos4a = res.text.indexOf('title="Step 4a"');
    const pos5a = res.text.indexOf('title="Step 5a"');

    expect(pos1a).toBeGreaterThan(-1);
    expect(pos2a).toBeGreaterThan(-1);
    expect(pos3a).toBeGreaterThan(-1);
    expect(pos4a).toBeGreaterThan(-1);
    expect(pos5a).toBeGreaterThan(-1);
    expect(pos1a).toBeLessThan(pos2a);
    expect(pos2a).toBeLessThan(pos3a);
    expect(pos3a).toBeLessThan(pos4a);
    expect(pos4a).toBeLessThan(pos5a);
  });
});
