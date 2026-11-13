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
}

module.exports = { chooseRequiredSpecs };
