'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { runSql } = require('./support/db');

/**
 * Owner decision D-402 (21 Nov 2026, "approve A"): on a CIF order the Insurance box fills itself from the CIF value
 * (rate × 110 % × (FOB + freight) ÷ (1 − rate × 110 %), Natural Stone 0.30 % incl. breakage, rounded up), with a note
 * showing how; a figure typed by staff is kept and the note offers "Use calculated". Nothing is saved by this test.
 */
test.describe('Calculated insurance on a CIF order (D-402)', () => {
  test('the Edit order page works out the insurance and keeps a typed figure', async ({ page }) => {
    const found = runSql(
      `SELECT o.id FROM orders o
        WHERE o.incoterm_code = 'CIF' AND o.status = 'active' AND o.is_locked = 0
          AND EXISTS (SELECT 1 FROM order_products p WHERE p.order_id = o.id AND p.is_active = 1 AND p.line_type = 'goods' AND p.category_id IS NULL AND p.fob_value > 0)
          AND NOT EXISTS (SELECT 1 FROM order_products p WHERE p.order_id = o.id AND p.is_active = 1 AND p.category_id IS NOT NULL)
        ORDER BY o.id DESC LIMIT 1`, 'find a CIF order');
    test.skip(!found, 'no CIF order in this database');
    const orderId = Number(found);
    const fob = Number(runSql(`SELECT ROUND(SUM(fob_value), 2) FROM order_products WHERE order_id = ${orderId} AND is_active = 1 AND line_type <> 'service'`, 'fob'));
    const k = 0.003 * 1.1;
    const expected = Math.ceil(Math.round((k * (fob + 950) / (1 - k)) * 1e6) / 1e6 - 1e-9).toFixed(2);

    await login(page, 'admin');
    await page.goto(`/orders/${orderId}/edit`);
    const box = page.locator('#freight-insurance-fieldset');
    const insurance = box.locator('input[name="indicative_insurance_amount"]');
    await box.locator('input[name="indicative_freight_amount"]').fill('950');
    await insurance.fill('');
    await box.locator('input[name="indicative_freight_amount"]').fill('950.00');
    await expect(insurance).toHaveValue(expected);
    await expect(box.locator('.ins-note')).toContainText('Calculated: 0.3% (Natural Stone — ICC (A) incl. breakage) × 110% of CIF value');
    await expect(box.locator('.ins-note')).toContainText('(rounded up)');

    await insurance.fill('250');
    await expect(box.locator('.ins-note')).toContainText('Your own figure is kept.');
    await expect(insurance).toHaveValue('250');
    await box.getByRole('button', { name: 'Use calculated' }).click();
    await expect(insurance).toHaveValue(expected);
  });
});
