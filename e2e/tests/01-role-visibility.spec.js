'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * Role/permission UI visibility smoke suite. The nav itself is
 * permission-gated server-side (one config, src/Config/navigation.json /
 * src/config/navigation.json, read by App\Services\Navigation and
 * services/navigation.js) and the role->permission grants are already
 * pinned at the controller level (IdorAndInputValidationTest::
 * testRolePermissionMatrixMatchesTheDocumentedGrantsExactly) — this adds
 * the one thing neither covers: did logging in AS each role, in a real
 * browser, actually render the right nav and refuse the right pages,
 * with no error page anywhere. Per docs/QA/TEST_PLAN.md Section 2's
 * documented grants.
 *
 * Owner decision D-320 (point 26): the left menu has 13 entries (Dashboard,
 * Approvals, My tasks; Orders, Clients, Associates, Products, Procurement &
 * suppliers, Finance & accounts; Reports; Help & SOP; Users & roles,
 * Settings). Each entry links to the first page of its section the person
 * can open; every other page of the section is in the section's menu bar
 * (`.section-nav`) at the top of each of its pages. So an entry is checked
 * by its key (`data-nav`), a section's first page by the entry's href, and
 * a sub-page (Disputes, Audit log) by opening the section and looking in
 * its menu bar. Locators are scoped to `.sidebar-nav` / `.section-nav`, so
 * a dashboard button with the same href never makes them ambiguous.
 */
function menuEntry(page, key) {
  return page.locator(`.sidebar-nav a[data-nav="${key}"]`);
}

function navLink(page, href) {
  return page.locator(`.sidebar-nav a[href="${href}"]`);
}

function barLink(page, href) {
  return page.locator(`.section-nav a[href="${href}"]`);
}

async function fullAccess(page) {
  await expect(navLink(page, '/orders')).toBeAttached();
  await expect(navLink(page, '/reports')).toBeAttached();
  await expect(navLink(page, '/users')).toBeAttached();
  await expect(navLink(page, '/ca')).toBeAttached(); // Finance & accounts opens on CA / Accounting
  await expect(menuEntry(page, 'settings')).toBeAttached();
  await page.goto('/reports');
  await expect(barLink(page, '/audit-log')).toBeAttached();
  await expect(barLink(page, '/cockpit')).toBeAttached();
}

test.describe('Role-based navigation visibility', () => {
  test('Managing Director (the seeded account) sees every module, including Admin/Settings', async ({ page }) => {
    await login(page, 'admin');
    await fullAccess(page);
  });

  // The 'Admin' role is a literal, distinct role (role_id 1). It used to get
  // the same full module set as Managing Director and Executive Director.
  // Owner decision D-379 (15 Nov 2026, report access per role): Admin's
  // report levels are Off except Management (cockpit, queues, audit log)
  // and Find an order — so Admin still has every working module and the
  // Admin/Settings menus, but the Reports page shows only those reports,
  // the money reports are refused by direct URL, and Finance & accounts
  // no longer opens on CA / Accounting (CA reports is Off for Admin).
  test('Admin (the literal role) sees every working module, but only the Management reports (D-379)', async ({ page }) => {
    await login(page, 'adminRole');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/users')).toBeAttached();
    await expect(menuEntry(page, 'finance')).toBeAttached();
    await expect(navLink(page, '/ca')).toHaveCount(0);
    await expect(menuEntry(page, 'sales_targets')).toHaveCount(0);
    await expect(menuEntry(page, 'settings')).toBeAttached();
    await page.goto('/reports');
    await expect(barLink(page, '/audit-log')).toBeAttached();
    await expect(barLink(page, '/cockpit')).toBeAttached();
    await expect(barLink(page, '/reports/freight-cost')).toHaveCount(0);
    await expect(page.locator('main a[href="/reports/aggregate"]')).toHaveCount(0);
    expect((await page.request.get('/reports/aggregate')).status()).toBe(403);
    expect((await page.request.get('/reports/order-profitability')).status()).toBe(403);
    expect((await page.request.get('/ca')).status()).toBe(403);
  });

  test('Executive Director sees every module too (same full grant as Admin/MD)', async ({ page }) => {
    await login(page, 'executiveDirector');
    await fullAccess(page);
  });

  // D-379: Overview gains "Sales targets" after Reports for the MD / ED
  // (Sales targets level All; the Sales Director gets the team view).
  test('the left menu has at most 14 entries, in the decided order', async ({ page }) => {
    await login(page, 'admin');
    const keys = await page.locator('.sidebar-nav a[data-nav]').evaluateAll((els) => els.map((e) => e.dataset.nav));
    expect(keys).toEqual(['dashboard', 'approvals', 'tasks', 'orders', 'clients', 'associates', 'products', 'procurement', 'finance', 'reports', 'sales_targets', 'help', 'users', 'settings']);
    await expect(navLink(page, '/sales-targets')).toBeAttached();
    await expect(page.locator('.sidebar-nav .nav-heading')).toHaveText(['Work', 'Overview', 'Help', 'Admin']);
  });

  test('Export Executive sees order handling but never CA or Admin', async ({ page }) => {
    await login(page, 'exportExecutive');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/clients')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/ca')).toHaveCount(0);
    await expect(menuEntry(page, 'users')).toHaveCount(0);
    await expect(menuEntry(page, 'settings')).toHaveCount(0);
    // Owner decision 2026-10-30 (QA Round 6, Q-R6-3): respond_to_disputes
    // (Export Executive's grant) now opens the Disputes list too, scoped
    // to the orders the user may see.
    await page.goto('/orders');
    await expect(barLink(page, '/disputes')).toBeAttached();

    const res = await page.request.get('/admin/permissions');
    expect(res.status()).toBe(403);
  });

  test('Accounts Executive sees order handling plus CA, but never Admin', async ({ page }) => {
    await login(page, 'accountsExecutive');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/ca')).toBeAttached();
    await expect(menuEntry(page, 'users')).toHaveCount(0);
  });

  // D-379: the Logistics Executive's Freight cost level is Own (and the role
  // holds view_logistics_costs), so Reports now opens on that one report,
  // scoped to the orders they work on. Every other report stays refused.
  test('Logistics Executive sees order handling and only their own Freight cost report — never CA or Admin', async ({ page }) => {
    await login(page, 'logisticsExecutive');
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/reports')).toBeAttached();
    await expect(navLink(page, '/ca')).toHaveCount(0);
    await expect(menuEntry(page, 'users')).toHaveCount(0);
    await expect(menuEntry(page, 'settings')).toHaveCount(0);
    await page.goto('/reports');
    await expect(page.getByText('Showing your own business')).toBeVisible();
    await expect(barLink(page, '/reports/freight-cost')).toBeAttached();
    await expect(barLink(page, '/audit-log')).toHaveCount(0);
    await expect(barLink(page, '/cockpit')).toHaveCount(0);
    expect((await page.request.get('/reports/aggregate')).status()).toBe(403);
    expect((await page.request.get('/reports/disputes')).status()).toBe(403);
    await page.goto('/orders');
    await expect(barLink(page, '/disputes')).toHaveCount(0);
  });

  test('Viewer/Auditor is read-only: sees Orders/Clients/Reports/Audit Log, but can never create or edit', async ({ page }) => {
    await login(page, 'viewerAuditor');
    await expect(navLink(page, '/reports')).toBeAttached();
    // External QA report U-18: /orders and /clients each also accept
    // their own narrower view_orders/view_clients permission — Viewer/
    // Auditor holds exactly these two, so both entries render.
    await expect(navLink(page, '/orders')).toBeAttached();
    await expect(navLink(page, '/clients')).toBeAttached();
    await page.goto('/reports');
    await expect(barLink(page, '/audit-log')).toBeAttached();
    await page.goto('/clients');
    await expect(barLink(page, '/clients/create')).toHaveCount(0);

    // The real read-only boundary: viewing is allowed, creating is not —
    // /orders/create requires manage_orders, which view_orders does not grant.
    const res = await page.request.get('/orders/create');
    expect(res.status()).toBe(403);
  });

  // Owner decision (2026-10-28, migration 2026-10-28d): the CA can open
  // orders read-only (view_orders) to reach each order's CAFIN box — but
  // still has no order-editing or client access. D-379: Reports now opens
  // for the CA on the reports its own permissions allow (Missing eBRC,
  // Freight cost, CAFIN export, CA reports); the sales reports, which need
  // view_reports, stay refused.
  test('CA sees orders read-only, the CA module and only the finance reports — no order editing or clients', async ({ page }) => {
    await login(page, 'ca');
    await expect(navLink(page, '/ca')).toBeAttached();
    await expect(navLink(page, '/orders')).toHaveCount(1);
    await expect(menuEntry(page, 'clients')).toHaveCount(0);
    await expect(navLink(page, '/reports')).toBeAttached();
    await page.goto('/reports');
    await expect(page.locator('main a[href="/ca/cafin-export"]')).toBeAttached();
    await expect(page.locator('main a[href="/reports/aggregate"]')).toHaveCount(0);
    expect((await page.request.get('/reports/aggregate')).status()).toBe(403);
    expect((await page.request.get('/audit-log')).status()).toBe(403);

    const res = await page.request.get('/orders/create');
    expect(res.status()).toBe(403);
  });

  // Owner decision D-320 (point 26): the order page's action bar.
  test('the order page has an action bar of buttons, and no "Edit Order Details" text link', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/orders');
    const first = page.locator('a[href^="/orders/"]').filter({ hasText: /\S/ }).first();
    const href = await first.getAttribute('href');
    const id = (href.match(/^\/orders\/(\d+)/) || [])[1];
    test.skip(!id, 'no order to open');
    await page.goto(`/orders/${id}`);
    const bar = page.getByTestId('order-action-bar');
    await expect(bar.getByRole('link', { name: 'Edit order' })).toBeVisible();
    await expect(bar.getByRole('link', { name: 'Documents' })).toBeVisible();
    await expect(bar.getByRole('button', { name: 'Duplicate order' })).toBeVisible();
    await expect(page.getByText('Edit Order Details')).toHaveCount(0);
    await page.mouse.wheel(0, 3000);
    await expect(bar).toBeInViewport(); // sticky
  });
});
