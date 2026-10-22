'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

test('staff can log in and reach the dashboard', async ({ page }) => {
  await login(page);

  await expect(page).not.toHaveURL(/\/login/);
  await expect(page.locator('body')).toContainText('Dashboard');
});
