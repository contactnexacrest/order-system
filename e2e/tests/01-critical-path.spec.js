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
 * A stage's gate only passes once that stage's own document is approved
 * (mail sending is off on the dev servers, so approval is enough) — each
 * stage step below generates and approves its document before the gate.
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
const DOC_GENERATE_STAGE = { BUYERPO: 2, PI: 3, OC: 4, SUPPO: 5, FDN: 6, PL: 7, BLI: 7, CI: 8, COOPREP: 9 };

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
// The checklist is split in two (Step 7: with the Supplier PO; Step 10:
// before shipment), each card with its own "Mark All N/A".
async function resolveComplianceChecklistIfNeeded(page, orderId) {
  for (const cardId of ['#compliance-checklist', '#compliance-checklist-shipment']) {
    if (await page.locator(cardId).count() === 0) { continue; }
    await openCardAndAncestors(page, cardId);
    const markAllNaForm = page.locator(`${cardId} form[action="/orders/${orderId}/compliance-tasks/mark-all-na"]`);
    if (await markAllNaForm.count() === 0) { continue; }
    await markAllNaForm.locator('input[name="skip_reason"]').fill('Not applicable to this smoke-test order');
    await markAllNaForm.getByRole('button', { name: 'Mark All N/A' }).click();
    await expect(page.locator('.alert')).toBeVisible();
  }
}

// Owner rule (2026-10-26): every sub-step waits for the one before it, so
// the walk below goes card by card. Submits the one form posting to
// `action` (its card must already be unlocked), filling `fields` first.
async function submitStepForm(page, stageNumber, action, fields, buttonName) {
  await openStage(page, stageNumber);
  const form = page.locator(`form[action="${action}"]`);
  for (const [name, value] of Object.entries(fields || {})) {
    await form.locator(`[name="${name}"]`).fill(value);
  }
  // Wait for the real page reload — a submit the browser refused (a
  // required field left empty) then fails here instead of passing on an
  // older banner.
  await Promise.all([page.waitForEvent('load'), form.getByRole('button', { name: buttonName }).click()]);
  await expect(page.locator('.alert')).toBeVisible();
}

// Step 5c (Generate PI) stays locked until the client's PI-stage form is
// applied — this smoke order's client never uses it, so staff skip it.
async function skipPiForm(page, orderId) {
  await openStage(page, 3);
  const skip = page.locator('details.pi-form-skip').first();
  await skip.locator('summary').click();
  await skip.locator('input[name="skip_reason"]').fill('Smoke-test client — details unchanged from the Quotation');
  await skip.getByRole('button', { name: /Skip PI Form/ }).click();
  await expect(page.locator('.alert')).toContainText('PI-stage form skipped');
}

// The Order Financials card's "Export Benefits" tab is JS-hidden
// (Order Detail Page Polish round) until its tab button is clicked —
// "Costs" is active by default. Each form submit is a full page
// reload, which resets the tabs back to default, so this is called
// again after every save.
async function openExportBenefitsTab(page) {
  await page.locator('[data-tab-target="order-financials-export-benefits"]').click();
}

async function resolveExportBenefitSchemes(page, orderId) {
  await page.goto(`/orders/${orderId}#order-financials`);
  await openCardAndAncestors(page, '#order-financials');
  await openExportBenefitsTab(page);
  // One form, every scheme at once (owner request 2026-10-26).
  const form = page.locator(`form[action="/orders/${orderId}/export-benefit-schemes"]`);
  const pendingRows = form.locator('tr').filter({ has: page.locator('select[data-scheme-status] option[value="pending"][selected]') });
  const count = await pendingRows.count();
  if (count === 0) { return; }
  for (let i = 0; i < count; i++) {
    const row = pendingRows.nth(i);
    await row.locator('input[data-scheme-reason]').fill('Not applicable to this smoke-test order');
    await row.locator('select[data-scheme-status]').selectOption('not_eligible');
  }
  await form.getByRole('button', { name: 'Save All Schemes' }).click();
  await expect(page.locator('.alert')).toContainText('eligibility saved');
}


// Suppliers live in the Suppliers directory (manage_suppliers — Admin/MD/
// ED), not an inline form on the order page; adding one lands on its
// edit page with a success banner.
async function addSupplierInDirectory(page, name, types) {
  await page.goto('/suppliers/create');
  await page.locator('input[name="supplier_legal_name"]').fill(name);
  for (const type of types) {
    await page.locator(`input[name="types[]"][value="${type}"]`).check();
  }
  await page.getByRole('button', { name: 'Add Supplier' }).click();
  await expect(page.locator('.alert')).toContainText('added to the suppliers directory');
}

test('staff can create a CIF order and walk it through all 9 stage gates to completion', async ({ page }) => {
  // Every stage now generates and approves its own document before its
  // gate — well past the suite's 60s default.
  test.setTimeout(240_000);

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

  await test.step('Stage 2 — Buyer PO: generate + approve, wet-signature upload, then confirm receipt', async () => {
    await generateDocument(page, orderId, 'BUYERPO', 'generated');
    await assignSelfAndApprove(page, 'BUYERPO');

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

  await test.step('Stage 3 — PI & Advance Payment: PI approved, record then clear', async () => {
    await skipPiForm(page, orderId);
    await generateDocument(page, orderId, 'PI', 'generated');
    await assignSelfAndApprove(page, 'PI');

    await openStage(page, 3);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/advance"]`);
    await recordForm.locator('input[name="advance_amount"]').fill('1800.00');
    // The day's exchange rate is recorded together with the remittance.
    await recordForm.locator('input[name="advance_exchange_rate"]').fill('90.1234');
    await expect(recordForm.locator('[data-remit-statement]')).toContainText("at today's exchange rate of");
    await recordForm.getByRole('button', { name: 'Record Advance Remittance' }).click();
    await expect(page.locator('.alert')).toContainText("Received EUR 1,800.00 at today's exchange rate of ₹90.1234");

    await openStage(page, 3);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/advance/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Advance Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 4 — Order Confirmation: generate OC, record acknowledgement', async () => {
    await generateDocument(page, orderId, 'OC', 'generated');
    await assignSelfAndApprove(page, 'OC');

    // 6b: send the buyer their action link; 6c: record their acknowledgement.
    await submitStepForm(page, 4, `/orders/${orderId}/buyer-action-link`, {}, /Generate Buyer Action Link/);

    await openStage(page, 4);
    const ackForm = page.locator(`form[action="/orders/${orderId}/oc-acknowledgment"]`);
    await ackForm
      .locator('textarea[name="acknowledged_note"]')
      .fill('Buyer confirmed by email reply, quoted verbatim in our records.');
    await ackForm.getByRole('button', { name: /Record Buyer's (Email )?Acknowledgement/ }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 5 — Supplier PO: add supplier, save terms, generate doc, sign', async () => {
    // Two types, so the Supplier PO form asks which applies to this order.
    await addSupplierInDirectory(page, `Smoke Test Quarry ${UNIQUE}`, ['Quarry Owner', 'Factory Owner']);
    await page.goto(`/orders/${orderId}`);

    await openStage(page, 5);
    const spoForm = page.locator('form#supplier-po-form');
    const supplierValue = await spoForm
      .locator('select[name="supplier_id"] option', { hasText: `Smoke Test Quarry ${UNIQUE}` })
      .first()
      .getAttribute('value');
    await spoForm.locator('select[name="supplier_id"]').selectOption(supplierValue);
    await spoForm.locator('select[name="supplier_type"]').selectOption('Quarry Owner');
    await spoForm.locator('input[name="quantity"]').fill('100');
    await spoForm.locator('input[name="unit_price_inr"]').fill('450.00');
    await spoForm.getByRole('button', { name: 'Save Supplier PO Terms' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await generateDocument(page, orderId, 'SUPPO', 'generated');
    await assignSelfAndApprove(page, 'SUPPO');

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

    await generateDocument(page, orderId, 'FDN', 'generated');
    await assignSelfAndApprove(page, 'FDN');

    await openStage(page, 6);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/freight"]`);
    await recordForm.locator('input[name="freight_amount"]').fill('1070.00');
    await recordForm.locator('input[name="freight_exchange_rate"]').fill('90.5');
    await recordForm.getByRole('button', { name: 'Record Freight Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 6);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/freight/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Freight Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 7 — Packing & BL Instruction: supplier balance, pack, PL, ship, BLI, confirm BL', async () => {
    // 10a: the supplier's balance is paid before packing starts.
    await submitStepForm(page, 7, `/orders/${orderId}/supplier-po/balance-paid`, { amount_paid_inr: '45000' }, 'Mark Balance Paid');
    await expect(page.locator('.alert')).toContainText('balance payment recorded');

    await openStage(page, 7);
    const packingForm = page.locator(`form[action="/orders/${orderId}/packing"]`);
    await packingForm.locator('input[name="actual_quantity_packed"]').fill('100');
    await packingForm.locator('input[name="crate_count"]').fill('5');
    await packingForm.locator('input[name="total_net_weight_kg"]').fill('2000');
    await packingForm.locator('input[name="total_gross_weight_kg"]').fill('2400');
    await packingForm.locator('input[name="total_cbm"]').fill('12.5');
    await packingForm.getByRole('button', { name: 'Save Packing & Crates' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await generateDocument(page, orderId, 'PL', 'generated');
    await assignSelfAndApprove(page, 'PL');

    await openStage(page, 7);
    const shippingForm = page.locator(`form[action="/orders/${orderId}/shipping"]`);
    await shippingForm.locator('input[name="shipping_line"]').fill('Smoke Test Shipping Line');
    await shippingForm.locator('input[name="vessel_name"]').fill('MV Smoke Tester');
    await shippingForm.locator('input[name="voyage_number"]').fill('ST-2026-01');
    await shippingForm.locator('input[name="container_count"]').fill('1');
    await shippingForm.locator('input[name="container_no"]').fill('SMKU1234567');
    await shippingForm.getByRole('button', { name: 'Save Shipping Details' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await generateDocument(page, orderId, 'BLI', 'generated');
    await assignSelfAndApprove(page, 'BLI');

    await openStage(page, 7);
    const blForm = page.locator(`form[action="/orders/${orderId}/bl-issued"]`);
    await blForm.locator('input[name="bl_number"]').fill(`SMOKE-BL-${UNIQUE}`);
    await blForm.getByRole('button', { name: /Confirm BL Issued/ }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 8 — Commercial Invoice & Balance Payment: generate CI, record, clear', async () => {
    await generateDocument(page, orderId, 'CI', 'generated');
    await assignSelfAndApprove(page, 'CI');

    // 11b: the scanned BL copy goes to the buyer before the balance comes in.
    await submitStepForm(page, 8, `/orders/${orderId}/scanned-bl-sent`, {}, /Mark Scanned BL Copy Sent/);

    await openStage(page, 8);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/balance"]`);
    await recordForm.locator('input[name="balance_exchange_rate"]').fill('91');
    await recordForm.getByRole('button', { name: 'Record Balance Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 8);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/balance/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Balance Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');
  });

  await test.step('Stage 9 — Document Despatch & Closure: COO Prep approved, then close', async () => {
    await generateDocument(page, orderId, 'COOPREP', 'generated');
    await assignSelfAndApprove(page, 'COOPREP');

    // 13b–13e: original BLs received, endorsement details saved, printed, originals endorsed.
    await submitStepForm(page, 9, `/orders/${orderId}/bl-originals-received`, {}, /Record Original BLs Received/);
    await submitStepForm(page, 9, `/orders/${orderId}/bl-endorsement`, { bl_number: `SMOKE-BL-${UNIQUE}` }, /Save BL Endorsement Details/);
    await submitStepForm(page, 9, `/orders/${orderId}/bl-endorsement/generate`, {}, /Generate \/ Print BL Endorsement/);
    await submitStepForm(page, 9, `/orders/${orderId}/bl-endorsed`, {}, /Mark Original BLs Endorsed/);

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
