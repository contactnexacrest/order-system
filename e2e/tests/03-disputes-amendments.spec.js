'use strict';

const path = require('path');
const { test, expect } = require('@playwright/test');
const { login, logout, loginClient, ROLES } = require('./support/auth');
const { resetClientPassword } = require('./support/db');

/**
 * Disputes and Amendments: two order-lifecycle side-flows with zero
 * prior E2E coverage. Built from the real route gates in
 * public_html/index.php and the real controller/service logic, not the
 * TEST_PLAN's one-line summaries:
 *
 *   DISPUTES — 'manage_disputes' (raise, update status, attach evidence,
 *     toggle the client-facing button) is "Not auto-inherited from
 *     manage_orders — only Admin/MD/ED and Super Admin get it by
 *     default" (seed.sql's own comment). 'respond_to_disputes' is a
 *     separate, narrower grant — Export Executive holds it, nobody else
 *     among the 5 operational roles does — "kept separate from
 *     manage_disputes so sales roles can answer without also managing
 *     dispute status or the button." The client-portal dispute form is
 *     itself gated per-order on orders.dispute_button_visible_to_client
 *     (default 0), both in the view AND re-checked server-side in
 *     ClientPortalController::raiseDispute — staff must explicitly turn
 *     it on for that order before the client can use it at all.
 *
 *   AMENDMENTS — creating one needs 'manage_orders' (Export Executive).
 *     MD-approving needs 'approve_documents' (Admin/MD/ED tier only —
 *     same gap as the document-review flow in 02-role-workflows.spec.js).
 *     AmendmentService::approveByMd() also has a maker-checker rule in
 *     code: whoever filed an amendment cannot also MD-approve it —
 *     UNLESS they hold 'manage_permissions' (MakerCheckerGuard.php's own
 *     comment: "that tier can already grant itself any approval role
 *     through the permission system, so enforcing separation on them is
 *     not a real control"). Every admin-tier role gets manage_permissions
 *     via seed.sql's full CROSS JOIN grant, and no seeded role holds
 *     approve_documents without it — so under the real seeded roles this
 *     guard can never actually fire; it was verified here, not assumed
 *     (an initial draft asserted the opposite and failed against the real
 *     app). What's actually testable and tested: the exemption working as
 *     designed (Admin self-approves their own amendment through to
 *     'active'), and the real, enforceable boundary — Export Executive
 *     holds manage_orders but not approve_documents, so they can file an
 *     amendment but are refused (403) approving ANY amendment, their own
 *     or otherwise.
 *
 * One minimal CIF order is used throughout — just enough pipeline
 * (QT approve, Buyer PO, advance clear) to get a client-portal login
 * provisioned, since the Disputes workflow specifically needs the real
 * client logged into their own portal, not a staff account standing in
 * for them.
 */

const DUMMY_FILE = path.join(__dirname, 'fixtures', 'dummy-signed-copy.pdf');
const UNIQUE = Date.now();
const CLIENT_PASSWORD = 'DisputeAmendTest2026';

async function openStage(page, stageNumber) {
  await page.locator(`.process-step[data-stage="${stageNumber}"]`).click();
}

function docForm(page, orderId, typeCode) {
  return page.locator(
    `form[action="/orders/${orderId}/documents/generate"]:has(input[name="document_type"][value="${typeCode}"])`
  );
}

// Each stage gate waits for its own document to be approved, so the Buyer
// PO (Stage 2) and PI (Stage 3) are generated from their own stage cards.
const DOC_GENERATE_STAGE = { BUYERPO: 2, PI: 3 };

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

async function adminAssignSelfAndApprove(page, typeCode, reviewerName) {
  await openDocumentReviewCard(page);
  let panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  const reviewerSelect = panel.locator('select[name="reviewer_ids[]"]');
  const selfOptionValue = await panel.locator('option', { hasText: reviewerName }).first().getAttribute('value');
  await reviewerSelect.selectOption(selfOptionValue);
  await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
  await expect(page.locator('.alert')).toBeVisible();

  await openDocumentReviewCard(page);
  panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  await panel.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.locator('.alert')).toBeVisible();
}

test.describe.serial('Disputes and Amendments — order lifecycle side-flows', () => {
  let orderId;
  let clientEmail;

  test('setup: minimal order reaches advance-cleared so a client-portal login exists', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page, 'admin');

    await page.goto('/clients/create');
    await page.locator('input[name="company_legal_name"]').fill(`Dispute Amend Buyer ${UNIQUE}`);
    await page.locator('textarea[name="billing_address"]').fill('1 Dispute Test Street, Test City, Test Country');
    clientEmail = `dispute-amend-buyer-${UNIQUE}@nexacrest.test`;
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

    // QA Round 5, R5-24: the Export Executive works this order's disputes
    // and amendments below, and every page of an order now needs the same
    // assignment as the order page itself — so assign them, as staff would.
    const assigneeForm = page.locator(`form[action="/orders/${orderId}/assignees"]`);
    const exportExecValue = await assigneeForm.locator('option', { hasText: ROLES.exportExecutive.name }).first().getAttribute('value');
    await assigneeForm.locator('select[name="user_id"]').selectOption(exportExecValue);
    await assigneeForm.getByRole('button', { name: 'Add' }).click();
    await expect(page.locator('.alert')).toContainText('added as an assignee');

    await generateDocument(page, orderId, 'QT', 'generated');
    await adminAssignSelfAndApprove(page, 'QT', 'Gulmohar Sontakke');
    await generateDocument(page, orderId, 'BUYERPO', 'generated');
    await adminAssignSelfAndApprove(page, 'BUYERPO', 'Gulmohar Sontakke');

    await openStage(page, 2);
    const uploadForm = page.locator(`form[action="/orders/${orderId}/buyer-po/documents"]`);
    await uploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
    await uploadForm.getByRole('button', { name: 'Attach Buyer PO Copy' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 2);
    const poForm = page.locator(`form[action="/orders/${orderId}/buyer-po"]`);
    await poForm.locator('input[name="buyers_po_ref"]').fill(`PO-DA-${UNIQUE}`);
    await poForm.getByRole('button', { name: 'Confirm Buyer PO Received' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    // 5c stays locked until the client's PI-stage form is applied — skip it.
    await openStage(page, 3);
    const piSkip = page.locator('details.pi-form-skip').first();
    await piSkip.locator('summary').click();
    await piSkip.locator('input[name="skip_reason"]').fill('Test client — details unchanged from the Quotation');
    await piSkip.getByRole('button', { name: /Skip PI Form/ }).click();
    await expect(page.locator('.alert')).toContainText('PI-stage form skipped');
    await generateDocument(page, orderId, 'PI', 'generated');
    await adminAssignSelfAndApprove(page, 'PI', 'Gulmohar Sontakke');

    await openStage(page, 3);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/advance"]`);
    await recordForm.locator('input[name="advance_amount"]').fill('1800.00');
    await recordForm.locator('input[name="advance_exchange_rate"]').fill('90.25');
    await recordForm.locator('input[name="advance_bank_reference"]').fill('E2E-UTR-ADVANCE-0001');
    await recordForm.getByRole('button', { name: 'Record Advance Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 3);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/advance/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Advance Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');

    resetClientPassword(clientEmail, CLIENT_PASSWORD);
  });

  test('Disputes: client raises one, Export Executive replies, Admin resolves, a non-respond role is refused', async ({
    page,
  }) => {
    page.on('dialog', (dialog) => dialog.accept());

    await test.step('Admin turns on the client-facing dispute button for this order (manage_disputes)', async () => {
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}`);
      // The toggle lives in the order page's own "Disputes" step-card
      // (<details class="step-card" id="disputes">) — collapsed by
      // default unless it's the order's current step, so open it first
      // or the checkbox is in the DOM but not visible/clickable.
      const disputesCard = page
        .locator('details.step-card')
        .filter({ has: page.locator('summary', { hasText: 'Disputes' }) })
        .first();
      // It sits in the After-Sales & Records section, collapsed unless the
      // order's current step is in it — open the section first.
      const afterSales = page.locator('#phase-aftersales');
      if (!(await afterSales.evaluate((el) => el.open))) {
        await afterSales.locator(':scope > summary').click();
      }
      if (!(await disputesCard.evaluate((el) => el.open))) {
        await disputesCard.locator('summary').click();
      }
      const toggle = page.locator('input[name="dispute_button_visible_to_client"]');
      await toggle.check();
      await expect(page.locator('.alert')).toBeVisible();
      await expect(page.locator('input[name="dispute_button_visible_to_client"]')).toBeChecked();
    });

    await logout(page);

    let disputeReplyFormAction;

    await test.step('The real client raises a dispute from their own portal', async () => {
      await loginClient(page, clientEmail, CLIENT_PASSWORD);
      await page.goto(`/client/orders/${orderId}`);
      const disputeForm = page.locator('form[action$="/disputes"]');
      await disputeForm.locator('textarea[name="description"]').fill('The crates arrived with visible damage to two units.');
      await disputeForm.getByRole('button', { name: 'Raise a Dispute' }).click();
      await expect(page.locator('body')).toContainText(/dispute has been logged/i);
    });

    await test.step('Export Executive (respond_to_disputes) replies to the real dispute', async () => {
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}/disputes`);
      // Unscoped `page.locator('details').first()` matches the sidebar
      // nav's own <details class="nav-group"> accordions (every staff
      // page's layout) before it ever reaches the dispute log — scope to
      // the "Dispute Log" section specifically.
      const disputeLog = page.locator('.section', { has: page.getByRole('heading', { name: 'Dispute Log' }) });
      const entry = disputeLog.locator('details').first();
      await entry.locator('summary').click();
      const replyForm = entry.locator('form[action$="/replies"]');
      disputeReplyFormAction = await replyForm.getAttribute('action');
      await replyForm.locator('textarea[name="body"]').fill('Apologies for the damage — a replacement shipment is being arranged.');
      await replyForm.getByRole('button', { name: 'Post Reply' }).click();
      await expect(page.locator('body')).toContainText('replacement shipment is being arranged');
    });

    await test.step('Accounts Executive (no respond_to_disputes) is refused the same reply route', async () => {
      await logout(page);
      await login(page, 'accountsExecutive');
      const res = await page.request.post(disputeReplyFormAction, { form: { body: 'Should not work' } });
      expect(res.status()).toBe(403);
    });

    await test.step('Admin resolves the dispute (manage_disputes)', async () => {
      await logout(page);
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}/disputes`);
      const disputeLog = page.locator('.section', { has: page.getByRole('heading', { name: 'Dispute Log' }) });
      const entry = disputeLog.locator('details').first();
      await entry.locator('summary').click();
      const statusForm = entry.locator('form[action$="/status"]');
      await statusForm.locator('select[name="status"]').selectOption({ label: 'Resolved' });
      await statusForm.locator('input[name="resolution_notes"]').fill('Replacement shipped and accepted by the buyer.');
      await statusForm.getByRole('button', { name: 'Update' }).click();
      await expect(entry.locator('summary')).toContainText('Resolved');
    });
  });

  test('Amendments: maker-checker exempts manage_permissions holders by design; non-admin-tier is refused outright', async ({
    page,
  }) => {
    page.on('dialog', (dialog) => dialog.accept());

    // MakerCheckerGuard::selfApprovalAllowed() (src/Services/
    // MakerCheckerGuard.php) deliberately EXEMPTS anyone holding
    // 'manage_permissions' from the self-approval rule — "that tier can
    // already grant itself any approval role through the permission
    // system, so enforcing separation on them is not a real control."
    // Every admin-tier role (Admin/Managing Director/Executive Director)
    // holds manage_permissions via seed.sql's full CROSS JOIN grant, so
    // under the real seeded roles this guard can never actually block
    // anyone — there is no seeded role that holds 'approve_documents'
    // (required to MD-approve at all) without also holding
    // 'manage_permissions'. So the real, testable behavior is the
    // exemption working as designed, not a block: confirm Admin can file
    // and MD-approve their own amendment through to completion.
    await test.step('Admin files an amendment and MD-approves it themselves — manage_permissions exempts them from the self-approval guard by design', async () => {
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}/amendments`);
      const createForm = page.locator('form[action$="/amendments"]');
      await createForm.locator('textarea[name="reason"]').fill('Admin self-filed amendment — self-approval exemption.');
      await createForm.locator('select[name="requested_by"]').selectOption('importer');
      await createForm.locator('input[name="amended_advance_pct"]').fill('25');
      await createForm.getByRole('button', { name: 'Create Amendment Request' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      // Scoped by the reason text, not row order/position — a second
      // pending amendment (Export Executive's, filed in the next step)
      // will land in the same table, and created_at-DESC ordering isn't
      // safe to rely on across two creates that can land in the same
      // second.
      const row = page.locator('tr', { hasText: 'Admin self-filed amendment' });
      const approveForm = row.locator('form[action$="/md-approve"]');
      await approveForm.getByRole('button', { name: 'MD Approve' }).click();
      await expect(page.locator('.alert')).toContainText('Amendment MD-approved');
    });

    let approveFormAction;

    await test.step('Export Executive (manage_orders) files a real amendment', async () => {
      await logout(page);
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}/amendments`);
      const createForm = page.locator('form[action$="/amendments"]');
      await createForm.locator('textarea[name="reason"]').fill('Buyer requested a revised balance trigger in writing.');
      await createForm.locator('select[name="requested_by"]').selectOption('importer');
      await createForm.locator('select[name="amended_balance_trigger_option"]').selectOption('B_AGAINST_BL');
      await createForm.getByRole('button', { name: 'Create Amendment Request' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      const pendingRow = page.locator('tr', { hasText: 'Buyer requested a revised balance trigger' });
      const approveForm = pendingRow.locator('form[action$="/md-approve"]');
      approveFormAction = await approveForm.getAttribute('action');

      // Export Executive holds manage_orders, not approve_documents —
      // the MD Approve button should not even be reachable for them.
      const res = await page.request.post(approveFormAction);
      expect(res.status()).toBe(403);
    });

    await test.step('A different admin-tier account (Executive Director) approves, generates, and activates it', async () => {
      await logout(page);
      await login(page, 'executiveDirector');
      await page.goto(`/orders/${orderId}/amendments`);
      const approveForm = page.locator(`form[action="${approveFormAction}"]`);
      await approveForm.getByRole('button', { name: 'MD Approve' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      const generateForm = page.locator('form[action$="/generate-document"]').first();
      await generateForm.getByRole('button', { name: 'Generate Agreement Document' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      const signedCopyForm = page.locator('form[action$="/signed-copy"]').first();
      await signedCopyForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
      await signedCopyForm.getByRole('button', { name: 'Upload Signed Copy & Activate' }).click();
      await expect(page.locator('body')).toContainText('Active — payment terms updated.');
    });

    await test.step('The real audit trail shows both the refused self-approval attempt and the real approval', async () => {
      await page.goto('/audit-log');
      await expect(page.locator('body')).toContainText('AMENDMENT_MD_APPROVED');
    });
  });
});
