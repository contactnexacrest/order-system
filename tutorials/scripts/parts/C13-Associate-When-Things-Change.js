'use strict';
// C13 — When things change: a client moved to another associate (MD only, with a reason the
// associate sees), a leaked code blocked and replaced, a suspended account, and an ended one.
const { ACTORS, nav, associateSetup, PLAN_A } = require('./_common');

const EMAIL = 'associate.changes@email.com';
const OTHER = 'associate.other.demo@email.com';
const BUYER = 'TEST Demo Marble Importer GmbH';

module.exports = {
  code: 'C13',
  file: 'C13-Associate-Client-Moved-Suspended-Code.mp4',
  group: 'Group C · Associates',
  title: 'When things change',
  subtitle: 'A client moved, a code blocked, an account suspended or ended: what you see, and why.',
  minutes: '10',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    demo.associate({ email: OTHER, name: 'Demo Associate Two', level: 'starter' });
    s.buyer = demo.buyerWithOrders({ associateId: s.a.id, company: BUYER, country: 'Germany', email: 'buyer.demo2@email.com', orders: [] });
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Business changes. Sometimes a client is moved, a code leaks, or an account is paused. This video shows each situation, so nothing ever surprises you, and you always know why.' },

    { card: 'chapter', title: 'A client is moved', subtitle: 'Only the Managing Director can do it, always with a reason.', say: 'Part one. When a client is moved.' },
    { as: 'associate', goto: '/associate/clients', caption: 'Your client today', say: 'Here is our demo associate, with one client, a marble importer in Germany.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /Registered clients/ })); await d.ring(d.page.locator('tr', { hasText: BUYER }).first()); await d.wait(1500); } },
    { as: 'md', goto: `/associates/${a.id}#clients`, caption: 'Staff: Clients introduced', say: 'Once a client is linked, only the Managing Director can change that link. For example, if the buyer proves they were introduced by someone else. The Managing Director picks: remove the link, or move to another associate, and must write a reason. You see that reason.', do: async (d) => { await d.scrollTo('#clients'); await d.ring(d.page.locator('#clients tr', { hasText: BUYER })); await d.wait(1800); } },
    { caption: 'Move, with a reason', say: 'Here, the client is moved to another associate, with the reason written down.', do: async (d) => {
      const row = d.page.locator('#clients tr', { hasText: BUYER });
      await d.select(row.locator('select[name="move_to"]'), { label: 'Move to Demo Associate Two' });
      await d.type(row.locator('input[name="reason"]'), 'Buyer showed an earlier introduction by another associate', { delay: 20 });
      await d.clickNav(row.getByRole('button', { name: 'Change' }));
    } },
    { as: 'associate', goto: '/associate', caption: 'You are told at once', say: 'You are told at once, with a notice and an e-mail that include the reason. The client no longer appears in your list. If you disagree, reply through Messages, with any proof you have.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Notices' })); await d.ring(d.page.locator('h2:has-text("Notices") + table')); await d.wait(2000); } },

    { card: 'chapter', title: 'A code is blocked', subtitle: 'For example, after it leaked.', say: 'Part two. A blocked code.' },
    { as: 'md', goto: `/associates/${a.id}#portal`, caption: 'Staff: set a new code, block the old', say: 'Imagine your personal code was posted publicly, and strangers use it. NexaCrest can give you a new code, and block the old one, with a reason.', do: async (d) => {
      await d.scrollTo('#portal');
      await d.type(d.page.locator('#portal input[name="readable"]'), 'DEMONEW');
      await d.check(d.page.locator('#portal input[name="block_old"]'));
      await d.type(d.page.locator('#portal input[name="block_reason"]'), 'Code was posted on a public website', { delay: 20 });
      await d.clickNav(d.page.locator('#portal').getByRole('button', { name: 'Set code' }));
    } },
    { as: 'associate', goto: '/associate/clients#code', caption: 'Your new code, the old one blocked', say: 'In your portal, under My codes, the new code is current, and the old one says: blocked by NexaCrest. A blocked code no longer brings buyers to anyone. Normal old codes, from a change you made yourself, keep working.', do: async (d) => { await d.scrollTo('#code'); await d.ring(d.page.locator('#code table.list')); await d.wait(2200); } },

    { card: 'chapter', title: 'Suspended', subtitle: 'You can look, but not act.', say: 'Part three. A suspended account.' },
    { as: 'md', goto: `/associates/${a.id}#profile`, caption: 'Staff: status Suspended', say: 'Sometimes an account is suspended for a while, for example while a question is cleared up. The Managing Director changes the status, with a reason.', do: async (d) => {
      await d.scrollTo('#profile form[action*="/status"]');
      await d.select(d.page.locator('#profile select[name="status"]'), 'suspended');
      await d.type(d.page.locator('#profile input[name="reason"]'), 'Paused while a client question is cleared up', { delay: 20 });
      await d.clickNav(d.page.locator('#profile').getByRole('button', { name: 'Change status' }));
    } },
    { as: 'associate', goto: '/associate', caption: 'The suspended banner', say: 'You can still log in and see your clients, orders and commission. But a banner explains: you cannot invite clients or change your details until the suspension ends. Commission already earned is not lost.', do: async (d) => { await d.point('[data-associate-suspended]', 2500); } },

    { card: 'chapter', title: 'Ended', subtitle: 'When the partnership stops.', say: 'Part four. When the partnership ends.' },
    { as: 'md', goto: `/associates/${a.id}#profile`, caption: 'Staff: status Ended', say: 'If the agreement ends, the status becomes Ended.', do: async (d) => {
      await d.scrollTo('#profile form[action*="/status"]');
      await d.select(d.page.locator('#profile select[name="status"]'), 'ended');
      await d.type(d.page.locator('#profile input[name="reason"]'), 'Agreement ended by mutual decision', { delay: 20 });
      await d.clickNav(d.page.locator('#profile').getByRole('button', { name: 'Change status' }));
    } },
    { as: 'associate', goto: '/associate/logout', caption: 'Portal access ends', say: 'The portal then refuses new logins, with a clear message to contact NexaCrest. Commission you had already earned is settled under your agreement.', do: async (d) => {
      await d.goto('/associate/login');
      await d.type(d.page.getByLabel('E-mail'), EMAIL);
      await d.type(d.page.getByLabel('Password'), 'TutorialDemo#2026', { delay: 35 });
      await d.clickNav(d.page.getByRole('button', { name: 'Continue' }));
      await d.point(d.page.locator('.alert').first(), 1800);
    } },
    { card: 'list', kicker: 'Recap', title: 'Changes, without surprises', items: [
      'Client moved: MD only, with a reason you see at once.',
      'Code blocked: you get a new one; the leaked one stops working.',
      'Suspended: you can look, not act; earned commission stays.',
      'Ended: no new logins; earned commission settled per the agreement.',
    ], next: 'C14 · What you never see', say: 'To recap. A client can only be moved by the Managing Director, with a reason you see. A leaked code is blocked and replaced. Suspended means you can look but not act. And Ended closes the portal, while earned commission is settled under your agreement. Next, C14: what associates never see, and why. See you there!' },
  ],
};
