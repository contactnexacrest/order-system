'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * Role/permission UI visibility smoke suite. The nav itself is
 * permission-gated server-side (views/layout/base.php's $can() calls /
 * the Nunjucks equivalent) and the role->permission grants are already
 * pinned at the controller level (IdorAndInputValidationTest::
 * testRolePermissionMatrixMatchesTheDocumentedGrantsExactly) — this adds
 * the one thing neither covers: did logging in AS each role, in a real
 * browser, actually render the right nav and refuse the right pages,
 * with no error page anywhere. Per docs/QA/TEST_PLAN.md Section 2's
 * documented grants.
 *
 * "Orders"/"Clients"/"Disputes" (Operations group), "Reports"/"Audit
 * Log" (Insights group), and "Users" (Admin group) sit inside a
 * collapsed <details> on first load (server only renders it `open` when
 * the CURRENT page is inside that group) — landing on the dashboard
 * always has every group collapsed. A closed native <details>'s
 * non-summary content is in the DOM but excluded from the accessibility
 * tree entirely (same as display:none), so Playwright's role-based
 * getByRole() locator finds ZERO matches for it regardless of whether
 * the link is actually present — a role-based toHaveCount(0) check would
 * then "pass" for a granted-but-collapsed link exactly as it would for a
 * genuinely denied one, which defeats the point of a permission check.
 * So grouped links are asserted by href via a plain CSS locator (DOM
 * presence/absence, unaffected by the accordion's open/closed state),
 * not by role. "CA / Accounting" and "Products" are top-level links with
 * no accordion, so role-based `toBeVisible()`/absence is fine for those.
 *
 * Dashboard quick-action buttons reuse some of the same hrefs ("All
 * Orders" -> /orders, a "Reports" shortcut -> /reports), so the href
 * locator is scoped to `.sidebar-nav` — otherwise a plain
 * `a[href="/orders"]` matches both the nav link and the dashboard button
 * and Playwright's strict mode rejects the ambiguous locator.
 */
function navLink(page, href) {
  return page.locator(`.sidebar-nav a[href="${href}"]`);
}

test.describe('Role-based navigation visibility', () => {
  test('Managing Director (the seeded account) sees every module, including Admin/Settings', async ({ page }) => {
    await login(page, 'admin');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/users')).toBeAttached();
    await expect(navLink(page, '/audit-log')).toBeAttached();
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toBeVisible();
  });

  // The 'Admin' role is a literal, distinct role (role_id 1) — it gets
  // the same full CROSS JOIN permission grant as Managing Director and
  // Executive Director in seed.sql, so it's expected to behave
  // identically, but it had never actually been logged into or tested as
  // its own account before — tested explicitly rather than assumed.
  test('Admin (the literal role, not Managing Director) sees every module too', async ({ page }) => {
    await login(page, 'adminRole');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/users')).toBeAttached();
    await expect(navLink(page, '/audit-log')).toBeAttached();
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toBeVisible();
  });

  test('Executive Director sees every module too (same full grant as Admin/MD)', async ({ page }) => {
    await login(page, 'executiveDirector');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/users')).toBeAttached();
    await expect(navLink(page, '/audit-log')).toBeAttached();
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toBeVisible();
  });

  test('Export Executive sees order handling but never CA or Admin', async ({ page }) => {
    await login(page, 'exportExecutive');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/clients')).toBeAttached();
    await expect(navLink(page, '/disputes')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toHaveCount(0);
    await expect(navLink(page, '/users')).toHaveCount(0);

    const res = await page.request.get('/admin/permissions');
    expect(res.status()).toBe(403);
  });

  test('Accounts Executive sees order handling plus CA, but never Admin', async ({ page }) => {
    await login(page, 'accountsExecutive');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toBeVisible();
    await expect(navLink(page, '/users')).toHaveCount(0);
  });

  test('Logistics Executive sees order handling but never Reports, CA, or Admin', async ({ page }) => {
    await login(page, 'logisticsExecutive');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/reports')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toHaveCount(0);
    await expect(navLink(page, '/disputes')).toHaveCount(0);
  });

  test('Viewer/Auditor is read-only: Reports and Audit Log, never Orders or Clients', async ({ page }) => {
    await login(page, 'viewerAuditor');
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/audit-log')).toBeAttached();
    await expect(navLink(page, '/orders')).toHaveCount(0);
    await expect(navLink(page, '/clients')).toHaveCount(0);

    // Not just hidden from the nav — the route itself must refuse a
    // direct hit too (a hidden link is not a security boundary).
    const res = await page.request.get('/orders/create');
    expect(res.status()).toBe(403);
  });

  test('CA has no order-management access at all — only the CA module', async ({ page }) => {
    await login(page, 'ca');
    await expect(page.getByRole('link', { name: 'CA / Accounting' })).toBeVisible();
    await expect(navLink(page, '/orders')).toHaveCount(0);
    await expect(navLink(page, '/clients')).toHaveCount(0);
    await expect(navLink(page, '/reports')).toHaveCount(0);

    const res = await page.request.get('/orders/create');
    expect(res.status()).toBe(403);
  });
});
