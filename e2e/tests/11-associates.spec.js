'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { verifyAssociateBank } = require('./support/db');

/**
 * Owner decision 1 Nov 2026 ("AS all as recommended"): one associate's
 * journey through the real pages, on both stacks —
 *   MD adds the associate (Invited) → bank verified → Active → portal access
 *   → the associate sets a password and logs in with the e-mailed code
 *   → creates a one-off link for a buyer → the buyer registers themselves,
 *   enters the e-mail code and confirms the introduction → staff accept on
 *   Quotation Intake → the client is the associate's, protected 12 months,
 *   with a registration certificate → Sales sees who introduced whom, no
 *   amounts.
 * In development the e-mailed codes and links are also flashed on screen
 * ("Development only — …"), which is what this test reads.
 */

const UNIQUE = Date.now();
const ASSOCIATE_EMAIL = `e2e-associate-${UNIQUE}@nexacrest.test`;
const ASSOCIATE_NAME = `E2E Associate ${UNIQUE}`;
const BUYER = `E2E Associate Buyer ${UNIQUE}`;
const PASSWORD = 'Associate2026pw';

test.describe.serial('Associate: invite → buyer registers → accepted → portal', () => {
  let associatePath;
  let inviteLink;

  test('MD adds the associate, the bank is verified, the associate becomes Active and gets portal access', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/associates/new');
    await page.getByLabel('Name *').fill(ASSOCIATE_NAME);
    await page.getByLabel('E-mail (portal login)').fill(ASSOCIATE_EMAIL);
    await page.getByLabel('Clients 1–5').fill('1.25');
    await page.getByRole('button', { name: 'Add associate' }).click();
    await expect(page.locator('.alert-success')).toContainText(`Associate "${ASSOCIATE_NAME}" added.`);
    associatePath = new URL(page.url()).pathname;
    await expect(page.locator('h1')).toContainText('Invited');
    await expect(page.locator('#profile')).toContainText('clients 1–5: 1.25% (personal)');
    await expect(page.locator('#profile')).toContainText('clients 6–10: 1.25% (personal)');

    verifyAssociateBank(ASSOCIATE_EMAIL);
    await page.locator('#profile select[name="status"]').selectOption('active');
    await page.locator('#profile input[name="reason"]').fill('KYC, agreement and bank checked.');
    await page.getByRole('button', { name: 'Change status' }).click();
    await expect(page.locator('.alert-success')).toContainText('Status changed to Active.');

    await page.getByRole('button', { name: 'Send portal access' }).click();
    const flash = await page.locator('.alert-success').innerText();
    expect(flash).toContain(`Portal access e-mailed to ${ASSOCIATE_EMAIL}.`);
    const m = flash.match(/link: (\S+)/);
    expect(m).not.toBeNull();
    await page.goto(new URL(m[1]).pathname);
    await page.getByLabel(/^New password/).fill(PASSWORD);
    await page.getByLabel('Re-enter the new password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Set password' }).click();
    await expect(page.locator('.alert-success')).toContainText('Password set.');
  });

  test('the associate logs in with the e-mailed code and creates a one-off link', async ({ page }) => {
    await page.goto('/associate/login');
    await page.getByLabel('E-mail').fill(ASSOCIATE_EMAIL);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Continue' }).click();
    const code = (await page.locator('.alert-info').innerText()).match(/(\d{6})/)[1];
    await page.getByLabel('Code').fill(code);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.locator('h1')).toContainText(`Welcome, ${ASSOCIATE_NAME}`);

    await page.getByRole('link', { name: 'My clients' }).click();
    await page.getByLabel('Company name *').fill(BUYER);
    await page.getByLabel('Country *').fill('Netherlands');
    await page.getByRole('button', { name: 'Create link' }).click();
    inviteLink = (await page.locator('.alert-success code').innerText()).trim();
    expect(inviteLink).toMatch(/\/invite\/[0-9a-f]{32}$/);
    await expect(page.locator('body')).toContainText('Link created');
  });

  test('the buyer registers through the link and verifies the e-mail; the link is detected automatically', async ({ page }) => {
    await page.goto(new URL(inviteLink).pathname);
    // The link travels as a hidden field: nothing to tick or type.
    await expect(page.locator('input[type="hidden"][name="associate_inv"]')).toHaveCount(1);
    await expect(page.locator('input[name="introduction_confirmed"], input[name="associate_code"]')).toHaveCount(0);
    await page.locator('input[name="company_legal_name"]').fill(BUYER);
    await page.locator('input[name="billing_address_line1"]').fill('1 Harbour Road');
    await page.locator('input[name="billing_city"]').fill('Rotterdam');
    await page.locator('#billing_country_select').selectOption({ label: 'Netherlands' });
    await page.locator('input[name="contact_person"]').fill('Jan Buyer');
    await page.locator('input[name="email"]').fill(`jan-${UNIQUE}@buyer-${UNIQUE}.example`);
    await page.locator('input[name="vat_eori_tax_no"]').fill(`NL${UNIQUE}`);
    await page.locator('#country_of_destination_select').selectOption({ label: 'Netherlands' });
    await page.locator('#incoterm_select').selectOption({ label: 'CIF' });
    await page.getByRole('button', { name: 'Submit Request' }).click();
    await expect(page.locator('h1')).toContainText('Confirm your request');
    const code = (await page.locator('.alert-info').innerText()).match(/(\d{6})/)[1];
    await page.getByLabel('Code from the e-mail *').fill(code);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.locator('h1')).toContainText('Thank you');
  });

  test('staff accept it on Quotation Intake and the client is the associate\'s', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/client-intake?view=list');
    const row = page.locator('tr', { hasText: BUYER });
    await expect(row).toContainText(`Associate: ${ASSOCIATE_NAME} — buyer’s e-mail verified`);
    await row.getByRole('button', { name: /Accept/ }).click();
    await expect(page.locator('.alert-success')).toContainText(`Linked to associate ${ASSOCIATE_NAME} — protected until`);
    await expect(page.locator('.associate-line')).toContainText(`Associate client — introduced by ${ASSOCIATE_NAME}`);
    await page.goto(associatePath);
    await expect(page.locator('#clients')).toContainText(BUYER);
    await expect(page.locator('#invitations')).toContainText('Accepted — your client');
  });

  test('the associate sees the client and prints the registration certificate; Sales sees no amounts', async ({ page, browser }) => {
    await page.goto('/associate/login');
    await page.getByLabel('E-mail').fill(ASSOCIATE_EMAIL);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Continue' }).click();
    const code = (await page.locator('.alert-info').innerText()).match(/(\d{6})/)[1];
    await page.getByLabel('Code').fill(code);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.locator('body')).toContainText('is now registered as your client');
    await page.getByRole('link', { name: 'My clients' }).click();
    const row = page.locator('tr', { hasText: BUYER }).filter({ has: page.getByRole('link', { name: 'Registration certificate' }) });
    await expect(row).toBeVisible();
    const href = await row.getByRole('link', { name: 'Registration certificate' }).getAttribute('href');
    await page.goto(href);
    await expect(page.locator('h1')).toContainText('Client registration certificate');
    await expect(page.locator('body')).toContainText(ASSOCIATE_NAME);

    const sales = await browser.newPage();
    await login(sales, 'exportExecutive');
    await sales.goto('/associates?view=list');
    await expect(sales.locator('body')).toContainText(ASSOCIATE_NAME);
    await expect(sales.locator('body')).not.toContainText('Commission terms');
    await sales.close();
  });
});
