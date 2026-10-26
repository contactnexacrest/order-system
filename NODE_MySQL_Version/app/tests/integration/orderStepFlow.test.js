'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderComplianceTaskRepository = require('../../src/repositories/orderComplianceTaskRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderStepFlowTest.php. Owner request (2026-10-25): "after refresh
 * I must be on the same card if sub tasks are there, or the next step open
 * if the previous is completed ... open the lock of one door, enter the
 * next room". Each lettered sub-step now has its own done signal; inside the
 * current step only its first unfinished sub-step starts open (the page
 * scrolls to it), done sub-steps show a tick, and a later step being done
 * marks every earlier one done. Since 2026-10-26 a sub-step waiting on the
 * one before it is locked (data-locked) and the Compliance Checklist runs
 * alongside Steps 7 and 10 without ever locking them.
 */
describe('Order page sequential step flow', () => {
  let agent;

  async function createTestDocument(orderId, typeCode, status = 'draft') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status }
    );
  }

  async function orderAtStep5() {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'QT', 'sent');
    await createTestDocument(orderId, 'BUYERPO');
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    return orderId;
  }

  function cardIcon(html, cardId) {
    const m = html.match(new RegExp(`id="${cardId}"[^>]*>\\s*<summary><span class="step-card-icon (\\w+)"`));
    expect(m).not.toBeNull();
    return m[1];
  }

  function cardIsOpen(html, cardId) {
    return html.includes(`id="${cardId}" open>`);
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

  it('ticks 5a and opens the next sub-step after the PI link is generated', async () => {
    const orderId = await orderAtStep5();
    await piIntakeRepository.createLink(orderId, null);

    const { text } = await agent.get(`/orders/${orderId}`);

    expect(cardIsOpen(text, 'step-group-stage-3')).toBe(true);
    expect(cardIcon(text, 'step-5a-pi-form-link')).toBe('done');
    expect(cardIsOpen(text, 'step-5a-pi-form-link')).toBe(false);
    expect(cardIcon(text, 'step-5b-pi-intake-apply')).toBe('current');
    expect(cardIsOpen(text, 'step-5b-pi-intake-apply')).toBe(true);
    expect(cardIcon(text, 'step-5c-pi-generate')).toBe('locked');
    expect(cardIsOpen(text, 'step-5c-pi-generate')).toBe(false);
  });

  it('opens only the first sub-step of Step 5 before anything is done', async () => {
    const { text } = await agent.get(`/orders/${await orderAtStep5()}`);

    expect(cardIcon(text, 'step-5a-pi-form-link')).toBe('current');
    expect(cardIsOpen(text, 'step-5a-pi-form-link')).toBe(true);
    expect(cardIsOpen(text, 'step-5b-pi-intake-apply')).toBe(false);
  });

  it('marks earlier sub-steps done when a later one is', async () => {
    const orderId = await orderAtStep5();
    await createTestDocument(orderId, 'PI', 'sent');

    const { text } = await agent.get(`/orders/${orderId}`);

    expect(cardIcon(text, 'step-5a-pi-form-link')).toBe('done');
    expect(cardIcon(text, 'step-5b-pi-intake-apply')).toBe('done');
    expect(cardIcon(text, 'step-5c-pi-generate')).toBe('done');
    expect(cardIsOpen(text, 'step-5d-payment-record')).toBe(true);
  });

  it('runs outstanding compliance alongside the steps and never locks them', async () => {
    // Owner request (2026-10-26): the checklist sits at the start of Steps 7
    // and 10, split by phase; outstanding tasks stay amber but never lock the
    // sub-steps next to them.
    const orderId = await orderAtStep5();
    await orderComplianceTaskRepository.initializeForOrder(orderId);
    for (const stageNumber of [3, 4, 5, 6]) {
      await stageGateService.passAndUnlockNext(orderId, stageNumber, null);
    }

    const { text } = await agent.get(`/orders/${orderId}`);

    expect(cardIcon(text, 'step-7d-supplier-po-signed')).toBe('done');
    expect(cardIcon(text, 'compliance-checklist')).toBe('current');
    expect(cardIcon(text, 'compliance-checklist-shipment')).toBe('current');
    expect(cardIsOpen(text, 'compliance-checklist-shipment')).toBe(true);
    expect(text).not.toContain('id="step-10a-supplier-balance-paid" data-locked');
  });

  it('locks a sub-step that waits on the one before it', async () => {
    // Owner rule (2026-10-26): the PI can't be generated until the client's
    // PI-stage form is applied or skipped.
    const orderId = await orderAtStep5();

    let { text } = await agent.get(`/orders/${orderId}`);
    expect(text).toContain('id="step-5c-pi-generate" data-locked="Complete Step 5a first"');

    await orderRepository.markPiFormSkipped(orderId, null, 'Repeat buyer, nothing changed');
    ({ text } = await agent.get(`/orders/${orderId}`));
    expect(cardIcon(text, 'step-5b-pi-intake-apply')).toBe('done');
    expect(text).not.toContain('id="step-5c-pi-generate" data-locked');
    expect(cardIsOpen(text, 'step-5c-pi-generate')).toBe(true);
  });

  it('keeps Mark Fully Settled current after the order is closed', async () => {
    // Owner report (2026-10-26): Close Order passes Stage 9's gate, which
    // used to paint all of Step 13 done — including 13g Mark Fully Settled.
    const orderId = await orderAtStep5();
    for (const stageNumber of [3, 4, 5, 6, 7, 8, 9]) {
      await stageGateService.passAndUnlockNext(orderId, stageNumber, null);
    }
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: orderId });

    const { text } = await agent.get(`/orders/${orderId}`);

    expect(cardIcon(text, 'step-group-stage-9')).toBe('current');
    expect(cardIcon(text, 'step-11g-close-order')).toBe('done');
    expect(cardIcon(text, 'step-despatch')).toBe('current');
    expect(text.indexOf('id="step-11c-bl-endorsed-mark"')).toBeLessThan(text.indexOf('id="step-despatch"'));
    expect(text.indexOf('id="step-11f-bl-endorsement-generate"')).toBeLessThan(text.indexOf('id="step-11c-bl-endorsed-mark"'));
  });

  it('opens only the section holding the current step', async () => {
    // Owner request (2026-10-26): the steps are grouped into five sections,
    // collapsed by default except the one being worked on.
    const { text } = await agent.get(`/orders/${await orderAtStep5()}`);

    expect(cardIsOpen(text, 'phase-booking')).toBe(true);
    expect(cardIcon(text, 'phase-booking')).toBe('current');
    for (const phase of ['phase-production', 'phase-shipment', 'phase-closure', 'phase-aftersales']) {
      expect(text).toContain(`id="${phase}">`);
    }
    expect(cardIcon(text, 'phase-production')).toBe('locked');
    expect(text).toContain('available any time');
    expect(text.indexOf('id="step-complete"')).toBeLessThan(text.indexOf('id="phase-aftersales"'));
  });

  it('marks every earlier pipeline step done once a later one is', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'QT', 'draft');
    for (const stageNumber of [1, 2, 3]) {
      await stageGateService.passAndUnlockNext(orderId, stageNumber, null);
    }

    const { text } = await agent.get(`/orders/${orderId}`);

    for (const cardId of ['step-1-quotation-prepare', 'step-2-quotation-send', 'step-3-quotation-accept', 'step-group-stage-2', 'step-group-stage-3']) {
      expect(cardIcon(text, cardId)).toBe('done');
    }
  });

  it('shows Step 7c Attach Acknowledgment before 7d Confirm Signed', async () => {
    const { text } = await agent.get(`/orders/${await orderAtStep5()}`);

    const attach = text.indexOf('id="step-7c-supplier-po-attach"');
    const confirm = text.indexOf('id="step-7d-supplier-po-signed"');
    expect(attach).toBeGreaterThan(-1);
    expect(confirm).toBeGreaterThan(attach);
  });
});
