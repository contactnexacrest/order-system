'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { runSql } = require('./support/db');

/**
 * Q3 crawl follow-up (6 Nov 2026). The Q3 crawl once logged "An invalid form
 * control with name='expense_pct' is not focusable" on the order page: the
 * Travel and Inspection cost forms sit in hidden panels of Step 12a's
 * "+ Add a cost" picker. A person can only submit the panel the picker shows,
 * so this checks, in a real browser on each stack, that:
 *  - only the picked panel is visible;
 *  - the Travel form submitted empty is stopped in the browser with an inline
 *    message beside the field, nothing is posted, and the browser never
 *    complains about a required field it cannot focus.
 */
test('order page: "+ Add a cost" shows only the picked form, and the Travel form is checked in the browser', async ({ page }) => {
  const orderId = Number(runSql("SELECT id FROM orders WHERE status = 'active' AND division_id = 1 ORDER BY id LIMIT 1", 'pick an order'));
  expect(orderId).toBeGreaterThan(0);
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  let posted = false;
  page.on('request', (r) => { if (r.method() === 'POST' && /travel-expenses/.test(r.url())) posted = true; });

  await login(page, 'admin');
  await page.goto(`/orders/${orderId}`);
  const box = page.locator('details.fin-add-cost');
  await box.evaluate((d) => { d.open = true; const card = d.closest('details:not(.fin-add-cost)'); if (card) card.open = true; let p = d.parentElement; while (p) { if (p.tagName === 'DETAILS') p.open = true; p = p.parentElement; } });

  const picker = box.locator('select[data-fin-cost-type]');
  await picker.selectOption('other');
  await expect(box.locator('[data-fin-cost-form="other"]')).toBeVisible();
  await expect(box.locator('[data-fin-cost-form="travel"]')).toBeHidden();

  await picker.selectOption('travel');
  const travel = box.locator('[data-fin-cost-form="travel"]');
  await expect(travel).toBeVisible();
  await expect(box.locator('[data-fin-cost-form="other"]')).toBeHidden();
  await travel.locator('select[name="expense_pct"]').selectOption('');
  await travel.getByRole('button', { name: 'Save' }).click();
  await page.waitForTimeout(300);

  expect(posted).toBe(false);
  await expect(travel.locator('.form-field-error').first()).toBeVisible();
  expect(consoleErrors.filter((t) => /not focusable/.test(t))).toEqual([]);
});
