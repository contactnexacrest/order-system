'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { runSql, ensurePricedProduct } = require('./support/db');
const { chooseRequiredSpecs } = require('./support/orderForm');

/**
 * Owner decisions D-382 … D-389 (18 Nov 2026), clicked through in a real browser on each stack, the way
 * the Managing Director would do it:
 *   1. Products › Pricing › Forms of stone: add a form (units ticked, starts on, group, reason).
 *   2. Products › Specifications (HS): add a specification of that form with its HS code.
 *   3. A product's Specifications page: tick what it is "Sold as".
 *   4. New Order: pick the product, choose "Sold as" — the HS code fills itself and the Unit list keeps
 *      only the form's units (Raw block: CBM and Tonne); the MD changes the HS code with a reason and the
 *      order page shows "changed by MD/ED".
 *   5. A category's Options tab: an option's order is edited and kept (D-389).
 */
test.describe.serial('Forms of stone, Specifications (HS), Sold as — as a person would', () => {
  const SUFFIX = `${Date.now()}`.slice(-6);
  const FORM = `E2E Pillar block ${SUFFIX}`;
  let productId;
  let optionRow;

  test.afterAll(() => {
    runSql(`DELETE phs FROM product_hs_specifications phs JOIN hs_specifications s ON s.id = phs.hs_spec_id WHERE s.name = '${FORM}'`, 'cleanup ticks');
    runSql(`DELETE FROM hs_specifications WHERE name = '${FORM}' AND id NOT IN (SELECT hs_spec_id FROM (SELECT DISTINCT hs_spec_id FROM order_products WHERE hs_spec_id IS NOT NULL) x)`, 'cleanup specification');
    runSql(`DELETE FROM product_forms WHERE name = '${FORM}' AND id NOT IN (SELECT form_id FROM (SELECT form_id FROM hs_specifications) x)`, 'cleanup form');
    if (productId) {
      runSql(`DELETE FROM product_hs_specifications WHERE product_id = ${Number(productId)}`, 'cleanup product ticks');
    }
    if (optionRow) {
      runSql(`UPDATE spec_options SET sort_order = ${Number(optionRow.order)} WHERE id = ${Number(optionRow.id)}`, 'restore option order');
    }
  });

  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('1–3: add a form and a specification, tick Sold as on a product', async ({ page }) => {
    await page.goto('/pricing/forms');
    const add = page.locator('form', { has: page.locator('input[name="_form_key"][value="new"]') }).first();
    await add.locator('input[name="name"]').fill(FORM);
    await add.locator('input[name="units[]"][value="CBM"]').check();
    await add.locator('input[name="units[]"][value="TON"]').check();
    await add.locator('select[name="default_unit"]').selectOption('CBM');
    await add.locator('select[name="spec_group"]').selectOption('block');
    await add.locator('input[name="sort_order"]').fill('90');
    await add.locator('input[name="reason"]').fill('E2E: a new form for cut pillar blocks');
    await add.locator('button[type="submit"]').first().click();
    await expect(page.locator('body')).toContainText(`Form "${FORM}" added.`);

    await page.goto('/products/hs-specifications');
    const spec = page.locator('form', { has: page.locator('input[name="_spec_key"][value="new"]') }).first();
    await spec.locator('input[name="name"]').fill(FORM);
    await spec.locator('select[name="form_id"]').selectOption({ label: FORM });
    await spec.locator('input[name="hs_code"]').fill('25161200');
    await spec.locator('input[name="sort_order"]').fill('90');
    await spec.locator('input[name="reason"]').fill('E2E: pillar blocks are sawn on all sides');
    await spec.locator('button[type="submit"]').first().click();
    await expect(page.locator('body')).toContainText(`Specification "${FORM}" added`);

    productId = ensurePricedProduct();
    await page.goto(`/products/${productId}/specifications`);
    const soldAs = page.locator(`form[action="/products/${productId}/sold-as"]`);
    for (const name of ['Gangsaw slab', 'Raw block', FORM]) {
      await soldAs.locator('label', { hasText: name }).first().locator('input[type="checkbox"]').check();
    }
    await soldAs.locator('input[name="reason"]').fill('E2E: this granite is sold as slabs and blocks');
    await soldAs.locator('button[type="submit"]').first().click();
    await expect(page.locator('body')).toContainText('Sold as saved');
  });

  test('4: Sold as fills the HS code and the units; the MD changes the HS code with a reason', async ({ page }) => {
    productId = productId || ensurePricedProduct();
    const clientId = Number(runSql("SELECT id FROM clients WHERE company_legal_name IS NOT NULL ORDER BY id LIMIT 1", 'a client'));
    await page.goto('/orders/create');
    await page.locator('select[name="client_id"]').selectOption(String(clientId));
    await page.locator('select[name="incoterm_id"]').selectOption({ label: 'FOB' });
    await page.locator('select[name="container_type"]').selectOption({ label: '20ft Standard' });
    const usd = await page.locator('select[name="currency_id"] option', { hasText: 'USD' }).first().getAttribute('value');
    await page.locator('select[name="currency_id"]').selectOption(usd);
    const preset = await page.locator('select[name="payment_preset_id"] option', { hasText: 'Standard' }).first().getAttribute('value');
    await page.locator('select[name="payment_preset_id"]').selectOption(preset);
    await page.locator('select[name="product_catalog_id[]"]').first().selectOption(String(productId));
    await chooseRequiredSpecs(page); // D-348 options, then Sold as = Gangsaw slab

    const soldAs = page.locator('select.sold-as-select').first();
    const unit = page.locator('select[name="product_unit[]"]').first();
    const visibleUnits = () => unit.locator('option').evaluateAll((os) => os.filter((o) => !o.hidden && !o.disabled && o.value !== '').map((o) => o.value));

    // Raw block: HS 25161100 and only CBM / Tonne.
    const labels = await soldAs.locator('option').allTextContents();
    await soldAs.selectOption({ index: labels.findIndex((l) => /^Raw block/.test(l)) });
    await expect(page.locator('[data-sold-as-hs-code]').first()).toHaveText('25161100');
    const blockUnits = (await visibleUnits()).map((u) => u.toUpperCase());
    expect(blockUnits.filter((u) => !/^(CBM|M3|TON|TONS|TONNE|TONNES|MT)$/.test(u))).toEqual([]);
    expect(blockUnits.length).toBeGreaterThan(0);

    // Back to Gangsaw slab: HS 68022310 and the slab units.
    await soldAs.selectOption({ index: labels.findIndex((l) => /^Gangsaw slab/.test(l)) });
    await expect(page.locator('[data-sold-as-hs-code]').first()).toHaveText('68022310');
    expect((await visibleUnits()).some((u) => /SQM|M2/i.test(u))).toBe(true);
    await unit.selectOption({ index: 0 }).catch(() => {});
    const sqm = (await visibleUnits()).find((u) => /SQM|M2/i.test(u));
    await unit.selectOption(sqm);
    await page.locator('input[name="product_quantity[]"]').first().fill('50');

    // The MD types another HS code: the reason box appears and is required.
    // Until the code changes, no reason box shows.
    await expect(page.locator('[data-hs-override-reason]').first()).toBeHidden();
    // The MD clicks "Change HS code": the HS Code box (inside More on the order page) gets the cursor.
    const hs = page.locator('input[name="product_hs_code[]"]').first();
    await page.locator('[data-change-hs]').first().click();
    await expect(hs).toBeVisible();
    await expect(hs).toBeFocused();
    await hs.fill('68029300');
    await hs.dispatchEvent('change');
    const reason = page.locator('input[name="product_hs_override_reason[]"]').first();
    await expect(reason).toBeVisible();
    await reason.fill('E2E: buyer customs classify these as worked stone');

    await page.getByRole('button', { name: 'Create Order' }).click();
    await expect(page).toHaveURL(/\/orders\/\d+$/);
    await expect(page.locator('body')).toContainText('Gangsaw slab');
    await expect(page.locator('body')).toContainText('changed by MD/ED');
    const orderId = Number(page.url().match(/\/orders\/(\d+)$/)[1]);
    const line = runSql(`SELECT CONCAT(hs_code, '|', IFNULL(hs_override_reason, '')) FROM order_products WHERE order_id = ${orderId} ORDER BY id LIMIT 1`, 'saved line');
    expect(line).toBe('68029300|E2E: buyer customs classify these as worked stone');
  });

  test('5: an option order is edited and kept (D-389)', async ({ page }) => {
    const row = runSql(`SELECT CONCAT(c.id, '|', s.id, '|', o.id, '|', o.sort_order) FROM spec_options o
      JOIN spec_definitions s ON s.id = o.spec_id AND s.name = 'Edge work' AND s.product_id IS NULL
      JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1
      WHERE o.name = 'Bullnose' AND o.product_id IS NULL LIMIT 1`, 'option');
    test.skip(!row, 'no Bullnose option in this database');
    const [categoryId, specId, optionId, order] = row.split('|');
    optionRow = { id: optionId, order };
    await page.goto(`/products/categories/${categoryId}?tab=options&edit=${specId}`);
    const box = page.locator('input[aria-label="Order of Bullnose"]').first();
    await box.fill('7');
    await box.locator('xpath=ancestor::form[1]').locator('button[type="submit"]').first().click();
    await expect(page.locator('body')).toContainText('Option saved');
    await page.goto(`/products/categories/${categoryId}?tab=options&edit=${specId}`);
    await expect(page.locator('input[aria-label="Order of Bullnose"]').first()).toHaveValue('7');
  });
});
