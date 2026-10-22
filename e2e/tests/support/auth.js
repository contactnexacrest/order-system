'use strict';

// Seeded admin account on both stacks' dev databases. The seed data ships
// this account with a placeholder password and force_password_change=1 —
// for a scripted suite that's reset to a known value directly in each
// dev DB instead (see README.md), rather than scripting the forced
// first-login password-change flow.
const ADMIN_EMAIL = 'gulmohar.sontakke@nexacrestinternational.com';
const ADMIN_PASSWORD = 'PlaywrightTest#2026';

async function login(page) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(ADMIN_EMAIL);
  await page.getByLabel('Password').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

module.exports = { login, ADMIN_EMAIL, ADMIN_PASSWORD };
