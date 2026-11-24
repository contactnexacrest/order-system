'use strict';
// F04 — Associate life cycle (D-410), section 4 of 4: profit and cost on the associate's orders, and the associate's
// profit and loss (owner, 24 Nov 2026 — MD / ED / whoever may see it).
// An order's profit with the commission and the part from our margin (2%, 7%, 10% orders), the Order Profitability
// report, the person statement and register for the associate, the associates report and "Paid from our margin so far".
// Data: the D-410 story on the tutorial database (see _scn410.js). Load scenario-ready.sql before recording.
const { ASSOCIATE, order, people, STORY } = require('./_scn410');

/** Opens Step 12 › 12c and 12d on an order page (closed by default). */
async function openFinancials(d) {
  await d.page.evaluate(() => { for (const id of ['order-financials', 'financials-12c', 'financials-12d']) { const e = document.getElementById(id); if (e) e.open = true; } });
  await d.wait(400);
}

module.exports = {
  code: 'F04',
  file: 'F04-Associate-Lifecycle-4-Profit-And-The-Associates-P-And-L.mp4',
  group: 'Group F · Associate life cycle (new rules, D-410)',
  title: 'Associate life cycle 4 of 4: profit and the associate\'s P&L',
  subtitle: 'For the MD and ED: what each order earns us after commission, and the associate\'s year in figures.',
  minutes: '10',
  restoreDb: true,

  async setup(demo) { return people(demo, ['md']); },

  steps: () => [
    { card: 'title', say: 'This is the last of four videos about one associate\'s year under the new rules. Now the money: what each order earns us after the associate\'s commission, and the associate\'s year in figures. This part is for the Managing Director, the Executive Director, and whoever may see profits.' },
    { ...STORY, say: 'The story, one last time. One associate, two clients, twenty five orders of about sixteen thousand dollars each. The client\'s price includes a fixed five percent. Each order\'s goods cost us one million thirty six thousand four hundred and eighty rupees from the quarry.' },

    { card: 'chapter', title: 'One order\'s profit', subtitle: 'Order 13 · 7%', say: 'Part one. One order\'s profit.' },
    { as: 'md', goto: `/orders/${order(13)}`, caption: 'Step 12c: the net profit', say: 'Order thirteen is at seven percent. On the order page, step twelve, closure and financials, shows the review of profit. The net profit of this order, after the associate\'s commission, is two lakh twenty thousand seven hundred and seventy four rupees.', do: async (d) => { await openFinancials(d); const s = d.page.locator('#financials-12c'); await d.scrollTo(s); await d.ring(s.locator(':scope > summary')); await d.wait(3200); } },
    { caption: 'From profit before to profit after', say: 'Step twelve d shows the sum. Order profit before incentives: three lakh fifteen thousand four hundred and six rupees. Minus the associate commission at seven percent: ninety four thousand six hundred and thirty two rupees, fixed at the rate of the buyer\'s payments. What is left is the profit used for the team incentive.', do: async (d) => { const s = d.page.locator('#financials-12d'); await d.scrollTo(s); await d.ring(s); await d.wait(4600); } },
    { caption: 'The part from our margin', say: 'And the margin box, for the Managing Director and the Executive Director only. Paid from our margin: three hundred and twenty two dollars, or twenty seven thousand and thirty eight rupees. The client\'s price includes five percent; the associate is at seven, so the other two percent comes from our margin.', do: async (d) => { const m = d.page.locator('[data-margin-box]').first(); await d.scrollTo(m); await d.ring(m); await d.wait(4200); } },
    { goto: `/orders/${order(24)}`, caption: 'Order 24 · 10%: five percent from our margin', say: 'Order twenty four is at ten percent. The commission is still an estimate, because the buyer is still paying. Its margin box says: five percent from our margin, eight hundred and five dollars.', do: async (d) => { await openFinancials(d); const m = d.page.locator('[data-margin-box]').first(); await d.scrollTo(m); await d.ring(m); await d.wait(3800); } },
    { goto: `/orders/${order(1)}`, caption: 'Order 1 · 2%: no margin box', say: 'And order one, at two percent. The client\'s price includes five percent but the associate earns two, so nothing comes from our margin, and there is no margin box. Those three percent stay in our profit, or give the associate room to negotiate.', do: async (d) => { await openFinancials(d); const s = d.page.locator('#financials-12d'); await d.scrollTo(s); await d.ring(s); await d.wait(3600); } },

    { card: 'chapter', title: 'All the orders together', subtitle: 'Reports › Order Profitability', say: 'Part two. All the orders together.' },
    { goto: '/reports/order-profitability?date_from=2026-01-01&date_to=2026-10-10&scope=all', caption: 'Order Profitability: January to early October', say: 'The Order Profitability report, here for the orders from January to early October: twenty three orders, all paid in full. At the top, the totals: revenue, cost, profit before commission, the associate commission, and the profit after it, with the overall margin.', do: async (d) => { await d.ring(d.page.locator('main .card, main').first().locator('.stat-row, .summary, table').first()); await d.wait(4200); } },
    { caption: 'One row per order', say: 'Then one row per order: revenue, cost, profit before, the associate commission, and the profit after, with the margin. As the associate climbs from two to seven percent, the commission per order grows from twenty seven thousand to ninety five thousand rupees, and our profit per order goes down by the same amount. That is the price of keeping the client\'s price unchanged.', do: async (d) => { const t = d.page.locator('table', { hasText: 'TEST-SCN-01' }).first(); await d.scrollTo(t); await d.ring(t); await d.wait(4800); } },
    { caption: 'Open orders are estimates', say: 'Orders still being paid can be included as estimates, or left out with locked orders only. Their revenue counts only what the buyer has paid so far, so they look low until the balance arrives.', do: async (d) => { await d.point(d.page.locator('select[name="scope"]'), 2400); } },

    { card: 'chapter', title: 'The associate\'s P&L', subtitle: 'Reports › Incentives & Commission · Associates report', say: 'Part three. The associate\'s profit and loss.' },
    { goto: `/reports/incentives/statement?associate_id=${ASSOCIATE.id}`, caption: 'Person statement: every order of the associate', say: 'Reports, incentives and commission, person statement. Choose the associate, and you see every order of his, in every state: the amount, the tax deducted, the state, the due date, the payout run and the day it was paid.', do: async (d) => { await d.ring(d.page.locator('table').first()); await d.wait(4400); } },
    { goto: '/reports/associates', caption: 'Associates report: the year in figures', say: 'The Associates report shows the financial year from April for each associate: clients, orders in the year, the F O B value of those orders, what was paid in rupees, the tax deducted, and what is still waiting. Below it, the commission waiting, with how many days, and the T D S register.', do: async (d) => { await d.ring(d.page.locator('table').first()); await d.wait(4400); } },
    { goto: `/associates/${ASSOCIATE.id}`, caption: 'Paid from our margin so far', say: 'And on the associate\'s own page: paid from our margin so far, this financial year, and on how many orders. That is the total cost to us of keeping his clients\' price at five percent while he climbed to seven and then ten.', do: async (d) => { const m = d.page.locator('[data-margin-so-far]'); await d.scrollTo(m); await d.ring(m.locator('xpath=..')); await d.wait(4000); } },
    { card: 'list', kicker: 'Recap', title: 'Profit and the associate\'s P&L', items: [
      'Each order: profit before → minus the associate commission (fixed at the buyer\'s payment rate) → profit after.',
      'Above the 5% in the price, the margin box shows what comes from our margin (MD and ED only).',
      'Order Profitability: every order and the totals; open orders are estimates.',
      'The associate\'s year: person statement, associates report, and "Paid from our margin so far".',
    ], say: 'In short: every order shows its profit before, the associate\'s commission, fixed at the rate of the buyer\'s payments, and the profit after. Above the five percent in the price, the margin box shows what comes from our margin. The order profitability report adds it all up. And the associate\'s year is in his person statement, the associates report, and paid from our margin so far on his page. That ends the four videos on one associate\'s year under the new rules.' },
  ],
};
