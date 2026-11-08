'use strict';
// B01 Part 2 — The Export Executive: Proforma Invoice, recording the advance (bank reference and
// INR credited), the Order Confirmation and the buyer's acknowledgement, Order Communication,
// My Tasks and the hand-off to Procurement (Stages 3–4). Starts where Part 1 ended (set up off
// camera, scripts/_b01.js). The Sales Manager's approvals are done off camera; the Finance Manager
// clears the advance on camera. Source of truth: docs/APPLICATION_HANDBOOK.md 5.4, SOP 03, 04, 13, 23.

const demo = require('../lib/demo');
const B = require('./_b01');
const A = require('./_a02');

let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
/** Points at the first match, or at the fallback when the first is not on the page. */
async function pointSafe(d, target, fallback, ms = 1800) {
  const l = typeof target === 'string' ? d.page.locator(target) : target;
  if (await l.count()) await d.point(l.first(), ms); else await d.point(fallback, ms);
}

module.exports = {
  code: 'B01-2',
  file: 'B01-Staff-Export-Executive-Part-2.mp4',
  group: 'Group B · Staff, by role',
  title: 'The Export Executive · Part 2',
  subtitle: 'Proforma Invoice, the advance, the Order Confirmation and the hand-off.',
  minutes: '10',
  restoreDb: true,

  async setup(d) {
    const ctx = await B.setup(d);
    order = B.orderAfterPart1(d);
    // The buyer's intake was accepted in Part 1: nothing waits in Quotation Intake Review.
    d.sql('SET FOREIGN_KEY_CHECKS = 0; DELETE FROM client_intake_submissions; SET FOREIGN_KEY_CHECKS = 1');
    ctx.actors.exec = ctx.actors.execIn;
    return ctx;
  },

  steps: () => [
    { card: 'title', say: 'Welcome to Part 2 for the Export Executive. Our buyer in Dubai has sent their purchase order. Now we issue the proforma invoice, record the advance when it arrives, confirm the order, keep the buyer informed, and hand the order over to Procurement.' },
    { card: 'list', kicker: 'In this part', title: 'From proforma to hand-off', items: [
      'Step 5: the PI form or skip it, then Generate Proforma Invoice.',
      'Record the advance: amount, rupees credited, bank reference.',
      'Step 6: Order Confirmation, the link, the buyer\'s acknowledgement.',
      'Order Communication, My Tasks, and the purchase requirement.',
    ], say: 'Four things in this part. Step 5: the proforma invoice details form, or skipping it, and then the proforma invoice itself. Recording the advance from the bank\'s advice: the amount, the rupees credited, and the bank reference. Step 6: the order confirmation, its link, and the buyer\'s acknowledgement. And finally Order Communication, My Tasks, and the purchase requirement that hands the order to Procurement.' },

    // ---------- 1. The Proforma Invoice ----------
    { card: 'chapter', title: 'The Proforma Invoice', subtitle: 'Step 5a to 5c.', say: 'Chapter one. The proforma invoice.' },
    { as: 'exec', caption: 'Step 5a · PI Form Link', say: 'Here is the order, at stage three. Step 5 a: Generate P I Form Link. It sends the buyer a short form, already filled in, to confirm the consignee, the notify party and the payment details before we issue the proforma invoice. What they send back appears in P I Intake Review, and you apply it in Step 5 b.', do: async (d) => { await d.goto(at('#step-5a-pi-form-link')); await A.openTo(d, '#step-5a-pi-form-link'); await d.point(d.page.locator('#step-5a-pi-form-link').getByRole('button', { name: 'Generate PI Form Link' }), 1800); } },
    { goto: '/pi-intake-review', caption: 'PI Intake Review', say: 'When a buyer does send the P I form back, it waits here, in P I Intake Review, in the Operations menu. You check it, and Accept and Apply: the details go onto the order, and Step 5 b turns green. Nothing waits today.', do: async (d) => { await d.point('h1', 2500); } },
    { caption: 'Or skip it, with a reason', say: 'Back on the order. Our buyer already gave us everything on the quotation form, so we skip it. The app asks why, so the next person knows. Type the reason, and Skip P I Form.', do: async (d) => {
      await d.goto(at('#step-5a-pi-form-link')); await A.openTo(d, '#step-5a-pi-form-link');
      const sum = d.page.locator('#step-5a-pi-form-link details.pi-form-skip > summary').first();
      await d.point(sum, 600);
      await sum.evaluate((el) => { el.parentElement.open = true; });
      await d.wait(400);
      await d.type('#step-5a-pi-form-link input[name="skip_reason"], #step-5a-pi-form-link textarea[name="skip_reason"]', 'Buyer details already confirmed on the quotation form', { delay: 35 });
      await d.clickNav(d.page.locator('#step-5a-pi-form-link button').filter({ hasText: /Skip PI Form/ }).first());
    } },
    { caption: 'Step 5c · Generate Proforma Invoice', say: 'Step 5 c: Generate Proforma Invoice. The price check runs again. The P I shows the advance the buyer pays now, forty percent, in an amber box. Like every document, it goes to the Sales Manager for review.', do: async (d) => { await d.goto(at('#step-5c-pi-generate')); await A.openTo(d, '#step-5c-pi-generate'); await d.clickNav(d.page.locator('#step-5c-pi-generate').getByRole('button', { name: 'Generate Proforma Invoice' })); await d.point('.alert', 1500); } },
    { caption: 'Approved: the order\'s terms are frozen', say: 'The Sales Manager approved it. From this moment the order keeps its own copy of the terms, our company details and the exchange rate. A later change in the settings never rewrites this order. Send the approved P I to the buyer, and wait for the money.', do: async (d) => { B.approve(demo, order, 'PI'); await d.goto(at('#document-review-approval')); await A.openTo(d, '#document-review-approval'); await d.point(d.page.locator('#document-review-approval tr').filter({ hasText: 'PI' }).first(), 2000); } },

    // ---------- 2. The advance ----------
    { card: 'chapter', title: 'Recording the advance', subtitle: 'From the bank\'s credit advice.', say: 'Chapter two. Recording the advance.' },
    { caption: 'Step 5d · Record Advance Remittance', say: 'The bank sent its credit advice: the advance has arrived. Step 5 d. The app shows the advance due, and fills in the amount. If the buyer paid a different amount, you pick the reason below: bank charges, paid less, or paid more.', do: async (d) => { await d.goto(at('#step-5d-payment-record')); await A.openTo(d, '#step-5d-payment-record'); await d.point('#step-5d-payment-record .advance-due-note', 1800); await d.point('#step-5d-payment-record input[name="advance_amount"]', 1200); await d.point('#step-5d-payment-record select[name="difference_reason_choice"]', 1200); } },
    { caption: 'Payment Action Link (optional)', say: 'At the top of Step 5 d: the Payment Action Link. It lets the buyer send us their payment details and a screenshot, without a login. That is information only: you always record the payment from our own bank\'s advice, as we do now.', do: async (d) => { await d.point(d.page.locator('#step-5d-payment-record').getByRole('button', { name: /Payment Link/ }).first(), 2500); } },
    { caption: 'INR credited by the bank', say: 'Now the rupees that actually reached our account, from the same advice. The app works out the exchange rate by itself: eighty four point one zero. If the bank prints its own rate, you can add it as a check.', do: async (d) => {
      const amt = parseFloat(await d.page.inputValue('#step-5d-payment-record input[name="advance_amount"]'));
      await d.type('#step-5d-payment-record input[name="advance_inr_credited"]', (Math.round(amt * 84.10 * 100) / 100).toFixed(2));
      await d.point('#step-5d-payment-record [data-remit-rate-derived]', 2000);
    } },
    { caption: 'The bank reference is required', say: 'And the bank reference: the SWIFT or U T R number on the advice. It is required. It lets anyone match this payment to the bank statement later, and it goes into the payment\'s description, here and in Zoho Books. The line below reads back what you entered. Record Advance Remittance.', do: async (d) => {
      await d.type('#step-5d-payment-record input[name="advance_bank_reference"]', 'SWIFT SAMPLE2611140');
      await d.point('#step-5d-payment-record [data-remit-statement]', 1800);
      await d.clickNav(d.page.locator('#step-5d-payment-record').getByRole('button', { name: /Record Advance Remittance/ }));
    } },
    { caption: 'Payment Overview', say: 'The Payment Overview, near the top of every order, shows every payment leg at a glance: the advance and the balance, what is due, what has been received, and what has been cleared. Here: the advance is received, and not yet cleared.', do: async (d) => { await d.point('.alert', 1200); await d.scrollTo('#payment-overview'); const s2 = d.page.locator('#payment-overview > summary'); if (!(await d.page.locator('#payment-overview').evaluate((e) => e.open))) await d.click(s2); await d.point('#payment-overview', 2500); } },
    { caption: 'Recorded, not cleared', say: 'Recording is not clearing. Step 5 e says it: awaiting Accounts to confirm it cleared. You cannot clear a payment yourself: a different person checks that the money has really landed.', do: async (d) => { await A.openTo(d, '#step-5e-payment-confirm'); await d.point('#step-5e-payment-confirm', 2000); } },
    { as: 'fm', caption: 'The Finance Manager clears it', say: 'That person is the Finance Manager. The clearing task appears in their My Tasks. They open the order, and mark the advance cleared. Are you sure? Yes.', do: async (d) => { await d.goto(at('#step-5e-payment-confirm')); await A.openTo(d, '#step-5e-payment-confirm'); await d.clickNav(d.page.locator('#step-5e-payment-confirm button[type=submit]').first()); await d.point('.alert', 1500); } },
    { caption: 'Stage 4 open, portal login created', say: 'Clearing does two things. Stage four opens. And the buyer\'s client portal login is created automatically: from now on the buyer can follow the order themselves.', do: async (d) => { await d.point('.alert', 1500); await d.point('.process-step[data-stage="3"]', 1000); await d.point('.process-step[data-stage="4"]', 1200); } },

    // ---------- 3. The Order Confirmation ----------
    { card: 'chapter', title: 'The Order Confirmation', subtitle: 'Generate, link, acknowledgement.', say: 'Chapter three. The order confirmation.' },
    { as: 'exec', caption: 'Step 6a · Generate Order Confirmation', say: 'Back to the Export Executive. Step 6 a: Generate Order Confirmation. It shows the order value, the advance actually received, and the balance still to come.', do: async (d) => { await d.goto(at('#step-6a-oc-generate')); await A.openTo(d, '#step-6a-oc-generate'); await d.clickNav(d.page.locator('#step-6a-oc-generate').getByRole('button', { name: 'Generate Order Confirmation' })); await d.point('.alert', 1500); } },
    { caption: 'Step 6b · OC Confirmation Link', say: 'Once the Sales Manager has approved it, Step 6 b: Generate O C Confirmation Link. It is never made automatically. The buyer opens the link, or their portal, and clicks: I acknowledge. Copy it, and send it with the confirmation.', do: async (d) => {
      B.approve(demo, order, 'OC');
      await d.goto(at('#step-6b-buyer-action-link'));
      await A.openTo(d, '#step-6b-buyer-action-link');
      await d.clickNav(d.page.locator('#step-6b-buyer-action-link button[type=submit]').first());
      await A.openTo(d, '#step-6b-buyer-action-link');
      await d.point(d.page.locator('#step-6b-buyer-action-link').getByRole('button', { name: 'Copy Link' }), 1500);
    } },
    { caption: 'Step 6c · Mark sent to buyer', say: 'E-mail sending is off, so you e-mailed the confirmation yourself. Press Mark Order Confirmation Sent to Buyer. That starts the buyer\'s forty eight hours: if they do not answer, the app confirms it automatically.', do: async (d) => { await A.openTo(d, '#step-6c-oc-acknowledge'); await d.clickNav(d.page.locator('#step-6c-oc-acknowledge').getByRole('button', { name: 'Mark Order Confirmation Sent to Buyer' })); await d.point('.alert', 1500); } },
    { caption: 'The buyer replied by e-mail', say: 'This buyer simply replied to your e-mail: confirmed. So you record it here, quoting their reply as the evidence, and press Record Buyer\'s Acknowledgement. Step 7 unlocks.', do: async (d) => {
      await A.openTo(d, '#step-6c-oc-acknowledge');
      await d.type('#step-6c-oc-acknowledge textarea[name="acknowledged_note"]', 'Buyer replied by e-mail today: "Order Confirmation received, all details correct, please proceed."', { delay: 25 });
      await d.clickNav(d.page.locator('#step-6c-oc-acknowledge button').filter({ hasText: /Record Buyer/ }).first());
      await d.point('.alert', 1500);
    } },

    { caption: 'Stage 4 done: on to Procurement', say: 'Stages one to four are done, and stage five, the supplier purchase order, is open. From here Procurement buys the stone. Your next job on this order is to tell them what to buy.', do: async (d) => { await d.goto(at()); await d.point('.process-step[data-stage="4"]', 1500); await d.point('.process-step[data-stage="5"]', 1800); } },
    // ---------- 4. Talking to the buyer ----------
    { card: 'chapter', title: 'Keeping the buyer informed', subtitle: 'Order Communication.', say: 'Chapter four. Keeping the buyer informed.' },
    { caption: 'Order Communication', say: 'Order Communication is your running conversation with the buyer, on this order. With e-mail sending off, what you post appears in the buyer\'s portal. With sending on, it is e-mailed to them too. Their replies appear here as well, and nothing is ever deleted.', do: async (d) => { await d.goto(at('#order-updates')); await A.openTo(d, '#order-updates'); await d.point('#order-updates > summary', 1500); await d.type('#order-updates textarea[name="body"]', 'Thank you, your advance has arrived and your order is confirmed. Production starts now; we will post the estimated shipment date here.', { delay: 25 }); } },
    { caption: 'Post to client portal', say: 'You can attach photos, videos or a P D F as well. Post. For something more formal, a payment reminder for example, Compose an e-mail from a template.', do: async (d) => { await d.clickNav(d.page.locator('#order-updates button[type=submit]')); await A.openTo(d, '#order-updates'); await d.point('#order-updates .chat-bubble', 1800); await d.point(d.page.locator('#order-updates').getByRole('link', { name: /Compose an email/ }), 1200); } },

    // ---------- 5. Hand-off ----------
    { card: 'chapter', title: 'Handing over to Procurement', subtitle: 'My Tasks and the purchase requirement.', say: 'Chapter five. Handing over to Procurement.' },
    { goto: '/my-tasks', caption: 'My Tasks: a new task', say: 'Now look at My Tasks. Because the advance is cleared, a task appeared for Sales and Export: raise the purchase requirement. It has a due date. An overdue task turns red, and a few days later it is escalated to management. Open.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'purchase requirement' }).first(), 2200); await d.clickNav(d.page.locator('tr').filter({ hasText: 'purchase requirement' }).first().getByRole('link', { name: 'Open' })); } },
    { caption: 'Purchase Requirements · for Procurement', say: 'Under Step 7: Purchase Requirements, for Procurement. Tell them what this order needs. Pick the product line, so the quantity and unit come with it, and the date it is needed by. No prices here: Procurement finds the supplier.', do: async (d) => {
      await A.openTo(d, '#purchase-requirements');
      const sel = d.page.locator('#purchase-requirements select[name="order_product_id"]');
      const label = (await sel.locator('option').allInnerTexts()).find((t) => /Black Galaxy/.test(t));
      await d.select(sel, { label });
      const due = new Date(Date.now() + 21 * 864e5).toISOString().slice(0, 10);
      await d.point('#purchase-requirements input[name="needed_by"]', 600);
      await d.page.fill('#purchase-requirements input[name="needed_by"]', due);
      await d.type('#purchase-requirements input[name="specification"]', '2 cm, polished, Grade A', { delay: 40 });
    } },
    { caption: 'Raise purchase requirement', say: 'Raise purchase requirement. It goes straight into Procurement\'s queue and their My Tasks. That is the hand-off: you never chase anyone by memory.', do: async (d) => { await d.clickNav(d.page.locator('#purchase-requirements').getByRole('button', { name: /Raise purchase requirement/ })); await A.openTo(d, '#purchase-requirements'); await d.point('#purchase-requirements table', 2000); } },
    { goto: '/my-tasks', caption: 'Done: the task is gone', say: 'And in My Tasks, the task has gone. Only orders I own narrows the list to orders where you are the Sales owner.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.getByRole('link', { name: 'Only orders I own' }), 1500); } },
    { goto: '/orders', caption: 'Orders', say: 'On the Orders page, the order\'s card has moved on to stage five, with the sample orders you also work on. The Kanban template shows the same cards as a board, one column per stage.', do: async (d) => { await d.point('h1', 900); await pointSafe(d, d.page.locator(':is(a, div, article)').filter({ hasText: 'TEST Gulf Stone' }).filter({ hasText: 'Stage' }).last(), 'h1', 2200); } },
    { caption: 'All Documents', say: 'One last place on the order page: All Documents, quick links to every final document on this order: the quotation, the buyer P O, the proforma invoice and the order confirmation, and below them every file uploaded to the order. Open any of them from here, without scrolling through the steps. And Recent Activity, on the Dashboard, lists the latest actions in the app, with who did them and when.', do: async (d) => { await d.goto(at()); const s = d.page.locator('summary').filter({ hasText: /All Documents/ }).first(); await d.scrollTo(s); await d.click(s); await d.point(s.locator('xpath=..'), 2500); } },

    { goto: '/', caption: 'Back on the Dashboard', say: 'And back on the Dashboard, Active Orders by Stage now counts this order under the supplier purchase order. Overdue Payments warns you when a buyer is late, and the bell, bottom left, counts your notifications, for example when a buyer posts a message.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /Active Orders by Stage/ })); await d.point(d.page.getByRole('heading', { name: /Active Orders by Stage/ }), 1800); await d.point(d.page.getByRole('heading', { name: 'Overdue Payments' }), 1200); await d.point('.sidebar-user-notif', 1500); } },
    { card: 'list', kicker: 'Recap', title: 'Part 2 in four lines', items: [
      'Skip or send the PI form, then generate the Proforma Invoice.',
      'Record the advance with the INR credited and the bank reference; Finance clears it.',
      'Order Confirmation, its link, then the buyer\'s acknowledgement: Step 7 opens.',
      'Keep the buyer posted; raise the purchase requirement from My Tasks.',
    ], next: 'B02 · Sales Manager and Sales Director', say: 'Let us recap. You skipped the P I form with a reason, and generated the proforma invoice, which the Sales Manager approved. You recorded the advance from the bank\'s advice, with the rupees credited and the bank reference, and the Finance Manager cleared it, which opened stage four and created the buyer\'s portal login. You generated the order confirmation and its link, and recorded the buyer\'s acknowledgement. You kept the buyer informed in Order Communication, and raised the purchase requirement from My Tasks, handing the order to Procurement. Everything works the same on the Kanban template. In the next video: the Sales Manager and the Sales Director.' },
  ],
};
