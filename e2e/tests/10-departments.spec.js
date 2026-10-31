'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * Owner decision D-214 (2026-10-31), phase 1: the menu shows the
 * department a person is logged in as; Admin → Departments shows every
 * department's ladder; "Preview the app as …" shows exactly what that
 * designation sees, and "Exit preview" returns.
 */
test.describe('Departments, Log in as and Preview as', () => {
  test('the menu shows the department the person is logged in as', async ({ page }) => {
    await login(page, 'admin');
    await expect(page.getByTestId('dept-badge')).toContainText('Managing Director');
    await expect(page.getByTestId('dept-badge')).toContainText('Company Management');
  });

  test('Admin previews a designation and exits again', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/admin/departments');
    await expect(page.getByRole('heading', { name: 'Departments & designations' })).toBeVisible();
    await page.locator('.desig', { hasText: 'Logistics Executive' }).getByRole('button', { name: 'Preview the app as this' }).click();
    await expect(page.getByTestId('preview-banner')).toContainText('Previewing the app as Logistics Executive · Logistics');

    const refused = await page.goto('/admin/departments');
    expect(refused.status()).toBe(403);

    await page.goto('/');
    await page.getByTestId('preview-banner').getByRole('button', { name: 'Exit preview' }).click();
    await expect(page).toHaveURL(/\/admin\/departments$/);
    await expect(page.getByTestId('preview-banner')).toHaveCount(0);
  });
});
