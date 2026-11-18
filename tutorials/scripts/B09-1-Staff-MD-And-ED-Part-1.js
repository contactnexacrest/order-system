'use strict';
// B09 Part 1 — Managing Director and Executive Director: the daily approvals (Group B, Staff). Owner request
// 18 Nov 2026. Uses the B02 set-up (scripts/_b02.js): a new order by the Export Executive on "Established
// Buyer — Post-BL" terms with one line below the lowest price. Off camera the Export Executive asks the MD to
// approve the line. On camera the MD works the Approvals page: the line price (price-approval page, with a
// reason), the payment terms, and the quotation review; then the dashboard, cockpit and sales targets.
// Part 2 (B09-2) covers the settings only the MD / ED change.
// Written 18 Nov 2026 from views/approvals, orders/price_approval.php, orders/show.php, reviews.

const B = require('./_b02');
const A = require('./_a02');
const { ACTORS } = require('./_overview');

let demo;
let order = 0;
let ref = '';
const at = (hash = '') => `/orders/${order}${hash}`;
const row = (d) => d.page.locator('table.approvals-table tbody tr').filter({ hasText: ref });

module.exports = {
  code: 'B09-1',
  file: 'B09-1-Staff-MD-And-ED-Part-1.mp4',
  group: 'Group B · Staff',
  title: 'Managing Director and Executive Director · Part 1',
  subtitle: 'The daily approvals: prices, payment terms and documents; the dashboard and the cockpit.',
  minutes: '4',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    const ctx = await B.setup(demo);
    order = ctx.order;
    ref = demo.sql(`SELECT order_reference FROM orders WHERE id = ${order}`);
    // The Export Executive asked the MD to approve line 1 (below the lowest price).
    A.php(demo, `$line = (int) $pdo->query('SELECT id FROM order_products WHERE order_id = ${order} ORDER BY id LIMIT 1')->fetchColumn();
      echo App\\Services\\OrderLinePricing::requestApproval(${order}, $line, $uid('export.executive@email.com')) ?? 'ok';`);
    ctx.actors.md = { ...ACTORS.md, cookies: await demo.staffCookies('md') };
    return ctx;
  },

  steps: ({ order: o = 0 } = {}) => { order = o || order; return [
    { card: 'title', say: 'This video is for the Managing Director and the Executive Director. You run the company: you see everything, and the important decisions wait for you. Part one is your daily work: the approvals. Part two covers the settings that only you change.' },
    { card: 'list', kicker: 'Your roles', title: 'Company Management · MD and ED', items: [
      'Both see every order, every amount and every department.',
      'Final approvals: prices below the lowest price, payment terms, documents, e-mails, amendments.',
      'Only the MD: pricing settings, incentives, divisions, product specifications, associate bank checks.',
      'Neither approves their own work: self-approval rules ask someone else.',
    ], say: 'Your roles. Both of you see every order, every amount and every department. You give the final approvals: prices below the lowest price, payment terms, documents, buyer e-mails, amendments and cancellations. A few settings are the Managing Director\'s alone: pricing, incentives, divisions, product specifications and associates\' bank checks. And neither of you approves your own work: the self-approval rules send it to someone else.' },

    // ---------- 1. What is waiting ----------
    { card: 'chapter', title: 'What is waiting for you', subtitle: 'Dashboard and Approvals.', say: 'Chapter one. Your morning.' },
    { as: 'md', goto: '/', caption: 'Dashboard · My Approvals', say: 'We are the Managing Director. The dashboard starts with My Approvals: prices waiting for you, payment terms, bank details to verify, other approvals, your pending document reviews, e-mails waiting for the second approval, and amendments. The menu shows the same total on Approvals.', do: async (d) => { await d.point(d.page.getByRole('heading', { name: 'My Approvals' }), 1000); await d.point(d.page.locator('.stat-tile').first(), 1500); } },
    { goto: '/approvals', caption: 'Approvals', say: 'Open Approvals: everything waiting for you in one list, oldest first, with tabs for each kind. Here, a line price on a new order, and the same order\'s payment terms.', do: async (d) => { await d.point('h1', 900); await d.point(row(d).first(), 1800); } },

    // ---------- 2. A price below the lowest ----------
    { card: 'chapter', title: 'A price below the lowest', subtitle: 'Approvals › Prices.', say: 'Chapter two. A price only you can approve.' },
    { goto: '/approvals?tab=prices', caption: 'Approvals › Prices', say: 'On the Prices tab: the Export Executive agreed a price below the lowest price for line one of this order, and asked you to approve it. Open it.', do: async (d) => { const r = row(d).first(); await d.point(r, 1200); await d.clickNav(r.getByRole('link').last()); } },
    { caption: 'The price check', say: 'This page shows the whole picture: the agreed price, our quote price, the lowest price staff may agree, and the minimum price below which nobody may sell. It also shows the cost and the margin left. Below the minimum, the app would have refused the line outright.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table, .kv-grid').first(), 2500); } },
    { caption: 'Approve with a reason', say: 'You decide. Approve or refuse, always with a reason, which is kept with the line. This buyer is a repeat customer, and the margin is still fine: approve. If the price or the options change later, before the quotation is approved, you are asked again.', do: async (d) => {
      await d.type(d.page.locator('textarea[name="reason"]'), 'Repeat buyer, first order on these terms: agreed to match their target price.', { delay: 25 });
      await d.clickNav(d.page.getByRole('button', { name: 'Approve' }));
      await d.point('.alert', 1500);
    } },

    // ---------- 3. Payment terms ----------
    { card: 'chapter', title: 'Payment terms', subtitle: 'Approvals › Payment terms.', say: 'Chapter three. Credit for the buyer.' },
    { goto: '/approvals?tab=payment_terms', caption: 'Approvals › Payment terms', say: 'The same order is on payment terms with no advance: the buyer pays after the bill of lading. That is credit, so it needs a director\'s approval before the proforma invoice. Approve terms opens the order at the yellow box.', do: async (d) => { const r = row(d).first(); await d.point(r, 1200); await d.clickNav(r.getByRole('link', { name: /Approve terms/ })); } },
    { caption: 'Approve payment terms', say: 'Check the buyer and the order value, and press Approve payment terms. The Finance Director may approve terms too.', do: async (d) => { const b = d.page.getByRole('button', { name: 'Approve payment terms' }).first(); await d.scrollTo(b); await d.point(b, 1200); await d.clickNav(b); await d.point('.alert', 1500); } },

    // ---------- 4. Documents ----------
    { card: 'chapter', title: 'Reviewing documents', subtitle: 'Approvals › Documents.', say: 'Chapter four. Nothing reaches a buyer unchecked.', do: async () => {
      A.php(demo, `$docForReview(${order}, 'QT', $uid('export.executive@email.com'), $uid('md@email.com')); echo 1;`);
    } },
    { goto: '/reviews', caption: 'Approvals › Documents', say: 'With the price and the terms approved, the Export Executive generated the quotation and chose you as reviewer. It waits under Approvals, Documents. Open the P D F, check it, and approve it. If something is wrong, reject it with a reason, and the executive corrects it.', do: async (d) => {
      const r = d.page.locator('tr').filter({ hasText: 'QT' }).first();
      await d.point(r, 1500);
      await d.clickNav(r.getByRole('button', { name: 'Approve' }).first());
      await d.point('.alert', 1500);
    } },
    { goto: at(), caption: 'Stage 1 passed', say: 'Back on the order, stage one is done, and the prices are now fixed by the approved quotation. From now on, a change of price or products needs an amendment, which also comes to you.', do: async (d) => { await d.point('.process-step[data-stage="1"]', 1200); await d.point('.process-step[data-stage="2"]', 1200); } },

    // ---------- 5. Overview ----------
    { card: 'chapter', title: 'The whole company', subtitle: 'Cockpit · Sales targets · Reports.', say: 'Chapter five. Keeping an eye on everything.' },
    { goto: '/cockpit', caption: 'Management cockpit', say: 'The management cockpit: the sales target for the quarter at the top, then open work per department, what is overdue, what is escalated, and orders without an owner. Escalated tasks list who should act.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('table').filter({ hasText: 'Open tasks' }).first(), 1800); } },
    { goto: '/reports', caption: 'Reports', say: 'And Reports, where you see every section: sales, payments and receivables, disputes, freight and suppliers, people, incentives, finance, and management, including order profitability. Other roles see only the reports their role allows.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.getByRole('heading', { name: /Management/ }), 1500); } },

    { card: 'list', kicker: 'Recap', title: 'MD and ED · Part 1', items: [
      'Start with My Approvals on the dashboard, then the Approvals page.',
      'Prices below the lowest price: approve or refuse with a reason.',
      'Payment terms with credit: approve on the order; documents: approve or reject.',
      'The cockpit and Reports show the whole company.',
    ], next: 'B09 Part 2 · Settings only you change', say: 'Let us recap part one. Start every day with My Approvals on the dashboard, and the Approvals page. Approve or refuse prices below the lowest price, always with a reason. Approve payment terms that give the buyer credit, and approve or reject documents before they reach a buyer. And the cockpit and the reports show you the whole company. In part two: the settings that only you change.' },
  ]; },
};
