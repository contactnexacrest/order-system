'use strict';

const path = require('path');
const { test, expect } = require('@playwright/test');
const { login, ROLES } = require('./support/auth');
const ADMIN_NAME = ROLES.admin.name;

/**
 * Critical-path UI smoke test: one real CIF order, created and walked
 * through all 9 pipeline stage gates exactly as staff would in the
 * browser, through to status = 'complete'. CIF (not FOB) is deliberately
 * chosen so Stage 6 (Freight Payment) is exercised rather than
 * auto-skipped — genuine 9-of-9 stage coverage.
 *
 * The order page is a vertical sequence of native <details>/<summary>
 * step-cards (views/orders/show.php) — each one collapsed/expanded
 * independently, no single-active-panel JS. The top stage stepper
 * (`.process-step[data-stage=N]`) still exists and opens (and scrolls
 * to) whichever card covers that DB stage — used for each document
 * type's own Generate action. Review/approve is different: every
 * document type's reviewer-assignment/approve widget lives inside the
 * single `#document-review-approval` reference card (reachable from
 * anywhere, not tied to any one stage), which — like every other
 * reference card — renders collapsed unless it happens to be the
 * order's current step, so reaching it needs its own explicit open.
 *
 * This only drives the actions that actually pass each stage's gate
 * (confirmed against each controller method, not assumed from the
 * TEST_PLAN's one-line summaries) — it deliberately skips every
 * non-gating optional step (FIRC/INR-actual recording, exchange-rate
 * entry, "Mark Scanned BL Copy Sent", the Stage 9 original-BL
 * record-keeping steps) to stay a lean smoke test rather than an
 * exhaustive one.
 */

const DUMMY_FILE = path.join(__dirname, 'fixtures', 'dummy-signed-copy.pdf');
const UNIQUE = Date.now();

// Clicks a stage stepper dot, which opens (and scrolls to) whichever
// step-card(s) cover that DB stage — the Advance Payment form lives in
// the Stage 3 "PI & Advance Payment" step-card, reached the same way as
// every other stage's own primary action.
async function openStage(page, stageNumber) {
  await page.locator(`.process-step[data-stage="${stageNumber}"]`).click();
}

function docForm(page, orderId, typeCode) {
  return page.locator(
    `form[action="/orders/${orderId}/documents/generate"]:has(input[name="document_type"][value="${typeCode}"])`
  );
}

// Every document type's Generate button lives in its own stage panel,
// not uniformly in a single "Documents & Approvals" panel — only QT
// and ANNEXA actually sit in data-stage="1". External QA report TI-10:
// this map had drifted (missing SUPPO/CI entirely, both silently
// defaulting to the now-wrong ?? 1 below; OC also moved to Stage 4
// after the order page's stage re-sequencing) — re-derived directly
// from views/orders/show.php's own data-stage sections rather than
// assumed from the TEST_PLAN's simplified summary.
const DOC_GENERATE_STAGE = { OC: 4, SUPPO: 5, BLI: 7, CI: 8, COOPREP: 9 };

async function generateDocument(page, orderId, typeCode, expectedBannerSubstring) {
  await openStage(page, DOC_GENERATE_STAGE[typeCode] ?? 1);
  await docForm(page, orderId, typeCode).locator('button[type="submit"]').click();
  await expect(page.locator('.alert')).toContainText(expectedBannerSubstring);
}

function documentPanel(page, typeCode) {
  // The order-detail-page rebuild wraps the whole "Documents & Approvals"
  // section in its own outer <details class="step-card">, which itself
  // "has" a matching nested summary descendant (the document-specific
  // review widget this is actually looking for) — excluding .step-card
  // keeps this matching only the innermost, document-specific <details>,
  // not the step-card wrapper around all of them.
  return page
    .locator('details:not(.step-card)')
    .filter({ has: page.locator('summary', { hasText: new RegExp('^\\s*' + typeCode + '\\s') }) })
    .first();
}

// #document-review-approval is a reference card (same bucket as Payment
// Overview/Compliance Checklist/Amendments) — it renders collapsed by
// default, not tied to any stage stepper dot, so every document's
// review/approve widget needs this opened explicitly first.
async function openDocumentReviewCard(page) {
  await page.locator('#document-review-approval').evaluate((el) => { el.open = true; });
}

/** Opens a document's review panel, assigns the logged-in admin as sole reviewer, then approves as them. */
async function assignSelfAndApprove(page, typeCode) {
  await openDocumentReviewCard(page);
  let panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  const reviewerSelect = panel.locator('select[name="reviewer_ids[]"]');
  const selfOptionValue = await panel
    .locator('option', { hasText: ADMIN_NAME })
    .first()
    .getAttribute('value');
  await reviewerSelect.selectOption(selfOptionValue);
  await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
  await expect(page.locator('.alert')).toBeVisible();

  await openDocumentReviewCard(page);
  panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  await panel.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.locator('.alert')).toBeVisible();
}

// Opens a card by id and every ancestor <details> above it (e.g.
// #compliance-checklist nests inside Step 8's .step-group) — a closed
// ancestor hides a nested card regardless of its own `open` state.
async function openCardAndAncestors(page, selector) {
  await page.locator(selector).evaluate((el) => {
    for (let node = el; node; node = node.parentElement ? node.parentElement.closest('details') : null) {
      node.open = true;
    }
  });
}

// Order closure now blocks (OrderController::markFullySettled) while any
// compliance-task or export-benefit-scheme row is unresolved — both are
// always materialized at order creation, never left as a missing row.
async function resolveComplianceChecklistIfNeeded(page, orderId) {
  await openCardAndAncestors(page, '#compliance-checklist');
  const markAllNaForm = page.locator(`form[action="/orders/${orderId}/compliance-tasks/mark-all-na"]`);
  if (await markAllNaForm.count() === 0) { return; }
  await markAllNaForm.locator('input[name="skip_reason"]').fill('Not applicable to this smoke-test order');
  await markAllNaForm.getByRole('button', { name: 'Mark All N/A' }).click();
  await expect(page.locator('.alert')).toBeVisible();
}

async function resolveExportBenefitSchemes(page, orderId) {
  await page.goto(`/orders/${orderId}#order-financials`);
  await openCardAndAncestors(page, '#order-financials');
  for (;;) {
    const pendingForm = page
      .locator(`form[action="/orders/${orderId}/export-benefit-schemes"]`)
      .filter({ has: page.locator('select[name="status"] option[value="pending"][selected]') });
    if (await pendingForm.count() === 0) { break; }
    const form = pendingForm.first();
    await form.locator('select[name="status"]').selectOption('not_eligible');
    await form.locator('input[name="reason"]').fill('Not applicable to this smoke-test order');
    await form.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.alert')).toContainText('eligibility updated');
    await openCardAndAncestors(page, '#order-financials');
  }
}

test('staff can create a CIF order and walk it through all 9 stage gates to completion', async ({ page }) => {
  // Several gate-confirming actions (advance/freight/balance clear, etc.)
  // use a native onsubmit confirm() as a last-are-you-sure check — accept
  // it automatically so the suite proceeds as a confident operator would.
  page.on('dialog', (dialog) => dialog.accept());

  await login(page);

  let orderId;

  await test.step('Create a fresh client', async () => {
    await page.goto('/clients/create');
    await page.locator('input[name="company_legal_name"]').fill(`Playwright Smoke Buyer ${UNIQUE}`);
    await page.locator('textarea[name="billing_address"]').fill('1 Smoke Test Street, Test City, Test Country');
    await page.locator('input[name="email"]').fill(`smoke-buyer-${UNIQUE}@nexacrest.test`);
    await page.getByRole('button', { name: 'Create Client' }).click();
    await expect(page).toHaveURL(/\/clients\/\d+$/);
  });

  const clientId = Number(page.url().match(/\/clients\/(\d+)$/)[1]);

  await test.step('Create a CIF order for that client', async () => {
    await page.goto('/orders/create');
    await page.locator('select[name="client_id"]').selectOption(String(clientId));
    await page.locator('select[name="incoterm_id"]').selectOption({ label: 'CIF' });
    const eurValue = await page
      .locator('select[name="currency_id"] option', { hasText: 'EUR' })
      .first()
      .getAttribute('value');
    await page.locator('select[name="currency_id"]').selectOption(eurValue);
    const presetValue = await page
      .locator('select[name="payment_preset_id"] option', { hasText: 'Standard' })
      .first()
      .getAttribute('value');
    await page.locator('select[name="payment_preset_id"]').selectOption(presetValue);
    await page.locator('input[name="port_of_discharge_text"]').fill('Rotterdam, Netherlands');
    await page.locator('input[name="indicative_freight_amount"]').fill('950');
    await page.locator('input[name="indicative_insurance_amount"]').fill('120');

    await page.locator('input[name="product_description[]"]').first().fill('Granite Memorial Headstone');
    await page.locator('input[name="product_quantity[]"]').first().fill('100');
    await page.locator('input[name="product_unit_price[]"]').first().fill('45.00');
    await page.locator('input[name="product_hs_code[]"]').first().fill('68022310');

    await page.getByRole('button', { name: 'Create Order' }).click();
    await expect(page).toHaveURL(/\/orders\/\d+$/);
  });

  orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);

  await test.step('Stage 1 — Enquiry & Quotation: generate QT and get it approved', async () => {
    await generateDocument(page, orderId, 'QT', 'generated');
    await assignSelfAndApprove(page, 'QT');
  });

  await test.step('Stage 2 — Buyer PO: wet-signature upload, then confirm receipt', async () => {
    await openStage(page, 2);
    const uploadForm = page.locator(`form[action="/orders/${orderId}/buyer-po/documents"]`);
    await uploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
    await uploadForm.getByRole('button', { name: 'Attach Buyer PO Copy' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 2);
    const poForm = page.locator(`form[action="/orders/${orderId}/buyer-po"]`);
    await poForm.locator('input[name="buyers_po_ref"]').fill(`PO-SMOKE-${UNIQUE}`);
    await poForm.getByRole('button', { name: 'Confirm Buyer PO Received' }).click();
    await expect(page.locator('.alert')).toBeVisible();
  });

  await test.step('Stage 3 — Advance Payment: record then clear', async () => {
    await openStage(page, 3);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/advance"]`);
    await recordForm.locator('input[name="advance_amount"]').fill('1800.00');
    await recordForm.getByRole('button', { name: 'Record Advance Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 3);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/advance/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Advance Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 4 — Order Confirmation: generate OC, record acknowledgement', async () => {
    await generateDocument(page, orderId, 'OC', 'generated');

    await openStage(page, 4);
    const ackForm = page.locator(`form[action="/orders/${orderId}/oc-acknowledgment"]`);
    await ackForm
      .locator('textarea[name="acknowledged_note"]')
      .fill('Buyer confirmed by email reply, quoted verbatim in our records.');
    await ackForm.getByRole('button', { name: /Record Buyer's Acknowledgement/ }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 5 — Supplier PO: add supplier, save terms, generate doc, sign', async () => {
    await openStage(page, 5);
    const supplierForm = page.locator(`form[action="/orders/${orderId}/suppliers"]`);
    await supplierForm.locator('input[name="supplier_legal_name"]').fill(`Smoke Test Quarry ${UNIQUE}`);
    await supplierForm.getByRole('button', { name: /Add Supplier/i }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 5);
    const spoForm = page.locator('form#supplier-po-form');
    const supplierValue = await spoForm
      .locator('select[name="supplier_id"] option', { hasText: `Smoke Test Quarry ${UNIQUE}` })
      .first()
      .getAttribute('value');
    await spoForm.locator('select[name="supplier_id"]').selectOption(supplierValue);
    await spoForm.locator('input[name="quantity"]').fill('100');
    await spoForm.locator('input[name="unit_price_inr"]').fill('450.00');
    await spoForm.getByRole('button', { name: 'Save Supplier PO Terms' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await generateDocument(page, orderId, 'SUPPO', 'generated');

    await openStage(page, 5);
    const ackUploadForm = page.locator(`form[action="/orders/${orderId}/supplier-po/documents"]`);
    await ackUploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
    await ackUploadForm.getByRole('button', { name: 'Attach Supplier PO Acknowledgment' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 5);
    const signedForm = page.locator(`form[action="/orders/${orderId}/supplier-po/signed"]`);
    await signedForm.getByRole('button', { name: /Confirm Supplier Signed/ }).click();
    await expect(page.locator('.alert')).toBeVisible();
  });

  await test.step('Stage 6 — Freight Payment: terms, record, clear', async () => {
    await openStage(page, 6);
    const termsForm = page.locator(`form[action="/orders/${orderId}/freight-terms"]`);
    await termsForm.locator('input[name="confirmed_freight_rate"]').fill('950');
    await termsForm.locator('input[name="insurance_amount"]').fill('120');
    await termsForm.locator('input[name="freight_forwarder_name"]').fill('Smoke Test Forwarder Ltd');
    await termsForm.getByRole('button', { name: 'Save Freight Terms' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 6);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/freight"]`);
    await recordForm.locator('input[name="freight_amount"]').fill('1070.00');
    await recordForm.getByRole('button', { name: 'Record Freight Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 6);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/freight/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Freight Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 7 — Packing & BL Instruction: pack, ship, confirm BL', async () => {
    await openStage(page, 7);
    const packingForm = page.locator(`form[action="/orders/${orderId}/packing"]`);
    await packingForm.locator('input[name="actual_quantity_packed"]').fill('100');
    await packingForm.locator('input[name="crate_count"]').fill('5');
    await packingForm.locator('input[name="total_net_weight_kg"]').fill('2000');
    await packingForm.locator('input[name="total_gross_weight_kg"]').fill('2400');
    await packingForm.locator('input[name="total_cbm"]').fill('12.5');
    await packingForm.getByRole('button', { name: 'Save Packing & Crates' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 7);
    const shippingForm = page.locator(`form[action="/orders/${orderId}/shipping"]`);
    await shippingForm.locator('input[name="shipping_line"]').fill('Smoke Test Shipping Line');
    await shippingForm.locator('input[name="vessel_name"]').fill('MV Smoke Tester');
    await shippingForm.locator('input[name="voyage_number"]').fill('ST-2026-01');
    await shippingForm.locator('input[name="container_count"]').fill('1');
    await shippingForm.locator('input[name="container_no"]').fill('SMKU1234567');
    await shippingForm.getByRole('button', { name: 'Save Shipping Details' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 7);
    const blForm = page.locator(`form[action="/orders/${orderId}/bl-issued"]`);
    await blForm.locator('input[name="bl_number"]').fill(`SMOKE-BL-${UNIQUE}`);
    await blForm.getByRole('button', { name: /Confirm BL Issued/ }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 8 — Commercial Invoice & Balance Payment: generate CI, record, clear', async () => {
    await generateDocument(page, orderId, 'CI', 'generated');

    await openStage(page, 8);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/balance"]`);
    await recordForm.getByRole('button', { name: 'Record Balance Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 8);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/balance/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Balance Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 9 — Document Despatch & Closure: COO Prep + BLI approved, then close', async () => {
    await generateDocument(page, orderId, 'COOPREP', 'generated');
    await assignSelfAndApprove(page, 'COOPREP');

    await generateDocument(page, orderId, 'BLI', 'generated');
    await assignSelfAndApprove(page, 'BLI');

    await openStage(page, 9);
    const closeForm = page.locator(`form[action="/orders/${orderId}/close"]`);
    await closeForm.locator('input[name="courier_tracking_number"]').fill(`SMOKE-COURIER-${UNIQUE}`);
    await closeForm.getByRole('button', { name: /Close Order/ }).click();
    // External QA report TI-10: closeOrder() is now phase 1 of the
    // two-phase closure (Phase 1d) — it moves the order to
    // 'shipped_docs_sent', not 'complete' directly, so the flash is no
    // longer "Order closed".
    await expect(page.locator('.alert')).toContainText('Documents sent to buyer');
  });

  await test.step('Mark Fully Settled — phase 2 of closure, the real completion', async () => {
    await resolveComplianceChecklistIfNeeded(page, orderId);
    await resolveExportBenefitSchemes(page, orderId);

    await openStage(page, 9);
    const settleForm = page.locator(`form[action="/orders/${orderId}/mark-fully-settled"]`);
    await settleForm.getByRole('button', { name: 'Mark Fully Settled' }).click();
    await expect(page.locator('.alert')).toContainText('Order fully settled and closed');
  });

  await test.step('Order is genuinely complete — all 9 stages resolved', async () => {
    await expect(page.locator('.badge-complete')).toHaveText('complete');
    await expect(page.locator('body')).toContainText('Complete — all 9 stages resolved');
  });
});
