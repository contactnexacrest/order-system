'use strict';

const path = require('path');
const { test, expect } = require('@playwright/test');
const { login, loginClient } = require('./support/auth');
const { resetClientPassword } = require('./support/db');

const DUMMY_FILE = path.join(__dirname, 'fixtures', 'dummy-signed-copy.pdf');

/**
 * Mobile/tablet responsive layout suite. This is NOT cross-browser
 * testing — this sandbox only has Chromium available
 * (/opt/pw-browsers/, no Firefox or WebKit binary, and the environment
 * explicitly says not to run `playwright install`), so a real
 * multi-engine matrix (Chromium/Firefox/WebKit) isn't reachable here.
 * What IS reachable and genuinely useful: Chromium at real phone/tablet
 * viewport sizes, exercising the app's actual responsive CSS rather than
 * assuming it works — per public_html/assets/css/app.css's own "Task
 * #18: responsive / mobile pass" comment, the design below 900px is:
 *   - the sidebar nav collapses behind a hamburger
 *     (#nav-toggle checkbox + its <label>, aria-label "Toggle
 *     navigation") — .sidebar-nav/.sidebar-user are display:none until
 *     the checkbox is checked.
 *   - .card-grid (orders/clients list) and .page-header-row drop to a
 *     single column.
 *   - the order detail page's .order-detail-sidebar (At a Glance + All
 *     Documents) drops from a sticky side column to a static block
 *     stacked below the step-card sequence.
 *   - chat bubbles widen to 90% at <=640px.
 * Every test also asserts no horizontal page overflow — the CSS
 * comment's own stated goal ("nothing forces horizontal scroll on a
 * phone") is a directly checkable thing, not just a visual impression.
 */

async function assertNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  );
  expect(overflow).toBeLessThanOrEqual(1); // 1px tolerance for subpixel rounding
}

const UNIQUE = Date.now();

test.describe('Mobile viewport (390x844 — iPhone 12/13 sized)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('login page has no horizontal overflow and is usable', async ({ page }) => {
    await page.goto('/login');
    await assertNoHorizontalOverflow(page);
    await login(page, 'admin');
    await expect(page).not.toHaveURL(/\/login/);
    await assertNoHorizontalOverflow(page);
  });

  test('dashboard nav is collapsed behind the hamburger and expands to reach a page', async ({ page }) => {
    await login(page, 'admin');
    await assertNoHorizontalOverflow(page);

    // Below the 900px breakpoint, .sidebar-nav is display:none until the
    // #nav-toggle checkbox is checked — the real security/UX property
    // being tested isn't "the link exists" (01-role-visibility.spec.js
    // covers that), it's "a human on a phone can actually reach it".
    await expect(page.locator('.sidebar-nav')).not.toBeVisible();
    await page.getByLabel('Toggle navigation').click();
    await expect(page.locator('.sidebar-nav')).toBeVisible();

    await page.locator('.sidebar-nav a[href="/products"]').click();
    await expect(page).toHaveURL(/\/products$/);
    await assertNoHorizontalOverflow(page);
  });

  test('orders list drops to a single-column card grid', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/orders');
    await assertNoHorizontalOverflow(page);

    const columns = await page.evaluate(() => {
      const grid = document.querySelector('.card-grid');
      if (!grid) return null;
      return getComputedStyle(grid)
        .gridTemplateColumns.trim()
        .split(/\s+/)
        .filter(Boolean).length;
    });
    // null (no .card-grid rendered, e.g. zero orders) is not a failure —
    // only assert the column count when the grid actually exists.
    if (columns !== null) {
      expect(columns).toBe(1);
    }
  });

  test.describe.serial('a real order created and worked on a phone viewport stays usable end to end', () => {
    let orderId;
    let clientEmail;
    const CLIENT_PASSWORD = 'ClientPortalMobile2026';

    test('create client + order via the mobile-width forms, no overflow at any step', async ({ page }) => {
      await login(page, 'admin');

      await page.goto('/clients/create');
      await assertNoHorizontalOverflow(page);
      await page.locator('input[name="company_legal_name"]').fill(`Mobile Layout Buyer ${UNIQUE}`);
      await page.locator('textarea[name="billing_address"]').fill('1 Mobile Test Street, Test City, Test Country');
      clientEmail = `mobile-layout-buyer-${UNIQUE}@nexacrest.test`;
      await page.locator('input[name="email"]').fill(clientEmail);
      await page.getByRole('button', { name: 'Create Client' }).click();
      await expect(page).toHaveURL(/\/clients\/\d+$/);
      const clientId = Number(page.url().match(/\/clients\/(\d+)$/)[1]);
      await assertNoHorizontalOverflow(page);

      await page.goto('/orders/create');
      await assertNoHorizontalOverflow(page);
      await page.locator('select[name="client_id"]').selectOption(String(clientId));
      await page.locator('select[name="incoterm_id"]').selectOption({ label: 'CIF' });
      const eurValue = await page
        .locator('select[name="currency_id"] option', { hasText: 'EUR' })
        .first()
        .getAttribute('value');
      await page.locator('select[name="currency_id"]').selectOption(eurValue);
      const presetValue = await page
        .locator('select[name="payment_preset_id"] option', { hasText: 'Standard' })
        .first()
        .getAttribute('value');
      await page.locator('select[name="payment_preset_id"]').selectOption(presetValue);
      await page.locator('input[name="port_of_discharge_text"]').fill('Rotterdam, Netherlands');
      await page.locator('input[name="indicative_freight_amount"]').fill('950');
      await page.locator('input[name="indicative_insurance_amount"]').fill('120');
      await page.locator('input[name="product_description[]"]').first().fill('Granite Memorial Headstone');
      await page.locator('input[name="product_quantity[]"]').first().fill('100');
      await page.locator('input[name="product_unit_price[]"]').first().fill('45.00');
      await page.locator('input[name="product_hs_code[]"]').first().fill('68022310');
      await page.getByRole('button', { name: 'Create Order' }).click();
      await expect(page).toHaveURL(/\/orders\/\d+$/);
      orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);
      await assertNoHorizontalOverflow(page);
    });

    test('order detail page: step-cards stack with the sidebar, and a collapsed card still opens', async ({
      page,
    }) => {
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}`);
      await assertNoHorizontalOverflow(page);

      // Below 900px .order-detail-sidebar (At a Glance + All Documents)
      // drops from a sticky side column to a static block stacked below
      // the step-card sequence — confirm that's really the layout in
      // effect, not just that the class exists.
      const sidebarPosition = await page.evaluate(() => {
        const el = document.querySelector('.order-detail-sidebar');
        return el ? getComputedStyle(el).position : null;
      });
      expect(sidebarPosition).toBe('static');

      // Step-cards are native <details>/<summary> — collapse/expand must
      // still work with a tap at phone width. "Disputes" is reliably
      // collapsed on a fresh order (it isn't the current step).
      const disputesCard = page
        .locator('details.step-card')
        .filter({ has: page.locator('summary', { hasText: 'Disputes' }) })
        .first();
      expect(await disputesCard.evaluate((el) => el.open)).toBe(false);
      await disputesCard.locator('summary').click();
      expect(await disputesCard.evaluate((el) => el.open)).toBe(true);
      await assertNoHorizontalOverflow(page);
    });

    test('generate QT, approve, confirm Buyer PO, clear advance — all on a phone viewport, no overflow', async ({
      page,
    }) => {
      test.setTimeout(120_000);
      page.on('dialog', (dialog) => dialog.accept());
      await login(page, 'admin');
      await page.goto(`/orders/${orderId}`);

      const openStage = async (n) => page.locator(`.process-step[data-stage="${n}"]`).click();
      const docForm = (typeCode) =>
        page.locator(
          `form[action="/orders/${orderId}/documents/generate"]:has(input[name="document_type"][value="${typeCode}"])`
        );

      await openStage(1);
      await docForm('QT').locator('button[type="submit"]').click();
      await expect(page.locator('.alert')).toContainText('generated');
      await assertNoHorizontalOverflow(page);

      // The QT-specific review widget lives inside #document-review-approval
      // — a reference card, not tied to any stage stepper dot, so it
      // renders collapsed by default and needs opening explicitly before
      // reaching the nested summary descendant this is actually looking for.
      const openDocumentReviewCard = () =>
        page.locator('#document-review-approval').evaluate((el) => { el.open = true; });
      await openDocumentReviewCard();
      let panel = page
        .locator('details:not(.step-card)')
        .filter({ has: page.locator('summary', { hasText: /^\s*QT\s/ }) })
        .first();
      await panel.locator('summary').click();
      const selfValue = await panel.locator('option', { hasText: 'Gulmohar Sontakke' }).first().getAttribute('value');
      await panel.locator('select[name="reviewer_ids[]"]').selectOption(selfValue);
      await panel.getByRole('button', { name: 'Assign Reviewers' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openDocumentReviewCard();
      panel = page
        .locator('details:not(.step-card)')
        .filter({ has: page.locator('summary', { hasText: /^\s*QT\s/ }) })
        .first();
      await panel.locator('summary').click();
      await panel.getByRole('button', { name: 'Approve', exact: true }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(2);
      const uploadForm = page.locator(`form[action="/orders/${orderId}/buyer-po/documents"]`);
      await uploadForm.locator('input[type="file"]').setInputFiles(DUMMY_FILE);
      await uploadForm.getByRole('button', { name: 'Attach Buyer PO Copy' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(2);
      const poForm = page.locator(`form[action="/orders/${orderId}/buyer-po"]`);
      await poForm.locator('input[name="buyers_po_ref"]').fill(`PO-MOBILE-${UNIQUE}`);
      await poForm.getByRole('button', { name: 'Confirm Buyer PO Received' }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
      await assertNoHorizontalOverflow(page);

      await openStage(3);
      const recordForm = page.locator(`form[action="/orders/${orderId}/payment/advance"]`);
      await recordForm.locator('input[name="advance_amount"]').fill('1800.00');
      await recordForm.getByRole('button', { name: 'Record Advance Remittance' }).click();
      await expect(page.locator('.alert')).toBeVisible();

      await openStage(3);
      const clearForm = page.locator(`form[action="/orders/${orderId}/payment/advance/clear"]`);
      await clearForm.getByRole('button', { name: 'Mark Advance Cleared' }).click();
      await expect(page.locator('.alert')).toContainText('unlocked');
      await assertNoHorizontalOverflow(page);

      resetClientPassword(clientEmail, CLIENT_PASSWORD);
    });

    test('client portal order page is usable on a phone: no overflow, chat form reachable and works', async ({
      page,
    }) => {
      await loginClient(page, clientEmail, CLIENT_PASSWORD);
      await assertNoHorizontalOverflow(page);

      await page.goto(`/client/orders/${orderId}`);
      await assertNoHorizontalOverflow(page);

      const commentForm = page.locator('.chat-post-form');
      await expect(commentForm).toBeVisible();
      await commentForm.locator('textarea[name="body"]').fill('Checking in from my phone — looks good so far.');
      await commentForm.getByRole('button', { name: 'Send' }).click();
      await expect(page.locator('body')).toContainText('Checking in from my phone');
      await assertNoHorizontalOverflow(page);
    });
  });
});

test.describe('Tablet viewport (768x1024 — iPad Mini portrait, still below the 900px breakpoint)', () => {
  test.use({ viewport: { width: 768, height: 1024 } });

  test('dashboard and orders list have no horizontal overflow and still use the hamburger', async ({ page }) => {
    await login(page, 'admin');
    await assertNoHorizontalOverflow(page);
    await expect(page.locator('.sidebar-nav')).not.toBeVisible();

    await page.goto('/orders');
    await assertNoHorizontalOverflow(page);
  });
});

test.describe('Desktop viewport (1280x800) — confirms the mobile breakpoint does NOT fire above 900px', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('sidebar nav is visible without needing the hamburger toggle', async ({ page }) => {
    await login(page, 'admin');
    await expect(page.locator('.sidebar-nav')).toBeVisible();
    await expect(page.locator('.nav-toggle-label')).not.toBeVisible();
    await assertNoHorizontalOverflow(page);
  });
});
