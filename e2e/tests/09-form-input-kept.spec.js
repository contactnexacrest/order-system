'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * Owner request (2026-10-25): "while submitting any form, if any error
 * occurs, whatever we filled earlier should not disappear ... can we add UI
 * validations before submitting the form?" Both behaviours live sitewide in
 * app.js (no per-form wiring), so a couple of representative forms prove
 * them on a real page in a real browser:
 *
 * - Pre-submit checks the browser's own validation misses: a required field
 *   holding only spaces, and a numeric field typed with a comma. The submit
 *   is stopped (no navigation) and the message shows next to the field.
 * - A server-side rejection (a duplicate email-template key — something no
 *   client-side check can know) used to come back as an empty form; the
 *   typed values are now put back, with a notice.
 */
test.describe('Form input is kept and checked before submit', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('a numeric field typed with a comma is stopped before submitting', async ({ page }) => {
    // The Edit Order form, whose required fields are already filled — on an
    // empty form the browser's own required-field check (which runs first)
    // would stop the submit before this one is reached.
    await page.goto('/orders');
    const orderHref = await page.locator('a[href^="/orders/"]').evaluateAll(
      (links) => links.map((a) => a.getAttribute('href')).find((h) => /^\/orders\/\d+$/.test(h))
    );
    await page.goto(`${orderHref}/edit`);
    await page.locator('input[name="estimated_gross_weight_kg"]').fill('27,000');
    // An order already past its early stages asks for an override reason
    // (required) — fill it so the comma check is what stops the submit.
    const reason = page.locator(`form[action="${orderHref}/edit"] [name="reason"]`);
    if (await reason.count()) {
      await reason.first().fill('Correcting the estimated weight');
    }
    await page.locator(`form[action="${orderHref}/edit"] button[type="submit"]`).first().click();

    await expect(page).toHaveURL(new RegExp(`${orderHref}/edit$`));
    await expect(page.locator('input[name="estimated_gross_weight_kg"] + .form-field-error'))
      .toHaveText('Enter a number — digits and a decimal point only (no commas, spaces or units).');
    await expect(page.locator('input[name="estimated_gross_weight_kg"]')).toBeFocused();

    await page.locator('input[name="estimated_gross_weight_kg"]').fill('27000');
    await expect(page.locator('input[name="estimated_gross_weight_kg"] + .form-field-error')).toHaveCount(0);
  });

  test('a required field holding only spaces is stopped before submitting', async ({ page }) => {
    await page.goto('/email-templates/create');
    await page.locator('input[name="template_key"]').fill('whitespace_check');
    await page.locator('input[name="subject"]').fill('   ');
    await page.locator('textarea[name="body"]').fill('Body');
    await page.locator('form[action="/email-templates"] button[type="submit"]').click();

    await expect(page).toHaveURL(/\/email-templates\/create$/);
    await expect(page.locator('input[name="subject"] + .form-field-error')).toHaveText('This field is required.');
  });

  test('typed values come back after the server rejects the form', async ({ page }) => {
    await page.goto('/email-templates/create');
    await page.locator('input[name="template_key"]').fill('send_qt'); // already exists in the seed
    await page.locator('input[name="subject"]').fill('My carefully written subject');
    await page.locator('textarea[name="body"]').fill('A long body I do not want to type again.');
    await page.locator('form[action="/email-templates"] button[type="submit"]').click();

    await expect(page).toHaveURL(/\/email-templates\/create$/);
    await expect(page.locator('.alert-error')).toContainText('already exists');
    await expect(page.locator('input[name="template_key"]')).toHaveValue('send_qt');
    await expect(page.locator('input[name="subject"]')).toHaveValue('My carefully written subject');
    await expect(page.locator('textarea[name="body"]')).toHaveValue('A long body I do not want to type again.');
    await expect(page.locator('.form-draft-notice')).toContainText('Your entries have been kept');

    // Kept only for the one failed round-trip: a fresh visit starts empty.
    await page.goto('/email-templates/create');
    await expect(page.locator('input[name="subject"]')).toHaveValue('');
  });

  // Owner request (2026-10-27): "Account Number" and "Re-enter Account
  // Number" must be checked on the page — else what is the purpose of it.
  test('a re-entered account number that does not match stops the submit', async ({ page }) => {
    await page.goto('/suppliers/create');
    await page.locator('input[name="supplier_legal_name"]').fill(`E2E Bank Match ${Date.now()}`);
    await page.locator('input[name="types[]"]').first().check();
    const confirm = page.locator('input[name="bank_account_number_confirm"]');
    await page.locator('input[name="bank_account_number"]').fill('123456789012');
    await confirm.fill('123456789021');
    await confirm.blur();
    // Said straight away, before Save is pressed.
    await expect(confirm.locator('xpath=following-sibling::*[contains(@class,"form-field-error")]')).toContainText('do not match');
    await page.getByRole('button', { name: 'Add Supplier' }).click();
    await expect(page).toHaveURL(/\/suppliers\/create$/);
    await expect(confirm).toHaveClass(/field-invalid/);
    // Correcting it clears the message. The holder, bank and IFSC are
    // then asked for, still before anything is sent.
    await confirm.fill('1234 5678 9012');
    await expect(confirm).not.toHaveClass(/field-invalid/);
    await page.getByRole('button', { name: 'Add Supplier' }).click();
    await expect(page).toHaveURL(/\/suppliers\/create$/);
    await expect(page.locator('input[name="bank_account_name"]')).toHaveClass(/field-invalid/);
    await page.locator('input[name="bank_account_name"]').fill('E2E Exports Pvt Ltd');
    await page.locator('input[name="bank_name"]').fill('State Bank of India');
    await page.locator('input[name="bank_ifsc"]').fill('SBIN123');
    await page.getByRole('button', { name: 'Add Supplier' }).click();
    await expect(page.locator('input[name="bank_ifsc"] ~ .form-field-error')).toContainText('valid 11-character IFSC');
    await page.locator('input[name="bank_ifsc"]').fill('SBIN0001234');
    await page.getByRole('button', { name: 'Add Supplier' }).click();
    await expect(page.locator('.alert')).toContainText('added to the suppliers directory');
  });

  test('a confirm password that does not match stops the submit', async ({ page }) => {
    await page.goto('/account');
    const form = page.locator('form[action="/account/password"]');
    await form.locator('[name="current_password"]').fill('whatever-it-is');
    await form.locator('[name="new_password"]').fill('NewPassword#2026');
    await form.locator('[name="confirm_password"]').fill('NewPassword#2027');
    await form.getByRole('button', { name: 'Update Password' }).click();
    await expect(page).toHaveURL(/\/account$/);
    await expect(form.locator('.form-field-error')).toContainText('passwords do not match');
  });

  // QA Round 5, R5-27 / R5-30: these forms used to send empty input to the
  // server (or, for a reference document, save it empty and wipe it).
  test('an empty dropdown option is stopped before submitting', async ({ page }) => {
    await page.goto('/admin/dropdown-options');
    const addForm = page.locator('form[action$="/create"]').first();
    await addForm.locator('button[type="submit"]').click();

    await expect(page).toHaveURL(/\/admin\/dropdown-options$/);
    // The browser's own required-field wording ("Please fill out this field.").
    await expect(addForm.locator('input[name="option_value"] + .form-field-error')).not.toBeEmpty();
  });

  test('a reference document cleared of its text is stopped before saving', async ({ page }) => {
    await page.goto('/reference-docs/STAGEGATE/edit');
    await page.locator('textarea[name="content"]').fill('   ');
    await page.locator('form[action="/reference-docs/STAGEGATE"] button[type="submit"]').click();

    await expect(page).toHaveURL(/\/reference-docs\/STAGEGATE\/edit$/);
    await expect(page.locator('textarea[name="content"] + .form-field-error')).toHaveText('This field is required.');
  });

  test('an order message needs text or a file before posting', async ({ page }) => {
    await page.goto('/orders');
    const orderHref = await page.locator('a[href^="/orders/"]').evaluateAll(
      (links) => links.map((a) => a.getAttribute('href')).find((h) => /^\/orders\/\d+$/.test(h))
    );
    await page.goto(orderHref);
    const form = page.locator('form.chat-post-form');
    await form.locator('button[type="submit"]').evaluate((button) => button.click());

    await expect(page).toHaveURL(new RegExp(`${orderHref}$`));
    await expect(form.locator('textarea[name="body"] + .form-field-error')).toHaveText('Write a message or attach a file before posting.');
  });
});
