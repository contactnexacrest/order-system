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

/**
 * Order ownership / assignment visibility: a scoped role (Export
 * Executive, Logistics Executive — neither holds view_all_orders) can
 * only open an order it's assigned to. This single shared order is
 * worked by BOTH of them at different points, so whoever's turn it is
 * next must be made the owner first — must be run as Admin (or any
 * Admin/MD/ED holder), the only tier holding assign_order_owner. Caller
 * is responsible for being logged in as such before calling this.
 */
async function assignOrderTo(page, orderId, roleName) {
  await page.goto(`/orders/${orderId}`);
  const form = page.locator(`form[action="/orders/${orderId}/assign"]`);
  const targetName = ROLES[roleName].name;
  const targetValue = await form.locator('option', { hasText: targetName }).first().getAttribute('value');
  await form.locator('select[name="assigned_to"]').selectOption(targetValue);
  await form.getByRole('button', { name: 'Reassign' }).click();
  await expect(page.locator('.alert')).toContainText('reassigned');
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
  await openStage(page, 1);
  let panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  const reviewerSelect = panel.locator('select[name="reviewer_ids[]"]');
  const selfOptionValue = await panel.locator('option', { hasText: adminName }).first().getAttribute('value');
  await reviewerSelect.selectOption(selfOptionValue);
  await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
  await expect(page.locator('.alert')).toBeVisible();

  await openStage(page, 1);
  panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  await panel.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.locator('.alert')).toBeVisible();
}

test.describe.serial('Deep per-role pipeline workflows', () => {
  let orderId;
  let clientEmail;
  const CLIENT_PASSWORD = 'ClientPortalTest2026';

  test('one CIF order, each stage done by the role whose permission actually covers it', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());

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

    await test.step('Admin — Stage 1 (QT generate + self-review-approve, needs approve_documents)', async () => {
      await generateDocument(page, orderId, 'QT', 'generated');
      await adminAssignSelfAndApprove(page, 'QT');
    });

    await test.step('Admin — Stage 2 Buyer PO (manage_orders)', async () => {
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

    await logout(page);

    await test.step('Accounts Executive — Stage 3 advance payment record + clear (manage_payments)', async () => {
      await login(page, 'accountsExecutive');
      await page.goto(`/orders/${orderId}`);
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

    await test.step('Client-portal login is now provisioned — reset its password for scripted login later', async () => {
      resetClientPassword(clientEmail, CLIENT_PASSWORD);
    });

    await logout(page);

    await test.step('Admin reassigns the order to Export Executive, whose turn is next', async () => {
      await login(page, 'admin');
      await assignOrderTo(page, orderId, 'exportExecutive');
      await logout(page);
    });

    await test.step('Export Executive — Stage 4 OC generate + acknowledgement (manage_orders)', async () => {
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}`);
      await generateDocument(page, orderId, 'OC', 'generated');

      await openStage(page, 4);
      const ackForm = page.locator(`form[action="/orders/${orderId}/oc-acknowledgment"]`);
      await ackForm
        .locator('textarea[name="acknowledged_note"]')
        .fill('Buyer confirmed by email reply, quoted verbatim in our records.');
      await ackForm.getByRole('button', { name: /Record Buyer's Acknowledgement/ }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
    });

    await test.step('Export Executive — Stage 5 Supplier PO: add supplier, terms, generate, sign (manage_orders)', async () => {
      await openStage(page, 5);
      const supplierForm = page.locator(`form[action="/orders/${orderId}/suppliers"]`);
      await supplierForm.locator('input[name="supplier_legal_name"]').fill(`Role Workflow Quarry ${UNIQUE}`);
      await supplierForm.getByRole('button', { name: /Add Supplier/i }).click();
      await expect(page.locator('.alert')).toBeVisible();

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

    await logout(page);

    await test.step('Admin reassigns the order to Logistics Executive, whose turn is next', async () => {
      await login(page, 'admin');
      await assignOrderTo(page, orderId, 'logisticsExecutive');
      await logout(page);
    });

    await test.step('Logistics Executive — Stage 6 freight terms/payment/clear (manage_shipping, not manage_payments)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);
      await openStage(page, 6);
      const termsForm = page.locator(`form[action="/orders/${orderId}/freight-terms"]`);
      await termsForm.locator('input[name="confirmed_freight_rate"]').fill('950');
      await termsForm.locator('input[name="insurance_amount"]').fill('120');
      await termsForm.locator('input[name="freight_forwarder_name"]').fill('Role Workflow Forwarder Ltd');
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

    await test.step('Logistics Executive — Stage 7 packing, shipping, BL confirm (manage_shipping)', async () => {
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
      await shippingForm.locator('input[name="shipping_line"]').fill('Role Workflow Shipping Line');
      await shippingForm.locator('input[name="vessel_name"]').fill('MV Role Tester');
      await shippingForm.locator('input[name="voyage_number"]').fill('RT-2026-01');
      await shippingForm.locator('input[name="container_count"]').fill('1');
      await shippingForm.locator('input[name="container_no"]').fill('ROLU1234567');
      await shippingForm.getByRole('button', { name: 'Save Shipping Details' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(page, 7);
      const blForm = page.locator(`form[action="/orders/${orderId}/bl-issued"]`);
      await blForm.locator('input[name="bl_number"]').fill(`ROLE-BL-${UNIQUE}`);
      await blForm.getByRole('button', { name: /Confirm BL Issued/ }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
    });

    await logout(page);

    await test.step('Admin reassigns the order back to Export Executive, whose turn is next', async () => {
      await login(page, 'admin');
      await assignOrderTo(page, orderId, 'exportExecutive');
      await logout(page);
    });

    await test.step('Export Executive — Stage 8 generate the Commercial Invoice (generate_documents; Accounts Executive does not hold it)', async () => {
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}`);
      await generateDocument(page, orderId, 'CI', 'generated');
    });

    await logout(page);

    await test.step('Accounts Executive — Stage 8 balance payment record + clear (manage_payments)', async () => {
      await login(page, 'accountsExecutive');
      await page.goto(`/orders/${orderId}`);
      await openStage(page, 8);
      const recordForm = page.locator(`form[action="/orders/${orderId}/payment/balance"]`);
      await recordForm.getByRole('button', { name: 'Record Balance Remittance' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(page, 8);
      const clearForm = page.locator(`form[action="/orders/${orderId}/payment/balance/clear"]`);
      await clearForm.getByRole('button', { name: 'Mark Balance Cleared' }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
    });

    await logout(page);

    await test.step('Admin reassigns the order back to Logistics Executive, whose turn is next', async () => {
      await login(page, 'admin');
      await assignOrderTo(page, orderId, 'logisticsExecutive');
      await logout(page);
    });

    await test.step('Logistics Executive — Stage 9 generate COOPREP + BLI (generate_documents)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);
      await generateDocument(page, orderId, 'COOPREP', 'generated');
      await generateDocument(page, orderId, 'BLI', 'generated');
    });

    await logout(page);

    await test.step('Admin — Stage 9 review + approve both (approve_documents, Logistics cannot do this part)', async () => {
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}`);
      await adminAssignSelfAndApprove(page, 'COOPREP');
      await adminAssignSelfAndApprove(page, 'BLI');
    });

    await logout(page);

    await test.step('Logistics Executive — Stage 9 close the order (close_orders)', async () => {
      await login(page, 'logisticsExecutive');
      await page.goto(`/orders/${orderId}`);
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
