'use strict';
// F01 — Associate life cycle (D-410), section 1 of 4: what the associate sees (owner, 24 Nov 2026).
// Owner's list for section 1: the price for the product · the price on the order · the commission on each order
// (or why not) · how the ladder rises · where the associate sees orders and commission after each order · why the
// ladder count is not shown · the complete report · anything else the associate can see.
// Data: the D-410 story on the tutorial database (see _scn410.js). Load scenario-ready.sql before recording.
const { ASSOCIATE, PRODUCT_ID, order, people, STORY } = require('./_scn410');

module.exports = {
  code: 'F01',
  file: 'F01-Associate-Lifecycle-1-What-The-Associate-Sees.mp4',
  group: 'Group F · Associate life cycle (new rules, D-410)',
  title: 'Associate life cycle 1 of 4: what the associate sees',
  subtitle: 'The price, every order, the commission and the payments, from the associate\'s own portal.',
  minutes: '10',
  restoreDb: true,

  async setup(demo) { return people(demo, ['associate']); },

  steps: () => [
    { card: 'title', say: 'This is the first of four videos that follow one associate through a whole year of orders, under the new commission rules. In this first video we stay in the associate\'s own portal, and see everything the associate sees.' },
    { ...STORY, say: 'Here is the story. One associate, based in India, brought in two clients. Between January and November 2026 those clients placed twenty five orders, each about sixteen thousand US dollars of granite. The ladder is two percent for steps one to five, five percent for steps six to ten, seven percent for steps eleven to twenty, and ten percent from step twenty one. The client\'s price always includes a fixed five percent. And an order earns commission when its goods are worth five lakh rupees or more. A client\'s first order always earns, whatever its size.' },

    { card: 'chapter', title: 'The home page', say: 'Part one. The home page.' },
    { as: 'associate', goto: '/associate', caption: 'Earned, waiting, paid', say: 'After logging in, the associate sees three totals of commission: earned in the last twelve months, waiting to be paid, and paid in the last twelve months. Never a total of orders or business: that stays private to NexaCrest.', do: async (d) => { await d.ring(d.page.locator('.stat-row').first()); await d.wait(2400); } },
    { caption: 'Latest orders, each with its status', say: 'Below, the latest orders. Each one shows the commission in US dollars with its percentage, and a coloured status. We will look at each status in a moment.', do: async (d) => { await d.ring(d.page.locator('table').first()); await d.wait(2200); } },

    { card: 'chapter', title: 'The price to quote', subtitle: 'Catalogue › Get price', say: 'Part two. The price the associate quotes to the client.' },
    { goto: '/associate/catalogue', caption: 'Catalogue: products to offer', say: 'In the Catalogue the associate sees the products he may offer. No supplier names, no costs. To quote, he presses Get price.', do: async (d) => { await d.ring(d.page.locator('table').first()); await d.wait(1500); } },
    { caption: 'Choose the specification', say: 'He chooses the specification the buyer wants. Here we keep the standard choices: premium grade, twenty millimetres, natural polish.', do: async (d) => { await d.clickNav(d.page.getByRole('link', { name: 'Get price' }).first()); await d.wait(1500); } },
    { caption: 'The quote and the lowest price', say: 'And the app answers at once: the price to quote, and the lowest price he may agree. The quote already includes his commission, so he never adds anything to it. Because the client\'s price always includes the same five percent, this quote is the same at every step of the ladder. As the associate climbs, his client\'s price does not go up.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Get price' }).first()); await d.wait(800); await d.ring(d.page.locator('main .card, main').first()); await d.wait(3000); } },

    { card: 'chapter', title: 'Every order and its commission', say: 'Part three. Every order, and the commission on it.' },
    { goto: '/associate/orders', caption: 'Orders of my clients', say: 'The Orders page lists every order of the clients he introduced. The text at the top says the rule in plain words: every order earns, while the agreement is active, when the goods are worth five lakh rupees or more, and a client\'s first order always earns.', do: async (d) => { await d.ring(d.page.locator('[data-orders-intro]')); await d.wait(2600); } },
    { caption: 'Three statuses', say: 'The legend explains the three statuses. Orange: not earned yet, the buyer is still paying. Blue: to be paid, on the date shown. Green: paid, with the date and the bank reference.', do: async (d) => { await d.ring(d.page.locator('[data-badge-legend]')); await d.wait(2600); } },
    { caption: 'Orders 24 and 25: not earned yet', say: 'The two newest orders, twenty four and twenty five, are at ten percent, but the buyer is still paying. So they say: not earned yet, buyer still paying. No date yet.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-24' }).first()); await d.wait(2600); } },
    { caption: 'Orders 22 and 23: will be paid', say: 'Orders twenty two and twenty three are paid in full by the buyer. Blue: will be paid to you on the thirtieth of November. The rupee amount is fixed, at the rate the bank used for the buyer\'s payment, and it will not change.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-23' }).first()); await d.wait(2800); } },
    { caption: 'Order 18: cancelled', say: 'Order eighteen was cancelled. No commission, order cancelled.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-18' }).first()); await d.wait(2000); } },
    { caption: 'Order 12: below ₹5,00,000', say: 'Order twelve was a small second order of the second client, about two lakh twenty seven thousand rupees. It earns nothing, and the line says exactly why: below five lakh rupees.', do: async (d) => { await d.scrollTo(d.page.locator('tr', { hasText: 'TEST-SCN-12' }).first()); await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-12' }).first()); await d.wait(2600); } },
    { caption: 'Order 9: the second client\'s first order', say: 'Order nine was the second client\'s first order. It earns, at the percentage of that time: five percent.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-09' }).first()); await d.wait(2200); } },
    { caption: 'Orders 1 and 2: same client, five days apart', say: 'And right at the bottom: orders one and two, the same client, five days apart. Both earn, and both were paid. Under the old rules only the first ten days of a client earned; now every order earns.', do: async (d) => { await d.scrollTo(d.page.locator('tr', { hasText: 'TEST-SCN-02' }).first()); await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-01' }).first()); await d.wait(2800); } },

    { card: 'chapter', title: 'One order in detail', say: 'Part four. One order in detail.' },
    { goto: `/associate/orders/${order(13)}`, caption: 'Order 13: the commission statement', say: 'Here is order thirteen. The value of the goods, F O B, without freight. The commission: seven percent, final since the proforma invoice was sent. Worked out on the goods only, line by line, and fixed in rupees on the day the buyer finished paying.', do: async (d) => { const h = d.page.getByRole('heading', { name: 'Commission statement' }); await d.scrollTo(h); await d.ring(d.page.locator('table').nth(1)); await d.wait(3200); } },
    { caption: 'What happened, step by step', say: 'Below, what happened: the proforma invoice fixed the rate, the buyer paid in full, and then the payment, with the bank reference.', do: async (d) => { const h = d.page.getByRole('heading', { name: 'What happened' }); await d.scrollTo(h); await d.ring(h); await d.wait(2600); } },

    { card: 'chapter', title: 'Climbing the ladder', say: 'Part five. How the associate climbs the ladder.' },
    { card: 'list', kicker: 'What the associate sees', title: 'The %, never the step count', items: [
      'Orders 1–6: 2% · orders 7–12: 5% · orders 13–23: 7% · orders 24–25: 10%.',
      'Each order keeps the % fixed when its Proforma Invoice was sent.',
      'The ladder steps themselves are not shown to the associate — only his %, order by order.',
      'Two orders of the same client within 15 days are one step; small and cancelled orders are no step.',
    ], say: 'How does he see the ladder rise? By the percentage on each order. Two percent on the first orders, five from order seven, seven from order thirteen, and ten from order twenty four. Each order keeps the percentage fixed at its proforma invoice. The step count itself is not shown in the portal: that is a NexaCrest rule, so an associate never sees a count of business. He sees his percentage, order by order. Two orders of the same client within fifteen days count as one step, and small or cancelled orders are no step. That is why order two did not move him up.' },
    { goto: '/associate/commission', caption: 'My commission: today 10%', say: 'On the Commission page, the top line says his percentage today: ten percent of the F O B value, for his next order. And how it works, in four short lines.', do: async (d) => { await d.ring(d.page.locator('[data-commission-headline]')); await d.wait(2200); await d.point('[data-how-it-works]', 2200); } },

    { card: 'chapter', title: 'The complete report', subtitle: 'Commission, payments, payment advice', say: 'Part six. The complete report.' },
    { caption: 'Earned, paid, waiting', say: 'Earned, paid and waiting, for the last twelve months.', do: async (d) => { await d.point(d.page.locator('p').filter({ hasText: 'Earned in the last' }).first(), 2000); } },
    { caption: 'One line per commission, tax shown', say: 'Then one line per order: the month, the order, his percentage, the amount, and the status. A paid line also shows the amount before tax, the tax deducted, T D S at two percent, and the amount paid to him, with the bank reference.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-SCN-21' }).first()); await d.wait(3200); } },
    { caption: 'Statement: CSV or printable', say: 'The full statement can be downloaded as a spreadsheet, or printed.', do: async (d) => { await d.point(d.page.getByRole('link', { name: 'Printable statement' }).first(), 1800); } },
    { caption: 'Payments to you', say: 'And Payments to you: one line per bank transfer, with its payment advice, in P D F or Word. One advice covers every order paid in that transfer, with the bank reference, so the associate can match it to his bank statement.', do: async (d) => { const p = d.page.locator('[data-payments-to-you]'); await d.scrollTo(p); await d.ring(p); await d.wait(3500); } },

    { card: 'chapter', title: 'Anything else?', say: 'Part seven. A few more things.' },
    { goto: '/associate/profile', caption: 'Associate number A0001', say: 'His profile shows his associate number, A zero zero zero one. The same number is printed on every payment advice.', do: async (d) => { await d.ring(d.page.locator('[data-associate-no]')); await d.wait(2400); } },
    { card: 'list', kicker: 'Recap', title: 'What the associate sees', items: [
      'Get price: one quote with the commission included — it does not rise as he climbs the ladder.',
      'Orders: every order, with its % and a clear status — or the reason it earns nothing.',
      'Commission: today\'s %, every payout with tax, statements, and one payment advice per bank transfer.',
      'Never a count or total of orders or business, and never the ladder steps.',
    ], next: 'Video 2 · The same year, seen by the MD and the staff', say: 'In short: the associate gets one quote with the commission already inside, and it does not rise as he climbs. Every order shows its percentage and a clear status, or the reason it earns nothing. The commission page shows today\'s percentage, every payout with the tax, the statements, and one payment advice per bank transfer. Next, in video two: the same year, seen by the Managing Director and the staff.' },
  ],
};
