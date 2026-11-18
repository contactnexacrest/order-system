'use strict';

/**
 * D-348: a goods line picked from Products must have its "Thickness" and "Size / format"
 * chosen — the order form starts them on "Choose…" (select[data-spec-required]). Picks the
 * first real option of each one still empty, the way a person would before saving.
 */
async function chooseRequiredSpecs(page) {
  const selects = page.locator('select[data-spec-required="1"]');
  await selects.first().waitFor({ state: 'attached', timeout: 10_000 }).catch(() => {});
  const count = await selects.count();
  for (let i = 0; i < count; i++) {
    const select = selects.nth(i);
    if (await select.isVisible() && (await select.inputValue()) === '') {
      await select.selectOption({ index: 1 });
    }
  }
  // D-385 (18 Nov 2026): a goods line picked from Products also needs "Sold as" (it sets the HS code
  // and the units). Gangsaw slab keeps the unit SQM, whose thickness the options above already give.
  const soldAs = page.locator('select.sold-as-select');
  const n = await soldAs.count();
  for (let i = 0; i < n; i++) {
    const select = soldAs.nth(i);
    if (await select.isVisible() && (await select.inputValue()) === '') {
      const labels = await select.locator('option').allTextContents();
      const idx = labels.findIndex((l) => /Gangsaw slab/.test(l));
      await select.selectOption({ index: idx > 0 ? idx : 1 });
    }
  }
}

module.exports = { chooseRequiredSpecs };
