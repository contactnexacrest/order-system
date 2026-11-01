'use strict';

const { expect } = require('@playwright/test');
const { login, ROLES } = require('./auth');

/**
 * Owner decision 1 Nov 2026 (A-2): nobody reviews the document they
 * generated — no exception, not even the MD. The suites used to assign
 * the generator as the reviewer; now a second person (by default the
 * Executive Director, who may approve documents and sees every order) is
 * assigned and approves the document in their own browser session, and
 * the original tab is reloaded.
 */
function documentPanel(page, typeCode) {
  return page
    .locator('details:not(.step-card)')
    .filter({ has: page.locator('summary', { hasText: new RegExp('^\\s*' + typeCode + '\\s') }) })
    .first();
}

async function openDocumentReviewCard(page) {
  await page.locator('#document-review-approval').evaluate((el) => { el.open = true; });
}

async function assignSecondPersonAndApprove(page, typeCode, reviewerRole = 'executiveDirector') {
  const orderUrl = page.url().split('#')[0];
  await openDocumentReviewCard(page);
  const panel = documentPanel(page, typeCode);
  await panel.locator('summary').click();
  const reviewerValue = await panel.locator('option', { hasText: ROLES[reviewerRole].name }).first().getAttribute('value');
  await panel.locator('select[name="reviewer_ids[]"]').selectOption(reviewerValue);
  await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
  await expect(page.locator('.alert').first()).toBeVisible();

  const browser = page.context().browser();
  const reviewerContext = await browser.newContext({ baseURL: new URL(orderUrl).origin, viewport: page.viewportSize() || undefined });
  try {
    const reviewer = await reviewerContext.newPage();
    await login(reviewer, reviewerRole);
    await reviewer.goto(orderUrl);
    await openDocumentReviewCard(reviewer);
    const reviewerPanel = documentPanel(reviewer, typeCode);
    await reviewerPanel.locator('summary').click();
    await reviewerPanel.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(reviewer.locator('.alert').first()).toBeVisible();
  } finally {
    await reviewerContext.close();
  }
  await page.goto(orderUrl);
}

module.exports = { assignSecondPersonAndApprove, documentPanel, openDocumentReviewCard };
