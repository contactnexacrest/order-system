'use strict';

// Seeded/created staff accounts on both stacks' dev databases, one per
// role. The real seeded admin ships with a placeholder password and
// force_password_change=1 — for a scripted suite that's reset to a known
// value directly in each dev DB instead (see README.md), rather than
// scripting the forced first-login password-change flow. The other 5
// roles had no seeded user at all — one was created per role directly in
// each dev DB, same password, purely for this suite.
const PASSWORD = 'PlaywrightTest#2026';

// 'admin' is historical — it's actually the seeded Managing Director
// account (role_id 2), not the distinct 'Admin' role (role_id 1).
// adminRole/executiveDirector below are the two separate role accounts
// that account's name never covered — Admin and Managing Director and
// Executive Director all get every permission via seed.sql's CROSS JOIN,
// so their *behavior* is identical, but each is still its own literal
// role and login, tested as such rather than assumed from the others.
const ROLES = {
  admin: { email: 'gulmohar.sontakke@nexacrestinternational.com', name: 'Gulmohar Sontakke' },
  adminRole: { email: 'ui-test-admin@nexacrest.test', name: 'UI Test Admin' },
  executiveDirector: { email: 'arti.sontakke@nexacrestinternational.com', name: 'Arti Sontakke' },
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

async function logout(page) {
  await page.getByRole('button', { name: 'Log out' }).click();
}

async function loginClient(page, email, password) {
  await page.goto('/client/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

module.exports = { login, logout, loginClient, ROLES, PASSWORD };
