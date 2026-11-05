'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * QA fix batch 1 (5 Nov 2026), in a real browser on each stack, with the
 * sitewide app.js both stacks load:
 *
 * - UI-1: "Why is it different?" is a drop-down whose name contains
 *   "reason"; the 10-character reason rule used to refuse every choice, so
 *   a payment that differs from the amount due could never be saved. A
 *   typed reason still needs its minimum length.
 * - UI-3: the bank-reference pattern is now valid in current browsers
 *   (they compile pattern with the "v" flag), so "!!!!" is refused.
 */
test.describe('QA batch 1 — browser checks', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/');
    await page.evaluate(() => {
      const form = document.createElement('form');
      form.id = 'qa-form';
      form.method = 'post';
      form.innerHTML = '<label>Why is it different? <select name="difference_reason_choice" required><option value="">Choose…</option><option value="3">Bank charges deducted</option></select></label>'
        + '<label>Reason <textarea name="reason" placeholder="e.g. bank charges"></textarea></label>'
        + '<label>Bank ref <input type="text" name="bank_reference" pattern="[A-Za-z0-9][A-Za-z0-9 .\\/_#:\\(\\)\\-]*" placeholder="UTR"></label>'
        + '<button type="submit">Save</button>';
      form.addEventListener('submit', (e) => { e.preventDefault(); window.qaSubmitted = true; });
      document.querySelector('main').appendChild(form);
    });
  });

  test('UI-1: a reason picked from the list is accepted; a short typed reason is not', async ({ page }) => {
    const form = page.locator('#qa-form');
    await form.locator('select[name="difference_reason_choice"]').selectOption('3');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await page.evaluate(() => window.qaSubmitted === true)).toBe(true);
    await expect(form.locator('.form-field-error')).toHaveCount(0);

    await page.evaluate(() => { window.qaSubmitted = false; });
    await form.locator('textarea[name="reason"]').fill('short');
    await form.getByRole('button', { name: 'Save' }).click();
    expect(await page.evaluate(() => window.qaSubmitted === true)).toBe(false);
    await expect(form.locator('.form-field-error')).toContainText('Reason must be at least 10 characters (5 so far).');
  });

  test('UI-3: the bank-reference pattern works in this browser', async ({ page }) => {
    const ref = page.locator('#qa-form input[name="bank_reference"]');
    await ref.fill('!!!!');
    expect(await ref.evaluate((el) => el.validity.patternMismatch)).toBe(true);
    await ref.fill('UTR/123 (SBI)');
    expect(await ref.evaluate((el) => el.validity.valid)).toBe(true);
  });
});
