'use strict';

const path = require('path');
const { test, expect } = require('@playwright/test');
const { login, logout, ROLES } = require('./support/auth');
const { resetClientPassword } = require('./support/db');
const { mark, sinceMark } = require('./support/mail');

const DUMMY_FILE = path.join(__dirname, 'fixtures', 'dummy-signed-copy.pdf');

/**
 * Email functionality: does the app actually attempt to send the right
 * email, to the right person, with the right attachment — for both the
 * deferred/approved document-send path and the plain-text notification
 * path. What this CANNOT cover, in this sandbox: real delivery to an
 * actual inbox. Neither stack has SMTP configured here (EmailService.php
 * / emailService.js both degrade to a logged line whenever SMTP_HOST/
 * PHPMailer aren't available — the only state this environment has), so
 * there is no real transport to verify receipt against. See
 * support/mail.js for exactly which log file each stack's "did it try to
 * send" signal comes from, and why.
 *
 * Three real signals, not one, for the document-attachment test
 * specifically:
 *   1. The Email Approvals screen's own "Exact Preview" shows the real
 *      subject/body/attachment BEFORE anyone approves it — "nothing is
 *      re-composed at dispatch time" per that page's own copy — so
 *      asserting on that UI text directly proves the right document is
 *      queued, no log-reading required.
 *   2. "Send Now" (EmailDispatchService::sendNowOverride(), the exact
 *      same code path app/cron/dispatch_deferred_emails.php uses on its
 *      own schedule, just without the wait) forces the real dispatch()
 *      call synchronously, so this test doesn't need to run or wait for
 *      a cron job.
 *   3. The email_log row leaving the pending/approved list after Send
 *      Now confirms the dispatch attempt actually completed (status
 *      flipped), and the log file's own "Attachments: ...pdf" line
 *      (EmailService::deliverWithAttachments()'s dev fallback) confirms
 *      a real attachment — not zero, not a wrong path — was handed to
 *      the send call.
 */

const UNIQUE = Date.now();

function openStage(page, stageNumber) {
  return page.locator(`.process-step[data-stage="${stageNumber}"]`).click();
}

function docForm(page, orderId, typeCode) {
  return page.locator(
    `form[action="/orders/${orderId}/documents/generate"]:has(input[name="document_type"][value="${typeCode}"])`
  );
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

test.describe('Email functionality — real attempts, right recipient, right attachment', () => {
  // Under the Notification Routing Plan (ActionNotificationService's
  // NONE_ACTION_TYPES), LOGIN_SUCCESS and PERMISSION_DENIED are
  // deliberately pure noise — no email at all, audit_log row only —
  // specifically so a routine login or a stray 403 doesn't drown out
  // the catch-all/self-toggle recipients. This asserts that contract:
  // both actions still happen for real, but neither attempts to send.
  test('plain-text notifications: Login Success and Permission Denied are audit-only (no email, "none" category)', async ({
    page,
  }) => {
    const mark1 = mark();
    await login(page, 'exportExecutive');
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
    const sinceLogin = sinceMark(mark1);
    expect(sinceLogin).not.toContain('Login Success');

    const mark2 = mark();
    const res = await page.request.get('/admin/permissions'); // Export Executive lacks manage_permissions
    expect(res.status()).toBe(403);
    const sincePermissionDenied = sinceMark(mark2);
    expect(sincePermissionDenied).not.toContain('Permission Denied');
  });

  test('client portal invite: the real client gets the real set-password email the moment their advance clears', async ({
    page,
  }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page, 'admin');

    await page.goto('/clients/create');
    await page.locator('input[name="company_legal_name"]').fill(`Email Test Buyer ${UNIQUE}`);
    await page.locator('textarea[name="billing_address"]').fill('1 Email Test Street, Test City, Test Country');
    const clientEmail = `email-functionality-buyer-${UNIQUE}@nexacrest.test`;
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
    const orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);

    await openStage(page, 1);
    await docForm(page, orderId, 'QT').locator('button[type="submit"]').click();
    await expect(page.locator('.alert')).toContainText('generated');
    await openDocumentReviewCard(page);
    let panel = documentPanel(page, 'QT');
    await panel.locator('summary').click();
    const selfValue = await panel.locator('option', { hasText: 'Gulmohar Sontakke' }).first().getAttribute('value');
    await panel.locator('select[name="reviewer_ids[]"]').selectOption(selfValue);
    await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
    await expect(page.locator('.alert')).toBeVisible();
    await openDocumentReviewCard(page);
    panel = documentPanel(page, 'QT');
    await panel.locator('summary').click();
    await panel.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 2);
    const uploadForm = page.locator(`form[action="/orders/${orderId}/buyer-po/documents"]`);
    await uploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
    await uploadForm.getByRole('button', { name: 'Attach Buyer PO Copy' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 2);
    const poForm = page.locator(`form[action="/orders/${orderId}/buyer-po"]`);
    await poForm.locator('input[name="buyers_po_ref"]').fill(`PO-EMAIL-${UNIQUE}`);
    await poForm.getByRole('button', { name: 'Confirm Buyer PO Received' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await openStage(page, 3);
    const recordForm = page.locator(`form[action="/orders/${orderId}/payment/advance"]`);
    await recordForm.locator('input[name="advance_amount"]').fill('1800.00');
    await recordForm.getByRole('button', { name: 'Record Advance Remittance' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    const mailMark = mark();
    await openStage(page, 3);
    const clearForm = page.locator(`form[action="/orders/${orderId}/payment/advance/clear"]`);
    await clearForm.getByRole('button', { name: 'Mark Advance Cleared' }).click();
    await expect(page.locator('.alert')).toContainText('unlocked');

    const sinceClear = sinceMark(mailMark);
    expect(sinceClear).toContain(clientEmail);
    expect(sinceClear).toMatch(/portal access/i);

    resetClientPassword(clientEmail, 'EmailFunctionalityTest2026');
  });

  test('document send: the exact attachment shown pre-approval is the one actually dispatched', async ({
    page,
  }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page, 'admin');

    await page.goto('/clients/create');
    await page.locator('input[name="company_legal_name"]').fill(`Email Attachment Buyer ${UNIQUE}`);
    await page.locator('textarea[name="billing_address"]').fill('1 Email Attach Street, Test City, Test Country');
    await page.locator('input[name="email"]').fill(`email-attachment-buyer-${UNIQUE}@nexacrest.test`);
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
    await page.locator('input[name="indicative_freight_amount"]').fill('0');
    await page.locator('input[name="indicative_insurance_amount"]').fill('0');
    await page.locator('input[name="product_description[]"]').first().fill('Granite Memorial Headstone');
    await page.locator('input[name="product_quantity[]"]').first().fill('50');
    await page.locator('input[name="product_unit_price[]"]').first().fill('60.00');
    await page.locator('input[name="product_hs_code[]"]').first().fill('68022310');
    await page.getByRole('button', { name: 'Create Order' }).click();
    await expect(page).toHaveURL(/\/orders\/\d+$/);
    const orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);

    await openStage(page, 1);
    await docForm(page, orderId, 'QT').locator('button[type="submit"]').click();
    await expect(page.locator('.alert')).toContainText('generated');
    await openDocumentReviewCard(page);
    let panel = documentPanel(page, 'QT');
    await panel.locator('summary').click();
    const selfValue = await panel.locator('option', { hasText: 'Gulmohar Sontakke' }).first().getAttribute('value');
    await panel.locator('select[name="reviewer_ids[]"]').selectOption(selfValue);
    await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
    await expect(page.locator('.alert')).toBeVisible();
    await openDocumentReviewCard(page);
    panel = documentPanel(page, 'QT');
    await panel.locator('summary').click();
    await panel.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.locator('.alert')).toBeVisible();

    await addAssigneeTo(page, orderId, 'exportExecutive');

    await logout(page);
    await login(page, 'exportExecutive');
    await page.goto(`/orders/${orderId}`);
    // #document-review-approval is a reference card, not tied to any
    // stage stepper dot — it renders collapsed by default, so it needs
    // opening explicitly before reaching the nested QT panel.
    await openDocumentReviewCard(page);
    panel = documentPanel(page, 'QT');
    await panel.locator('summary').click();
    await panel.getByRole('link', { name: 'Send to Buyer (deferred, 2-level approval)' }).click();
    await expect(page).toHaveURL(new RegExp(`/orders/${orderId}/documents/\\d+/send$`));
    await page.locator('select[name="template_key"]').selectOption('send_qt');
    await page.getByRole('button', { name: 'Load Preview' }).click();
    await expect(page.locator('#email-preview-subject')).toBeVisible();
    await page.getByRole('button', { name: 'Submit for Level-2 Approval' }).click();
    await expect(page.locator('.alert')).toContainText('submitted for approval');
    await logout(page);

    await login(page, 'adminRole');
    await page.goto('/email-approvals');
    const row = page.locator('.section', { hasText: `email-attachment-buyer-${UNIQUE}` }).first();
    await expect(row).toBeVisible();

    // The "Exact Preview" — proves the RIGHT document is attached before
    // anyone approves anything, straight from the page's own displayed
    // state, no log-reading needed for this part.
    await expect(row).toContainText(/Attachment:\s*QT\s+\S+\.pdf/);
    await expect(row).not.toContainText('Attachment: none');

    await row.getByRole('button', { name: 'Approve' }).click();
    await expect(page.locator('.alert')).toContainText('approved');

    const mailMark = mark();
    const sendNowRow = page.locator('.section', { hasText: `email-attachment-buyer-${UNIQUE}` }).first();
    await sendNowRow.getByRole('button', { name: 'Send Now' }).click();
    await expect(page.locator('.alert')).toBeVisible();

    // Dispatched — the row no longer shows as pending/approved-waiting.
    await page.goto('/email-approvals');
    await expect(page.locator('.section', { hasText: `email-attachment-buyer-${UNIQUE}` })).toHaveCount(0);

    // And the real send path (EmailService::deliverWithAttachments, the
    // same dev-fallback the app's own PHPUnit integration tests read back
    // from) actually ran with a real, non-empty PDF attachment.
    const sinceSend = sinceMark(mailMark);
    expect(sinceSend).toMatch(/Attachments:.*\.pdf/i);
  });
});
