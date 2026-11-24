'use strict';

const { spawnSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { runSql, bcryptHash } = require('./support/db');
const { isNodeStack } = require('./support/stack');

/**
 * Owner decision D-410 (24 Nov 2026): the new associate commission rules, clicked through like a person,
 * on both stacks.
 *  - MD: "Associate commission in the client's price" (Admin › Pricing › Settings) — each choice grouped with
 *    its text, a change needs a reason; the associate's own choice ("Change for this associate"), the associate
 *    number, "Ladder steps so far"; Associates › Settings: 15 days, 7–45, a change needs a reason.
 *  - Export Executive: "Set by the MD." — no rule, ladder or client-price choice.
 *  - Payment advice: made when a payout is marked paid (one per bank transfer); the associate sees it under
 *    "Payments to you" and downloads it (PDF / Word); another associate's advice is refused; the MD makes "Rev 1".
 * The app's own service makes the advice from a paid payout (the same call Accounts' "Mark paid" makes).
 */

const UNIQUE = Date.now();
const ASSOCIATE_EMAIL = `e2e-d410-${UNIQUE}@nexacrest.test`;
const ASSOCIATE_NAME = `E2E D410 Associate ${UNIQUE}`;
const PASSWORD = 'AssociateD410pw';
const UTR = `E2E-UTR-${UNIQUE}`;

function sqlStr(v) { return `'${String(v).replace(/'/g, "''")}'`; }

/** Runs the stack's own AssociatePaymentAdvice::afterPaid for one payout; returns the advice id. */
function makeAdvice(payoutId) {
  const r = isNodeStack()
    ? spawnSync('node', ['-e', `require('./src/services/associatePaymentAdvice').afterPaid([${payoutId}], null).then(async (ids) => { console.log(String(ids[0])); await require('./src/config/db').pool.end(); });`],
      { cwd: '/home/user/order-system/NODE_MySQL_Version/app', env: { ...process.env, NODE_OPTIONS: '', TZ: 'Asia/Kolkata' } })
    : spawnSync('php', ['-r', `require "bootstrap.php"; echo App\\Services\\AssociatePaymentAdvice::afterPaid([${payoutId}], null)[0];`],
      { cwd: '/home/user/order-system/PHP_MySQL_Version/app' });
  const out = r.stdout.toString().trim().split('\n').pop();
  if (r.status !== 0 || !/^\d+$/.test(out)) throw new Error(`advice not made (status ${r.status}, signal ${r.signal}): ${r.stderr.toString()} ${r.stdout.toString()}`);
  return Number(out);
}

test.describe.serial('D-410: associate rules, client-price choice and payment advice', () => {
  let associateId;
  let adviceId;

  test.beforeAll(() => {
    runSql(`INSERT INTO associates (name, based_in, country, currency_code, email, status, portal_enabled, password_hash, force_password_change, pan, commission_mode)
            VALUES (${sqlStr(ASSOCIATE_NAME)}, 'india', 'India', 'INR', ${sqlStr(ASSOCIATE_EMAIL)}, 'active', 1, ${sqlStr(bcryptHash(PASSWORD))}, 0, 'AAAAA0000A', 'ladder')`, 'associate');
    associateId = Number(runSql(`SELECT id FROM associates WHERE email = ${sqlStr(ASSOCIATE_EMAIL)}`, 'associate id'));
    const orderId = Number(runSql("SELECT MIN(id) FROM orders WHERE status <> 'cancelled'", 'an order'));
    runSql(`INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, status, paid_on, paid_inr, tds_inr, tds_section, paid_via, paid_reference)
            VALUES (${orderId}, 'associate', ${associateId}, 'associate', 10000, 120, 'USD', DATE_FORMAT(CURDATE(), '%Y-%m'), 'paid', CURDATE(), 9800, 200, '194H', 'bank_transfer', ${sqlStr(UTR)})`, 'paid payout');
    const payoutId = Number(runSql(`SELECT id FROM incentive_payouts WHERE paid_reference = ${sqlStr(UTR)}`, 'payout id'));
    adviceId = makeAdvice(payoutId);
  });

  test('MD: the client-price choice — each option grouped with its text; a change needs a reason', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/pricing/settings');
    const box = page.locator('#associate-price-rule');
    await expect(box.locator('.choice-group')).toHaveCount(3);
    await expect(box.locator('.choice-group').nth(1)).toContainText('A fixed %');
    await expect(box.locator('.choice-group').nth(1)).toContainText('The price never rises.');
    await expect(box.locator('.choice-group').nth(1).locator('input[name="associate_price_fixed_pct"]')).toBeVisible();
    await expect(box).toContainText('Right now:');
    await expect(box).toContainText('What each choice would cost us');
    // Switch to "Ladder rate" without a reason: refused, the setting stays "A fixed %".
    await box.locator('input[name="associate_price_rule"][value="ladder"]').check();
    await page.getByRole('button', { name: 'Save settings' }).click();
    await expect(page.locator('.alert-error')).toContainText("Give the reason for changing what the client's price includes");
    await page.goto('/pricing/settings');
    await expect(page.locator('#associate-price-rule input[name="associate_price_rule"][value="fixed"]')).toBeChecked();
  });

  test("MD: the associate's page — number, ladder steps, the client-price choice and a change with a reason", async ({ page }) => {
    await login(page, 'admin');
    await page.goto(`/associates/${associateId}`);
    await expect(page.locator('[data-associate-no]')).toHaveText(`A${String(associateId).padStart(4, '0')}`);
    await expect(page.locator('[data-commission-rule]')).toContainText('Ladder steps so far');
    await expect(page.locator('[data-step-rule]').first()).toContainText('15 days');
    await expect(page.locator('[data-price-rule]')).toContainText('the company setting');
    await expect(page.locator('[data-right-now]')).toContainText('Right now for this associate');
    await page.locator('#commission-rule details summary').first().click();
    const form = page.locator('[data-price-rule-form]');
    await expect(form.locator('.choice-group')).toHaveCount(4);
    await form.locator('input[name="price_rule"][value="ladder"]').check();
    await form.locator('input[name="price_rule_reason"]').fill('E2E: agreed with the associate for this market');
    await form.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.alert-success')).toContainText("Saved. New prices for this associate's clients follow it");
    await expect(page.locator('[data-price-rule]')).toContainText("this associate's own choice");
    await expect(page.locator('[data-price-rule]')).toContainText('Ladder rate');
  });

  test('MD: Associates › Settings — same client within 15 days, 7 to 45, a change needs a reason', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/associates/ladder');
    const days = page.locator('input[name="associate_group_days"]');
    await expect(days).toHaveValue('15');
    await expect(days).toHaveAttribute('min', '7');
    await expect(days).toHaveAttribute('max', '45');
    await expect(page.locator('[data-step-rule]')).toContainText('once the order\'s advance has cleared');
    await days.fill('20');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.alert-error')).toContainText('Give the reason for changing the minimum order value or the days');
    await page.goto('/associates/ladder');
    await expect(page.locator('input[name="associate_group_days"]')).toHaveValue('15');
  });

  test('Export Executive: "Set by the MD." — no rule, ladder or client-price choice', async ({ page }) => {
    await login(page, 'exportExecutive');
    await page.goto(`/associates/${associateId}`);
    await expect(page.locator('[data-commission-rule-hidden]')).toContainText('Set by the MD.');
    await expect(page.locator('[data-price-rule]')).toHaveCount(0);
    await expect(page.locator('[data-margin-so-far]')).toHaveCount(0);
  });

  test('the associate: badges legend, "Payments to you", downloads; another associate\'s advice is refused', async ({ page }) => {
    await page.goto('/associate/login');
    await page.getByLabel('E-mail').fill(ASSOCIATE_EMAIL);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Continue' }).click();
    const code = (await page.locator('.alert-info').innerText()).match(/(\d{6})/)[1];
    await page.getByLabel('Code').fill(code);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/associate$/);
    await page.goto('/associate/orders');
    const legend = page.locator('[data-badge-legend]');
    await expect(legend.locator('.badge-notyet')).toContainText('Not earned yet — buyer still paying');
    await expect(legend.locator('.badge-tobepaid')).toContainText('To be paid');
    await expect(legend.locator('.badge-active')).toContainText('Paid');
    await expect(page.locator('[data-orders-intro]')).toContainText('every order of the clients you introduced');
    await page.goto('/associate/profile');
    await expect(page.locator('[data-associate-no]')).toContainText(`A${String(associateId).padStart(4, '0')}`);
    await page.goto('/associate/commission');
    const pay = page.locator('[data-payments-to-you]');
    await expect(pay).toContainText(UTR);
    await expect(pay).toContainText(`NC/SC/CPA/A${String(associateId).padStart(4, '0')}/`);
    const pdf = await page.request.get(`/associate/payment-advices/${adviceId}/pdf`);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');
    const docx = await page.request.get(`/associate/payment-advices/${adviceId}/docx`);
    expect(docx.status()).toBe(200);
    expect(docx.headers()['content-type']).toContain('wordprocessingml');
    const other = Number(runSql(`SELECT COALESCE(MIN(id), 0) FROM associate_payment_advices WHERE associate_id <> ${associateId}`, 'another advice'));
    if (other > 0) {
      expect((await page.request.get(`/associate/payment-advices/${other}/pdf`)).status()).toBe(404);
    }
    expect((await page.request.get(`/associate/payment-advices/999999999/pdf`)).status()).toBe(404);
  });

  test('MD: the payment advices list and a correction ("Rev 1") with a reason', async ({ page }) => {
    await login(page, 'admin');
    await page.goto(`/associates/${associateId}`);
    const list = page.locator('[data-payment-advices]');
    await expect(list).toContainText(UTR);
    const form = list.locator(`form[action$="/payment-advices/${adviceId}/revise"]`);
    await form.locator('input[name="reason"]').fill('E2E: bank reference typed wrong');
    await form.getByRole('button', { name: 'Make Rev' }).click();
    await expect(page.locator('.alert-success')).toContainText('Corrected advice made');
    await expect(page.locator('[data-payment-advices]')).toContainText('Rev 1');
    await expect(page.locator('[data-payment-advices]')).toContainText('superseded');
    const rev = await page.request.get(`/associates/${associateId}/payment-advices/${adviceId}/pdf`);
    expect(rev.status()).toBe(200);
  });
});
