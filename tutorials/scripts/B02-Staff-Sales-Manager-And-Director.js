'use strict';
// B02 — The Sales/Export Manager and the Sales Director: assigning orders and owners, payment-terms
// approval, prices below the lowest price (asked of the MD), document reviews, the self-approval
// rules, and for the Director the e-mail send approval (Level 2), the management cockpit, the staff
// productivity report and the agreements register. Standard template. Source of truth:
// docs/APPLICATION_HANDBOOK.md 4.2, 4.3, 4.6, 5.3, 6.8, 6.12, 7.2, 3.10; SOP 01, 02, 13, 21, 23, 27.
// Neither role has CAFIN, costs or the MD's price build-up: none is shown. Set-up: scripts/_b02.js.
// Updated 13 Nov 2026 (docs audit C; not recorded, recording on hold): D-310 Approvals page (Documents,
// E-mails, Prices tabs) instead of My Reviews / Email Approvals in the sidebar; D-320 menu paths; D-280
// e-mail wording; D-342 prices fixed by the approved Quotation; D-361 demo product with an active supplier.

const demo = require('../lib/demo');
const B = require('./_b02');
const A = require('./_a02');

let order = 0;
let handover = 0;
const at = (hash = '') => `/orders/${order}${hash}`;

/** The e-mailed 6-digit code, typed in on camera (the dev page shows it; it is hidden from the video). */
async function typeLoginCode(d) {
  const banner = d.page.locator('.alert-dev strong').first();
  if (!(await banner.count())) return;
  const code = ((await banner.textContent()) || '').trim();
  await d.point('input[name="code"]', 2500);
  await d.type('input[name="code"]', code, { delay: 160 });
  await d.wait(1200);
  await d.clickNav(d.page.getByRole('button', { name: 'Verify' }));
}

module.exports = {
  code: 'B02',
  file: 'B02-Staff-Sales-Manager-And-Director.mp4',
  group: 'Group B · Staff, by role',
  title: 'The Sales Manager and the Sales Director',
  subtitle: 'Assigning orders, approving terms and documents, prices below the floor, e-mail approval and the cockpit.',
  minutes: '13',
  restoreDb: true,

  async setup(d) {
    const ctx = await B.setup(d);
    order = ctx.order; handover = ctx.handover;
    return ctx;
  },

  steps: () => [
    { card: 'title', say: 'This video is for the Sales and Export Manager and the Sales Director: the people who check the Export Executive\'s work, assign the orders, and approve what needs a second pair of eyes. We look at assigning orders and owners, approving payment terms, prices below the lowest price, document reviews, and the rules about approving your own work. Then, for the Sales Director, the e-mail send approval, the management cockpit, and the staff reports.' },
    { card: 'list', kicker: 'Your role', title: 'Manager and Director', items: [
      'Both: everything the Export Executive does, on every order.',
      'Both: assign orders and owners; approve payment terms and documents.',
      'Director only: approve e-mails to buyers, the cockpit, staff reports, agreements.',
      'Neither: approves a price below the lowest price, or sees our costs or the CAFIN.',
    ], say: 'Here are the two roles in four lines. Both can do everything the Export Executive does, and on every order, not only the ones assigned to them. Both assign orders and department owners, and approve payment terms and documents. The Sales Director also approves e-mails before they go to a buyer, and sees the management cockpit, the staff productivity report and the agreements register. Neither of you approves a price below the lowest price: that is for the Managing Director. And neither of you sees our costs or the C A FIN.' },

    // ---------- 1. Logging in ----------
    { card: 'chapter', title: 'Logging in and your screens', subtitle: 'Password, the e-mailed code, then every order.', say: 'Chapter one. Logging in, and your screens.' },
    { as: 'salesmgrLogin', goto: '/login', caption: 'Staff login', say: 'Sign in with your work e-mail and your password. We log in as the Sales Manager.', do: async (d) => {
      await d.type(d.page.getByLabel('Email'), 'sales.manager@email.com');
      await d.type(d.page.getByLabel('Password'), demo.STAFF.salesmgr.password, { delay: 40 });
      // The dev server prints the code on the page (APP_ENV=local only): hidden before the page draws.
      await d.page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
        const st = document.createElement('style'); st.textContent = '.alert-dev{display:none!important}'; document.head.appendChild(st);
      }));
      await d.clickNav(d.page.getByRole('button', { name: 'Sign in' }));
    } },
    { caption: 'The e-mailed code', say: 'Then the second step: the six digit code we e-mail you at that moment. Type it, and press Verify. It works once, for a few minutes. Never share it: nobody from NexaCrest will ever ask you for it.', do: async (d) => { await typeLoginCode(d); } },
    { caption: 'Dashboard · My Approvals', say: 'On the Dashboard, My Approvals counts what waits for you: prices, payment terms, documents to review, e-mails and amendments. The same counts are on Approvals, in the left menu. Bottom left: you are working as Sales and Export Manager, in the Sales and Export department.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'My Approvals' })); await d.point(d.page.getByRole('heading', { name: 'My Approvals' }), 1500); await d.point('.dept-badge', 2000); } },
    { goto: '/orders', caption: 'Every order, with who has it', say: 'Orders shows every order in the company, not only yours, because you may see all orders. Each card names the people assigned to it, so you see at once who is working on what.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('.tag-navy').first(), 2200); } },

    // ---------- 2. Assigning ----------
    { card: 'chapter', title: 'Assigning orders and owners', subtitle: 'Assignees, and one owner per department.', say: 'Chapter two. Assigning orders and owners.' },
    { goto: `/orders/${handover}`, caption: 'Assigned to', say: 'Open an order. Under the buyer\'s name: who it is assigned to. Here, the Managing Director. Most people see only the orders they are assigned to, so this decides who can work on it.', do: async (d) => { await d.point(d.page.locator('p.muted').filter({ hasText: 'Assigned to' }).first(), 2500); } },
    { caption: 'Hand it over: add first', say: 'To hand the order to the Export Executive, add them first. Choose them under Add assignee, and press Add. An order always keeps at least one assignee, so you add the new person before you remove the old one.', do: async (d) => {
      const add = d.page.locator(`form[action="/orders/${handover}/assignees"]`);
      await d.select(add.locator('select'), { label: 'Export Executive (Export Executive)' });
      await d.clickNav(add.getByRole('button', { name: 'Add' }));
      await d.point('.alert', 1800);
    } },
    { caption: 'Then remove', say: 'Now remove the Managing Director, with the small cross, and confirm. The order is now the Export Executive\'s: it appears in their Orders list, and they are told.', do: async (d) => {
      const md = demo.sql("SELECT id FROM users WHERE email = 'md@email.com'");
      const x = d.page.locator(`form[action="/orders/${handover}/assignees/${md}/remove"] button`);
      await d.point(x, 1200);
      await d.clickNav(x);
      await d.point(d.page.locator('p.muted').filter({ hasText: 'Assigned to' }).first(), 2000);
    } },
    { goto: at('#department-owners'), caption: 'Owners: one per department', say: 'Now a new order, made by the Export Executive for a buyer in Qatar. In the side panel, Owners: one person per department, who is answerable for this order\'s tasks. You choose the owner for your own department, Sales and Export, from the people in it. Pick the Export Executive, and Set. The Managing Director, the Executive Director and the Admin can choose for any department.', do: async (d) => {
      const own = d.page.locator('#department-owners form').first();
      await d.scrollTo('#department-owners'); await d.point('#department-owners h3', 1200);
      await d.select(own.locator('select'), { label: 'Export Executive (Sales/Export Executive)' });
      await d.clickNav(own.getByRole('button', { name: 'Set' }));
      await d.scrollTo('#department-owners'); await d.point(d.page.locator('#department-owners .kv-grid > div').first(), 2200);
    } },

    // ---------- 3. Payment terms ----------
    { card: 'chapter', title: 'Approving payment terms', subtitle: 'Before the quotation can be made.', say: 'Chapter three. Approving payment terms.' },
    { caption: 'Terms that need approval', say: 'This order uses the payment terms Established Buyer, Post B L: the balance is paid after shipment. Terms like these, and letters of credit, need approval before the quotation can be generated or sent. The yellow box under Step 1 says so. You may approve them: so may the Sales Director, the Finance Director, and the Managing and Executive Directors.', do: async (d) => { await A.openTo(d, '#payment-terms-approval'); await d.point('#payment-terms-approval .review-banner', 3500); } },
    { caption: 'Approve payment terms', say: 'Check the advance and balance with the deal you agreed, and press Approve payment terms. The box turns green, with your name and the date. One rule: you can never approve the terms of an order you created yourself. A second person always does that.', do: async (d) => {
      await d.clickNav(d.page.locator('#payment-terms-approval button[type=submit]').first());
      await A.openTo(d, '#payment-terms-approval'); await d.point('#payment-terms-approval', 2500);
    } },

    // ---------- 4. Prices ----------
    { card: 'chapter', title: 'Prices below the lowest price', subtitle: 'Only the Managing Director approves them.', say: 'Chapter four. Prices below the lowest price.' },
    { caption: 'Price check', say: 'The quotation still cannot be generated. The Price check shows why. The line was picked from Products. Its quote price is fifty three dollars and five cents, and the lowest price we may agree is fifty one fifty. The Export Executive agreed fifty and seventy five cents: below the lowest price.', do: async (d) => { await A.openTo(d, '#price-check'); await d.point(d.page.locator('#price-check tr').filter({ hasText: 'Absolute Black' }).first(), 4000); } },
    { caption: 'Ask MD to approve line 1', say: 'You cannot approve this price, and neither can the Sales Director. Press Ask M D to approve line 1. The Managing Director is told at once, the request waits on their Approvals page under Prices, and the line shows that it was sent.', do: async (d) => {
      const b = d.page.getByRole('button', { name: 'Ask MD to approve line 1' });
      await d.point(b, 1200); await d.clickNav(b);
      await A.openTo(d, '#price-check'); await d.point(d.page.locator('#price-check').getByText('Sent to the MD for approval'), 2500);
    } },
    { card: 'list', kicker: 'Good to know', title: 'Prices and the MD', items: [
      'At or above the lowest price: nothing to approve.',
      'Below the lowest price: the MD approves it, with a reason, or refuses it.',
      'Below the minimum price: refused on saving. Nobody can approve it.',
      'Change the price or options before the QT is approved: asked again. After: amendment only.',
    ], say: 'So, how prices work. At or above the lowest price, there is nothing to approve. Below the lowest price, the line can be saved, but the quotation and the proforma invoice wait until the Managing Director approves it, with a reason, or refuses it. Below the minimum price, the app refuses to save the line at all, and nobody can approve it. If anyone changes the price or the options of an approved line before the quotation is approved, the approval falls away, and it must be asked again. Once the quotation is approved, the prices and products are fixed: a change then goes through a Products and prices amendment, and a price can only go down.' },
    { caption: 'Approved by the MD', do: async (d) => { B.mdApprovesLine(demo, order); await d.goto(at('#price-check')); await A.openTo(d, '#price-check'); await d.point(d.page.locator('#price-check .price-badge').first(), 2500); }, say: 'The Managing Director has approved the price, with a reason. The badge now reads: approved by the M D, and the quotation can be generated.' },

    // ---------- 5. Documents ----------
    { card: 'chapter', title: 'Reviewing documents', subtitle: 'Assign the reviewer, then approve or reject.', say: 'Chapter five. Reviewing documents.' },
    { caption: 'A draft, waiting for a reviewer', say: 'The Export Executive has now generated the quotation. It is a draft, with a DRAFT watermark. Document Review and Approval lists it at the top: generated, no reviewer assigned yet.', do: async (d) => {
      B.execGeneratesQt(demo, order);
      await d.goto(at('#document-review-approval')); await A.openTo(d, '#document-review-approval');
      await d.point(d.page.locator('#document-review-approval').getByText('no reviewer assigned yet', { exact: false }).first(), 2500);
    } },
    { caption: 'Assign reviewers', say: 'Open the draft, and choose the reviewer. The person who generated a document is never in this list: a second pair of eyes is the point. You can choose yourself, the Sales Director, or a director. Here: yourself. Assign Reviewers. The document is now in review.', do: async (d) => {
      const summary = d.page.locator('#document-review-approval details > summary').filter({ hasText: 'Not sent for review' }).first();
      await d.click(summary);
      const panel = summary.locator('xpath=..');
      if (!(await panel.evaluate((el) => el.open))) await panel.evaluate((el) => { el.open = true; });
      const sel = panel.locator('select[name="reviewer_ids[]"]').first();
      await d.point(sel, 1500);
      const label = (await sel.locator('option').allInnerTexts()).find((t) => /Sales Manager/.test(t));
      await d.select(sel, { label });
      await d.clickNav(d.page.getByRole('button', { name: 'Assign Reviewers' }).first());
    } },
    { goto: '/reviews', caption: 'Approvals › Documents', say: 'Every document waiting for you is on Approvals, under the Documents tab. Open the P D F and check it against the order: the buyer, the product, the quantity, the price and the terms.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('tr').filter({ hasText: 'QT' }).first(), 3000); } },
    { caption: 'Approve, or reject with a reason', say: 'If something is wrong, Reject, with the reason: the document goes back to draft, and the Export Executive generates a new revision. Here it is right: Approve. The P D F is redrawn as final, without the draft watermark.', do: async (d) => { const b = d.page.getByRole('button', { name: 'Approve' }).first(); await d.point(b, 1500); await d.clickNav(b); } },
    { goto: at('#document-review-approval'), caption: 'Approved, not yet sent', say: 'Back on the order, the quotation is approved, and its prices and products are now fixed. E-mails to buyers are switched on in this company, so the next step is sending it to the buyer, and that has its own approval: the Export Executive asks to send it, and the Sales Director approves the e-mail.', do: async (d) => { await A.openTo(d, '#document-review-approval'); await d.point(d.page.locator('#document-review-approval').getByText('approved, not yet sent', { exact: false }).first(), 3000); } },

    // ---------- 6. Rules ----------
    { card: 'list', kicker: 'Self-approval', title: 'Approving your own work', items: [
      'Your own document, your own order\'s terms: someone else approves.',
      'An e-mail you asked to send: always someone else, whatever the role.',
      'Exceptions: Users & roles › Self-approval rules; by default MD and ED only.',
      'Purchase approval limits are Procurement\'s: they do not apply to Sales.',
    ], say: 'A word on approving your own work. A document you generated, or the payment terms of an order you created, are approved by someone else. An e-mail you asked to send is always approved by someone else, whatever your role: an e-mail to a buyer cannot be called back. The only exceptions are set by the Admin, in Users and roles, Self-approval rules, and by default only the Managing and Executive Directors may approve their own work. And the money approval limits you may hear about are for Procurement\'s purchases: they do not apply to Sales.' },

    // ---------- 7. Sales Director ----------
    { card: 'chapter', title: 'The Sales Director', subtitle: 'E-mail approval, the cockpit, staff reports and agreements.', say: 'Chapter six. What the Sales Director sees as well.' },
    { as: 'salesdir', goto: '/', caption: 'Emails awaiting Level-2 approval', say: 'Now the Sales Director. The Export Executive has asked to send the quotation to the buyer. That is Level 1. On the Dashboard, My Approvals counts the e-mails awaiting Level 2 approval, and Approvals, in the left menu, shows the count too.', do: async (d) => {
      B.execRequestsSend(demo, order);
      await d.goto('/');
      await d.scrollTo(d.page.getByRole('heading', { name: 'My Approvals' })); await d.point(d.page.getByText('Emails awaiting Level-2 approval').first(), 2200);
      await d.point(d.page.locator('nav.sidebar-nav a[data-nav="approvals"]').first(), 1500);
    } },
    { goto: '/email-approvals', caption: 'Approvals › E-mails', say: 'Approvals, the E-mails tab: Email Send Approvals shows each waiting e-mail exactly as it will go: the buyer\'s address, the subject, the full text, and View attachment opens the very P D F that will be attached. Nothing is written again at sending time.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByText('purchasing@coralbay-stone.example').first(), 1500); await d.point(d.page.getByText('Subject:', { exact: false }).first(), 2000); await d.point(d.page.getByRole('link', { name: /View attachment/ }).first(), 1500); } },
    { caption: 'Approve', say: 'All correct: Approve. It goes out with the next sending run, a few minutes later. Until then anyone can still Cancel it, with a reason, or press Send Now. If it is wrong, Reject it, and the Export Executive corrects it.', do: async (d) => { const b = d.page.getByRole('button', { name: /^Approve/ }).first(); await d.point(b, 1200); await d.clickNav(b); await d.point('.alert', 2500); } },
    { goto: '/cockpit', caption: 'Management cockpit', say: 'Under Reports: the Management Cockpit. For each department: the open tasks, the overdue ones, the escalated ones, and the orders with no owner yet. Below: orders by status, everything escalated that needs management, and suppliers who are late. Counts and status only, never amounts.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('table.list').first(), 3000); await d.scrollTo(d.page.getByText('Escalated — needs management').first()); await d.point(d.page.getByText('Escalated — needs management').first(), 1500); } },
    { goto: '/reports/staff', caption: 'Staff Productivity Report', say: 'Reports, Staff Productivity: the documents each person generated, by type, and their actions in the app, for the dates you choose. Export C S V downloads it.', do: async (d) => {
      await d.page.evaluate(() => { for (const tr of document.querySelectorAll('table tr')) { const c = tr.querySelector('td'); if (c && /^MM /.test(c.innerText.trim())) tr.style.display = 'none'; } });
      await d.point('h1', 1000); await d.point(d.page.locator('tr').filter({ hasText: 'Export Executive' }).first(), 2500); await d.point(d.page.getByRole('link', { name: /Export CSV/ }).first(), 1200);
    } },
    { goto: '/agreements', caption: 'Clients › Agreements', say: 'And Agreements, under Clients: every agreement signed with a client, a supplier, a logistics partner or an associate, with its dates and the signed copy. You can view and download them; adding or changing one is for the Managing and Executive Directors and the Admin.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByText('Expiring within').first(), 1500); await d.point(d.page.locator('table tr').nth(1), 2000); } },

    { card: 'list', kicker: 'Recap', title: 'Manager and Director in four lines', items: [
      'Assign orders (add first, then remove) and set your department\'s owner.',
      'Approve payment terms and documents: never your own.',
      'Below the lowest price: ask the MD. Below the minimum: not possible.',
      'Director: approve e-mails to buyers; cockpit, staff report, agreements.',
    ], next: 'B03 · Accounts Executive', say: 'Let us recap. You logged in with your password and the e-mailed code, and you see every order. You hand an order over by adding the new person first, and you set your department\'s owner. You approve payment terms and review documents, but never your own work. A price below the lowest price goes to the Managing Director, and below the minimum it cannot be saved at all. And the Sales Director approves every e-mail to a buyer, and keeps an eye on the cockpit, the staff report and the agreements. Everything works the same on the Kanban template.' },
  ],
};
