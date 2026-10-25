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
});
