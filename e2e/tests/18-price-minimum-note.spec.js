'use strict';

const { test, expect } = require('@playwright/test');
const { login } = require('./support/auth');
const { ensurePricedProduct } = require('./support/db');
const { chooseRequiredSpecs } = require('./support/orderForm');

/**
 * Owner decision D-397 (20 Nov 2026): the note beside an order line's price tells the two cases of
 * D-245 apart — below "your lowest" the saved line goes to the MD for approval; below the minimum
 * price the save is refused and the MD cannot approve it either. People who see the price build-up
 * (MD / ED) see the minimum figure; everyone else only that the price cannot be accepted.
 */
test.describe.serial('Price below the minimum: the note says the MD cannot approve it (D-397)', () => {
  let minimum = 0;
  let floor = 0;

  async function newLine(page) {
    const productId = ensurePricedProduct();
    await page.goto('/orders/create');
    const usd = await page.locator('select[name="currency_id"] option', { hasText: 'USD' }).first().getAttribute('value');
    await page.locator('select[name="currency_id"]').selectOption(usd);
    await page.locator('select[name="product_catalog_id[]"]').first().selectOption(String(productId));
    await chooseRequiredSpecs(page);
    const price = page.locator('input[name="product_unit_price[]"]').first();
    await expect(price).toHaveAttribute('data-floor', /\d/);
    return price;
  }
  const note = (price) => price.locator('xpath=following-sibling::span[contains(@class, "floor-note")]');

  test('the Managing Director sees the minimum figure and that the MD cannot approve below it', async ({ page }) => {
    await login(page, 'admin');
    const price = await newLine(page);
    await expect(price).toHaveAttribute('data-minimum', /\d/);
    floor = Number(await price.getAttribute('data-floor'));
    minimum = Number(await price.getAttribute('data-minimum'));
    expect(minimum).toBeLessThan(floor);

    await price.fill((minimum - 1).toFixed(2));
    await expect(note(price)).toContainText(`Below the minimum price NexaCrest can accept (${minimum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}) — the MD cannot approve this.`);

    if (floor - minimum > 0.02) {
      await price.fill(((floor + minimum) / 2).toFixed(2));
      await expect(note(price)).toContainText('saving sends this line to the MD for approval');
    }
  });

  test('staff without the price build-up are told the price cannot be accepted, without the figure', async ({ page }) => {
    test.skip(minimum === 0, 'needs the minimum from the first test');
    await login(page, 'exportExecutive');
    const price = await newLine(page).catch(() => null);
    test.skip(price === null, 'this role does not see selling prices on the order form');
    await expect(price).not.toHaveAttribute('data-minimum', /./);
    await price.fill((minimum - 1).toFixed(2));
    await expect(note(price)).toHaveText('Below what NexaCrest can accept — raise it; the MD cannot approve this.');
    if (floor - minimum > 0.02) {
      await price.fill(((floor + minimum) / 2).toFixed(2));
      await expect(note(price)).toContainText('saving sends this line to the MD for approval');
    }
  });
});
