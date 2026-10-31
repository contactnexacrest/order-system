'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');

/**
 * Deep CA / Accounting module coverage. The role named after this
 * module — 'CA / Chartered Accountant' — only ever gets 'ca_module_view'
 * + 'inr_actual_view' (seed.sql): view-only, by the role's own seed.sql
 * description ("No order-management access"). The role that can
 * actually DO anything here is Accounts Executive, which holds
 * 'ca_module_view', 'ca_module_manage', 'inr_actual_edit', AND
 * 'ca_fy_lock_override' — confirmed by reading seed.sql's role_permissions
 * grants, not assumed from the module's name.
 *
 * Real write routes and their actual gates (public_html/index.php):
 *   POST /ca/export-benefits                 — inr_actual_edit
 *   POST /ca/export-benefits/{id}/mark-received — inr_actual_edit
 *   POST /ca/fy-locks/lock, /unlock           — ca_module_manage
 *   POST /ca/bank-statement/upload            — inr_actual_edit
 * CA holds none of these — ca_module_view only gets them the read-only
 * pages (GET /ca, /ca/reports, /ca/expenses, /ca/tds-summary,
 * /ca/bank-statement, /ca/reconciliation, /ca/export-benefits); even
 * GET /ca/fy-locks itself is refused since that needs ca_module_manage,
 * a GET route with no CSRF step to hide behind the permission check.
 *
 * The FY lock/unlock test restores whatever state it found a financial
 * year in before this test ran — toggling it and toggling it straight
 * back — since other specs in this suite write real CA-relevant data
 * (payment records, exchange rates) against the current financial year,
 * and leaving it locked would break them on a later run.
 */

test.describe('CA / Accounting module — Accounts Executive writes, CA confirmed view-only', () => {
  test('Accounts Executive records a real export benefit claim and marks it received', async ({ page }) => {
    await login(page, 'accountsExecutive');
    await page.goto('/ca/export-benefits');

    const claimedAt = new Date().toISOString().slice(0, 10);
    const form = page.locator('form[action="/ca/export-benefits"]');
    const schemeValue = await form.locator('select[name="scheme_name"] option').nth(1).getAttribute('value');
    expect(schemeValue, 'expected at least one export-benefit scheme option seeded').toBeTruthy();
    await form.locator('select[name="scheme_name"]').selectOption(schemeValue);
    await form.locator('input[name="claimed_amount"]').fill('15000.00');
    await form.locator('input[name="claimed_at"]').fill(claimedAt);
    await form.locator('input[name="notes"]').fill(`Playwright CA module test claim ${Date.now()}`);
    await form.getByRole('button', { name: 'Record Claim' }).click();
    // If today's financial year happens to already be locked, Accounts
    // Executive's ca_fy_lock_override permission pushes the write through
    // anyway and an extra "lock overridden... logged for audit" banner
    // stacks above the success one — both are expected, so assert on the
    // success class specifically rather than generic .alert visibility.
    await expect(page.locator('.alert-success')).toBeVisible();

    // Mark it received — a second real write against the same row. The
    // "Notes" field isn't rendered as its own table column, so the row
    // is found by the claims list's own real ordering instead (CaExport
    // BenefitRepository::ORDER BY claimed_at DESC, id DESC — the claim
    // just created is the first data row, not by matching text that was
    // never displayed).
    const claimsTable = page.locator('.section', { has: page.getByRole('heading', { name: 'Claims' }) });
    const row = claimsTable.locator('table.list tr').nth(1); // row 0 is the header
    const markReceivedForm = row.locator('form[action$="/mark-received"]');
    await markReceivedForm.locator('input[name="received_amount"]').fill('15000.00');
    await markReceivedForm.locator('input[name="received_at"]').fill(claimedAt);
    await markReceivedForm.locator('input[name="received_reference"]').fill('E2E-ESCRIP-0001');
    await markReceivedForm.getByRole('button', { name: 'Mark Received' }).click();
    await expect(page.locator('.alert-success')).toBeVisible();

    // Confirm it actually landed — the same (still-first) row now shows
    // a received amount, not just that the button click didn't error.
    const updatedRow = claimsTable.locator('table.list tr').nth(1);
    await expect(updatedRow).toContainText('15,000.00');
  });

  test('Accounts Executive locks and unlocks a real financial year, restoring its original state', async ({
    page,
  }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page, 'accountsExecutive');
    await page.goto('/ca/fy-locks');

    // Scoped to the "Financial Years" section specifically — the page
    // also has a "Lock / Unlock History" table and a "Recent Overrides"
    // table, either of which can independently contain a row mentioning
    // the same year (an unscoped `tr` filter resolves to multiple
    // elements and Playwright's strict mode rejects it).
    const yearsSection = page.locator('.section', { has: page.getByRole('heading', { name: 'Financial Years' }) });
    const firstRow = yearsSection.locator('table.list tr').nth(1); // row 0 is the header
    await expect(firstRow).toBeVisible();
    const financialYear = (await firstRow.locator('td').first().textContent()).trim();
    expect(financialYear, 'expected at least one financial year with CA activity to already exist').toBeTruthy();
    const startedLocked = (await firstRow.textContent()).includes('Locked');

    async function toggle() {
      const row = yearsSection.locator('tr', { hasText: financialYear });
      if ((await row.textContent()).includes('Locked')) {
        const unlockForm = row.locator('form[action="/ca/fy-locks/unlock"]');
        await unlockForm.locator('input[name="unlock_reason"]').fill('Playwright CA module test — reopen then re-lock');
        await unlockForm.getByRole('button', { name: 'Unlock' }).click();
      } else {
        const lockForm = row.locator('form[action="/ca/fy-locks/lock"]');
        await lockForm.getByRole('button', { name: 'Lock' }).click();
      }
      await expect(page.locator('.alert')).toBeVisible();
    }

    await toggle(); // flip
    const flippedRow = yearsSection.locator('tr', { hasText: financialYear });
    await expect(flippedRow).toContainText(startedLocked ? 'Open' : 'Locked');

    await toggle(); // flip back — leave exactly as found
    const restoredRow = yearsSection.locator('tr', { hasText: financialYear });
    await expect(restoredRow).toContainText(startedLocked ? 'Locked' : 'Open');
  });

  test('CA is refused every CA write route, including a GET that needs ca_module_manage', async ({ page }) => {
    await login(page, 'ca');

    await test.step('read-only pages work (ca_module_view)', async () => {
      const index = await page.goto('/ca');
      expect(index.status()).toBe(200);
      const expenses = await page.goto('/ca/expenses');
      expect(expenses.status()).toBe(200);
      const bankStatement = await page.goto('/ca/bank-statement');
      expect(bankStatement.status()).toBe(200);
    });

    await test.step('fy-locks needs ca_module_manage, refused even as a GET', async () => {
      const res = await page.request.get('/ca/fy-locks');
      expect(res.status()).toBe(403);
    });

    await test.step('bank statement upload needs inr_actual_edit, refused', async () => {
      const res = await page.request.post('/ca/bank-statement/upload');
      expect(res.status()).toBe(403);
    });

    await test.step('export benefit claim needs inr_actual_edit, refused', async () => {
      const res = await page.request.post('/ca/export-benefits', {
        form: { scheme_name: 'x', claimed_amount: '1', claimed_at: '2026-01-01' },
      });
      expect(res.status()).toBe(403);
    });
  });
});
