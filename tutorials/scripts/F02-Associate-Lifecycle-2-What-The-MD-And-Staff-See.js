'use strict';
// F02 — Associate life cycle (D-410), section 2 of 4: the same year seen by the MD and the staff (owner, 24 Nov 2026:
// "how admin / staff / MD / ED can see the above complete operation in the same manner").
// The associate's page (number, ladder, client-price choice, margin so far, every order's ladder step), a payment-advice
// correction (Rev 1), Associates › Settings, Accounts' payout runs, and what the ED and an Export Executive see.
// Data: the D-410 story on the tutorial database (see _scn410.js). Load scenario-ready.sql before recording.
const { ASSOCIATE, people, STORY } = require('./_scn410');

const ordersTable = (d) => d.page.locator('table', { has: d.page.getByRole('columnheader', { name: /Ladder step/ }) }).first();
const orderRow = (d, ref) => ordersTable(d).locator('tr', { hasText: ref }).first();

module.exports = {
  code: 'F02',
  file: 'F02-Associate-Lifecycle-2-What-The-MD-And-Staff-See.mp4',
  group: 'Group F · Associate life cycle (new rules, D-410)',
  title: 'Associate life cycle 2 of 4: what the MD and the staff see',
  subtitle: 'The same year from the inside: the ladder, every step, the payments and who sees what.',
  minutes: '11',
  restoreDb: true,

  async setup(demo) { return people(demo, ['md', 'accounts', 'ed', 'exec']); },

  steps: () => [
    { card: 'title', say: 'This is the second of four videos about one associate\'s year under the new commission rules. In video one we saw the associate\'s portal. Now we look at the same year from the inside: what the Managing Director, Accounts and the other staff see.' },
    { ...STORY, say: 'A quick reminder of the story. One associate in India, two clients, twenty five orders from January to November 2026, each about sixteen thousand US dollars. The ladder is two, five, seven and ten percent. The client\'s price includes a fixed five percent. And an order earns when its goods are worth five lakh rupees or more; a client\'s first order always earns.' },

    { card: 'chapter', title: 'The associate\'s page', subtitle: 'Associates › the associate', say: 'Part one. The associate\'s page, as the Managing Director sees it.' },
    { as: 'md', goto: `/associates/${ASSOCIATE.id}`, caption: 'Associate number A0001', say: 'At the top, the associate\'s name with his associate number: A zero zero zero one. The same number is printed on every payment advice.', do: async (d) => { await d.ring(d.page.locator('[data-associate-no]')); await d.wait(2200); } },
    { caption: 'The commission rule and the ladder', say: 'Further down, the commission block. The rule: the standard ladder. The next order earns ten percent, level four. And the ladder steps so far: twenty two, the top step. The four boxes show the ladder, with the current one marked: now.', do: async (d) => { await d.scrollTo('[data-commission-rule]'); await d.ring(d.page.locator('[data-commission-rule]')); await d.wait(3200); } },
    { caption: 'What counts as a step', say: 'Under the boxes, the rule in one sentence. An order of five lakh rupees or more counts as one step once its advance has cleared. For a letter of credit, once the L C is confirmed. Orders of the same client within fifteen days of the first one are one step. A smaller order earns nothing and does not count, except the client\'s first order. Cancelled orders never count.', do: async (d) => { await d.ring(d.page.locator('[data-step-rule]')); await d.wait(4200); } },
    { caption: 'Commission in the client\'s price', say: 'Then: commission in the client\'s price. This associate uses the company setting, a fixed five percent. Right now: the client\'s price includes five percent, the associate is at ten percent, so five percent comes from our margin. And paid from our margin so far, this financial year: the total, and on how many orders.', do: async (d) => { await d.ring(d.page.locator('[data-price-rule]')); await d.wait(3600); await d.point('[data-margin-so-far]', 2200); } },

    { card: 'chapter', title: 'Every order and its ladder step', say: 'Part two. Every order, and its ladder step.' },
    { caption: 'Orders and commission', say: 'The table Orders and commission lists all twenty five orders, with the stage, the F O B value, whether the buyer paid in full, the level fixed on the order, the ladder step, and the commission with its status.', do: async (d) => { await d.scrollTo(ordersTable(d)); await d.ring(ordersTable(d)); await d.wait(3000); } },
    { caption: 'Orders 24 and 25: steps 21 and 22', say: 'The newest orders are steps twenty one and twenty two, at ten percent. Not earned yet: the buyer is still paying. Their step already counts, because the advance has cleared.', do: async (d) => { await d.ring(orderRow(d, 'TEST-SCN-24')); await d.wait(3000); } },
    { caption: 'Order 18: cancelled, no step', say: 'Order eighteen, cancelled: no level, no step, no commission.', do: async (d) => { await d.scrollTo(orderRow(d, 'TEST-SCN-18')); await d.ring(orderRow(d, 'TEST-SCN-18')); await d.wait(2400); } },
    { caption: 'Order 12: below ₹5,00,000', say: 'Order twelve, the small order: does not count, below five lakh rupees. It also earns nothing, because it was not the client\'s first order.', do: async (d) => { await d.scrollTo(orderRow(d, 'TEST-SCN-12')); await d.ring(orderRow(d, 'TEST-SCN-12')); await d.wait(2800); } },
    { caption: 'Orders 8 and 9: two clients, two steps', say: 'Orders eight and nine came five days apart, but from two different clients. So each is its own step: seven and eight.', do: async (d) => { await d.scrollTo(orderRow(d, 'TEST-SCN-09')); await d.ring(orderRow(d, 'TEST-SCN-08')); await d.wait(1600); await d.ring(orderRow(d, 'TEST-SCN-09')); await d.wait(1800); } },
    { caption: 'Order 2: same client within 15 days', say: 'And order two: same step as order one, same client within fifteen days. It earns its commission, but it does not move the associate up the ladder.', do: async (d) => { await d.scrollTo(orderRow(d, 'TEST-SCN-02')); await d.ring(orderRow(d, 'TEST-SCN-02')); await d.wait(3000); } },

    { card: 'chapter', title: 'Payment advices', subtitle: 'One per bank transfer', say: 'Part three. Payment advices.' },
    { caption: 'One advice per bank transfer', say: 'Every bank transfer to the associate has its payment advice, made by the app the moment Accounts marks the payment as paid. The number restarts every financial year: twenty five slash for February and March, twenty six slash from April.', do: async (d) => { const l = d.page.locator('[data-payment-advices]'); await d.scrollTo(l); await d.ring(l); await d.wait(3400); } },
    { caption: 'A correction: Rev 1, with a reason', say: 'An advice is frozen once it is made. If something on it was wrong, the Managing Director, the Executive Director, or someone given the permission Manage associate rules can correct it, with a reason. Let us say the October advice needs correcting. Type the reason, and press Make Rev.', do: async (d) => {
      const f = d.page.locator('[data-payment-advices] form[action$="/payment-advices/9/revise"]');
      await d.type(f.locator('input[name="reason"]'), 'Demo: the bank confirmed a different value date');
      await d.clickNav(f.getByRole('button', { name: 'Make Rev' }));
    } },
    { caption: 'Rev 1 made, the old one kept', say: 'Done. The advice now has a Rev one, with the same number and the reason. The earlier one is kept, marked superseded. The associate sees the corrected advice in his portal.', do: async (d) => { const l = d.page.locator('[data-payment-advices]'); await d.scrollTo(l); await d.ring(l); await d.wait(3200); } },

    { card: 'chapter', title: 'Associates › Settings', subtitle: 'Minimum value and the 15 days', say: 'Part four. The settings behind the ladder.' },
    { goto: '/associates/ladder', caption: 'Minimum order value and 15 days', say: 'Under Associates, Settings, the Managing Director or the Executive Director sets the minimum order value, five lakh rupees, and how many days count as the same step for one client: fifteen, anywhere from seven to forty five. Changing either needs a reason, which is kept in the audit log.', do: async (d) => { await d.ring(d.page.locator('input[name="associate_group_days"]')); await d.wait(2400); await d.point('[data-step-rule]', 2400); } },

    { card: 'chapter', title: 'Accounts: paying the commission', say: 'Part five. Accounts, paying the commission.' },
    { as: 'accounts', goto: '/incentives/payouts?month=2026-11', caption: 'November: two commissions due', say: 'Accounts opens Incentive Payouts. In November, two commissions are due, orders twenty two and twenty three, to be paid on the thirtieth. That is exactly what the associate sees: will be paid to you on the thirtieth of November.', do: async (d) => { await d.ring(d.page.locator('table').first()); await d.wait(3000); } },
    { goto: '/incentives/payouts?month=2026-03', caption: 'March: the bank transfer made', say: 'An earlier month, March, after Accounts marked it paid. Bank transfers made this run: one transfer, with its date, the bank reference, the amount in rupees, and its payment advice. One transfer, one advice, however many orders it covers.', do: async (d) => { const l = d.page.locator('[data-bank-transfers]'); await d.scrollTo(l); await d.ring(l); await d.wait(3400); } },

    { card: 'chapter', title: 'Who sees what', say: 'Part six. Who sees what.' },
    { as: 'ed', goto: `/associates/${ASSOCIATE.id}`, caption: 'Executive Director: sees and changes', say: 'The Executive Director sees the same commission block: the rule, the ladder, what the client\'s price includes, and the margin. And, like the Managing Director, can change it. Anyone else can be given the permission Manage associate rules in Admin, Roles.', do: async (d) => { await d.scrollTo('[data-commission-rule]'); await d.ring(d.page.locator('[data-commission-rule]')); await d.wait(3000); } },
    { as: 'exec', goto: `/associates/${ASSOCIATE.id}`, caption: 'Export Executive: set by the MD / ED', say: 'An Export Executive opening the same associate sees only: commission, set by the M D or the E D. No rule, no ladder, no margin.', do: async (d) => { await d.scrollTo('[data-commission-rule-hidden]'); await d.ring(d.page.locator('[data-commission-rule-hidden]')); await d.wait(2600); } },
    { card: 'list', kicker: 'Recap', title: 'The same year, from the inside', items: [
      'The associate\'s page: number A0001, the ladder, what the client\'s price includes, and the margin so far.',
      'Every order shows its ladder step — or why it is no step (same client within 15 days, below ₹5,00,000, cancelled).',
      'One payment advice per bank transfer; the MD or ED corrects it (Rev 1, with a reason).',
      'MD and ED change the rules (or anyone given "Manage associate rules"); other staff see "Set by the MD / ED."',
    ], next: 'Video 3 · How the quote price is worked out', say: 'In short: the associate\'s page shows the associate number, the ladder, what the client\'s price includes, and how much has come from our margin. Every order shows its ladder step, or why it is no step. One payment advice per bank transfer, corrected by the MD or the ED with a reason. And only the MD, the ED, or someone given the permission changes the rules. Next, in video three: how the quote price is worked out.' },
  ],
};
