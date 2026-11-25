'use strict';
// C04 — The Starter level, page by page, with one demo buyer and one order.
// Updated 19 Nov 2026 (script only, not recorded): D-292 commission statuses ("Not earned yet — buyer still
// paying" / "Earned — will be paid on <date>" / "Paid on <date> (ref)" / "On hold — a buyer claim …"), the
// 4-step "How it works" on the Commission page, payout on the last day of the month; D-330 wording on My
// clients ("Your client until", "Last day for orders that earn commission"), Orders ("Order values are not
// shown for your account"), the locked page ("This page is not open for your account") and the tax heading
// ("Tax certificates (Form 16A) …"). Selectors changed: the 10-day column and the tax-certificates heading.
// Updated 25 Nov 2026 (D-426): the "Last day for orders that earn commission" column is gone; "Your client" shows
// "Yours while your agreement runs" once the client has ordered; the intro says a client with an order stays yours.
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.starter@email.com';

module.exports = {
  code: 'C04',
  file: 'C04-Associate-Starter-Tour.mp4',
  group: 'Group C · Associates',
  title: 'The Starter level, page by page',
  subtitle: 'Your clients, their orders, your commission: what you see at the Starter level, and what comes next.',
  minutes: '9',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    s.buyer = demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Demo Stone Buyer BV', country: 'Netherlands', email: 'buyer.starter@email.com',
      orders: [{ reference: 'TEST-TUT-0401', lines: [{ description: 'Granite slabs, polished, 20 mm', qty: 400, unit: 'm2', price: 32 }] }] });
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Hello! Most associates start at the Starter level. In this video we take a slow walk through every Starter page, with a real-looking demo buyer and order, so you know exactly what each number means.' },
    { as: 'associate', goto: '/associate', caption: 'Home at the Starter level', say: 'Here is the home page of a Starter associate. Look at the menu: Home, My clients, Orders, Commission, Messages and Profile. No Catalogue at this level. We come back to that at the end.', do: async (d) => { await d.point('nav.topbar-nav', 1800); } },
    { caption: 'One client, one order', say: 'Our demo associate has one client, a stone buyer in the Netherlands, and one order. The order shows up under Latest orders, with its stage and its commission status.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Latest orders' })); await d.ring(d.page.locator('table.list').first()); await d.wait(1500); } },

    { card: 'chapter', title: 'My clients', subtitle: 'Who is yours, and for how long.', say: 'Part one. My clients.' },
    { goto: '/associate/clients', caption: 'How a buyer becomes your client', say: 'The text at the top explains the rule. Your buyer registers with NexaCrest, through your buyer registration link or your quotation link with your personal code, and confirms that you introduced them. Once NexaCrest accepts, the company is your client. Once they have placed an order, they stay your client while your agreement runs. If they place no order within six months, the registration ends, and you can invite them again.', do: async (d) => { await d.point('p.muted', 2000); } },
    { caption: 'Registered clients', say: 'In the table: the client, the country, how they came to us, the date they registered, the column Your client, and the order numbers of that client. A client who has ordered shows: yours while your agreement runs. A buyer who has signed up but not ordered yet shows the day the sign-up ends.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /Registered clients/ })); await d.ring(d.page.locator('h2:has-text("Registered clients") + .table-scroll table')); await d.wait(1800); } },
    { caption: 'Your record', say: 'This table is your record that each buyer was introduced by you, with the date. NexaCrest keeps the same record.', do: async (d) => { await d.point(d.page.locator('[data-client-status]').first(), 1500); } },

    { card: 'chapter', title: 'Orders', subtitle: 'Follow every order without chasing anyone.', say: 'Part two. Orders.' },
    { goto: '/associate/orders', caption: 'Orders of my clients', say: 'All orders of your clients, newest first. The order number, the client, the products, the stage, whether the buyer has paid in full, and your commission.', do: async (d) => { await d.ring(d.page.locator('table.list')); await d.wait(1800); } },
    { caption: 'No order value at Starter', say: 'Notice the note under the table: order values are not shown for your account. At Starter, you follow the progress, not the money of the order.', do: async (d) => { await d.point(d.page.locator('p.small.muted').last(), 1500); } },
    { caption: 'One order', say: 'Click an order to open it. You see when it was ordered, the products, the stage, and under What happened, every step NexaCrest has recorded, in plain words.', do: async (d) => {
      await d.clickNav(d.page.locator('table.list a').first());
      await d.point('h1', 800); await d.scrollTo(d.page.getByRole('heading', { name: 'What happened' }));
    } },
    { caption: 'Not earned yet, earned, paid', say: 'The commission cell moves through simple statuses. Not earned yet, buyer still paying, while the order is running. Earned, will be paid on a date, the moment the buyer has paid in full. From then on the amount never changes. And Paid on a date, with the bank reference, once it is in your bank. Never on the advance alone: only after full payment. If a buyer has an open claim on the order, it says: On hold, a buyer claim on this order is open.', do: async (d) => { await d.scrollTo('table.kv'); await d.ring(d.page.locator('table.kv tr', { hasText: 'Commission' }).first()); await d.wait(1500); } },

    { card: 'chapter', title: 'Commission and TDS', subtitle: 'Your money page.', say: 'Part three. The Commission page.' },
    { goto: '/associate/commission', caption: 'My commission', say: 'On the Commission page: first How it works, in four steps, ending with our payment day, normally the last day of the month. Then a short note on how we set your commission, and the totals: earned, paid and waiting for the recent months, in the order\'s currency. Then one line per commission, with the month, the order, the client, the amount and the status.', do: async (d) => { await d.point('h1', 800); await d.ring(d.page.locator('table.list').first()); await d.wait(1500); } },
    { caption: 'Your invoice per line', say: 'For each line waiting to be paid, you upload your invoice right here: the invoice number and the file. NexaCrest pays against your invoice, only into the bank account we have checked. Video C05 shows the whole journey.', do: async (d) => { await d.ring(d.page.locator('th', { hasText: 'Your invoice' })); await d.wait(1500); } },
    { caption: 'Tax certificates', say: 'Below, your tax certificates. By law, NexaCrest deducts tax from your commission, called T D S, and every three months your Form 16A appears here. Video C05 explains T D S and G S T in detail.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /Tax certificates/ })); await d.wait(1000); } },

    { card: 'chapter', title: 'What Starter does not show', subtitle: 'And how you move up.', say: 'Part four. What is not there yet.' },
    { goto: '/associate/catalogue', caption: 'A locked page', say: 'What happens if you open a page your level does not include, say from an old bookmark? You simply see: this page is not open for your account. Nothing breaks, and there is a button to message NexaCrest if you think you need it.', do: async (d) => { await d.point('h1', 1800); } },
    { card: 'list', kicker: 'Moving up', title: 'From Starter to Quoting', items: [
      'Once a few of your orders are completed and fully paid…',
      '…the Managing Director is asked: ready for the Quoting level?',
      'Quoting adds: order value, commission %, catalogue, get a price, price requests, statements.',
    ], say: 'So how do you move up? Once a few of your orders are completed and fully paid, the Managing Director gets a suggestion on the dashboard: ready for the Quoting level? Quoting adds the order value, your commission percentage, the catalogue, get a price, price requests, and commission statements. Part three of video C04 covers all levels.' },
    { card: 'list', kicker: 'Recap', title: 'Starter in a nutshell', items: [
      'My clients: who you introduced, when, and whether they are yours while your agreement runs.',
      'Orders: stage, paid in full, commission status; no order value.',
      'Commission: lines, your invoice per line, TDS certificates.',
      'Locked pages say so; good results unlock the Quoting level.',
    ], next: 'Part 2 · Inviting clients', say: 'That is the Starter level. My clients, Orders, Commission, Messages and Profile. Next, in part two, the most important skill of all: inviting a client.' },
  ],
};
