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

  // 5a (PI Form Link) and 6c (Buyer Action Link) are deliberate exceptions
  // — both are optional, never-blocking alternate channels available from
  // the very first render regardless of stage ("entirely optional and
  // never blocks anything" per docs/SOP), not part of the gated sequence.

  it("shows only Stage 1's badge (plus the two documented exceptions) on a fresh order", async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('<span class="step-badge" title="Step 1a">1a</span>');
    expect(res.text).toContain('title="Step 5a"');
    expect(res.text).toContain('title="Step 6c"');

    const stripped = res.text.replace(/class="step-badge" title="Step (1a|5a|6c)">[^<]*<\/span>/g, '');
    expect(stripped).not.toContain('class="step-badge"');
  });

  /**
   * The order-detail-page rebuild relabelled every badge to the user's
   * own step numbering rather than the DB's stage_number, so a badge's
   * own digit prefix no longer equals its governing DB stage — this map
   * is the authoritative link between the two, kept in lock-step with
   * the stepBadge() calls in views/orders/show.njk. Freight Payment (DB
   * stage 6) kept its original Step 9 numbering; Packing & BL
   * Instruction (DB stage 7, previously also mislabelled "Step 9") is
   * now Step 10 (10a-10e); Commercial Invoice & Balance (DB stage 8,
   * previously "Step 9-10" with an isFob-dependent dual badge) is now
   * unconditionally Step 11 (11a-11d, no FOB branching); Closure —
   * Documents & Settlement (DB stage 9, previously colliding with Order
   * Financials as "Step 11") is now Step 13 (13a-13g).
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
      '4a': 2, '4b': 2, '4c': 2,
      '5c': 3, '5d': 3, '5e': 3,
      '6a': 4, '6b': 4,
      '7a': 5, '7b': 5, '7c': 5, '7d': 5,
      '9a': 6, '9b': 6, '9c': 6, '9d': 6,
      '10a': 7, '10b': 7, '10c': 7, '10d': 7, '10e': 7,
      '11a': 8, '11b': 8, '11c': 8, '11d': 8,
      '13a': 9, '13b': 9, '13c': 9, '13d': 9, '13e': 9, '13f': 9, '13g': 9,
    };

    for (let reachedStage = 1; reachedStage <= 9; reachedStage++) {
      await unlockThrough(orderId, reachedStage - 1);

      const res = await agent.get(`/orders/${orderId}`);
      expect(res.status).toBe(200);

      const matches = [...res.text.matchAll(/class="step-badge" title="Step ([0-9a-z]+)"/g)];
      for (const match of matches) {
        const badge = match[1];
        if (badge === '5a' || badge === '6c') { continue; }
        expect(badgeStage).toHaveProperty(badge);
        expect(badgeStage[badge]).toBeLessThanOrEqual(reachedStage);
      }
    }
  });

  /**
   * Stages 1-5's own primary badge each render with no further setup
   * beyond the stage itself being unlocked. The order-detail-page rebuild
   * lays out cards in the user's own step sequence (QT -> Buyer PO -> PI
   * & Advance Payment -> Order Confirmation -> Supplier PO), not DB
   * stage_number order, and every badge now carries that same user-step
   * numbering too, so the badges appear in ascending step order: 1a ->
   * 4a -> 5a -> 6a -> 7a. (5a is itself an always-rendered exception — see
   * above — but its card still sits positionally inside the Step 5 group,
   * between Step 4's and Step 6's, so the ordering check still holds.)
   */
  it('badges appear in ascending order as they first become actionable', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 4);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    const pos1a = res.text.indexOf('title="Step 1a"');
    const pos4a = res.text.indexOf('title="Step 4a"');
    const pos5a = res.text.indexOf('title="Step 5a"');
    const pos6a = res.text.indexOf('title="Step 6a"');
    const pos7a = res.text.indexOf('title="Step 7a"');

    expect(pos1a).toBeGreaterThan(-1);
    expect(pos4a).toBeGreaterThan(-1);
    expect(pos5a).toBeGreaterThan(-1);
    expect(pos6a).toBeGreaterThan(-1);
    expect(pos7a).toBeGreaterThan(-1);
    expect(pos1a).toBeLessThan(pos4a);
    expect(pos4a).toBeLessThan(pos5a);
    expect(pos5a).toBeLessThan(pos6a);
    expect(pos6a).toBeLessThan(pos7a);
  });
});
