'use strict';
// F03 — Associate life cycle (D-410), section 3 of 4: how the quote price for an associate's client is worked out,
// and whether it rises as the associate climbs the ladder (owner, 24 Nov 2026 — MD / ED / whoever may see it).
// Pricing settings (the client-price choice, what each choice would cost us), the product's price build-up and its
// associates table, the commission levels and ladder, and the price on an order.
// Data: the D-410 story on the tutorial database (see _scn410.js). Load scenario-ready.sql before recording.
const { PRODUCT_ID, order, people, STORY } = require('./_scn410');

const section = (d, name) => d.page.getByRole('heading', { name }).first().locator('xpath=..');

module.exports = {
  code: 'F03',
  file: 'F03-Associate-Lifecycle-3-How-The-Quote-Price-Is-Worked-Out.mp4',
  group: 'Group F · Associate life cycle (new rules, D-410)',
  title: 'Associate life cycle 3 of 4: how the quote price is worked out',
  subtitle: 'For the MD and ED: from the supplier\'s price to the associate\'s quote — and why it never rises.',
  minutes: '9',
  restoreDb: true,

  async setup(demo) { return people(demo, ['md']); },

  steps: () => [
    { card: 'title', say: 'This is the third of four videos about one associate\'s year under the new rules. Now we look behind the price: how the quote for an associate\'s client is worked out, and whether it rises as the associate climbs the ladder. This part is for the Managing Director, the Executive Director, and whoever has the price build-up permission.' },
    { ...STORY, say: 'The story again, briefly. One associate, two clients, twenty five orders of Black Galaxy granite in 2026. The ladder runs from two to ten percent, and the client\'s price includes a fixed five percent.' },

    { card: 'chapter', title: 'The rule for every product', subtitle: 'Admin › Pricing › Settings', say: 'Part one. The rule for every product.' },
    { as: 'md', goto: '/pricing/settings', caption: 'How a price is built', say: 'Pricing settings starts with how every price is built. Cost is the supplier\'s price in rupees, divided by our pricing exchange rate, plus other costs to F O B. The minimum price adds our margin. The staff floor adds the team incentive reserve. And the quote adds a small negotiating buffer.', do: async (d) => { await d.ring(d.page.getByText('How a price is built', { exact: false }).first()); await d.wait(4000); } },
    { caption: 'Commission in the client\'s price: three choices', say: 'Then the new setting: associate commission in the client\'s price. Three choices, each with its explanation. The starting rate. A fixed percent, the default, five percent. Or the ladder rate, where the client\'s price includes the associate\'s full current percent.', do: async (d) => { const b = d.page.locator('#associate-price-rule'); await d.scrollTo(b); await d.ring(b.locator('.choice-group').nth(0)); await d.wait(1600); await d.ring(b.locator('.choice-group').nth(1)); await d.wait(1800); await d.ring(b.locator('.choice-group').nth(2)); await d.wait(1800); } },
    { caption: 'What each choice would cost us', say: 'Below, what each choice would have cost us over the last twelve months of this associate\'s orders, with today\'s ladder. The starting rate: the most from our margin. A fixed five percent: much less. The ladder rate: nothing from our margin, but then the client\'s price rises every time the associate moves up. That is why the fixed five percent is the default.', do: async (d) => { const t = d.page.locator('#associate-price-rule table').first(); await d.scrollTo(t); await d.ring(t); await d.wait(4200); } },
    { caption: 'A change needs a reason', say: 'The Managing Director or the Executive Director can change it, only with a reason, using its own Save button. The reason is kept in the audit log. It applies to new prices only: orders with a quotation or a proforma invoice keep theirs.', do: async (d) => { await d.point(d.page.locator('#associate-price-rule input[name="associate_price_reason"]'), 2000); } },

    { card: 'chapter', title: 'One product, step by step', subtitle: 'Products › Black Galaxy Granite (demo)', say: 'Part two. One product, step by step.' },
    { goto: `/products/${PRODUCT_ID}`, caption: 'The price build-up', say: 'On the product page, the price build-up, per square metre, F O B, in US dollars. The supplier\'s price, three thousand two hundred and eighty rupees, divided by eighty four rupees per dollar, gives a cost of thirty nine dollars five cents. With a fifteen percent margin, the minimum price is forty five ninety four. Plus the team incentive reserve: the staff floor, forty six ninety seven. Plus three percent buffer: the staff quote, forty eight thirty eight.', do: async (d) => { const s = section(d, /Price build-up/); await d.scrollTo(s); await d.ring(s.locator('table').first()); await d.wait(6000); } },
    { caption: 'Associates: lowest and quote per ladder level', say: 'Then the associates table: for each ladder level, the associate\'s commission, his lowest price, the client\'s quote, and the part paid from our margin. Look at the quote column: fifty dollars ninety three, at every level. The client always pays the same, whether the associate is at two percent or ten.', do: async (d) => { const t = section(d, /^Associates/).locator('table').first(); await d.scrollTo(t); await d.ring(t); await d.wait(4400); } },
    { card: 'list', kicker: 'The sums behind the table', title: 'Quote 50.93 at every level', items: [
      'Quote = staff floor 46.97 ÷ (1 − 5%) = 49.44, + 3% buffer = USD 50.93.',
      'Lowest at 2%: 46.97 ÷ (1 − 2%) = 47.93 — the associate has 3% room to negotiate.',
      'Lowest from 5% up: 46.97 ÷ (1 − 5%) = 49.44.',
      'At 7% and 10% the extra 2% and 5% are paid from our margin — the client\'s price stays 50.93.',
    ], say: 'Here are the sums. The quote is the staff floor, forty six ninety seven, divided by one minus five percent, which is forty nine forty four, plus the three percent buffer: fifty ninety three. At two percent the associate\'s lowest price is forty seven ninety three, so he has three percent of room to negotiate. From five percent up, his lowest price is forty nine forty four. And at seven and ten percent, the extra two and five percent are paid from our margin, while the client\'s price stays at fifty ninety three.' },

    { card: 'chapter', title: 'The ladder and the levels', subtitle: 'Admin › Pricing › Commission levels', say: 'Part three. The levels and the ladder.' },
    { goto: '/pricing/commission-levels', caption: 'Four levels and the ladder', say: 'The commission levels: two, five, seven and ten percent. And the ladder that moves an associate between them: steps one to five, six to ten, eleven to twenty, and twenty one onwards. The Managing Director or the Executive Director changes these.', do: async (d) => { await d.ring(d.page.locator('table').first()); await d.wait(2600); const h = d.page.getByRole('heading', { name: 'Ladder' }).first(); await d.scrollTo(h); await d.ring(h.locator('xpath=..')); await d.wait(2600); } },

    { card: 'chapter', title: 'The price on the order', say: 'Part four. The price on the order.' },
    { goto: `/orders/${order(13)}`, caption: 'Order 13: the line at the quote price', say: 'Here is order thirteen, at seven percent. Its line is the demo granite at fifty dollars ninety three per square metre: exactly the quote from the product page. Order one, at two percent, has the same price. If a line were priced below the associate\'s lowest price, the order page says so, and it needs the Managing Director\'s approval before the proforma invoice.', do: async (d) => { const s = d.page.locator('#step-products'); await d.click(s.locator(':scope > summary')); await d.wait(900); await d.ring(s); await d.wait(4200); } },
    { card: 'list', kicker: 'Recap', title: 'The quote price in four lines', items: [
      'Built from the supplier\'s price: cost → minimum (margin) → staff floor (team reserve) → quote (+3%).',
      'The client\'s price includes a fixed 5% for associates — the MD\'s setting, changed only with a reason.',
      'So the quote is the same at every ladder level: the client\'s price never rises as the associate climbs.',
      'Above 5%, the rest of the commission comes from our margin — video 4 shows what that does to profit.',
    ], next: 'Video 4 · Profit and the associate\'s profit and loss', say: 'In short: the price is built from the supplier\'s price, through cost, minimum, staff floor and quote. For associates, the client\'s price includes a fixed five percent, set by the Managing Director or the Executive Director. So the quote is the same at every level: the client\'s price never rises as the associate climbs. Above five percent, the rest comes from our margin. Next, in video four: what that does to our profit.' },
  ],
};
