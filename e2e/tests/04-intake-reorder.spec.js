'use strict';

const { test, expect } = require('@playwright/test');
const { login, logout, loginClient } = require('./support/auth');
const { provisionClientLogin } = require('./support/db');

/**
 * Three feature areas that had ZERO E2E coverage before this spec:
 * Client Intake Review, PI (Proforma Invoice) Intake Review, and Reorder
 * Requests. All three follow the same shape — a public, unauthenticated
 * form a client fills (no login, token- or rate-limit-protected instead),
 * landing in a staff review queue gated on 'manage_orders' — and all
 * three are chained into one client's realistic journey: their cold
 * intake gets accepted into a real client record, an order is created
 * for them, their PI-stage confirmation gets applied to that order, and
 * they submit a reorder request against it that staff approves into a
 * brand new order.
 *
 * Real routes/controllers confirmed by reading the PHP source before
 * writing any selector (not assumed from naming):
 *   Client intake:  public form '/quotation-details' (POST
 *     '/quotation-details/submit', rate-limited 5 req/15min per IP via
 *     RateLimiter — see the note on the first test below if you hit it
 *     while iterating) -> staff review '/client-intake', 'Accept & Create
 *     Client' (ClientIntakeReviewController::accept) -> redirects to the
 *     new /clients/{id}.
 *   PI intake: staff generates a one-time token from the order's Stage 3
 *     panel (POST /orders/{id}/pi-form-link, 'manage_orders'), the raw
 *     link is flashed back in the order page's own .alert banner (it's
 *     also emailed, but email doesn't fire without SMTP in dev) -> client
 *     visits the public '/pi-details/{token}' form -> staff review
 *     '/pi-intake-review', 'Accept & Apply'
 *     (PiIntakeReviewController::accept) -> redirects back to
 *     /orders/{id} and overwrites the client's identity fields from what
 *     the client confirmed.
 *   Reorder requests: client portal '/client/orders/{id}/reorder' (GET
 *     the form pre-filled from the order's own product lines, POST to
 *     submit) -> staff '/reorder-requests', 'Approve & Create Order'
 *     (ReorderRequestController::approve, 'manage_orders') requires a
 *     real active HS code per line (the client never supplies one) ->
 *     redirects to a BRAND NEW /orders/{id}/edit.
 *
 * The client-portal login for the Reorder Request step is provisioned
 * directly via db.js's provisionClientLogin() rather than walking the
 * order through the Stage 3 advance-cleared gate — Reorder Requests has
 * no stage dependency of its own (showReorderForm only checks the order
 * belongs to the client), so forcing a full pipeline walk just to get a
 * portal login would test the pipeline again, not this feature.
 */

const UNIQUE = Date.now();
const INTAKE_EMAIL = `intake-buyer-${UNIQUE}@nexacrest.test`;
const CLIENT_PASSWORD = 'IntakeReorderTest2026';

function openStage(page, stageNumber) {
  return page.locator(`.process-step[data-stage="${stageNumber}"]`).click();
}

test.describe.serial('Client Intake -> PI Intake -> Reorder Request, one client journey', () => {
  let clientId;
  let orderId;
  let clientEmailFinal; // the email the client confirms at PI-stage, used for the client-portal login

  test('Client Intake Review: public submission, staff accepts and a real client is created', async ({
    page,
  }) => {
    // NOTE: '/quotation-details/submit' is IP-rate-limited to 5 requests
    // per 15 minutes (ClientIntakeController::RATE_LIMIT_MAX_REQUESTS) —
    // if this test is re-run many times in quick succession while
    // debugging and starts failing with "Too many submissions", clear it:
    //   DELETE FROM rate_limit_hits WHERE bucket_key='quotation_intake_submit';
    await page.goto('/quotation-details');
    await page.locator('input[name="company_legal_name"]').fill(`Intake Journey Buyer ${UNIQUE}`);
    await page.locator('input[name="billing_address_line1"]').fill('1 Intake Test Street');
    await page.locator('input[name="billing_city"]').fill('Rotterdam');
    // External QA report U-06: Country was free text — now a select
    // (#<field>_select) that syncs into the actual submitted field
    // (input[name="billing_country"], readonly/hidden unless "Other")
    // via its own change-event JS, same pattern as #incoterm_select
    // below, so select it rather than filling the readonly input directly.
    await page.locator('#billing_country_select').selectOption({ label: 'Netherlands' });
    await page.locator('input[name="contact_person"]').fill('Intake Test Contact');
    await page.locator('input[name="email"]').fill(INTAKE_EMAIL);
    await page.locator('input[name="vat_eori_tax_no"]').fill('NL123456789B01');
    await page.locator('#country_of_destination_select').selectOption({ label: 'Netherlands' });
    await page.locator('input[name="port_of_discharge_text"]').fill('Rotterdam, Netherlands');
    await page.locator('#incoterm_select').selectOption({ label: 'CIF' });
    await page.getByRole('button', { name: 'Submit Request' }).click();
    await expect(page.locator('h1')).toHaveText('Thank you');

    await login(page, 'exportExecutive');
    await page.goto('/client-intake');
    const row = page.locator('tr', { hasText: `Intake Journey Buyer ${UNIQUE}` });
    await row.getByRole('button', { name: 'Accept & Create Client' }).click();
    await expect(page).toHaveURL(/\/clients\/\d+$/);
    await expect(page.locator('.alert')).toContainText('Client created');
    clientId = Number(page.url().match(/\/clients\/(\d+)$/)[1]);
    await logout(page);
  });

  test('Create an order for that client so a PI-stage intake has something to attach to', async ({
    page,
  }) => {
    await login(page, 'exportExecutive');
    await page.goto('/orders/create');
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
    await logout(page);
  });

  test('PI Intake Review: staff generates the link, client confirms, staff applies it', async ({ page }) => {
    await login(page, 'exportExecutive');
    await page.goto(`/orders/${orderId}`);
    await openStage(page, 3);
    const linkForm = page.locator(`form[action="/orders/${orderId}/pi-form-link"]`);
    await linkForm.getByRole('button', { name: /Generate PI Form Link/ }).click();
    const alertText = await page.locator('.alert').textContent();
    const tokenMatch = alertText.match(/\/pi-details\/([a-f0-9]+)/);
    expect(tokenMatch, `expected a /pi-details/<token> link in the flash banner, got: "${alertText}"`).toBeTruthy();
    const token = tokenMatch[1];
    await logout(page);

    // Public form — no staff session needed here, same page/context is fine.
    clientEmailFinal = `pi-confirmed-${UNIQUE}@nexacrest.test`;
    await page.goto(`/pi-details/${token}`);
    await page.locator('input[name="billing_address_line1"]').fill('1 PI Confirmed Street');
    await page.locator('input[name="billing_city"]').fill('Rotterdam');
    // Unlike the client_intake form above, views/pi_intake/form.php's
    // billing_country is still a plain free-text input — U-06's
    // dropdown conversion was never applied here.
    await page.locator('input[name="billing_country"]').fill('Netherlands');
    await page.locator('input[name="email"]').fill(clientEmailFinal);
    // Required but never pre-filled on a fresh PI intake submission row
    // (unlike company_legal_name/billing_*/email, which DO carry over
    // from the order/client): phone (required, no default at all), and
    // Incoterm — pi_intake_submissions.incoterm_confirmed starts NULL on
    // a fresh link (it is NOT inherited from the order's own incoterm),
    // so the real client always has to pick one here. The visible select
    // (#incoterm_select) syncs into the actual submitted field
    // (input[name="incoterm_confirmed"], readonly/hidden unless "Other")
    // via its own change-event JS, so select it rather than filling the
    // readonly input directly.
    await page.locator('input[name="phone"]').fill('+31 10 555 1234');
    await page.locator('#incoterm_select').selectOption({ label: 'CIF' });
    await expect(page.locator('input[name="incoterm_confirmed"]')).toHaveValue('CIF');
    // Unlike the client_intake form above, country_of_destination here is
    // also still a plain free-text input.
    await page.locator('input[name="country_of_destination"]').fill('Netherlands');
    await page.locator('label').filter({ hasText: 'I confirm the above is correct' }).locator('input').check();
    await page.getByRole('button', { name: 'Submit PI Details' }).click();
    await expect(page.locator('h1')).toHaveText('Thank you');

    await login(page, 'exportExecutive');
    await page.goto('/pi-intake-review');
    const row = page.locator('tr', { hasText: `Intake Journey Buyer ${UNIQUE}` });
    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: 'Accept & Apply' }).click();
    await expect(page).toHaveURL(new RegExp(`/orders/${orderId}$`));
    await expect(page.locator('.alert')).toContainText('Client details updated from the PI-stage confirmation');
    await logout(page);
  });

  test('Reorder Request: client submits against the real order, staff approves into a new order', async ({
    page,
  }) => {
    provisionClientLogin(clientEmailFinal, CLIENT_PASSWORD);

    await loginClient(page, clientEmailFinal, CLIENT_PASSWORD);
    await expect(page).not.toHaveURL(/\/client\/login/);

    await page.goto(`/client/orders/${orderId}/reorder`);
    // Product lines are pre-filled from the order's own products — the
    // form is valid to submit as-is (ClientPortalController::submitReorder
    // only requires at least one non-empty description, already present).
    await page.getByRole('button', { name: 'Submit Reorder Request' }).click();
    await expect(page.locator('.alert')).toContainText('Reorder request submitted');

    await page.getByRole('button', { name: 'Log out' }).click();

    await login(page, 'exportExecutive');
    await page.goto('/reorder-requests');
    const row = page.locator('tr', { hasText: `Intake Journey Buyer ${UNIQUE}` });
    await row.getByRole('link', { name: 'Review' }).click();
    await expect(page).toHaveURL(/\/reorder-requests\/\d+$/);

    // The client never supplies an HS code or unit price — staff must
    // confirm both before approval (ReorderRequestController::approve
    // hard-requires an active HS code per line).
    await page.locator('input[name="hs_code[0]"]').fill('68022310');
    await page.locator('input[name="unit_price[0]"]').fill('45.00');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Approve & Create Order' }).click();
    await expect(page).toHaveURL(/\/orders\/\d+\/edit$/);
    await expect(page.locator('.alert')).toContainText('Reorder request approved — new order created');
  });
});
