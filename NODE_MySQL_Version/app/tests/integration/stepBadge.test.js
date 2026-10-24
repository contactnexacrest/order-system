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

  // 4d (PI Form Link) and 4c (Buyer Action Link) are deliberate exceptions
  // — both are optional, never-blocking alternate channels available from
  // the very first render regardless of stage ("entirely optional and
  // never blocks anything" per docs/SOP), not part of the gated sequence —
  // so they're stripped out before the leak-invariant checks below.
  function stripUngatedExceptions(html) {
    return html.split('title="Step 4d"').join('').split('title="Step 4c"').join('');
  }

  it("shows only Stage 1's badge (plus the two documented exceptions) on a fresh order", async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('<span class="step-badge" title="Step 1a">1a</span>');
    expect(res.text).toContain('title="Step 4d"');
    expect(res.text).toContain('title="Step 4c"');

    const stripped = res.text.replace(/class="step-badge" title="Step (1a|4d|4c)">[^<]*<\/span>/g, '');
    expect(stripped).not.toContain('class="step-badge"');
  });

  /**
   * The order-detail-page rebuild relabelled every badge to the user's
   * own step numbering rather than the DB's stage_number, so a badge's
   * own digit prefix no longer equals its governing DB stage — this map
   * is the authoritative link between the two, kept in lock-step with
   * the stepBadge() calls in views/orders/show.njk.
   */
  it('never shows a badge for a stage further along than what has actually unlocked', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    // Driving the gate chain directly with passAndUnlockNext() (rather than
    // the real "Confirm BL Issued" action) still leaves blIssued true once
    // Stage 7 passes, so a real BL record is needed here too or the order
    // page reads order_shipping.bl_number/bl_date off a row that was never
    // actually recorded.
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');

    const badgeStage = {
      '1a': 1,
      '2a': 2, '2b': 2, '2c': 2,
      '5a': 3, '5b': 3, '5c': 3,
      '4a': 4, '4b': 4,
      '7a': 5, '7b': 5, '7c': 5, '7d': 5,
      '6a': 6, '6b': 6, '6c': 6, '6d': 6,
      '9g': 7, '9h': 7, '9i': 7, '9j': 7, '9k': 7,
      '8a': 8, '8b': 8, '8c': 8, '8d': 8,
      '9a': 9, '9b': 9, '9c': 9, '9d': 9, '9e': 9, '9f': 9,
      '11e': 9,
    };

    for (let reachedStage = 1; reachedStage <= 9; reachedStage++) {
      await unlockThrough(orderId, reachedStage - 1);

      const res = await agent.get(`/orders/${orderId}`);
      expect(res.status).toBe(200);

      const matches = [...res.text.matchAll(/class="step-badge" title="Step ([0-9a-z]+)"/g)];
      for (const match of matches) {
        const badge = match[1];
        if (badge === '4c' || badge === '4d') { continue; }
        expect(badgeStage).toHaveProperty(badge);
        expect(badgeStage[badge]).toBeLessThanOrEqual(reachedStage);
      }
    }
  });

  /**
   * DOM order follows the user's own step sequence (QT -> Buyer PO -> PI
   * & Advance Payment -> Order Confirmation -> Supplier PO), not DB
   * stage_number order, so the badges appear in that same sequence even
   * though their own letters (driven by each card's governing DB stage)
   * aren't numerically ascending — "5a" (PI & Advance, DB stage 3) sits
   * before "4a" (Order Confirmation, DB stage 4) here.
   */
  it('badges appear in ascending order as they first become actionable', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 4);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    const pos1a = res.text.indexOf('title="Step 1a"');
    const pos2a = res.text.indexOf('title="Step 2a"');
    const pos5a = res.text.indexOf('title="Step 5a"');
    const pos4a = res.text.indexOf('title="Step 4a"');
    const pos7a = res.text.indexOf('title="Step 7a"');

    expect(pos1a).toBeGreaterThan(-1);
    expect(pos2a).toBeGreaterThan(-1);
    expect(pos5a).toBeGreaterThan(-1);
    expect(pos4a).toBeGreaterThan(-1);
    expect(pos7a).toBeGreaterThan(-1);
    expect(pos1a).toBeLessThan(pos2a);
    expect(pos2a).toBeLessThan(pos5a);
    expect(pos5a).toBeLessThan(pos4a);
    expect(pos4a).toBeLessThan(pos7a);
  });
});
