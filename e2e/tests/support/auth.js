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
  admin: { email: 'md@email.com', name: 'Managing Director' },
  adminRole: { email: 'ui-test-admin@nexacrest.test', name: 'UI Test Admin' },
  executiveDirector: { email: 'ed@email.com', name: 'Executive Director' },
  exportExecutive: { email: 'ui-test-export-exec@nexacrest.test', name: 'UI Test Export Exec' },
  accountsExecutive: { email: 'ui-test-accounts-exec@nexacrest.test', name: 'UI Test Accounts Exec' },
  logisticsExecutive: { email: 'ui-test-logistics-exec@nexacrest.test', name: 'UI Test Logistics Exec' },
  viewerAuditor: { email: 'ui-test-viewer@nexacrest.test', name: 'UI Test Viewer Auditor' },
  ca: { email: 'ui-test-ca@nexacrest.test', name: 'UI Test CA' },
  procurementManager: { email: 'ui-test-procurement-mgr@nexacrest.test', name: 'UI Test Procurement Manager' },
};

/**
 * D-265: after the password every staff member and buyer gets a 6-digit code by
 * e-mail. The dev servers run with APP_ENV=local, which shows the code on the code
 * page; this reads it and submits it, the way a person types it from the e-mail.
 */
async function enterLoginCode(page, codePath) {
  await page.waitForLoadState('domcontentloaded');
  if (new URL(page.url()).pathname !== codePath) return;
  const code = ((await page.locator('.alert-dev strong').first().innerText()) || '').trim();
  await page.locator('input[name="code"]').fill(code);
  await page.getByRole('button', { name: 'Verify' }).click();
  await page.waitForURL((url) => url.pathname !== codePath);
}

async function login(page, role = 'admin') {
  const account = ROLES[role];
  if (!account) {
    throw new Error(`Unknown role "${role}" — known roles: ${Object.keys(ROLES).join(', ')}`);
  }
  await loginAs(page, account.email, PASSWORD);
}

/** Signs a staff e-mail in: password, then the e-mailed code (D-265). */
async function loginAs(page, email, password = PASSWORD) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => url.pathname !== '/login');
  await enterLoginCode(page, '/2fa');
}

async function logout(page) {
  await page.getByRole('button', { name: 'Log out' }).click();
}

async function loginClient(page, email, password) {
  await page.goto('/client/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => url.pathname !== '/client/login');
  await enterLoginCode(page, '/client/login/code');
  // Final QA run: wait until the portal page has replaced the login page, so a check made right after
  // (e.g. the phone-width overflow check) never measures a page that is still navigating.
  await page.waitForURL((url) => !/\/client\/login/.test(url.pathname));
  await page.waitForLoadState('load');
}

module.exports = { login, loginAs, enterLoginCode, logout, loginClient, ROLES, PASSWORD };
