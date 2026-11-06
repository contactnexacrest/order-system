'use strict';
// C07 — "Other": the buyer needs something not listed. The request goes to NexaCrest, is priced
// by hand within 24 hours, and comes back to the associate. Includes the browser-side check.
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.other@email.com';

module.exports = {
  code: 'C07',
  file: 'C07-Associate-Price-Request-Other.mp4',
  group: 'Group C · Associates',
  title: 'When the buyer wants something special',
  subtitle: 'Price requests marked "Other": NexaCrest prices them by hand within 24 hours.',
  minutes: '8',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'quoting', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    s.productId = demo.pricedProduct(s.a.id);
    return s;
  },

  steps: ({ a = PLAN_A, productId = 0 } = {}) => {
    const st = {};
    return [
      { card: 'title', say: 'Sometimes a buyer wants something that is not in the list. A special size, an unusual finish, a one-off project. No problem! In this video you send an Other price request, and we watch NexaCrest price it for you.' },
      { as: 'associate', goto: `/associate/catalogue/${productId}`, caption: 'Get price', say: 'We start on the Get price page of our demo product, exactly like in the last video.', do: async (d) => { await d.point('h1', 1200); } },
      { caption: 'The buyer needs something not listed', say: 'This time we tick the box: the buyer needs something not listed here. Our team prices it within twenty-four hours.', do: async (d) => { await d.check(d.page.locator('input[name="other"]')); await d.ring(d.page.locator('label', { hasText: 'not listed' })); await d.wait(1200); } },
      { caption: 'Notes are required now', say: 'Now the notes become required. Watch what happens if we press Get price without them. The message appears right beside the field: tell our team what the buyer needs, at least ten characters.', do: async (d) => {
        await d.click(d.page.getByRole('button', { name: 'Get price' }));
        await d.wait(900);
        await d.ring(d.page.locator('textarea[name="note"]')); await d.wait(1500);
      } },
      { caption: 'Describe what the buyer needs', say: 'So we describe it clearly: the size, the use, the quantity. The better the description, the faster and sharper the price.', do: async (d) => {
        await d.type(d.page.locator('textarea[name="note"]'), 'Buyer needs 25 mm slabs, flamed and brushed, for a hotel lobby, 1 container', { delay: 30 });
        await d.clickNav(d.page.getByRole('button', { name: 'Get price' }));
      } },
      { caption: 'With our team', say: 'The request is sent. The yellow note explains: this is not a standard specification, so our team sends the price here, and by e-mail, within twenty-four hours, with the exact deadline. And it does not use one of your daily price requests.', do: async (d) => { st.url = new URL(d.page.url()).pathname; await d.point('.alert-warning', 2200); } },

      { card: 'chapter', title: 'NexaCrest prices it', subtitle: 'Within 24 hours.', say: 'Meanwhile, at NexaCrest.' },
      { as: 'md', goto: '/associates/price-requests?show=pending', caption: 'Staff: Price requests → Needs a price', say: 'The request lands in the staff queue, marked needs a manual price, with your note and the deadline.', do: async (d) => { await d.ring(d.page.locator('#price-requests tr').nth(1)); await d.wait(1800); } },
      { caption: 'Price it', say: 'The pricing team opens Price it, and enters the quote price, the lowest price, the unit, and a short note for you.', do: async (d) => {
        await d.click(d.page.locator('#price-requests summary', { hasText: 'Price it' }).first());
        const f = d.page.locator('#price-requests form').first();
        await d.type(f.locator('input[name="quote"]'), '41.50');
        await d.type(f.locator('input[name="lowest"]'), '39.80');
        await d.type(f.locator('input[name="note"]'), 'Priced for 25 mm, flamed and brushed, 1 container', { delay: 25 });
      } },
      { caption: 'Send the price', say: 'And presses Send the price.', do: async (d) => { await d.clickNav(d.page.locator('#price-requests form').first().getByRole('button', { name: 'Send the price' })); } },

      { card: 'chapter', title: 'Your price is here', say: 'Back to you.' },
      { as: 'associate', caption: 'Priced', say: 'You get an e-mail and a notice. Open the request, and there are your two prices, the quote and the lowest you may agree, valid until the date shown.', do: async (d) => { await d.goto(st.url); await d.ring(d.page.locator('.field-grid').first()); await d.wait(2000); } },
      { goto: '/associate/catalogue', caption: 'Priced by our team', say: 'In your list it now shows: priced, valid until a date. From here, it works just like an automatic price.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Your price requests' })); await d.ring(d.page.locator('table.list').last()); await d.wait(1800); } },
      { card: 'list', kicker: 'Recap', title: '"Other" in four steps', items: [
        'Get price → tick "not listed here".',
        'Describe the need (at least 10 characters).',
        'NexaCrest prices it within 24 hours: e-mail + notice.',
        'Does not use your daily price requests.',
      ], next: 'Part 3 · Access levels and upgrades', say: 'That is Other in four steps. Tick the box, describe the need, NexaCrest prices it within a day, and it does not use your daily limit. Next, in part three: the access levels, and how you move up.' },
    ];
  },
};
