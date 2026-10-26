'use strict';

const { test, expect } = require('@playwright/test');
const { login, logout, ROLES } = require('./support/auth');

/**
 * Two workflows with zero prior E2E coverage: the Email & Deferred Send
 * System's Level-1/Level-2 approval queue (Spec Section 10), and a
 * representative Admin/Settings CRUD screen — both built from the real
 * routes/controllers/views, not assumed:
 *
 *   - 'approve_email_send' (Level-2 approve/reject/send-now on
 *     /email-log/{id}/...) and 'manage_company_settings' (the Holiday
 *     Calendar CRUD at /holidays) are BOTH only in seed.sql's full
 *     CROSS-JOIN grant (Admin/Managing Director/Executive Director) —
 *     none of the 5 operational roles hold either one. So this file
 *     exercises them as 'adminRole' (the literal Admin-role account,
 *     the one this session had never actually logged into before), and
 *     confirms an operational role is refused both.
 *   - A document's "Send to Buyer" link only renders once it's
 *     status='approved' AND its document_type_category is
 *     'customer_facing' (views/orders/show.php) — QT qualifies, and
 *     'generate_documents' (held by Export Executive and Logistics
 *     Executive, not Accounts Executive) gates the compose/request-send
 *     routes, so Export Executive is the one who requests the send here.
 *   - EmailDispatchService::requestSend() only queues for approval (vs.
 *     sending immediately) when company_settings.mail_approval_queue_
 *     enabled is '1' — seed.sql's own default for that key, so this is
 *     a real reachable path on a freshly-seeded dev DB, not a fabricated
 *     one.
 *   - Router middleware runs in array order and short-circuits on the
 *     first failure (Router::dispatch), and every one of these routes
 *     lists PermissionCheck before CsrfCheck — so a permission-denied
 *     POST without a CSRF token still correctly 403s for the right
 *     reason, the same pattern 01/02's negative checks already rely on.
 */

const UNIQUE = Date.now();

async function openStage(page, stageNumber) {
  await page.locator(`.process-step[data-stage="${stageNumber}"]`).click();
}

function docForm(page, orderId, typeCode) {
  return page.locator(
    `form[action="/orders/${orderId}/documents/generate"]:has(input[name="document_type"][value="${typeCode}"])`
  );
}

async function generateDocument(page, orderId, typeCode, expectedBannerSubstring) {
  await openStage(page, 1);
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
 * Order ownership / assignment visibility: Admin created this order (so
 * it's auto-assigned to Admin as its first assignee), but Export
 * Executive — who doesn't hold view_all_orders — needs to open it
 * directly next. Must be run while still logged in as Admin (or any
 * assign_order_owner holder).
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

test.describe.serial('Email Approvals — the Level-1/Level-2 deferred send queue', () => {
  let orderId;
  let orderReference;
  let emailLogId;

  test('Export Executive requests a buyer send; is refused approving it themselves', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());

    await test.step('Admin creates an order and gets its QT approved (a prerequisite, not the thing under test)', async () => {
      await login(page, 'admin');
      await page.goto('/clients/create');
      await page.locator('input[name="company_legal_name"]').fill(`Email Approval Buyer ${UNIQUE}`);
      await page.locator('textarea[name="billing_address"]').fill('1 Email Test Street, Test City, Test Country');
      await page.locator('input[name="email"]').fill(`email-approval-buyer-${UNIQUE}@nexacrest.test`);
      await page.getByRole('button', { name: 'Create Client' }).click();
      await expect(page).toHaveURL(/\/clients\/\d+$/);
      const clientId = Number(page.url().match(/\/clients\/(\d+)$/)[1]);

      await page.goto('/orders/create');
      await page.locator('select[name="client_id"]').selectOption(String(clientId));
      await page.locator('select[name="incoterm_id"]').selectOption({ label: 'FOB' });
      const usdValue = await page
        .locator('select[name="currency_id"] option', { hasText: 'USD' })
        .first()
        .getAttribute('value');
      await page.locator('select[name="currency_id"]').selectOption(usdValue);
      const presetValue = await page
        .locator('select[name="payment_preset_id"] option', { hasText: 'Standard' })
        .first()
        .getAttribute('value');
      await page.locator('select[name="payment_preset_id"]').selectOption(presetValue);
      // FOB: the Indicative Freight / Insurance fields are hidden (buyer's own).
      await page.locator('input[name="product_description[]"]').first().fill('Granite Memorial Headstone');
      await page.locator('input[name="product_quantity[]"]').first().fill('50');
      await page.locator('input[name="product_unit_price[]"]').first().fill('60.00');
      await page.locator('input[name="product_hs_code[]"]').first().fill('68022310');
      await page.getByRole('button', { name: 'Create Order' }).click();
      await expect(page).toHaveURL(/\/orders\/\d+$/);
      orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);
      orderReference = (await page.locator('h1').first().textContent()).trim();

      await generateDocument(page, orderId, 'QT', 'generated');
      await adminAssignSelfAndApprove(page, 'QT');
    });

    await test.step('Admin assigns Export Executive so they can open it next', async () => {
      await addAssigneeTo(page, orderId, 'exportExecutive');
    });

    await logout(page);

    await test.step("Export Executive opens the approved QT's Send-to-Buyer flow (generate_documents)", async () => {
      await login(page, 'exportExecutive');
      await page.goto(`/orders/${orderId}`);
      // #document-review-approval is a reference card, not tied to any
      // stage stepper dot — it renders collapsed by default, so it
      // needs opening explicitly before reaching the nested QT panel.
      await openDocumentReviewCard(page);
      const panel = documentPanel(page, 'QT');
      await panel.locator('summary').click();
      await panel.getByRole('link', { name: 'Send to Buyer (deferred, 2-level approval)' }).click();
      await expect(page).toHaveURL(new RegExp(`/orders/${orderId}/documents/\\d+/send$`));
    });

    await test.step('Load the exact preview for the send_qt template, then submit for Level-2 approval', async () => {
      await page.locator('select[name="template_key"]').selectOption('send_qt');
      await page.getByRole('button', { name: 'Load Preview' }).click();
      await expect(page.locator('#email-preview-subject')).toBeVisible();

      await page.getByRole('button', { name: 'Submit for Level-2 Approval' }).click();
      await expect(page.locator('.alert')).toContainText('submitted for approval');
      await expect(page).toHaveURL(new RegExp(`/orders/${orderId}$`));
    });

    await test.step('Capture the real email_log id from the page, then confirm Export Executive cannot approve it themselves', async () => {
      const cancelFormAction = await page
        .locator('form[action^="/email-log/"][action$="/cancel"]')
        .first()
        .getAttribute('action');
      emailLogId = Number(cancelFormAction.match(/\/email-log\/(\d+)\/cancel/)[1]);
      expect(emailLogId).toBeGreaterThan(0);

      const res = await page.request.post(`/email-log/${emailLogId}/approve`);
      expect(res.status()).toBe(403);
    });
  });

  test('adminRole (approve_email_send) approves the real pending send from the queue', async ({ page }) => {
    await login(page, 'adminRole');
    await page.goto('/email-approvals');

    const row = page.locator('.section').filter({ hasText: orderReference });
    await expect(row).toBeVisible();
    await expect(row).toContainText('pending approval');

    await row.getByRole('button', { name: 'Approve' }).click();
    await expect(page.locator('.alert')).toContainText('approved');

    // The row now shows as approved (will send once its scheduled time
    // arrives) — confirms the approval actually changed real state, not
    // just that the button click didn't error.
    const orderPage = await page.goto(`/orders/${orderId}`);
    expect(orderPage.status()).toBe(200);
    await openDocumentReviewCard(page);
    const panel = documentPanel(page, 'QT');
    await panel.locator('summary').click();
    await expect(panel).toContainText('Approved — will send once its scheduled time arrives.');
  });
});

test.describe('Admin/Settings CRUD — Holiday Calendar (manage_company_settings)', () => {
  // UNIQUE mapped into a far-future date avoids colliding with the
  // schema's UNIQUE KEY on holiday_date across repeated runs, real
  // seeded holidays, or the other stack's own dev DB.
  const dayOffset = UNIQUE % 300;
  const holidayDate = new Date(Date.UTC(2090, 0, 1) + dayOffset * 86400000).toISOString().slice(0, 10);
  const description = `Playwright Test Holiday ${UNIQUE}`;

  test('Export Executive — no manage_company_settings — is refused creating a holiday', async ({ page }) => {
    await login(page, 'exportExecutive');
    const res = await page.request.post('/holidays', {
      form: { holiday_date: holidayDate, description: 'Should Not Be Created' },
    });
    expect(res.status()).toBe(403);
  });

  test('adminRole creates and removes a real holiday row', async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page, 'adminRole');
    await page.goto('/holidays');

    const createForm = page.locator('form[action="/holidays"]');
    await createForm.locator('input[name="holiday_date"]').fill(holidayDate);
    await createForm.locator('input[name="description"]').fill(description);
    await createForm.getByRole('button', { name: 'Add Holiday' }).click();
    await expect(page.locator('body')).toContainText(description);

    await page
      .locator('form[action$="/update"]')
      .filter({ has: page.locator(`input[value="${description}"]`) })
      .getByRole('button', { name: 'Remove' })
      .click();
    await expect(page.locator('body')).not.toContainText(description);
  });
});
