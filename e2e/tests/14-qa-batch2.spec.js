'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * QA fix batch 2 (5 Nov 2026), in a real browser on each stack, with the
 * sitewide app.js both stacks load (UI-8: cross-field rules on the new
 * division forms are checked before the round trip):
 *
 * - data-not-with: a GST rate is for an HSN prefix or a category, never both;
 *   data-required-when="category_id=": one of the two is needed.
 * - data-required-when="switch_bl=1": the switch BL number once the box is ticked.
 * - the import supplier's country cannot be India (pattern + message).
 */
test.describe('QA batch 2 — browser checks', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/');
    await page.evaluate(() => {
      const form = document.createElement('form');
      form.id = 'qa2-form';
      form.method = 'post';
      form.innerHTML = '<label>HSN starts with<input type="text" name="hsn_prefix" maxlength="8" pattern="[0-9]{2,8}" placeholder="e.g. 6802" data-not-with="category_id" data-not-with-message="Give the HSN prefix or the category, not both." data-required-when="category_id=" data-required-when-message="Give the HSN prefix, or choose a category."></label>'
        + '<label>or Category<select name="category_id"><option value="">—</option><option value="7">Natural Stone</option></select></label>'
        + '<label><input type="checkbox" name="switch_bl" value="1"> Switch BL</label>'
        + '<label>Switch BL number<input type="text" name="switch_bl_no" placeholder="e.g. X-S" data-required-when="switch_bl=1" data-required-when-message="Enter the switch BL number."></label>'
        + '<label>Country *<input type="text" name="supplier_country" required placeholder="e.g. China" pattern="(?!\\s*[Ii][Nn][Dd][Ii][Aa]\\s*$).*" data-pattern-message="An import comes from a supplier abroad: the country cannot be India."></label>'
        + '<button type="submit">Save</button>';
      form.addEventListener('submit', (e) => { e.preventDefault(); window.qa2Submitted = true; });
      document.querySelector('main').appendChild(form);
    });
  });

  const submitted = (page) => page.evaluate(() => window.qa2Submitted === true);

  test('UI-8: HSN or category, not both, and one of them is needed', async ({ page }) => {
    const form = page.locator('#qa2-form');
    await form.locator('input[name="supplier_country"]').fill('China');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await submitted(page)).toBe(false);
    await expect(form.locator('.form-field-error')).toContainText('Give the HSN prefix, or choose a category.');

    await form.locator('input[name="hsn_prefix"]').fill('6802');
    await form.locator('select[name="category_id"]').selectOption('7');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await submitted(page)).toBe(false);
    await expect(form.locator('.form-field-error')).toContainText('Give the HSN prefix or the category, not both.');

    await form.locator('select[name="category_id"]').selectOption('');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await submitted(page)).toBe(true);
  });

  test('UI-8: the switch BL number once the box is ticked; India is not an import country', async ({ page }) => {
    const form = page.locator('#qa2-form');
    await form.locator('input[name="hsn_prefix"]').fill('6802');
    await form.locator('input[name="switch_bl"]').check();
    await form.locator('input[name="supplier_country"]').fill('China');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await submitted(page)).toBe(false);
    await expect(form.locator('label:has(input[name="switch_bl_no"]) .form-field-error')).toContainText('Enter the switch BL number.');

    await form.locator('input[name="switch_bl_no"]').fill('MSCU1234567-S');
    await form.locator('input[name="supplier_country"]').fill(' india ');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await submitted(page)).toBe(false);
    await expect(form.locator('label:has(input[name="supplier_country"]) .form-field-error')).toContainText('An import comes from a supplier abroad: the country cannot be India.');

    await form.locator('input[name="supplier_country"]').fill('Indonesia');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await submitted(page)).toBe(true);
  });
});
