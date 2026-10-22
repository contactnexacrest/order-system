'use strict';

// Seeded/created staff accounts on both stacks' dev databases, one per
// role. The real seeded admin ships with a placeholder password and
// force_password_change=1 — for a scripted suite that's reset to a known
// value directly in each dev DB instead (see README.md), rather than
// scripting the forced first-login password-change flow. The other 5
// roles had no seeded user at all — one was created per role directly in
// each dev DB, same password, purely for this suite.
const PASSWORD = 'PlaywrightTest#2026';

const ROLES = {
  admin: { email: 'gulmohar.sontakke@nexacrestinternational.com', name: 'Gulmohar Sontakke' },
  exportExecutive: { email: 'ui-test-export-exec@nexacrest.test', name: 'UI Test Export Exec' },
  accountsExecutive: { email: 'ui-test-accounts-exec@nexacrest.test', name: 'UI Test Accounts Exec' },
  logisticsExecutive: { email: 'ui-test-logistics-exec@nexacrest.test', name: 'UI Test Logistics Exec' },
  viewerAuditor: { email: 'ui-test-viewer@nexacrest.test', name: 'UI Test Viewer Auditor' },
  ca: { email: 'ui-test-ca@nexacrest.test', name: 'UI Test CA' },
};

async function login(page, role = 'admin') {
  const account = ROLES[role];
  if (!account) {
    throw new Error(`Unknown role "${role}" — known roles: ${Object.keys(ROLES).join(', ')}`);
  }
  await page.goto('/login');
  await page.getByLabel('Email').fill(account.email);
  await page.getByLabel('Password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

module.exports = { login, ROLES, PASSWORD };
