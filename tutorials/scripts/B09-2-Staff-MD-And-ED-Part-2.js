'use strict';
// B09 Part 2 — Managing Director and Executive Director: the settings only they change (Group B, Staff).
// Owner request 18 Nov 2026. A tour as the MD on the app's own Sample Data plus one priced product:
// Global pricing settings (margin, other costs, negotiation room, exchange rate), category price rules,
// commission levels, team incentives, users and departments, self-approval rules, field protection,
// company settings, e-mail testing mode, divisions and sample data. Narration follows the pages' own text
// (views/pricing/settings.php, pricing/commission_levels.php, incentives/settings.php, admin/*, settings/*).

const { ACTORS, cleanStage } = require('./_overview');

const legend = (d, t) => d.page.locator('legend').filter({ hasText: t }).first();
const h = (d, t) => d.page.getByRole('heading', { name: t }).first();

module.exports = {
  code: 'B09-2',
  file: 'B09-2-Staff-MD-And-ED-Part-2.mp4',
  group: 'Group B · Staff',
  title: 'Managing Director and Executive Director · Part 2',
  subtitle: 'Pricing, commission and incentives, people and rights, and the company settings.',
  minutes: '5',
  restoreDb: true,

  async setup(demo) {
    cleanStage(demo);
    return { actors: { md: { ...ACTORS.md, cookies: await demo.staffCookies('md') } } };
  },

  steps: () => [
    { card: 'title', say: 'Welcome to part two of the video for the Managing Director and the Executive Director. Here are the settings that steer the whole company: how prices are worked out, how associates and the team are rewarded, who may do what, and the company details every document prints. Most of these only the Managing Director changes; the Executive Director sees them.' },

    // ---------- 1. Pricing ----------
    { card: 'chapter', title: 'How prices are worked out', subtitle: 'Products › Pricing › Global pricing settings.', say: 'Chapter one. Your prices.' },
    { as: 'md', goto: '/pricing/settings', caption: 'Global pricing settings', say: 'Global pricing settings apply to every product, unless a category or a product changes them. Every change needs a reason, and is kept in the audit log.', do: async (d) => { await d.point('h1', 1500); } },
    { caption: 'Margin and costs', say: 'Margin and costs. The margin is on the selling price, not a markup: twenty percent on a cost of one thousand dollars gives a minimum price of one thousand two hundred and fifty. Nobody can sell below the minimum. Other costs cover packing, transport to the port, the C H A, inspection, documents and bank charges. And the room to negotiate: the quote price is the lowest price plus this percentage, and the team may agree anything between the two.', do: async (d) => { const l = legend(d, 'Margin and Costs'); await d.scrollTo(l); await d.point(l, 1200); await d.point(l.locator('xpath=..'), 3000); } },
    { caption: 'The exchange rate', say: 'The exchange rate turns the suppliers\' rupee prices into dollars. Automatic fetches the market rate every day and takes off a small cushion, so our prices stay safe if the rupee gets stronger; manual uses the rate you type. A daily move of more than a set percentage waits for your approval. Quotations and proforma invoices already issued never change.', do: async (d) => { const l = legend(d, 'Exchange Rate'); await d.scrollTo(l); await d.point(l, 1000); await d.point(l.locator('xpath=..'), 3000); } },
    { caption: 'Who can do what', say: 'At the bottom, a summary of who may do what with prices: who sees costs, who sees the lowest price, and who may approve a price below it. Only you.', do: async (d) => { const x = h(d, 'Who Can Do What'); await d.scrollTo(x); await d.point(x, 2000); } },
    { goto: '/products/price-rules', caption: 'Category price rules', say: 'Category price rules change the settings for one category: for example, a different margin for monuments, or thickness rules for slabs. A single product can also override a value; it then shows a yellow badge in the catalogue.', do: async (d) => { await d.point('h1', 1200); await d.point(d.page.locator('table, form').first(), 1800); } },

    // ---------- 2. Rewards ----------
    { card: 'chapter', title: 'Commission and incentives', subtitle: 'Associates · Team.', say: 'Chapter two. Rewarding people.' },
    { goto: '/pricing/commission-levels', caption: 'Associate commission levels', say: 'Associate commission levels: each level is a percentage of the order value, or a fixed amount. The level is fixed on each order when its proforma invoice is issued, and the commission is earned only when the buyer has paid in full.', do: async (d) => { await d.point('h1', 1200); await d.point(d.page.locator('table').first(), 1800); } },
    { goto: '/incentives/settings', caption: 'Team incentive settings', say: 'Team incentives: the program switch, the incentive roles and their usual shares, the caps, and each person\'s incentive role. When an order is finished, you lock its team incentive in Step 12 d, and it is paid in the monthly payout run.', do: async (d) => { await d.point('h1', 1000); for (const t of ['Program', 'Incentive roles', 'Caps']) { const l = legend(d, t); if (await l.count()) await d.point(l, 900); } } },

    // ---------- 3. People and rights ----------
    { card: 'chapter', title: 'People and their rights', subtitle: 'Users & roles.', say: 'Chapter three. Who may do what.' },
    { goto: '/users', caption: 'Users & roles › Users', say: 'Users lists every staff login. Add a user with their name, role e-mail and role; they set their own password, and every login asks for a code by e-mail. Deactivate someone who leaves; their history stays.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1800); } },
    { goto: '/admin/departments', caption: 'Users & roles › Departments', say: 'Departments and designations decide what each person sees and does. Add a person to a department at the right level, and the rights follow. Preview the app as this shows exactly what that designation sees.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('button', { name: 'Preview the app as this' }).first(), 1800); } },
    { goto: '/admin/self-approval', caption: 'Users & roles › Self-approval rules', say: 'Self-approval rules: nobody approves their own document, their own payment terms or their own e-mail. Here you decide the few exceptions, by default only for the Managing Director and the Executive Director, and every self-approval is logged.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table, form').first(), 1800); } },
    { goto: '/admin/field-protection', caption: 'Users & roles › Field protection', say: 'Field protection locks sensitive settings, such as the bank account printed on our invoices, against accidental change. Protecting or unprotecting a field needs a second person to agree.', do: async (d) => { await d.point('h1', 1800); } },

    // ---------- 4. Company ----------
    { card: 'chapter', title: 'The company', subtitle: 'Settings.', say: 'Chapter four. The company\'s own details.' },
    { goto: '/admin/setup-checklist', caption: 'Settings › Setup checklist', say: 'The setup checklist lists what a new installation still needs: company and bank details, the e-mail server, backups, and three ticks that are yours: you have checked the pricing settings, the commission levels, and, with the chartered accountant, the tax settings.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table').first(), 1800); } },
    { goto: '/admin/email-settings', caption: 'Settings › E-mail', say: 'E-mail: every e-mail the app sends, and to whom. Testing mode holds every e-mail to buyers and suppliers, so the team can practise on real screens without anyone outside receiving anything. Switch it off when you go live.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.getByText(/Testing mode/).first(), 1800); } },
    { goto: '/admin/divisions', caption: 'Settings › Divisions', say: 'Divisions: today, Export. Domestic trade, merchant trade, and import and distribution are prepared, and stay planned until you activate them, each with its own checklist.', do: async (d) => { await d.point('h1', 900); await d.point('table', 1500); } },
    { goto: '/sample-data', caption: 'Settings › Sample data', say: 'And Sample data loads practice orders, marked SAMPLE, at every stage, so new staff can learn on real screens. Clear them again with one click; real orders are never touched.', do: async (d) => { await d.point('h1', 1800); } },

    { card: 'list', kicker: 'Recap', title: 'MD and ED · Part 2', items: [
      'Global pricing settings: margin, other costs, room to negotiate, the exchange rate.',
      'Commission levels for associates; team incentive roles, shares and caps.',
      'Users, departments, self-approval rules and field protection.',
      'Setup checklist, e-mail testing mode, divisions and sample data.',
    ], next: 'B10 · Admin and System Administrator', say: 'Let us recap part two. The global pricing settings decide the margin, the other costs, the room to negotiate and the exchange rate. Commission levels reward associates; team incentive roles, shares and caps reward the team. Users, departments, self-approval rules and field protection decide who may do what. And the setup checklist, e-mail testing mode, divisions and sample data keep the company ready. Next: the Admin and the System Administrator.' },
  ],
};
