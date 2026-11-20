'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { runSql } = require('./support/db');

/**
 * Owner decision D-394 (20 Nov 2026): quick-quote prospects, clicked through in a real browser on
 * each stack, the way the Managing Director would do it:
 *   1. Clients › + New Client: tick "Quick quote" — the billing address stops being required in the
 *      browser and Email / Country of Destination start being required (app.js, data-required-*-ticked);
 *      save with only the three details → the prospect page with its badge, dates and buttons.
 *   2. Clients list: "Show: Prospects" lists it with the gold Prospect badge and its expiry line.
 *   3. "Keep for 60 more days" moves the date.
 *   4. Closed (as the daily job leaves it): Deactivated Clients shows the badge, why, and
 *      "Reactivate (fresh 60 days)"; reactivating gives a fresh date and the message.
 *   5. Orders › Quotation requests: a request from the same e-mail shows "Matches prospect";
 *      "Update this prospect" shows each change old → new and keeps the same client.
 *   6. Settings › Quick-quote prospects: the browser refuses 400 days.
 * The daily job itself (reminder, closing the order) is covered by ProspectD394Test / prospectD394.test.js.
 */
test.describe.serial('Quick-quote prospects — as a person would', () => {
  const SUFFIX = `${Date.now()}`.slice(-7);
  const NAME = `E2E Quick Quote ${SUFFIX} Ltd`;
  const EMAIL = `e2e-prospect-${SUFFIX}@buyer.example`;
  let clientId;

  const sql = (s) => s.replace(/'/g, "''");

  test.afterAll(() => {
    // Nothing is deleted in the app; the test's own rows are only parked out of the way.
    runSql(`UPDATE client_intake_submissions SET status = 'rejected', rejection_reason = 'E2E clean-up' WHERE email = '${EMAIL.toUpperCase()}' AND status = 'pending'`, 'park request');
    if (clientId) runSql(`UPDATE clients SET is_active = 0, is_prospect = 0 WHERE id = ${Number(clientId)}`, 'park client');
  });

  test.beforeEach(async ({ page }) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page, 'admin');
  });

  test('1: + New Client with Quick quote — only the name, e-mail and country', async ({ page }) => {
    await page.goto('/clients/create');
    const line1 = page.locator('input[name="billing_address_line1"]');
    const email = page.locator('form input[name="email"]');
    await expect(line1).toHaveJSProperty('required', true);
    await expect(email).toHaveJSProperty('required', false);
    await page.locator('input[name="is_prospect"]').check();
    await expect(line1).toHaveJSProperty('required', false);
    await expect(email).toHaveJSProperty('required', true);
    await expect(page.locator('input[name="country_of_destination"]')).toHaveJSProperty('required', true);
    await expect(page.locator('.prospect-optional').first()).toBeVisible();

    // The browser stops a prospect without its e-mail, next to the field.
    await page.locator('input[name="company_legal_name"]').fill(NAME);
    await page.locator('input[name="country_of_destination"]').fill('Germany');
    await page.locator('form button[type="submit"]', { hasText: 'Create Client' }).click();
    await expect(page).toHaveURL(/\/clients\/create/);
    await expect(page.locator('.form-field-error').first()).toBeVisible();

    await email.fill(EMAIL);
    await page.locator('form button[type="submit"]', { hasText: 'Create Client' }).click();
    await expect(page).toHaveURL(/\/clients\/\d+$/);
    clientId = Number(page.url().split('/').pop());
    await expect(page.locator('body')).toContainText('Prospect created');
    await expect(page.locator('h1 [data-testid="prospect-badge"]')).toHaveText('Prospect');
    await expect(page.locator('[data-testid="prospect-box"]')).toContainText('Prospect — details not confirmed yet.');
    await expect(page.locator('[data-testid="prospect-box"]')).toContainText('Keep for 60 more days');
    await expect(page.locator('[data-testid="prospect-box"]')).toContainText('E-mail the Public Quotation Form');
  });

  test('2–3: the Clients list filters it; Keep for 60 more days', async ({ page }) => {
    await page.goto('/clients?show=prospects');
    const card = page.locator('.entity-card', { hasText: NAME });
    await expect(card.locator('[data-testid="prospect-badge"]')).toHaveText('Prospect');
    await expect(card).toContainText('Quote expires');
    await expect(page.locator('.filter-chip.active')).toContainText('Prospects');

    runSql(`UPDATE clients SET prospect_expires_on = DATE_ADD(CURDATE(), INTERVAL 3 DAY) WHERE id = ${Number(clientId)}`, 'expiry in 3 days');
    await page.goto('/clients?show=prospects');
    await expect(page.locator('.entity-card', { hasText: NAME }).locator('.prospect-expiry-urgent')).toContainText('(in 3 days)');
    await page.goto(`/clients/${clientId}`);
    await page.locator('[data-testid="prospect-box"] button', { hasText: 'Keep for 60 more days' }).click();
    await expect(page.locator('body')).toContainText('is kept until');
    expect(runSql(`SELECT DATEDIFF(prospect_expires_on, CURDATE()) FROM clients WHERE id = ${Number(clientId)}`, 'read expiry')).toBe('63');
  });

  test('4: closed → Deactivated Clients → Reactivate (fresh 60 days)', async ({ page }) => {
    // The state the daily job leaves (its order part is covered by the unit / integration tests).
    runSql(`SET NAMES utf8mb4; UPDATE clients SET is_active = 0, prospect_expired_on = CURDATE(), prospect_expired_reason = '${sql('Quote expired today — no reply in 60 days')}' WHERE id = ${Number(clientId)}`, 'expire');
    await page.goto('/clients/inactive');
    const row = page.locator('tr', { hasText: NAME });
    await expect(row.locator('[data-testid="prospect-badge"]')).toHaveText('Prospect');
    await expect(row).toContainText('Quote expired today — no reply in 60 days');
    await row.locator('button', { hasText: 'Reactivate (fresh 60 days)' }).click();
    await expect(page.locator('body')).toContainText('is active again. It stays a prospect until its details are filled in');
    expect(runSql(`SELECT CONCAT(is_active, '/', DATEDIFF(prospect_expires_on, CURDATE())) FROM clients WHERE id = ${Number(clientId)}`, 'read state')).toBe('1/60');
  });

  test('5: a Quotation request from the same e-mail updates the prospect', async ({ page }) => {
    runSql(`INSERT INTO client_intake_submissions (company_legal_name, billing_address, billing_address_line1, billing_city, billing_country, contact_person, email, country_of_destination, submitted_ip, status, consignee_same_as_buyer)
            VALUES ('${sql(NAME)} GmbH', '5 Stone Street, Hamburg, Germany', '5 Stone Street', 'Hamburg', 'Germany', 'Purchasing Manager', '${EMAIL.toUpperCase()}', 'Germany', '127.0.0.1', 'pending', 1)`, 'request');
    await page.goto('/client-intake?view=list');
    const row = page.locator('tr', { hasText: `${NAME} GmbH` });
    await expect(row.locator('[data-testid="prospect-match"]')).toContainText('Matches prospect');
    await expect(row).toContainText('Accept as a new client');
    await row.locator('a', { hasText: 'Update this prospect' }).click();
    const changes = page.locator('[data-testid="prospect-changes"]');
    await expect(changes).toContainText('Company Legal Name');
    await expect(changes).toContainText(`${NAME} GmbH`);
    await expect(changes).toContainText('Billing Address Line 1');
    await page.locator('form button', { hasText: 'Update this prospect' }).click();
    await expect(page).toHaveURL(new RegExp(`/clients/${clientId}$`));
    await expect(page.locator('body')).toContainText('no longer a prospect');
    await expect(page.locator('h1 [data-testid="prospect-badge"]')).toHaveCount(0);
    expect(runSql(`SELECT CONCAT(is_prospect, '/', billing_city) FROM clients WHERE id = ${Number(clientId)}`, 'read client')).toBe('0/Hamburg');
  });

  test('6: Settings › Quick-quote prospects refuses 400 days in the browser', async ({ page }) => {
    await page.goto('/settings');
    const section = page.locator('details[data-category="prospects"]');
    await section.locator('summary').click();
    await expect(section).toContainText('Close a prospect\'s quotation after (days)');
    await expect(section).toContainText('Remind the order\'s owner before (days)');
    await expect(section).toContainText('Automatic closing');
    const days = section.locator('input[name="settings[prospect_expiry_days]"]');
    await expect(days).toHaveAttribute('max', '365');
    await expect(days).toHaveAttribute('min', '7');
    await days.fill('400');
    expect(await days.evaluate((el) => el.checkValidity())).toBe(false);
  });
});
