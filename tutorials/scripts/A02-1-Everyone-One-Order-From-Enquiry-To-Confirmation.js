'use strict';
// A02 Part 1 — One order, end to end: from enquiry to a confirmed order (Stages 1–4).
// One CIF order for a sample buyer an associate introduced. On camera, the people do their own
// part in the app (approve terms, generate and approve documents, clear the advance, the buyer
// acknowledges); between scenes, during the cards, the other routine steps are done off camera
// (scripts/_a02.js). Source of truth: docs/APPLICATION_HANDBOOK.md 5.1–5.4, SOP 01–06.

const demo = require('../lib/demo');
const A = require('./_a02');

let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;

module.exports = {
  code: 'A02-1',
  file: 'A02-1-Everyone-One-Order-From-Enquiry-To-Confirmation.mp4',
  group: 'Group A · Everyone',
  title: 'One Order, End to End · Part 1',
  subtitle: 'From the first enquiry to an order the buyer has confirmed.',
  minutes: '10',
  restoreDb: true,

  async setup(d) {
    const ctx = await A.setup(d);
    order = ctx.orderId;
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'In video A01 we saw the big picture. Now let us follow one real export order, from the very first enquiry to the moment the buyer confirms it. Along the way you will meet the people who handle it, and see exactly what each one does, and when the order moves on to the next person.' },
    { card: 'list', kicker: 'Meet the team', title: 'Who touches this order', items: [
      'Associate: introduced the buyer. Export Executive: runs the order day to day.',
      'Sales Manager: checks documents. Managing Director: approves terms and quotations.',
      'Accounts Executive records money; the Finance Manager clears it.',
      'Procurement, Logistics and Export Compliance in Part 2; the buyer, in the client portal.',
    ], say: 'Here is the team. An associate, who introduced the buyer. The Export Executive, who runs the order day to day. The Sales Manager, who checks documents. The Managing Director, who approves terms and quotations. Accounts, who record the money, and the Finance Manager, who clears it. Procurement, Logistics and Export Compliance join in Part 2. And of course, the buyer, in the client portal. Watch the badge in the top right corner: it always shows who is working.' },

    // ---------- 1. Where the order comes from ----------
    { card: 'chapter', title: 'Where the order comes from', subtitle: 'An associate introduces a buyer.', say: 'Chapter one. Where does the order come from?' },
    { as: 'associate', goto: '/associate/clients', caption: 'The associate introduced the buyer', say: 'Our story starts with an associate. They sent a buyer registration link to a stone importer in the Netherlands: Harbourline Stone Imports, a sample buyer. The buyer registered with their own details, NexaCrest accepted, and now the buyer is this associate\'s client, protected for six months.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /Registered clients/ })); await d.point(d.page.locator('tr').filter({ hasText: 'Harbourline' }).first(), 1500); } },
    { as: 'exec', goto: at(), caption: 'The Export Executive creates the order', say: 'The buyer asked for a price. So the Export Executive created the order: Black Galaxy granite, slabs and tiles, shipped C I F to Rotterdam, priced in US dollars. Look at the reference at the top: one number that follows this order everywhere.', do: async (d) => { await d.point('h1', 1200); await d.point(d.page.locator('.process-stepper'), 1000); } },
    { caption: 'At a Glance', say: 'At a Glance shows the essentials: the client, the incoterm and port, the order value of eighteen thousand five hundred and sixty dollars, and the payment terms. And this note: the buyer is an associate\'s client, so the associate\'s commission is tracked on this order.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'At a Glance' })); await d.point(d.page.getByText('Associate client', { exact: false }).first(), 1500); } },
    { caption: 'Products', say: 'Under Products, the order lines: the description, the size, the finish, the quantity and the unit price. While the order is new, the Export Executive can still change them. These lines feed every document from now on: the quotation, the invoices, the packing list.', do: async (d) => { await d.click(d.page.locator('#step-products > summary')); await d.point(d.page.locator('#step-products input[name*="description"]').first(), 1500); await d.point(d.page.locator('#step-products input[name*="quantity"]').first(), 1200); } },
    { caption: 'Step 1 waits for the MD', say: 'Now look at Step 1. A yellow box. This order uses the payment terms called Established Buyer, Post B L: forty percent advance, and the balance after the bill of lading. Terms like these need approval by the Managing Director or the Executive Director before any quotation can be generated.', do: async (d) => { await A.openTo(d, '#step-1-quotation-prepare .review-banner'); await d.point('#step-1-quotation-prepare .review-banner', 2000); } },

    // ---------- 2. The quotation ----------
    { card: 'chapter', title: 'The quotation', subtitle: 'Approve the terms, generate, review, approve.', say: 'Chapter two. The quotation.' },
    { as: 'md', goto: at('#step-1-quotation-prepare'), caption: 'The MD approves the payment terms', say: 'The Managing Director opens the order, and approves the payment terms. A second person always approves: the person who created an order cannot approve its own terms.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Approve payment terms' })); await d.point('.alert', 1200); } },
    { as: 'exec', goto: at('#step-1-quotation-prepare'), caption: 'Step 1a · Generate Quotation', say: 'Back to the Export Executive. The yellow box has gone, so: Generate Quotation. One click, and the app builds the quotation P D F from the order itself. Nothing is typed twice.', do: async (d) => { await d.clickNav(d.page.locator('#step-1-quotation-prepare').getByRole('button', { name: 'Generate Quotation' })); await d.point('.alert', 1200); } },
    { caption: 'A draft, waiting for review', say: 'The quotation exists, but only as a draft, with a DRAFT watermark. No document reaches a buyer until someone has reviewed and approved it.', do: async (d) => { await d.scrollTo('#document-review-approval'); await d.point('#document-review-approval > summary', 1500); } },
    { as: 'salesmgr', goto: at('#document-review-approval'), caption: 'The Sales Manager assigns a reviewer', say: 'The Sales Manager, who may approve documents, opens Document Review and Approval, picks the draft, and assigns a reviewer: the Managing Director.', do: async (d) => {
      const summary = d.page.locator('#document-review-approval details > summary').filter({ hasText: 'Not sent for review' }).first();
      await d.click(summary);
      // The click can land while the page is still settling after the hash jump; make sure the panel is open.
      const panel = summary.locator('xpath=..');
      if (!(await panel.evaluate((el) => el.open))) { await panel.evaluate((el) => { el.open = true; }); await new Promise((r) => setTimeout(r, 400)); }
      const sel = panel.locator('select[name="reviewer_ids[]"]').first();
      const label = (await sel.locator('option').allInnerTexts()).find((t) => /^Managing Director/.test(t));
      await d.select(sel, { label });
      await d.clickNav(d.page.getByRole('button', { name: 'Assign Reviewers' }).first());
    } },
    { as: 'md', goto: '/reviews', caption: 'My Reviews', say: 'Now the quotation sits in the Managing Director\'s review queue, under My Reviews. They check it, and approve.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'QT' }).first(), 1200); await d.clickNav(d.page.getByRole('button', { name: 'Approve' }).first()); } },
    { caption: 'Stage 1 passes by itself', say: 'And look at the tracker: stage one is done. With e-mail sending switched off, approval counts as sent, so the stage passed on its own. Nobody had to remember to tick a box.', do: async (d) => { await d.scrollTo('.process-stepper'); await d.point('.process-step[data-stage="1"]', 1200); await d.point('.process-step[data-stage="2"]', 1000); } },

    // ---------- 3. Buyer's PO and the Proforma Invoice ----------
    { card: 'chapter', title: 'The buyer says yes', subtitle: 'Buyer\'s PO, then the Proforma Invoice.', say: 'Chapter three. The buyer says yes.', do: async () => { A.move(demo, 'buyerPo', order); A.move(demo, 'piForReview', order); } },
    { as: 'exec', goto: at('#step-group-stage-2'), caption: 'Step 4 · Buyer\'s PO', say: 'The buyer accepted the quotation and sent their purchase order. The Export Executive generated the Buyer P O document, it was approved, and they recorded the buyer\'s P O number and the date it arrived. That passes stage two.', do: async (d) => { await A.openTo(d, '#step-group-stage-2'); await d.point('#step-group-stage-2 > summary', 1500); await d.point('.process-step[data-stage="2"]', 900); } },
    { caption: 'Step 5 · Proforma Invoice', say: 'Next, Step 5: the Proforma Invoice, the bill for the advance. The buyer\'s details were already confirmed, so the optional P I form was skipped, with a reason, as the app requires. Then the Export Executive generated the Proforma Invoice, and sent it for review.', do: async (d) => { await A.openTo(d, '#step-group-stage-3'); await d.point('#step-group-stage-3 > summary', 1500); } },
    { as: 'salesmgr', goto: '/reviews', caption: 'The Sales Manager approves the PI', say: 'This time the reviewer is the Sales Manager. One click: approved. From this moment the order keeps its own frozen copy of the terms, the company details and the exchange rate. Later changes in settings never rewrite this order.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'PI' }).first(), 1200); await d.clickNav(d.page.getByRole('button', { name: 'Approve' }).first()); } },

    // ---------- 4. The advance ----------
    { card: 'chapter', title: 'The advance payment', subtitle: 'One person records, another clears.', say: 'Chapter four. The money arrives.', do: async () => { A.move(demo, 'advanceRecorded', order); } },
    { as: 'accounts', goto: at('#step-5d-payment-record'), caption: 'Step 5d · Accounts records the remittance', say: 'The buyer paid the forty percent advance. The Accounts Executive saw it on the bank statement, and recorded it: the amount in dollars, the rupees the bank credited, and the bank reference. The app works out the exchange rate, and writes a full description of the payment by itself.', do: async (d) => { await A.openTo(d, '#step-5d-payment-record'); await d.point(d.page.locator('#step-5d-payment-record').getByText('credited by the bank', { exact: false }).first(), 2500); } },
    { caption: 'Payment Overview', say: 'And the Payment Overview, pinned near the top of every order, shows each payment leg at a glance: what is due, what has been received, and what has been cleared. Here: the advance is received, but not yet cleared.', do: async (d) => { await d.scrollTo('#payment-overview'); await d.click(d.page.locator('#payment-overview > summary')); await d.point('#payment-overview', 2200); } },
    { as: 'fm', goto: at('#step-5e-payment-confirm'), caption: 'Step 5e · The Finance Manager clears it', say: 'Recording is not clearing. A different person, the Finance Manager, confirms the money has really landed, and marks the advance cleared. The app asks: are you sure? Yes.', do: async (d) => { await A.openTo(d, '#step-5e-payment-confirm'); await d.clickNav(d.page.locator('#step-5e-payment-confirm button[type=submit]').first()); await d.point('.alert', 1500); } },
    { caption: 'Stage 4 unlocked, portal login created', say: 'Two things just happened. Stage four is unlocked. And the buyer\'s client portal login was created automatically: from now on the buyer can follow the order themselves.', do: async (d) => { await d.point('.alert', 2500); } },

    // ---------- 5. Order Confirmation ----------
    { card: 'chapter', title: 'The buyer confirms', subtitle: 'Order Confirmation, acknowledged in the portal.', say: 'Chapter five. The buyer confirms.', do: async (d) => { A.move(demo, 'ocSent', order); d.actors.client.cookies = await A.clientCookies(demo); } },
    { as: 'exec', goto: at('#step-group-stage-4'), caption: 'Step 6 · Order Confirmation', say: 'The Export Executive generated the Order Confirmation: the order value, the advance received, and the balance still to come. It was approved, and the confirmation link was generated. Now the buyer has to acknowledge it.', do: async (d) => { await A.openTo(d, '#step-group-stage-4'); await d.point('#step-group-stage-4 > summary', 1500); } },
    { as: 'client', goto: '/client', caption: 'The buyer logs in', say: 'Now we are the buyer, in the client portal. My Orders shows this one order, at the Order Confirmation stage. View documents.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'Order Confirmation' }).first(), 1200); await d.clickNav(d.page.getByRole('link', { name: 'View documents' }).first()); } },
    { caption: 'What the buyer sees', say: 'The buyer sees a summary to confirm, and every document we have finalized for them: the quotation, the buyer P O, the proforma invoice and the order confirmation. Only final, approved copies. Never a draft, never our costs.', do: async (d) => { await d.point(d.page.getByText('Your Acknowledgement Needed').first(), 1200); await d.point(d.page.locator('table').filter({ hasText: 'Download PDF' }).first(), 1800); } },
    { caption: 'I acknowledge', say: 'The buyer reads the order confirmation, and clicks: I acknowledge and confirm to proceed. If a buyer never answers, the app confirms it automatically after forty-eight hours.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'I acknowledge and confirm to proceed' })); await d.point('.alert', 1500); } },
    { as: 'exec', goto: at(), caption: 'Hand-off to Procurement', say: 'Back on the order page: stages one to four are done, and stage five, the supplier purchase order, is open. The order now passes to Procurement.', do: async (d) => { await d.point('.process-step[data-stage="4"]', 1000); await d.point('.process-step[data-stage="5"]', 1500); } },

    { card: 'list', kicker: 'Recap', title: 'From enquiry to a confirmed order', items: [
      'Associate introduces the buyer; the Export Executive creates the order.',
      'MD approves special terms; every document is reviewed before the buyer sees it.',
      'Accounts records the advance, the Finance Manager clears it: portal login created.',
      'The buyer acknowledges the Order Confirmation in the portal: on to Procurement.',
    ], next: 'A02 · Part 2 · From factory to closure', say: 'Let us recap. The associate introduced the buyer, and the Export Executive created the order. The Managing Director approved the special payment terms, and every document was reviewed and approved before the buyer saw it. Accounts recorded the advance, and the Finance Manager cleared it, which created the buyer\'s portal login. Finally the buyer acknowledged the order confirmation, and the order moved on to Procurement. In Part 2 we buy the stone, ship it, collect the balance, and close the order. See you there!' },
  ]; },
};
