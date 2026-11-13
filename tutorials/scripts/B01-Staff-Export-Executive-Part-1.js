'use strict';
// B01 Part 1 — The Export Executive: logging in, the day's screens, a new buyer, the order,
// the quotation and the buyer's PO (Stages 1–2). Everything the Export Executive does is done on
// camera, on the Standard template; the Sales Manager's document approvals are shown once on camera,
// then done off camera (scripts/_b01.js). Source of truth: docs/APPLICATION_HANDBOOK.md 4.2, 5.2–5.4,
// SOP 01, 02, 13, 23. The Export Executive has no CAFIN and cannot approve documents: neither is shown.
// Updated 13 Nov 2026 (docs audit C; not recorded, recording on hold): D-348 Thickness and Size / format
// chosen before the price shows; D-357 finish from the Surface finish option; D-361 only configured
// products with an active supplier are offered; D-341 "Edit Annexure A ›"; D-342 lines fixed by the
// approved Quotation; D-310 / D-320 Approvals › Documents and Orders › Quotation requests; D-280 wording.

const demo = require('../lib/demo');
const B = require('./_b01');
const A = require('./_a02');

let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const EXEC = 'export.executive@email.com';

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
  code: 'B01-1',
  file: 'B01-Staff-Export-Executive-Part-1.mp4',
  group: 'Group B · Staff, by role',
  title: 'The Export Executive · Part 1',
  subtitle: 'Your screens, a new buyer, the order, the quotation and the buyer\'s PO.',
  minutes: '10',
  restoreDb: true,

  async setup(d) { return B.setup(d); },

  steps: () => [
    { card: 'title', say: 'This video is for the Export Executive: the person who runs each export order day to day, from the first quotation to the order confirmation. In Part 1 we log in, look at the screens you use every day, accept a new buyer, create the order, and take it through the quotation and the buyer\'s purchase order.' },
    { card: 'list', kicker: 'Your role', title: 'What the Export Executive does', items: [
      'Creates orders and generates every document: QT, Buyer PO, PI, OC.',
      'Records the buyer\'s payments, with the bank reference. Finance clears them.',
      'Talks to the buyer in Order Communication, and hands over to Procurement.',
      'Does not approve documents, and never sees our costs or the CAFIN.',
    ], say: 'Here is your job in four lines. You create the orders and generate every document: the quotation, the buyer P O, the proforma invoice and the order confirmation. You record the buyer\'s payments, with the bank reference, and the Finance Manager clears them. You talk to the buyer in Order Communication, and hand the order over to Procurement. What you do not do: approve documents, that is for the Sales Manager or a director. And you never see our costs or the C A FIN.' },

    // ---------- 1. Logging in ----------
    { card: 'chapter', title: 'Logging in', subtitle: 'Password, then the code we e-mail you.', say: 'Chapter one. Logging in.' },
    { as: 'exec', goto: '/login', caption: 'Staff login', say: 'Open the NexaCrest address and sign in with your work e-mail and your password.', do: async (d) => {
      await d.type(d.page.getByLabel('Email'), EXEC);
      await d.type(d.page.getByLabel('Password'), demo.STAFF.exec ? demo.STAFF.exec.password : demo.STAFF.md.password, { delay: 40 });
      await d.clickNav(d.page.getByRole('button', { name: 'Sign in' }));
    } },
    { caption: 'The e-mailed code', say: 'Every login asks for a second step: a six digit code, e-mailed to you at that moment. Open your mail, type the code, and press Verify. The code works once, for a few minutes only. Never share it: nobody from NexaCrest will ever ask you for it.', do: async (d) => { await typeLoginCode(d); } },
    { caption: 'Dashboard', say: 'You land on the Dashboard. A search box for any client or order reference. My Approvals. Your active orders by stage, overdue payments, and the latest activity on your own orders. On the left, the short menu: Dashboard, Approvals, My tasks, Orders and the other sections, each with its own menu bar.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: /Search/ }), 1200); await d.scrollTo(d.page.getByRole('heading', { name: /Active Orders by Stage/ })); await d.point(d.page.getByRole('heading', { name: /Active Orders by Stage/ }), 1500); } },
    { caption: 'My Approvals', say: 'My Approvals counts the documents waiting for your review, and amendments waiting for approval. As an Export Executive you approve nothing, so here it stays at zero: your documents go to the Sales Manager.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'My Approvals' })); await d.point(d.page.getByRole('heading', { name: 'My Approvals' }), 1200); await d.point(d.page.locator('.stat-tile').first(), 1500); } },
    { caption: 'Logged in as', say: 'Bottom left, the app shows who you are working as: Sales and Export Executive, in the Sales and Export department. What you can see and do follows from this. Someone who belongs to two departments switches here.', do: async (d) => { await d.point('.dept-badge', 2500); } },
    { goto: '/my-tasks', caption: 'My Tasks', say: 'My Tasks is your to-do list. When another department finishes a step that you have to follow up, the task appears here, with a due date; when you do it, it disappears. Right now: nothing to do. We will come back here in Part 2.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByText('Nothing to do right now', { exact: false }), 1500); } },
    { goto: '/orders', caption: 'Orders', say: 'Orders shows every order you are assigned to, as cards: the reference, the buyer, the stage, and the order value. The tabs filter them: active, overdue, complete, lost. If you prefer the Kanban template, the same orders appear as a board, with exactly the same filters and counts.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('a, button').filter({ hasText: /^Active/ }).first(), 1200); await d.point(d.page.locator('a, button').filter({ hasText: /^Overdue/ }).first(), 1000); } },

    // ---------- 2. A new buyer ----------
    { card: 'chapter', title: 'A new buyer', subtitle: 'Quotation Intake Review.', say: 'Chapter two. A new buyer.' },
    { goto: '/client-intake', caption: 'Orders › Quotation requests', say: 'A stone importer in Dubai, a test buyer, filled in our public Quotation Details Form: company, address, consignee, port and incoterm. It lands here, in Orders, Quotation requests: the Quotation Intake Review. The badge in the menu counts what is waiting.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('tr').filter({ hasText: B.BUYER.company }).first(), 2000); } },
    { caption: 'Accept & Create Client', say: 'You check the details. If something is wrong, Resend Link lets the buyer correct it, and Reject needs a reason. All good, so: Accept and Create Client.', do: async (d) => { await d.point(d.page.getByRole('button', { name: 'Resend Link' }).first(), 900); await d.point(d.page.getByRole('button', { name: 'Reject' }).first(), 900); await d.clickNav(d.page.getByRole('button', { name: 'Accept & Create Client' }).first()); } },
    { caption: 'The client and its Buyer Inquiry Ref', say: 'The client exists now, with its own Buyer Inquiry Reference. Accepting creates no order. That is the next click: New Order for this client.', do: async (d) => { await d.point('.alert', 1800); await d.point('h1', 900); await d.clickNav(d.page.getByRole('link', { name: /New Order for this client/ })); } },

    // ---------- 3. The order ----------
    { card: 'chapter', title: 'Creating the order', subtitle: 'Terms, then the product lines.', say: 'Chapter three. Creating the order.' },
    { caption: 'Commercial & shipping terms', say: 'The client is already chosen, and the buyer\'s port of discharge, Jebel Ali, came from the form. Choose the incoterm: F O B, the buyer arranges the freight. The currency: US dollars. And the payment preset: Standard, New Buyer, forty percent advance and sixty percent balance. These terms are copied onto the order: they cannot be changed later by Edit, only by an amendment.', do: async (d) => {
      await d.select('select[name="incoterm_id"]', { label: 'FOB' });
      await d.select('select[name="currency_id"]', { label: 'USD — US Dollar' });
      await d.select('select[name="payment_preset_id"]', { label: 'Standard — New Buyer (40% adv / 60% bal)' });
      await d.point('input[name="port_of_discharge_text"]', 900);
    } },
    { caption: 'Pick from Products', say: 'Now the product line. On a US dollar order, a goods line is picked from Products. The list offers only products that are ready to quote: configured by the product manager, with an active supplier. Any other product is greyed at the bottom, under Not offered yet, with the reason. Pick Black Galaxy Granite Slab: the description, the H S code and the unit fill in by themselves, and the product\'s options appear.', do: async (d) => {
      const pick = d.page.locator('select.product-picker').first();
      await d.scrollTo(pick);
      await d.select(pick, { label: 'Black Galaxy Granite Slab (HS 68022390)' });
      await d.point('.product-picker-hint', 2000);
    } },
    { caption: 'Thickness and Size / format', say: 'Two options start empty, on Choose: the Thickness, and the Size or format. The price depends on them, so the app shows no price until you have chosen both. Thickness: twenty millimetres. Size or format: gangsaw slab. Now the price fills in. The hint shows the quote price, and the lowest price you may agree. Below that, the Managing Director must approve the line. The finish comes from the Surface finish option, Natural Polish, so a picked line has no separate finish box.', do: async (d) => {
      const opts = d.page.locator('.product-row').first().locator('.spec-options');
      await d.point(opts, 1200);
      await d.select(opts.locator('select[aria-label="Thickness"]'), { label: '20 mm' });
      await d.select(opts.locator('select[aria-label="Size / format"]'), { label: 'Gangsaw slab' });
      await d.wait(1200);
      await d.point('.product-picker-hint', 2200);
      await d.point(opts.locator('select[aria-label="Surface finish"]'), 1200);
    } },
    { caption: 'Size and quantity', say: 'Add the size as it prints on the documents, three hundred by one eighty by two centimetres, and the quantity: two hundred square metres. Then: Create Order.', do: async (d) => {
      await d.type('input[name="product_dimensions[]"]', '300 x 180 x 2 cm');
      await d.type('input[name="product_quantity[]"]', '200');
      await d.point('input[name="product_unit_price[]"]', 900);
      await d.clickNav(d.page.getByRole('button', { name: 'Create Order' }));
      order = B.orderOf(demo);
    } },
    { caption: 'Your order page', say: 'The order is created, with its own reference. Everything about this order happens on this one page. At the top, the tracker with the nine stages. Below it, the owners per department, and At a Glance: the stage, the incoterm, the port, the payment terms and the order value.', do: async (d) => { await d.point('.alert', 1500); await d.point('.process-stepper', 1500); await d.point(d.page.getByRole('heading', { name: 'At a Glance' }), 1800); } },
    { caption: 'Products', say: 'Under Products, the order line you just entered, in a compact table: the product and its options, the size, the quantity, the price and the price check. Until the quotation is approved, you can still change a line, or add one with Add product. These lines feed every document from now on: the quotation, the invoices, the packing list. Once the quotation is approved, the prices and products are fixed, and a change goes through an amendment.', do: async (d) => { await d.click(d.page.locator('#step-products > summary')); await d.point(d.page.locator('#step-products table.products-table tbody tr').first(), 1800); await d.point(d.page.locator('#step-products').getByRole('button', { name: '+ Add product' }), 1200); await d.click(d.page.locator('#step-products > summary')); } },
    { caption: 'Order Communication', say: 'Further down, Order Communication: a running conversation with the buyer, which they see in their portal. And under Order Booking, the steps for you: Step 1, Prepare Quotation, is highlighted, because that is next.', do: async (d) => { await d.scrollTo('#order-updates'); await d.point('#order-updates > summary', 1500); await A.openTo(d, '#step-1-quotation-prepare'); await d.point('#step-1-quotation-prepare > summary', 1500); } },

    // ---------- 4. The quotation ----------
    { card: 'chapter', title: 'The quotation', subtitle: 'Generate, then review and approval.', say: 'Chapter four. The quotation.' },
    { caption: 'Annexure A, if you need it', say: 'Before generating: if the buyer needs a product specification sheet or extra terms, press Edit Annexure A here. It is attached to the quotation, the P I, the order confirmation and the buyer P O as one integral document. This buyer does not need one.', do: async (d) => { await d.goto(at('#step-1-quotation-prepare')); await A.openTo(d, '#step-1-quotation-prepare'); await d.point(d.page.locator('#step-1-quotation-prepare').getByRole('link', { name: /Annexure A/ }).first(), 2500); } },
    { caption: 'Step 1a · Generate Quotation', say: 'Step 1 a: Generate Quotation. The app builds the P D F from the order itself: the buyer, the terms, the lines, our bank details. Tick D O C X as well if you want an internal Word copy.', do: async (d) => { await d.goto(at('#step-1-quotation-prepare')); await A.openTo(d, '#step-1-quotation-prepare'); await d.point(d.page.locator('#step-1-quotation-prepare').getByText('DOCX').first(), 1000); await d.clickNav(d.page.locator('#step-1-quotation-prepare').getByRole('button', { name: 'Generate Quotation' })); await d.point('.alert', 1500); } },
    { caption: 'A draft, waiting for a reviewer', say: 'The quotation exists, as a draft with a DRAFT watermark. Document Review and Approval says it plainly: generated, no reviewer assigned yet. Choosing the reviewer and approving is not your job: the Sales Manager does it. No document ever reaches the buyer before it is approved.', do: async (d) => { await A.openTo(d, '#document-review-approval'); await d.point('#document-review-approval > summary', 1200); await d.point(d.page.locator('#document-review-approval').getByText('no reviewer assigned yet', { exact: false }).first(), 2000); } },
    { as: 'salesmgr', caption: 'The Sales Manager assigns a reviewer', say: 'Here is the Sales Manager. They open the draft, and assign the reviewer: here, themselves.', do: async (d) => { await d.goto(at('#document-review-approval'));
      await A.openTo(d, '#document-review-approval');
      const summary = d.page.locator('#document-review-approval details > summary').filter({ hasText: 'Not sent for review' }).first();
      await d.click(summary);
      const panel = summary.locator('xpath=..');
      if (!(await panel.evaluate((el) => el.open))) await panel.evaluate((el) => { el.open = true; });
      const sel = panel.locator('select[name="reviewer_ids[]"]').first();
      const label = (await sel.locator('option').allInnerTexts()).find((t) => /Sales Manager/.test(t));
      await d.select(sel, { label });
      await d.clickNav(d.page.getByRole('button', { name: 'Assign Reviewers' }).first());
    } },
    { goto: '/reviews', caption: 'Approvals › Documents · Approve', say: 'It now sits in their review queue: Approvals, the Documents tab. They check the quotation, and approve. The P D F is redrawn without the draft watermark.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'QT' }).first(), 1200); await d.clickNav(d.page.getByRole('button', { name: 'Approve' }).first()); } },
    { as: 'exec', caption: 'Stage 1 is done', say: 'Back to you. Stage one is done by itself: while document e-mails to the buyer are held, in testing mode or switched off in the e-mail settings, approval counts as sent, and you send the approved P D F to the buyer yourself. When they are switched on, it goes from Document Review. Step 3, the client accepts, happens outside the app: a reply, a call.', do: async (d) => { await d.goto(at()); await d.point('.process-step[data-stage="1"]', 1200); await d.point('.process-step[data-stage="2"]', 1200); } },
    { caption: 'The approved quotation', say: 'In Document Review and Approval, the quotation\'s status now reads sent: with document e-mails held, approval counts as sent. Download the final P D F here, and e-mail it to the buyer. Only this final copy ever goes to a buyer: never the draft, never the Word file.', do: async (d) => { await A.openTo(d, '#document-review-approval'); const row = d.page.locator('#document-review-approval tr').filter({ hasText: 'SC/QT/' }).first(); await d.point(row, 1500); await d.point(row.getByRole('link', { name: 'PDF' }), 1500); } },
    { caption: 'Prices and products are now fixed', say: 'The approval changed one more thing. Open Products: a note says the prices and products are fixed by the approved quotation, at the exchange rate of that day. From now on nobody can change, add or remove a line here, not even a director. A change goes through an amendment, under Amendments, Products and prices, and a price can only go down, with the Managing Director\'s approval.', do: async (d) => { await A.openTo(d, '#step-products'); await d.point('#price-lock-note', 3500); await d.page.locator('#step-products').evaluate((el) => { el.open = false; }); } },

    // ---------- 5. The buyer's PO ----------
    { card: 'chapter', title: 'The buyer\'s PO', subtitle: 'Generate, link, signed copy, PO number.', say: 'Chapter five. The buyer\'s purchase order.' },
    { caption: 'Step 4a · Generate Buyer PO', say: 'The buyer accepted. Step 4 a: Generate Buyer P O. This is our purchase order form, in the buyer\'s name, with the milestones and this order\'s payment wording. It goes through review and approval like every document.', do: async (d) => { await d.goto(at('#step-group-stage-2')); await A.openTo(d, '#step-4a-buyer-po-generate'); await d.clickNav(d.page.locator('#step-4a-buyer-po-generate').getByRole('button', { name: 'Generate Buyer PO' })); await d.point('.alert', 1500); } },
    { caption: 'Step 4b · Buyer Action Link', say: 'The Sales Manager approved it. Step 4 b, optional: the Buyer Action Link. One click makes a link with no login, where the buyer downloads the approved P O, and uploads the signed copy with their P O number. Copy it, and send it to the buyer.', do: async (d) => {
      B.approve(demo, order, 'BUYERPO');
      await d.goto(at('#step-4b-buyer-po-link'));
      await A.openTo(d, '#step-4b-buyer-po-link');
      await d.clickNav(d.page.locator('#step-4b-buyer-po-link button[type=submit]').first());
      await A.openTo(d, '#step-4b-buyer-po-link');
      await d.point(d.page.locator('#step-4b-buyer-po-link').getByRole('button', { name: 'Copy Link' }), 1500);
    } },
    { caption: 'Step 4c · The signed copy', say: 'If the buyer uses the link, steps 4 c and 4 d complete by themselves. This buyer e-mailed the signed P O instead. So you attach it, in Step 4 c. A signed copy is required before the P O can be confirmed.', do: async (d) => {
      await A.openTo(d, '#step-4c-buyer-po-attach');
      await d.point('#step-4c-buyer-po-attach > summary', 1500);
      await d.upload('#step-4c-buyer-po-attach input[type=file]', demo.sampleFile('Buyer PO signed - SAMPLE.pdf', 'SIGNED BUYER PO - SAMPLE'));
      await d.clickNav(d.page.locator('#step-4c-buyer-po-attach button[type=submit]').first());
      await A.openTo(d, '#step-4c-buyer-po-attach');
      await d.point('#step-4c-buyer-po-attach', 1500);
    } },
    { caption: 'Step 4d · Confirm Buyer PO Received', say: 'Step 4 d: the buyer\'s own P O number, and the date it arrived, by e-mail or post. Not the date printed on it, and never a date in the future. Confirm Buyer P O Received.', do: async (d) => {
      await A.openTo(d, '#step-4d-buyer-po-confirm');
      await d.type('#step-4d-buyer-po-confirm input[name="buyers_po_ref"]', 'GSI-PO-2611-14');
      await d.point('#step-4d-buyer-po-confirm input[name="buyer_po_received_on"]', 1000);
      await d.clickNav(d.page.locator('#step-4d-buyer-po-confirm').getByRole('button', { name: 'Confirm Buyer PO Received' }));
    } },
    { caption: 'Stage 2 passed', say: 'Buyer P O recorded, and stage three, the proforma invoice, is unlocked. At a Glance now shows the buyer\'s P O reference too.', do: async (d) => { await d.point('.alert', 2000); await d.point('.process-step[data-stage="2"]', 1000); await d.point('.process-step[data-stage="3"]', 1200); } },

    { card: 'list', kicker: 'Recap', title: 'Part 1 in four lines', items: [
      'Log in with your password and the e-mailed code; you work as Sales & Export.',
      'Accept the buyer in Quotation requests; New Order; choose Thickness and Size.',
      'Generate the Quotation; once approved, prices and products are fixed.',
      'Buyer PO: generate, link or signed copy, then the PO number and date.',
    ], next: 'B01 · Part 2 · Proforma, payment, confirmation', say: 'Let us recap. You logged in with your password and the code we e-mailed, working as Sales and Export. You accepted the new buyer in Quotation requests, and created the order from the client page, picking the product from Products and choosing its thickness and size before the price appeared. You generated the quotation, and the Sales Manager reviewed and approved it, which fixed the prices and products. Then the buyer\'s P O: generated, the action link or the signed copy, and the P O number with the date it arrived. In Part 2: the proforma invoice, recording the advance, the order confirmation, talking to the buyer, and the hand-off to Procurement.' },
  ],
};
