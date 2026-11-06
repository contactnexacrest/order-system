'use strict';
// C06 — Quoting: the catalogue (no prices), Get price for a chosen specification, the quote
// price and the lowest you may agree, the daily limit, and what NexaCrest controls.
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.quoting@email.com';

module.exports = {
  code: 'C06',
  file: 'C06-Associate-Get-A-Price.mp4',
  group: 'Group C · Associates',
  title: 'Quoting: get a price in seconds',
  subtitle: 'The catalogue, Get price, the quote and the lowest price you may agree.',
  minutes: '9',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'quoting', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    s.productId = demo.pricedProduct(s.a.id);
    return s;
  },

  steps: ({ a = PLAN_A, productId = 0 } = {}) => [
    { card: 'title', say: 'Your buyer asks: how much? With the Quoting level, you answer in seconds, without waiting for anyone. Let us see how.' },
    { card: 'chapter', title: 'What NexaCrest opens for you', subtitle: 'Categories, forms, a daily limit.', say: 'Part one. First, a quick look at what NexaCrest controls.' },
    { as: 'md', goto: `/associates/${a.id}/price-access`, caption: 'Staff: Price access', say: 'On the staff side, the Managing Director decides which product categories, and which forms, like slabs or tiles, you may price. Here, natural stone, slabs and tiles, is ticked for our demo associate.', do: async (d) => { await d.ring(d.page.locator('fieldset')); await d.wait(2000); } },
    { caption: 'Daily limit and validity', say: 'They also set how many prices you can ask for per day, and how long a price stays valid. Empty means the company default: ten a day, valid seven days.', do: async (d) => { await d.ring(d.page.locator('.form-row').first()); await d.wait(1800); } },

    { card: 'chapter', title: 'The catalogue', subtitle: 'Products you may offer. No prices, no suppliers.', say: 'Part two. Your catalogue.' },
    { as: 'associate', goto: '/associate/catalogue', caption: 'Catalogue', say: 'Back to you. The Catalogue tab lists the products you can offer, with their forms and finishes. Notice: no prices, no supplier names, no quarry locations. The descriptions can be shared with buyers.', do: async (d) => { await d.ring(d.page.locator('table.list').first()); await d.wait(2000); } },
    { caption: '10 of 10 left today', say: 'At the top: how many price requests you have left today. Use them wisely, NexaCrest keeps an eye on how many prices turn into orders.', do: async (d) => { await d.point(d.page.locator('p.muted').first(), 1500); } },

    { card: 'chapter', title: 'Get price', subtitle: 'Choose the options. Get the price.', say: 'Part three. Let us get a price.' },
    { caption: 'Get price', say: 'Press Get price next to the product.', do: async (d) => { await d.clickNav(d.page.getByRole('link', { name: 'Get price' }).first()); } },
    { caption: 'Only this product\'s options', say: 'You only see the options this product really offers. Each drop-down starts at the standard choice. Let us pick what our buyer asked for.', do: async (d) => { await d.ring(d.page.locator('form.field-grid')); await d.wait(1500); } },
    { caption: 'Thickness, finish, quantity', say: 'Thirty millimetres thick, a leather finish, and two to four containers.', do: async (d) => {
      const pick = async (label, option) => {
        const sel = d.page.locator('label', { hasText: label }).locator('select').first();
        if (await sel.count()) await d.select(sel, { label: option }).catch(() => {});
      };
      await pick('Thickness', '30 mm');
      await pick('Surface finish', 'Leather');
      await pick('Quantity', '2–4 containers');
    } },
    { caption: 'Get price', say: 'Press Get price.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Get price' })); } },
    { caption: 'Quote price and lowest price', say: 'And there it is, instantly. Two numbers. The quote price, which you offer the buyer. And the lowest price you may agree, if the buyer negotiates. Both are FOB, an Indian port, per unit.', do: async (d) => { await d.ring(d.page.locator('.field-grid').first()); await d.wait(2200); } },
    { caption: 'Your commission does not change', say: 'Here is the friendly part: your commission is the same anywhere between those two prices. So negotiate freely within that range, it does not cost you anything.', do: async (d) => { await d.point(d.page.locator('p.small.muted').last(), 1800); } },
    { caption: 'What changed the price', say: 'The lines show what changed the price compared to the standard specification, like a thicker slab or a special finish. Never costs or margins, only what moved the price.', do: async (d) => { const t = d.page.locator('table.list'); if (await t.count()) { await d.ring(t.first()); } await d.wait(1800); } },
    { caption: 'Valid until…', say: 'And the price is valid until the date shown. After that, simply ask again.', do: async (d) => { await d.point(d.page.locator('p.muted').nth(1), 1200); } },

    { card: 'chapter', title: 'Your price requests', say: 'Part four. Keeping track.' },
    { goto: '/associate/catalogue', caption: 'Your price requests', say: 'Back on the catalogue, the request is listed under Your price requests, with its number, the specification, the two prices and the status. When the buyer orders, it says: became an order.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Your price requests' })); await d.ring(d.page.locator('table.list').last()); await d.wait(2000); } },
    { as: 'md', goto: '/associates/price-requests', caption: 'Staff see every request', say: 'And NexaCrest sees every request too, with a ninety-day report: how many prices each associate asked for, and how many orders followed. Many prices with no orders gets a gentle warning.', do: async (d) => { await d.ring(d.page.locator('#price-requests')); await d.wait(1500); await d.scrollTo('#price-request-report'); } },
    { card: 'list', kicker: 'Golden rules', title: 'Quoting the right way', items: [
      'Prices are for you only: never forward the page or a screenshot.',
      'Offer the quote price; you may go down to the lowest price, not below.',
      'The final price is confirmed on the Proforma Invoice.',
      'Something not listed? Use "Other" (next video).',
    ], next: 'Part 2 · Price requests: "Other"', say: 'A few golden rules. Prices are for you only, so never forward the page or a screenshot. Offer the quote price, and go down to the lowest price if you must, never below. The final price is always confirmed on the Proforma Invoice. And if the buyer wants something not in the list, that is what Other is for, coming up next, in part two.' },
  ],
};
