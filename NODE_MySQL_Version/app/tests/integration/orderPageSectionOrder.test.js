'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderPackingRepository = require('../../src/repositories/orderPackingRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderPageSectionOrderTest.php. Task #36 — "Rearrange Standard
 * template into exact 0-17 sequence." The user's own complaint: "no back
 * and forth on screen... after every step we need to come back to
 * document - to generate/view document." Every stage's own "Generate X"
 * document button used to be bunched together in one central "Documents
 * & Approvals" panel at the top of the page, away from that stage's
 * other actions — this moved each one into its own stage section. Also
 * fixes three section-ordering violations found during the audit: Order
 * Communication (step 13) sat before everything else on the page instead
 * of after step 12; Disputes (15/16) rendered before Order Feedback
 * (14); and Travel Expenses (11a) sat next to step 8 instead of with the
 * rest of Order Financials.
 *
 * Position assertions anchor on each real section's own closing `</h2>`
 * tag (e.g. 'Stage 2 — Buyer PO</h2>') rather than the bare heading
 * text — the page's own progress-stepper nav (near the very top of the
 * page) renders every stage's name too, in an `aria-label="Jump to
 * Stage N — ..."` with no `</h2>` anywhere near it, so a bare-text
 * search would false-match that earlier stepper entry instead of the
 * real section further down the page.
 */
describe('Order page section order (Task #36)', () => {
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

  async function createTestSupplierPo(orderId) {
    const result = await db.execute(
      "INSERT INTO suppliers (supplier_legal_name) VALUES ('Section Order Test Supplier Co')"
    );
    const supplierId = result.insertId;
    await db.execute(
      `INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference)
       VALUES (:order_id, :supplier_id, 'Section Order Test Supplier Co', 'SECORDER-SPO-TEST')`,
      { order_id: orderId, supplier_id: supplierId }
    );
  }

  async function render(orderId) {
    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    return res.text;
  }

  // ---------------------------------------------------------------
  // Document-generate buttons now live inside their own stage section
  // ---------------------------------------------------------------

  it('Buyer PO generate button lives inside its own Step 4a card, not the top Documents panel', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 1);

    const output = await render(orderId);

    const stage2Pos = output.indexOf("Step 4a &middot; Generate Buyer's PO</h2>");
    const buyerPoGeneratePos = output.indexOf('Generate Buyer PO</button>');

    expect(stage2Pos).toBeGreaterThan(-1);
    expect(buyerPoGeneratePos).toBeGreaterThan(-1);
    expect(buyerPoGeneratePos).toBeGreaterThan(stage2Pos);

    const topOfPage = output.slice(0, stage2Pos);
    expect(topOfPage).not.toContain('Generate Buyer PO</button>');
  });

  it('PI generate button lives inside its own Step 5a section', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 2);

    const output = await render(orderId);

    const stage3Pos = output.indexOf('Step 5a &middot; Generate Proforma Invoice</h2>');
    const piGeneratePos = output.indexOf('Generate Proforma Invoice</button>');

    expect(stage3Pos).toBeGreaterThan(-1);
    expect(piGeneratePos).toBeGreaterThan(-1);
    expect(piGeneratePos).toBeGreaterThan(stage3Pos);
  });

  it('OC generate button lives inside Stage 4 section', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 3);

    const output = await render(orderId);

    const stage4Pos = output.indexOf('Step 6a &middot; Generate Order Confirmation</h2>');
    const ocGeneratePos = output.indexOf('Generate Order Confirmation</button>');

    expect(stage4Pos).toBeGreaterThan(-1);
    expect(ocGeneratePos).toBeGreaterThan(-1);
    expect(ocGeneratePos).toBeGreaterThan(stage4Pos);
  });

  it('Supplier PO generate button lives inside Stage 5 section once a Supplier PO exists', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 4);
    await createTestSupplierPo(orderId);

    const output = await render(orderId);

    const stage5Pos = output.indexOf('Step 7b &middot; Generate Supplier PO</h2>');
    const suppoGeneratePos = output.indexOf('Generate Supplier PO</button>');

    expect(stage5Pos).toBeGreaterThan(-1);
    expect(suppoGeneratePos).toBeGreaterThan(-1);
    expect(suppoGeneratePos).toBeGreaterThan(stage5Pos);
  });

  it('Debit Note generate button lives inside Stage 6 section once freight terms are saved', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await unlockThrough(orderId, 5);
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '1200.00', insurance_amount: '150.00' });

    const output = await render(orderId);

    const stage6Pos = output.indexOf('Step 9b &middot; Generate Debit Note</h2>');
    const fdnGeneratePos = output.indexOf('Generate Debit Note</button>');

    expect(stage6Pos).toBeGreaterThan(-1);
    expect(fdnGeneratePos).toBeGreaterThan(-1);
    expect(fdnGeneratePos).toBeGreaterThan(stage6Pos);
  });

  it('Packing List generate button lives inside Stage 7 section once packing is saved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 6);
    await orderPackingRepository.upsert(orderId, { actual_quantity_packed: '50', crate_count: '10' });

    const output = await render(orderId);

    const stage7Pos = output.indexOf('Step 9f &middot; Generate Packing List</h2>');
    const plGeneratePos = output.indexOf('Generate Packing List</button>');

    expect(stage7Pos).toBeGreaterThan(-1);
    expect(plGeneratePos).toBeGreaterThan(-1);
    expect(plGeneratePos).toBeGreaterThan(stage7Pos);
  });

  it('Commercial Invoice generate button lives inside Stage 8 section', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 7);

    const output = await render(orderId);

    const stage8Pos = output.indexOf('Step 9j &middot; Generate Commercial Invoice');
    const ciGeneratePos = output.indexOf('Generate Commercial Invoice</button>');

    expect(stage8Pos).toBeGreaterThan(-1);
    expect(ciGeneratePos).toBeGreaterThan(-1);
    expect(ciGeneratePos).toBeGreaterThan(stage8Pos);
  });

  it('no document-generate button except Quotation and Annexure remains in the top Step 1 card', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 1);

    const output = await render(orderId);

    const documentsPanelStart = output.indexOf('id="step-1-quotation-prepare"');
    const stage2Pos = output.indexOf("Step 4a &middot; Generate Buyer's PO</h2>");
    expect(documentsPanelStart).toBeGreaterThan(-1);
    expect(stage2Pos).toBeGreaterThan(-1);
    const panel = output.slice(documentsPanelStart, stage2Pos);

    expect(panel).toContain('document_type" value="QT"');
    for (const label of ['Generate Buyer PO</button>', 'Generate Proforma Invoice</button>', 'Generate Order Confirmation</button>', 'Generate Supplier PO</button>', 'Generate Debit Note</button>', 'Generate Packing List</button>', 'Generate Commercial Invoice</button>']) {
      expect(panel).not.toContain(label);
    }
  });

  // ---------------------------------------------------------------
  // Section-order fixes: Communication -> Feedback -> Disputes,
  // Travel Expenses inside Order Financials
  // ---------------------------------------------------------------

  it('Order Communication renders before Feedback, which renders before Disputes', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const output = await render(orderId);

    const communicationPos = output.indexOf('<h2>Step 13 &middot; Order Communication</h2>');
    const feedbackPos = output.indexOf('<h2>Step 14 &middot; Order Feedback</h2>');
    const disputesPos = output.indexOf('<h2>Step 16 &middot; Disputes</h2>');

    expect(communicationPos).toBeGreaterThan(-1);
    expect(feedbackPos).toBeGreaterThan(-1);
    expect(disputesPos).toBeGreaterThan(-1);
    expect(communicationPos).toBeLessThan(feedbackPos);
    expect(feedbackPos).toBeLessThan(disputesPos);
  });

  it('Travel Expenses renders inside Order Financials, next to LC and Bank Loan', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const output = await render(orderId);

    const financialsPos = output.indexOf('id="order-financials"');
    const lcPos = output.indexOf('Letter of Credit &amp; Bank Loan</h3>');
    const travelPos = output.indexOf('Travel Expenses — Factory Visits');
    const otherCostsPos = output.indexOf('Other Order Costs</h3>');
    const productionPos = output.indexOf('<h2>Step 8 &middot; Production &amp; Estimated Shipment</h2>');

    expect(financialsPos).toBeGreaterThan(-1);
    expect(lcPos).toBeGreaterThan(-1);
    expect(travelPos).toBeGreaterThan(-1);
    expect(otherCostsPos).toBeGreaterThan(-1);
    expect(travelPos).toBeGreaterThan(financialsPos);
    expect(travelPos).toBeGreaterThan(lcPos);
    expect(travelPos).toBeLessThan(otherCostsPos);
    expect(productionPos).toBeLessThan(travelPos);
  });

  it('Order Financials renders before Stage 9 closure', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const output = await render(orderId);

    const financialsPos = output.indexOf('id="order-financials"');
    const stage9Pos = output.indexOf('id="step-11a-coo-prep"');

    expect(financialsPos).toBeGreaterThan(-1);
    expect(stage9Pos).toBeGreaterThan(-1);
    expect(financialsPos).toBeLessThan(stage9Pos);
    expect(output).not.toContain('Order Financials below');
  });
});
