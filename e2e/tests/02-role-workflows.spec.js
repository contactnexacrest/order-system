'use strict';

const path = require('path');
const { test, expect } = require('@playwright/test');
const { login, logout, loginClient, ROLES } = require('./support/auth');
const { resetClientPassword } = require('./support/db');

/**
 * Deep per-role workflow suite: not "does the nav look right" (that's
 * 01-role-visibility.spec.js) but "can this role actually DO the one
 * piece of real work their permission grant exists for, in a real
 * browser, with no error — and are they correctly refused the pieces
 * that belong to a different role." Built from the real route
 * permission gates in public_html/index.php, not the TEST_PLAN's
 * one-line summaries:
 *
 *   Stage 1/9 approvals — needs 'approve_documents' (Admin/MD/ED only;
 *     none of the 5 operational roles hold it) to ASSIGN a reviewer, so
 *     those sub-steps stay Admin's even mid-pipeline.
 *   Stage 2 Buyer PO, Stage 4 OC ack, Stage 5 Supplier PO — 'manage_orders'
 *     (Export Executive, not Accounts/Logistics).
 *   Stage 3/8 advance & balance payment clearance — 'manage_payments'
 *     (Accounts Executive only — Export Executive also holds it, but
 *     Accounts is the one role whose whole point is clearing payments).
 *     Generating the Stage 8 Commercial Invoice itself needs the
 *     separate 'generate_documents' permission, which Accounts Executive
 *     does NOT hold — Export Executive generates it, Accounts clears the
 *     payment against it, exactly as two different real people would.
 *   Stage 6 freight terms/payment/clear AND Stage 7 packing/shipping/BL
 *     — both actually gated on 'manage_shipping', not 'manage_payments'
 *     (freight is logistics-owned here, not accounts-owned) — so both
 *     stages are Logistics Executive's, plus Stage 9's document
 *     generation (generate_documents) and the final close (close_orders),
 *     both of which Logistics also holds.
 *   CA / Chartered Accountant holds only ca_module_view + inr_actual_view
 *     — NOT ca_module_manage, so /ca/fy-locks (gated on ca_module_manage)
 *     is refused even though it's a GET, and no CA write route is
 *     reachable at all.
 *   Viewer / Auditor holds view_orders (so /orders/{id} itself succeeds)
 *     but no manage_orders/close_orders — refused on any mutation.
 *
 * One real CIF order is walked through all 9 stages exactly as
 * 01-critical-path.spec.js does, except each stage's actual work is
 * done by whichever role's permission actually covers it, with explicit
 * login/logout between stages — proving the gate isn't just "any
 * logged-in user", it's the specific role the grant names.
 */

const DUMMY_FILE = path.join(__dirname, 'fixtures', 'dummy-signed-copy.pdf');
const UNIQUE = Date.now();

async function openStage(page, stageNumber) {
  await page.locator(`.process-step[data-stage="${stageNumber}"]`).click();
}

function docForm(page, orderId, typeCode) {
  return page.locator(
    `form[action="/orders/${orderId}/documents/generate"]:has(input[name="document_type"][value="${typeCode}"])`
  );
}

// Every document type's Generate button lives in its own stage panel —
// External QA report TI-10: this map had drifted (missing SUPPO/CI
// entirely, both silently defaulting to the now-wrong ?? 1 below; OC
// also moved to Stage 4 after the order page's stage re-sequencing),
// re-derived directly from views/orders/show.php's own data-stage
// sections. See 01-critical-path.spec.js's own copy of this map/comment.
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

/**
 * Order ownership / assignment visibility: a scoped role (Export
 * Executive, Logistics Executive — neither holds view_all_orders) can
 * only open an order it's assigned to. This single shared order is
 * worked by BOTH of them at different points (the multi-assignee model
 * exists precisely so both can be assigned to it concurrently, rather
 * than reassigning it back and forth on every handoff) — must be run as
 * Admin (or any Admin/MD/ED holder), the only tier holding
 * assign_order_owner. Caller is responsible for being logged in as such
 * before calling this.
 */
async function addAssigneeTo(page, orderId, roleName) {
  await page.goto(`/orders/${orderId}`);
  const form = page.locator(`form[action="/orders/${orderId}/assignees"]`);
  const targetName = ROLES[roleName].name;
  const targetValue = await form.locator('option', { hasText: targetName }).first().getAttribute('value');
  await form.locator('select[name="user_id"]').selectOption(targetValue);
  await form.getByRole('button', { name: 'Add' }).click();
  await expect(page.locator('.alert')).toContainText('added as an assignee');
}

/**
 * Assigning a reviewer requires 'approve_documents', which only
 * Admin/MD/ED hold — so this is always run as Admin, naming whichever
 * role just generated the document as the one who should actually sign
 * off isn't an option here; Admin reviews and approves regardless of who
 * generated it, exactly as the real permission grant forces in practice.
 */
async function adminAssignSelfAndApprove(page, typeCode) {
  const adminName = ROLES.admin.name;
  await openDocumentReviewCard(page);
  let panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  const reviewerSelect = panel.locator('select[name="reviewer_ids[]"]');
  const selfOptionValue = await panel.locator('option', { hasText: adminName }).first().getAttribute('value');
  await reviewerSelect.selectOption(selfOptionValue);
  await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
  await expect(page.locator('.alert')).toBeVisible();

  await openDocumentReviewCard(page);
  panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  await panel.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.locator('.alert')).toBeVisible();
}

/**
 * A stage's gate only passes once that stage's own document is approved,
 * and only Admin holds approve_documents — so a role mid-stage hands over
 * to Admin for the approval, then picks the order back up.
 */
async function approveAsAdmin(page, orderId, typeCode, returnToRole) {
  await logout(page);
  await login(page, 'admin');
  await page.goto(`/orders/${orderId}`);
  await adminAssignSelfAndApprove(page, typeCode);
  await logout(page);
  await login(page, returnToRole);
  await page.goto(`/orders/${orderId}`);
}

// Order closure now blocks (OrderController::markFullySettled) while any
// compliance-task or export-benefit-scheme row is unresolved — both are
// always materialized at order creation, never left as a missing row.
// close_orders holders (here, Logistics Executive) can bulk-resolve the
// Compliance Checklist themselves; Export Benefit Scheme Eligibility
// lives in the Order Financials card, gated on manage_order_financials
// (Admin/MD/ED only), so that one is resolved by whichever Admin step
// already runs just before the close.
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
// the walk goes card by card. Submits the one form posting to `action`
// (its card must already be unlocked), filling `fields` first, and waits
// for the real page reload.
async function submitStepForm(page, stageNumber, action, fields, buttonName, scope = '') {
  await openStage(page, stageNumber);
  const form = page.locator(`${scope} form[action="${action}"]`.trim());
  for (const [name, value] of Object.entries(fields || {})) {
    await form.locator(`[name="${name}"]`).fill(value);
  }
  await Promise.all([page.waitForEvent('load'), form.getByRole('button', { name: buttonName }).click()]);
  await expect(page.locator('.alert')).toBeVisible();
}

async function resolveExportBenefitSchemes(page, orderId) {
  // Owner decision (2026-10-26): eligibility is Step 12b (#financials-12b).
  await page.goto(`/orders/${orderId}#financials-12b`);
  await openCardAndAncestors(page, '#financials-12b');
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


// Owner request (2026-10-27): Step 9a picks the freight forwarder from the
// Logistics Partners directory (manage_logistics_partners — Admin/MD/ED).
async function addLogisticsPartner(page, name) {
  await page.goto('/logistics-partners/create');
  await page.locator('input[name="partner_name"]').fill(name);
  await page.locator('select[name="service_type"]').selectOption('freight_forwarder');
  await page.locator('form[action="/logistics-partners"] button[type="submit"]').click();
  await expect(page.locator('.alert')).toContainText('added to the logistics partners directory');
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

test.describe.serial('Deep per-role pipeline workflows', () => {
  let orderId;
  let clientEmail;
  const CLIENT_PASSWORD = 'ClientPortalTest2026';

  test('one CIF order, each stage done by the role whose permission actually covers it', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());
    // Every stage now waits on an Admin document approval before its gate.
    test.setTimeout(360_000);

    await login(page, 'admin');

    await test.step('Admin creates the client and a CIF order', async () => {
      await page.goto('/clients/create');
      await page.locator('input[name="company_legal_name"]').fill(`Role Workflow Buyer ${UNIQUE}`);
      await page.locator('textarea[name="billing_address"]').fill('1 Role Test Street, Test City, Test Country');
      clientEmail = `role-workflow-buyer-${UNIQUE}@nexacrest.test`;
      await page.locator('input[name="email"]').fill(clientEmail);
      await page.getByRole('button', { name: 'Create Client' }).click();
      await expect(page).toHaveURL(/\/clients\/\d+$/);
      const clientId = Number(page.url().match(/\/clients\/(\d+)$/)[1]);

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
      orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);
    });

    await test.step('Admin assigns Export Executive and Logistics Executive to the order up front — both need it concurrently later', async () => {
      await addAssigneeTo(page, orderId, 'exportExecutive');
      await addAssigneeTo(page, orderId, 'logisticsExecutive');
    });

    await test.step('Admin adds the supplier to the Suppliers directory (manage_suppliers — Export Executive does not hold it)', async () => {
      await addSupplierInDirectory(page, `Role Workflow Quarry ${UNIQUE}`, ['Quarry Owner']);
      // Step 9a picks the freight forwarder from the Logistics Partners directory.
      await addLogisticsPartner(page, `Role Workflow Forwarder ${UNIQUE}`);
      await page.goto(`/orders/${orderId}`);
    });

    await test.step('Admin — Stage 1 (QT generate + self-review-approve, needs approve_documents)', async () => {
      await generateDocument(page, orderId, 'QT', 'generated');
      await adminAssignSelfAndApprove(page, 'QT');
    });

    await test.step('Admin — Stage 2 Buyer PO (manage_orders)', async () => {
      await generateDocument(page, orderId, 'BUYERPO', 'generated');
      await adminAssignSelfAndApprove(page, 'BUYERPO');

      await openStage(page, 2);
      const uploadForm = page.locator(`form[action="/orders/${orderId}/buyer-po/documents"]`);
      await uploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
      await uploadForm.getByRole('button', { name: 'Attach Buyer PO Copy' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(page, 2);
      const poForm = page.locator(`form[action="/orders/${orderId}/buyer-po"]`);
      await poForm.locator('input[name="buyers_po_ref"]').fill(`PO-ROLE-${UNIQUE}`);
      await poForm.getByRole('button', { name: 'Confirm Buyer PO Received' }).click();
      await expect(page.locator('.alert')).toBeVisible();
    });

    await test.step('Admin — Stage 3 PI generate + approve (Accounts Executive holds neither generate_documents nor approve_documents)', async () => {
      // 5c stays locked until the client's PI-stage form is applied — this
      // client won't use it, so staff skip it (reason required).
      await openStage(page, 3);
      const skip = page.locator('details.pi-form-skip').first();
      await skip.locator('summary').click();
      await skip.locator('input[name="skip_reason"]').fill('Role-workflow client — details unchanged from the Quotation');
      await skip.getByRole('button', { name: /Skip PI Form/ }).click();
      await expect(page.locator('.alert')).toContainText('PI-stage form skipped');

      await generateDocument(page, orderId, 'PI', 'generated');
      await adminAssignSelfAndApprove(page, 'PI');
    });

    await logout(page);

    await test.step('Accounts Executive — Stage 3 advance payment record + clear (manage_payments)', async () => {
      await login(page, 'accountsExecutive');
      await page.goto(`/orders/${orderId}`);
      await openStage(page, 3);
      const recordForm = page.locator(`form[action="/orders/${orderId}/payment/advance"]`);
      await recordForm.locator('input[name="advance_amount"]').fill('1800.00');
      await recordForm.locator('input[name="advance_exchange_rate"]').fill('90.25');
      await recordForm.getByRole('button', { name: 'Record Advance Remittance' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(page, 3);
      const clearForm = page.locator(`form[action="/orders/${orderId}/payment/advance/clear"]`);
      await clearForm.getByRole('button', { name: 'Mark Advance Cleared' }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
    });

    await test.step('Client-portal login is now provisioned — reset its password for scripted login later', async () => {
      resetClientPassword(clientEmail, CLIENT_PASSWORD);
    });

    await logout(page);

    await test.step('Export Executive — Stage 4 OC generate + acknowledgement (manage_orders)', async () => {
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}`);
      await generateDocument(page, orderId, 'OC', 'generated');
      await approveAsAdmin(page, orderId, 'OC', 'exportExecutive');

      // 6b: the buyer's action link; 6c: their acknowledgement.
      await submitStepForm(page, 4, `/orders/${orderId}/buyer-action-link`, {}, /(Generate|Regenerate) Buyer Action Link/, '#step-6b-buyer-action-link');

      await openStage(page, 4);
      const ackForm = page.locator(`form[action="/orders/${orderId}/oc-acknowledgment"]`);
      await ackForm
        .locator('textarea[name="acknowledged_note"]')
        .fill('Buyer confirmed by email reply, quoted verbatim in our records.');
      await ackForm.getByRole('button', { name: /Record Buyer's (Email )?Acknowledgement/ }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
    });

    await test.step('Export Executive — Stage 5 Supplier PO: pick supplier, terms, generate, sign (manage_orders)', async () => {
      await openStage(page, 5);
      const spoForm = page.locator('form#supplier-po-form');
      const supplierValue = await spoForm
        .locator('select[name="supplier_id"] option', { hasText: `Role Workflow Quarry ${UNIQUE}` })
        .first()
        .getAttribute('value');
      await spoForm.locator('select[name="supplier_id"]').selectOption(supplierValue);
      await spoForm.locator('input[name="quantity"]').fill('100');
      await spoForm.locator('input[name="unit_price_inr"]').fill('450.00');
      await spoForm.getByRole('button', { name: 'Save Supplier PO Terms' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await generateDocument(page, orderId, 'SUPPO', 'generated');
      await approveAsAdmin(page, orderId, 'SUPPO', 'exportExecutive');

      await openStage(page, 5);
      const ackUploadForm = page.locator(`form[action="/orders/${orderId}/supplier-po/documents"]`);
      await ackUploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
      await ackUploadForm.getByRole('button', { name: 'Attach Supplier PO Acknowledgment' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(page, 5);
      const signedForm = page.locator(`form[action="/orders/${orderId}/supplier-po/signed"]`);
      await signedForm.getByRole('button', { name: /Confirm Supplier Signed/ }).click();
      await expect(page.locator('.alert')).toBeVisible();

      // Owner report (2026-10-27): confirming the supplier signed (7d) does
      // not tick Step 8 or open Group C — Step 8 is done only once production
      // status / estimated shipment is actually entered.
      await expect(page.locator('#step-group-stage-production > summary .step-card-icon.done')).toHaveCount(0);
      await expect(page.locator('#phase-shipment')).not.toHaveAttribute('open', '');
      await openStage(page, 5);
      const productionForm = page.locator(`form[action="/orders/${orderId}/production-status"]`);
      await productionForm.locator('input[name="production_status_text"]').fill('Cutting and finishing in progress');
      await Promise.all([page.waitForEvent('load'), productionForm.getByRole('button', { name: 'Update' }).click()]);
      await expect(page.locator('.alert')).toContainText('Production status updated');
      await expect(page.locator('#step-group-stage-production > summary .step-card-icon.done')).toHaveCount(1);
    });

    await logout(page);

    await test.step('Logistics Executive — Stage 6 freight terms/payment/clear (manage_shipping, not manage_payments)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);
      await openStage(page, 6);
      const termsForm = page.locator(`form[action="/orders/${orderId}/freight-terms"]`);
      await termsForm.locator('input[name="confirmed_freight_rate"]').fill('950');
      await termsForm.locator('input[name="insurance_amount"]').fill('120');
      await termsForm.locator('select[name="freight_forwarder_partner_id"]').selectOption({ label: `Role Workflow Forwarder ${UNIQUE} (Freight Forwarder)` });
      await termsForm.getByRole('button', { name: 'Save Freight Terms' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await generateDocument(page, orderId, 'FDN', 'generated');
      await approveAsAdmin(page, orderId, 'FDN', 'logisticsExecutive');

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

    await logout(page);

    await test.step('Accounts Executive — Step 10a supplier balance paid before packing (manage_supplier_payments)', async () => {
      await login(page, 'accountsExecutive');
      await page.goto(`/orders/${orderId}`);
      await submitStepForm(page, 7, `/orders/${orderId}/supplier-po/balance-paid`, { amount_paid_inr: '45000' }, 'Mark Balance Paid');
      await expect(page.locator('.alert')).toContainText('balance payment recorded');
    });

    await logout(page);

    await test.step('Logistics Executive — Stage 7 packing, packing list, shipping, BLI, BL confirm (manage_shipping)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);
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
      await approveAsAdmin(page, orderId, 'PL', 'logisticsExecutive');

      await openStage(page, 7);
      const shippingForm = page.locator(`form[action="/orders/${orderId}/shipping"]`);
      await shippingForm.locator('input[name="shipping_line"]').fill('Role Workflow Shipping Line');
      await shippingForm.locator('input[name="vessel_name"]').fill('MV Role Tester');
      await shippingForm.locator('input[name="voyage_number"]').fill('RT-2026-01');
      await shippingForm.locator('input[name="container_count"]').fill('1');
      await shippingForm.locator('input[name="container_no"]').fill('ROLU1234567');
      await shippingForm.getByRole('button', { name: 'Save Shipping Details' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await generateDocument(page, orderId, 'BLI', 'generated');
      await approveAsAdmin(page, orderId, 'BLI', 'logisticsExecutive');

      await openStage(page, 7);
      const blForm = page.locator(`form[action="/orders/${orderId}/bl-issued"]`);
      await blForm.locator('input[name="bl_number"]').fill(`ROLE-BL-${UNIQUE}`);
      await blForm.getByRole('button', { name: /Confirm BL Issued/ }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
    });

    await logout(page);

    await test.step('Export Executive — Stage 8 generate the Commercial Invoice (generate_documents; Accounts Executive does not hold it)', async () => {
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}`);
      await generateDocument(page, orderId, 'CI', 'generated');
      await approveAsAdmin(page, orderId, 'CI', 'exportExecutive');
      // 11b: the scanned BL copy goes to the buyer before the balance (manage_shipping).
      await submitStepForm(page, 8, `/orders/${orderId}/scanned-bl-sent`, {}, /Mark Scanned BL Copy Sent/);
    });

    await logout(page);

    await test.step('Accounts Executive — Stage 8 balance payment record + clear (manage_payments)', async () => {
      await login(page, 'accountsExecutive');
      await page.goto(`/orders/${orderId}`);
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

    await logout(page);

    await test.step('Logistics Executive — Stage 9 generate COOPREP (generate_documents)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);
      await generateDocument(page, orderId, 'COOPREP', 'generated');
    });

    await logout(page);

    await test.step('Admin — Stage 9 review + approve COOPREP (approve_documents, Logistics cannot do this part)', async () => {
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}`);
      await adminAssignSelfAndApprove(page, 'COOPREP');

      // Export Benefit Scheme Eligibility lives in the Order Financials
      // card (manage_order_financials — Admin/MD/ED only), so it's
      // resolved here rather than by Logistics Executive, who can't see
      // that card at all.
      await resolveExportBenefitSchemes(page, orderId);
    });

    await logout(page);

    await test.step('Logistics Executive — Stage 9 close the order (close_orders)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);

      // Closure now blocks while any Compliance Checklist task is still
      // unresolved — bulk-resolve it here (close_orders also governs
      // this card, so Logistics Executive can do it themselves).
      await resolveComplianceChecklistIfNeeded(page, orderId);

      // 13b–13e: original BLs received, endorsement details saved, printed, originals endorsed.(manage_shipping).
      await submitStepForm(page, 9, `/orders/${orderId}/bl-originals-received`, {}, /Record Original BLs Received/);
      await submitStepForm(page, 9, `/orders/${orderId}/bl-endorsement`, { bl_number: `ROLE-BL-${UNIQUE}` }, /Save BL Endorsement Details/);
      await submitStepForm(page, 9, `/orders/${orderId}/bl-endorsement/generate`, {}, /Generate \/ Print BL Endorsement/);
      await submitStepForm(page, 9, `/orders/${orderId}/bl-endorsed`, {}, /Mark Original BLs Endorsed/);

      await openStage(page, 9);
      const closeForm = page.locator(`form[action="/orders/${orderId}/close"]`);
      await closeForm.locator('input[name="courier_tracking_number"]').fill(`ROLE-COURIER-${UNIQUE}`);
      await closeForm.getByRole('button', { name: /Close Order/ }).click();
      // External QA report TI-10: closeOrder() is now phase 1 of the
      // two-phase closure (Phase 1d) — it moves the order to
      // 'shipped_docs_sent', not 'complete' directly, so the flash is
      // no longer "Order closed".
      await expect(page.locator('.alert')).toContainText('Documents sent to buyer');

      // Phase 2 of closure, same role/permission (close_orders) as the close above.
      await openStage(page, 9);
      const settleForm = page.locator(`form[action="/orders/${orderId}/mark-fully-settled"]`);
      await settleForm.getByRole('button', { name: 'Mark Fully Settled' }).click();
      await expect(page.locator('.alert')).toContainText('Order fully settled and closed');
    });

    await test.step('Order is genuinely complete — every role did only its own real work', async () => {
      await expect(page.locator('.badge-complete')).toHaveText('complete');
      await expect(page.locator('body')).toContainText('Complete — all 9 stages resolved');
    });
  });

  test('Viewer/Auditor reads the real order and reports, but cannot mutate it', async ({ page }) => {
    await login(page, 'viewerAuditor');

    await test.step('view_orders reaches the real completed order', async () => {
      const res = await page.goto(`/orders/${orderId}`);
      expect(res.status()).toBe(200);
      await expect(page.locator('.badge-complete')).toHaveText('complete');
    });

    await test.step('view_reports and view_audit_log reach real data, not an error page', async () => {
      const reports = await page.goto('/reports');
      expect(reports.status()).toBe(200);
      const audit = await page.goto('/audit-log');
      expect(audit.status()).toBe(200);
    });

    await test.step('no close_orders — direct mutation is refused, not just hidden', async () => {
      const res = await page.request.post(`/orders/${orderId}/close`, {
        form: { courier_tracking_number: 'SHOULD-NOT-WORK' },
      });
      expect(res.status()).toBe(403);
    });
  });

  test('CA sees only the view-only CA module — ca_module_manage routes stay refused', async ({ page }) => {
    await login(page, 'ca');

    await test.step('ca_module_view reaches the CA dashboard and reports', async () => {
      const index = await page.goto('/ca');
      expect(index.status()).toBe(200);
      const reports = await page.goto('/ca/reports');
      expect(reports.status()).toBe(200);
    });

    await test.step('fy-locks needs ca_module_manage, which CA does not hold, even as a GET', async () => {
      const res = await page.request.get('/ca/fy-locks');
      expect(res.status()).toBe(403);
    });

    await test.step('no inr_actual_edit — a CA write route is refused', async () => {
      const res = await page.request.post('/ca/export-benefits', { form: { amount: '100' } });
      expect(res.status()).toBe(403);
    });
  });

  test('Client portal: the real buyer logs in after their advance cleared and posts a message', async ({
    page,
  }) => {
    await loginClient(page, clientEmail, CLIENT_PASSWORD);
    await expect(page).not.toHaveURL(/\/client\/login/);

    const res = await page.goto(`/client/orders/${orderId}`);
    expect(res.status()).toBe(200);
    await expect(page.locator('h1')).toContainText('Order');

    const commentForm = page.locator('.chat-post-form');
    await commentForm.locator('textarea[name="body"]').fill('Thanks for the update — looking forward to delivery.');
    await commentForm.getByRole('button', { name: 'Send' }).click();
    await expect(page.locator('body')).toContainText('Thanks for the update');
  });
});
