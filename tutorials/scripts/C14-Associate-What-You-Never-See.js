'use strict';
// C14 — What associates never see, and why: costs, margins, suppliers, other associates' clients,
// staff screens and finance reports. Shown by trying, so the associate sees the door is closed.
const { nav, associateSetup, PLAN_A } = require('./_common');

const EMAIL = 'associate.privacy@email.com';

module.exports = {
  code: 'C14',
  file: 'C14-Associate-What-You-Never-See.mp4',
  group: 'Group C · Associates',
  title: 'What you never see, and why',
  subtitle: 'Costs, suppliers, other associates and staff screens stay private: by design.',
  minutes: '8',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'full', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' }, { withMd: false });
    s.productId = demo.pricedProduct(s.a.id);
    return s;
  },

  steps: ({ productId = 0 } = {}) => [
    { card: 'title', say: 'Trust works both ways. NexaCrest protects your data, and it protects its own, and its buyers\' and suppliers\'. In this video we look at what an associate never sees, and why that is good for everyone, including you.' },
    { card: 'list', kicker: 'Never in your portal', title: 'Private by design', items: [
      'Supplier names, quarry locations, costs, margins and price formulas.',
      'Other associates, their clients, their codes and their commission.',
      'NexaCrest\'s internal finance reports and bank details.',
      'Staff screens: orders, documents in progress, approvals.',
    ], say: 'Here is the list. You never see supplier names, quarry locations, costs, margins or price formulas. You never see other associates, their clients, codes or commission, and they never see yours. You never see NexaCrest\'s internal finance reports or bank details. And you never enter staff screens. Let us prove a few of these.' },

    { card: 'chapter', title: 'Prices without secrets', say: 'Part one. Prices without secrets.' },
    { as: 'associate', goto: '/associate/catalogue', caption: 'No supplier, no cost', say: 'The catalogue says it in plain words: no prices, supplier names or quarry locations are shown here.', do: async (d) => { await d.point(d.page.locator('p.small.muted').first(), 2000); } },
    { goto: `/associate/catalogue/${productId}`, caption: 'Quote and lowest only', say: 'And a price request only ever shows two numbers, the quote and the lowest you may agree, plus what changed the price. Never the cost, the margin, or the formula behind it. That keeps NexaCrest\'s supplier relationships safe, and those are what make your quotes competitive.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Get price' })); await d.ring(d.page.locator('.field-grid').first()); await d.wait(2000); } },
    { caption: 'For your eyes only', say: 'That is also why the page says: prices are for you only, do not pass this page on. There is no download or export of prices.', do: async (d) => { await d.point(d.page.locator('p.small.muted').last(), 1500); } },

    { card: 'chapter', title: 'Closed doors', subtitle: 'Staff pages simply do not open.', say: 'Part two. Closed doors.' },
    { goto: '/orders', caption: 'Staff pages ask for a staff login', say: 'What if an associate types the address of a staff page, like slash orders? The system asks for a staff login. Your associate login does not open it. Staff, associates and clients each have their own, separate door.', do: async (d) => { await d.point('h1, h2', 2000); } },
    { goto: '/associate/orders/999999', caption: 'Only your own orders', say: 'And if you try to open an order that is not your client\'s, it is simply not found. You only ever see your own clients and their orders.', do: async (d) => { await d.wait(1800); } },

    { card: 'chapter', title: 'Your data is private too', say: 'Part three. The other way round.' },
    { card: 'list', kicker: 'Your privacy', title: 'What NexaCrest protects for you', items: [
      'Your bank account is masked: only the last digits are shown, even to most staff.',
      'Aadhaar: only the last 4 digits are kept.',
      'Every bank change is checked by two people.',
      'Your clients are linked to you; only the MD can change that, with a reason.',
    ], say: 'And the same care protects you. Your bank account is masked, only the last digits are shown. For Aadhaar, only the last four digits are ever stored. Every bank change is checked by two people. And your clients are linked to you; only the Managing Director can change that, always with a reason you see.' },
    { card: 'list', kicker: 'Recap', title: 'Private by design', items: [
      'You see your clients, their orders, your prices and your commission.',
      'Never costs, suppliers, other associates or staff screens.',
      'Your own data is protected the same way.',
    ], next: 'C15 · Questions and answers', say: 'So: you see everything you need, your clients, their orders, your prices and your commission, and nothing that is not yours. And your own data is protected the same way. Last video in this series: C15, questions and answers. See you there!' },
  ],
};
