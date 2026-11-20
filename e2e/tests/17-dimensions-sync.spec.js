'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { runSql } = require('./support/db');

/**
 * Owner decision D-396 (20 Nov 2026, "one way"): on an order line, Dimensions follows Length / Width /
 * Thickness — on a new line, a saved line and a duplicated line alike. A Dimensions text made only of
 * sizes is kept equal to the three sizes; typing in Dimensions never changes the sizes; own wording ("Random sizes, as available") is never changed, and a note offers "Use the sizes".
 * Nothing is saved by the browser alone: the form still saves what is in the boxes.
 */
// The order page's Products card opens on a click, like a person would open it.
async function openProducts(page) {
  const card = page.locator('details#step-products');
  if (!(await card.evaluate((d) => d.open))) { await card.locator(':scope > summary').click(); }
}

test.describe('Dimensions follow the three sizes (D-396)', () => {
  const copies = [];
  test.afterAll(() => {
    if (copies.length) { runSql(`DELETE FROM order_products WHERE id IN (${copies.join(',')})`, 'remove test copies'); }
  });
  test.beforeEach(async ({ page }) => {
    await login(page, 'admin');
  });

  test('New Order form: sizes fill Dimensions, a duplicated row follows, own wording is kept', async ({ page }) => {
    await page.goto('/orders/create');
    const row = page.locator('.product-row').first();
    const L = row.locator('input[name="product_piece_length_cm[]"]');
    const W = row.locator('input[name="product_piece_width_cm[]"]');
    const T = row.locator('input[name="product_piece_thickness_cm[]"]');
    const D = row.locator('input[name="product_dimensions[]"]');

    await L.fill('180');
    await W.fill('90');
    await T.fill('14');
    await expect(D).toHaveValue('180 × 90 × 14 cm');
    await L.fill('200');
    await expect(D).toHaveValue('200 × 90 × 14 cm');

    // A duplicated row keeps following its own sizes.
    await row.locator('button.duplicate-row').click();
    const copy = page.locator('.product-row').nth(1);
    await expect(copy.locator('input[name="product_dimensions[]"]')).toHaveValue('200 × 90 × 14 cm');
    await copy.locator('input[name="product_piece_width_cm[]"]').fill('70');
    await expect(copy.locator('input[name="product_dimensions[]"]')).toHaveValue('200 × 70 × 14 cm');
    await expect(D).toHaveValue('200 × 90 × 14 cm');

    // Own wording is kept; the note offers the sizes.
    await D.fill('Random sizes, as available');
    await L.fill('150');
    await expect(D).toHaveValue('Random sizes, as available');
    const note = row.locator('.dims-note');
    await expect(note).toContainText('Your own wording is kept. The sizes are 150 × 90 × 14 cm.');
    await note.getByRole('button', { name: 'Use the sizes' }).click();
    await expect(D).toHaveValue('150 × 90 × 14 cm');
    await expect(note).toBeHidden();
  });

  test('New Order form: typing in Dimensions never changes the sizes, and is not cut while sizes are typed', async ({ page }) => {
    await page.goto('/orders/create');
    const row = page.locator('.product-row').first();
    const D = row.locator('input[name="product_dimensions[]"]');
    await D.fill('300 x 180 x 2 cm');
    await D.blur();
    await expect(row.locator('input[name="product_piece_length_cm[]"]')).toHaveValue('');
    await expect(row.locator('input[name="product_quantity[]"]')).toBeEditable();
    await row.locator('input[name="product_piece_thickness_cm[]"]').fill('2');
    await expect(D).toHaveValue('300 x 180 x 2 cm');
  });

  test('a saved line and its duplicate on the order page follow their sizes', async ({ page }) => {
    const found = runSql(
      `SELECT o.id, MIN(p.id) FROM orders o JOIN stages_master sm ON sm.id = o.current_stage_id
         JOIN order_products p ON p.order_id = o.id AND p.is_active = 1 AND p.line_type = 'goods' AND p.as_per_annexure = 0 AND p.unit IN ('CBM', 'SQM')
        WHERE o.is_locked = 0 AND o.status = 'active' AND sm.stage_number = 1
        GROUP BY o.id ORDER BY o.id DESC LIMIT 1`, 'find an editable order line');
    test.skip(!found, 'no editable stage-1 order line in this database');
    const [orderId, sourceId] = found.split('\t').map(Number);

    // Works only on copies it makes with the order page's own Duplicate button (removed afterwards),
    // so the order's own line is never changed.
    async function duplicate(lineId) {
      await page.goto(`/orders/${orderId}`);
      await openProducts(page);
      const form = page.locator(`form[action="/orders/${orderId}/products/${lineId}/duplicate"]`);
      if (await form.locator('input[name="reason"]').count()) {
        await form.locator('input[name="reason"]').fill('E2E: a second size of the same stone');
      }
      await Promise.all([page.waitForNavigation(), form.getByRole('button', { name: 'Duplicate' }).click()]);
      const id = Number(runSql(`SELECT IFNULL(MAX(id), 0) FROM order_products WHERE copied_from_id = ${lineId} AND order_id = ${orderId}`, 'find copy'));
      expect(id).toBeGreaterThan(0);
      copies.push(id);
      return id;
    }
    async function openLine(lineId) {
      await page.goto(`/orders/${orderId}`);
      await openProducts(page);
      await page.locator(`[data-open-panel="line-panel-${lineId}"]`).click();
      return page.locator(`#line-panel-${lineId}`);
    }

    // The screenshot case on a saved line: sizes 180 × 90 × 14, Dimensions still the old 120 × 60 × 12 cm.
    const saved = await duplicate(sourceId);
    runSql(`SET NAMES utf8mb4; UPDATE order_products SET dimensions = '120 × 60 × 12 cm', piece_length_cm = 180, piece_width_cm = 90, piece_thickness_cm = 14 WHERE id = ${saved}`, 'set mismatched line');
    let panel = await openLine(saved);
    await expect(panel.locator('input[name="dimensions"]')).toHaveValue('180 × 90 × 14 cm');
    await expect(panel.locator('.dims-note')).toContainText('Filled from the sizes (was 120 × 60 × 12 cm).');
    await panel.locator('input[name="piece_length_cm"]').fill('175');
    await expect(panel.locator('input[name="dimensions"]')).toHaveValue('175 × 90 × 14 cm');

    // Its duplicate follows its own sizes too.
    runSql(`SET NAMES utf8mb4; UPDATE order_products SET dimensions = '180 × 90 × 14 cm' WHERE id = ${saved}`, 'line in step');
    const copy = await duplicate(saved);
    panel = await openLine(copy);
    await expect(panel.locator('input[name="dimensions"]')).toHaveValue('180 × 90 × 14 cm');
    await panel.locator('input[name="piece_width_cm"]').fill('80');
    await expect(panel.locator('input[name="dimensions"]')).toHaveValue('180 × 80 × 14 cm');
  });
});
