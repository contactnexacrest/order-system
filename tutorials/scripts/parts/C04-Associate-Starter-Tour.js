'use strict';
// C04 — The Starter level, page by page, with one demo buyer and one order.
const { nav, associateSetup, PLAN_A } = require('./_common');

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
    s.buyer = demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Demo Stone Buyer BV', country: 'Netherlands', email: 'buyer.demo@email.com',
      orders: [{ reference: 'TEST-TUT-0401', lines: [{ description: 'Granite slabs, polished, 20 mm', qty: 400, unit: 'm2', price: 32 }] }] });
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Hello! Most associates start at the Starter level. In this video we take a slow walk through every Starter page, with a real-looking demo buyer and order, so you know exactly what each number means.' },
    { as: 'associate', goto: '/associate', caption: 'Home at the Starter level', say: 'Here is the home page of a Starter associate. Look at the menu: Home, My clients, Orders, Commission, Messages and Profile. No Catalogue at this level. We come back to that at the end.', do: async (d) => { await d.point('nav.topbar-nav', 1800); } },
    { caption: 'One client, one order', say: 'Our demo associate has one client, a stone buyer in the Netherlands, and one order. The order shows up under Latest orders, with its stage and its commission status.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Latest orders' })); await d.ring(d.page.locator('table.list').first()); await d.wait(1500); } },

    { card: 'chapter', title: 'My clients', subtitle: 'Who is yours, and for how long.', say: 'Part one. My clients.' },
    { goto: '/associate/clients', caption: 'How a buyer becomes your client', say: 'The text at the top explains the rule. Your buyer registers with NexaCrest, through your link or your code, and confirms that you introduced them. Once NexaCrest accepts, the company is your client, however they contact NexaCrest later.', do: async (d) => { await d.point('p.muted', 2000); } },
    { caption: 'Registered clients', say: 'In the table: the client, the country, how they came to you, the date they registered, and the date they are protected until. Protected means: during that time, any order from that company counts as yours.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /Registered clients/ })); await d.ring(d.page.locator('h2:has-text("Registered clients") + table')); await d.wait(1800); } },
    { caption: 'The 10-day window', say: 'And this column, the 10-day window. Commission is earned on a client\'s first orders: everything they order within ten days of their first purchase order. After that window, repeat orders earn no commission. So the first order is the big moment!', do: async (d) => { await d.ring(d.page.locator('th', { hasText: '10-day window' })); await d.wait(1500); } },
    { caption: 'Registration certificate', say: 'Each client has a registration certificate. It is your proof that this buyer was introduced by you, with the date. Keep it handy.', do: async (d) => { await d.point(d.page.getByRole('link', { name: 'Registration certificate' }).first(), 1500); } },

    { card: 'chapter', title: 'Orders', subtitle: 'Follow every order without chasing anyone.', say: 'Part two. Orders.' },
    { goto: '/associate/orders', caption: 'Orders of my clients', say: 'All orders of your clients, newest first. The order number, the client, the products, the stage, whether the buyer has paid in full, and your commission.', do: async (d) => { await d.ring(d.page.locator('table.list')); await d.wait(1800); } },
    { caption: 'No order value at Starter', say: 'Notice the note under the table: the order value is not shown at your access level. At Starter, you follow the progress, not the money of the order.', do: async (d) => { await d.point(d.page.locator('p.small.muted').last(), 1500); } },
    { caption: 'One order', say: 'Click an order to open it. You see when it was ordered, the products, the stage, and under What happened, every step NexaCrest has recorded, in plain words.', do: async (d) => {
      await d.clickNav(d.page.locator('table.list a').first());
      await d.point('h1', 800); await d.scrollTo(d.page.getByRole('heading', { name: 'What happened' }));
    } },
    { caption: 'Commission: expected, earned, paid', say: 'The commission cell moves through simple states. Expected, while the order is running. Earned, once the buyer has paid in full. And Paid, once it is in your bank. Never on the advance alone: only after full payment.', do: async (d) => { await d.scrollTo('table.kv'); await d.ring(d.page.locator('table.kv tr', { hasText: 'Commission' })); await d.wait(1500); } },

    { card: 'chapter', title: 'Commission and TDS', subtitle: 'Your money page.', say: 'Part three. The Commission page.' },
    { goto: '/associate/commission', caption: 'My commission', say: 'On the Commission page: earned, paid and waiting, for the recent months. Then one line per commission, with the month it is paid in, the order, the client, the amount and the status.', do: async (d) => { await d.point('h1', 800); await d.ring(d.page.locator('table.list').first()); await d.wait(1500); } },
    { caption: 'Your invoice per line', say: 'For each line waiting to be paid, you upload your invoice right here: the invoice number and the file. NexaCrest pays against your invoice, only to your verified bank account. Video C09 shows the whole journey.', do: async (d) => { await d.ring(d.page.locator('th', { hasText: 'Your invoice' })); await d.wait(1500); } },
    { caption: 'TDS certificates', say: 'Below, your TDS certificates. NexaCrest deducts tax at source as the law requires, and your Form 16A appears here each quarter. Video C11 explains TDS and GST in detail.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /TDS certificates/ })); await d.wait(1000); } },

    { card: 'chapter', title: 'What Starter does not show', subtitle: 'And how you move up.', say: 'Part four. What is not there yet.' },
    { goto: '/associate/catalogue', caption: 'A locked page', say: 'What happens if you open a page your level does not include, say from an old bookmark? You simply see: not available at your access level. Nothing breaks, and there is a button to message NexaCrest if you think you need it.', do: async (d) => { await d.point('h1', 1800); } },
    { card: 'list', kicker: 'Moving up', title: 'From Starter to Quoting', items: [
      'Once a few of your orders are completed and fully paid…',
      '…the Managing Director is asked: ready for the Quoting level?',
      'Quoting adds: order value, commission %, catalogue, get a price, price requests, statements.',
    ], say: 'So how do you move up? Once a few of your orders are completed and fully paid, the Managing Director gets a suggestion on the dashboard: ready for the Quoting level? Quoting adds the order value, your commission percentage, the catalogue, get a price, price requests, and commission statements. Video C08 covers all levels.' },
    { card: 'list', kicker: 'Recap', title: 'Starter in a nutshell', items: [
      'My clients: who is yours, protected until when, the 10-day window.',
      'Orders: stage, paid in full, commission state; no order value.',
      'Commission: lines, your invoice per line, TDS certificates.',
      'Locked pages say so; good results unlock the Quoting level.',
    ], next: 'C05 · Inviting clients', say: 'That is the Starter level. My clients, Orders, Commission, Messages and Profile. Next, in C05, the most important skill of all: inviting a client. See you there!' },
  ],
};
