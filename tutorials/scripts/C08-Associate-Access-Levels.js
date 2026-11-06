'use strict';
// C08 — Access levels: Starter, Quoting, Full and Custom; the level settings; how the MD moves
// an associate up (with a reason), and what changes on the associate's screen.
const { nav, associateSetup, PLAN_A } = require('./_common');

const EMAIL = 'associate.levels@email.com';

module.exports = {
  code: 'C08',
  file: 'C08-Associate-Access-Levels-And-Upgrade.mp4',
  group: 'Group C · Associates',
  title: 'Access levels: Starter, Quoting, Full, Custom',
  subtitle: 'What each level shows, who decides, and how you move up.',
  minutes: '9',

  async setup(demo) {
    return associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Why does one associate see a Catalogue tab, and another does not? It is all about access levels. In this video we look at the four levels, who decides, and we watch an associate move up, live.' },
    { card: 'list', kicker: 'The levels', title: 'Four levels', items: [
      'Starter: clients, orders and stage, commission, links, messages, TDS certificates.',
      'Quoting: + order value, commission %, catalogue, get price, "Other", statements.',
      'Full: + documents of your orders and order amounts in notices.',
      'Custom: the Managing Director ticks features one by one.',
    ], say: 'There are four levels. Starter: your clients, their orders and stage, your commission, registration links, messages and TDS certificates. Quoting adds the order value, your commission percentage, the catalogue, get a price, Other requests, and statements. Full adds the documents of your orders, and order amounts in your notices. And Custom lets the Managing Director tick features one by one, for special cases.' },

    { card: 'chapter', title: 'Starter today', say: 'Part one. Our demo associate today.' },
    { as: 'associate', goto: '/associate', caption: 'A Starter menu', say: 'Here is our demo associate at Starter. The menu: Home, My clients, Orders, Commission, Messages, Profile. No Catalogue.', do: async (d) => { await d.point('nav.topbar-nav', 1800); } },
    { goto: '/associate/orders', caption: 'No order value', say: 'And on Orders, there is no order value column. Remember these two things, so we can spot the change in a minute.', do: async (d) => { await d.ring(d.page.locator('table.list tr').first()); await d.wait(1500); } },

    { card: 'chapter', title: 'Who decides, and how', subtitle: 'Only the Managing Director changes a level.', say: 'Part two. On the NexaCrest side.' },
    { as: 'md', goto: '/associates/access-levels', caption: 'Staff: Associate access levels', say: 'The Managing Director has a page called Associate access levels. It is a grid: every feature down the side, every level across the top. A tick means that level shows that feature.', do: async (d) => { await d.ring(d.page.locator('form[data-access-levels] table').first()); await d.wait(2200); } },
    { caption: 'Changes need a reason', say: 'If the grid is changed, a reason is required, and every change is kept in the history below. There is even a button to put the recommended defaults back.', do: async (d) => { await d.scrollTo(d.page.getByRole('button', { name: 'Save level settings' })); await d.ring(d.page.locator('input[name="reason"]').first()); await d.wait(1500); } },
    { goto: `/associates/${a.id}#portal-access`, caption: 'One associate: Portal access', say: 'Now the record of one associate. The Portal access section shows the current level and the track record. When a Starter associate has a few completed and fully paid orders, a blue note appears here: ready for the Quoting level? Nothing upgrades by itself. The Managing Director decides, or presses Not yet.', do: async (d) => { await d.scrollTo('#portal-access'); await d.wait(1500); } },
    { caption: 'Move up to Quoting', say: 'Let us move our associate up. Choose Quoting, write the reason, and press Save portal access.', do: async (d) => {
      await d.click(d.page.locator('#portal-access input[name="access_level"][value="quoting"]'));
      await d.type(d.page.locator('#portal-access input[name="reason"]'), '3 orders completed and paid, may now quote', { delay: 25 });
      await d.clickNav(d.page.locator('#portal-access').getByRole('button', { name: 'Save portal access' }));
    } },
    { caption: 'Recorded with the reason', say: 'Saved, with who changed it, when, from which level to which, and why.', do: async (d) => { await d.scrollTo('#portal-access'); await d.ring(d.page.locator('#portal-access table.list').first()); await d.wait(1800); } },

    { card: 'chapter', title: 'What changes for you', say: 'Part three. What the associate sees now.' },
    { as: 'associate', goto: '/associate', caption: 'Catalogue appears', say: 'Back in the portal, open any page, and there it is: the Catalogue tab.', do: async (d) => { await d.point(nav(d, 'Catalogue'), 1800); } },
    { goto: '/associate/orders', caption: 'Order value appears', say: 'And on Orders, the order value column is now visible. Same data, more detail.', do: async (d) => { await d.ring(d.page.locator('table.list tr').first()); await d.wait(1500); } },
    { goto: '/associate/commission', caption: 'Statements appear', say: 'On Commission, the statement buttons appear: a CSV file and a printable statement. Video C10 shows them.', do: async (d) => { const b = d.page.getByRole('link', { name: 'Statement (CSV)' }); if (await b.count()) await d.point(b, 1500); } },

    { card: 'chapter', title: 'Custom', subtitle: 'For special cases.', say: 'Part four. The Custom level.' },
    { as: 'md', goto: `/associates/${a.id}#portal-access`, caption: 'Custom: tick features one by one', say: 'For a special case, the Managing Director chooses Custom, and ticks exactly the features this one associate should see. A Custom associate starts from Starter, for anything not ticked.', do: async (d) => {
      await d.scrollTo('#portal-access');
      await d.click(d.page.locator('#portal-access summary', { hasText: 'Custom' }));
      await d.ring(d.page.locator('#portal-access details')); await d.wait(2000);
    } },
    { card: 'list', kicker: 'Good to know', title: 'Levels, in short', items: [
      'Your level only changes what you see, never what you earn.',
      'Only the Managing Director changes it, always with a reason.',
      'Opening a locked page simply says "Not available at your access level".',
      'Good results, completed and paid orders, are the way up.',
    ], next: 'C09 · Your commission, start to finish', say: 'In short: your level changes what you see, never what you earn. Only the Managing Director changes it, always with a reason. A locked page simply tells you so. And the way up is good results: completed, fully paid orders. Next, C09: your commission, from start to finish. See you there!' },
  ],
};
