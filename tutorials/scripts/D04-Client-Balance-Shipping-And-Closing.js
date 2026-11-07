'use strict';
// D04 — For buyers: shipping documents, the balance, closing, feedback and reordering (scenarios 14–17).
// The goods have sailed: the buyer gets the scanned BL and the Commercial Invoice, finds the shipping
// documents in the portal, reports the balance payment, sees it reviewed, the order closes, the buyer
// leaves feedback and sends a Reorder This request. Staff steps run off camera (scripts/_buyer.js).
// Source of truth: docs/SOP/12-client-portal.md, 08/09 stage chapters, 17 (reorder); ClientPortalController.

const demo = require('../lib/demo');
const B = require('./_buyer');
const { clientCookies } = require('./_overview');

const st = { client: 0, order: 0, ref: '', ci: '', fdn: '', balance: '' };
const portal = (o, hash = '') => `/client/orders/${o}${hash}`;
const section = (d, title) => d.page.locator('.section').filter({ has: d.page.locator('h2', { hasText: title }) }).first();
const today = () => new Date().toISOString().slice(0, 10);

module.exports = {
  code: 'D04',
  file: 'D04-Client-Balance-Shipping-And-Closing.mp4',
  group: 'Group D · Buyers',
  title: 'Balance, Shipping and Closing',
  subtitle: 'For buyers: your shipping documents, paying the balance, your feedback, and ordering again.',
  minutes: '9',
  restoreDb: true,

  async setup(d) {
    const ctx = await B.setup(d, { withClient: true });
    st.client = ctx.clientId;
    st.order = B.createOrder(d, st.client);
    // The unit as the order form's drop-down stores it, so Reorder This pre-selects it.
    d.sql(`UPDATE order_products SET unit = 'SQM' WHERE order_id = ${st.order}`);
    B.moves.readyForPayment(d, st.order, 'NSS-PO-2611-01');
    B.moves.advanceRecorded(d, st.order);
    B.moves.ocSent(d, st.order);
    B.moves.ocAcknowledged(d, st.order);
    // Production, freight, packing, Bill of Lading, Commercial Invoice; scanned BL sent.
    st.ci = B.moves.shipped(d, st.order);
    st.ref = d.sql(`SELECT order_reference FROM orders WHERE id = ${st.order}`);
    st.fdn = B.ref(d, st.order, 'FDN');
    // Updates our team posted in Order Updates during production and shipping (the app's own service).
    require('./_a02').php(d, `$exec = $uid('export.executive@email.com');
      App\\Services\\OrderCommentService::postAsStaff(${st.order}, $exec, 'Production update: your slabs and tiles are finished and passed our quality check. Packing starts on Monday.', []);
      App\\Services\\OrderCommentService::postAsStaff(${st.order}, $exec, 'Your goods are loaded on MV Sample Voyager, container SAMU7654321. The scanned Bill of Lading and the Commercial Invoice follow by e-mail.', []);`);
    st.balance = Number(d.sql(`SELECT balance_amount FROM order_payment_status WHERE order_id = ${st.order}`)).toFixed(2);
    B.portalPassword(d);
    ctx.actors.buyer.cookies = await clientCookies(d, B.BUYER_EMAIL);
    return ctx;
  },

  steps: () => [
    { card: 'title', say: 'Welcome back! Our buyer\'s granite has been produced, packed, and loaded on the ship. In this video: your shipping documents and the commercial invoice, telling us about your balance payment, closing the order, giving us your feedback, and ordering again with one click.' },
    { card: 'list', kicker: 'In this video', title: 'Four things to know', items: [
      'Your shipping documents and the Commercial Invoice.',
      'Paying the balance, and telling us about it.',
      'Closing the order, and your feedback.',
      'Reorder This: the same goods again, in a minute.',
    ], say: 'Four things. Your shipping documents and the commercial invoice. Paying the balance, and telling us about it. Closing the order, with your feedback. And Reorder This, to order the same goods again.' },

    // ---------- 1. Shipping documents ----------
    { card: 'chapter', title: 'Your goods are on the way', subtitle: 'The scanned Bill of Lading and the Commercial Invoice.', say: 'Part one. Your goods are on the way.' },
    { card: 'list', kicker: 'Since your acknowledgement', title: 'What our team has done', items: [
      'Ordered the stone and followed production at the factory.',
      'Checked quality, and arranged the inspection and certificates.',
      'Booked and insured the sea freight (CIF orders).',
      'Packed your goods in crates and loaded them on the ship.',
    ], say: 'Since you acknowledged the Order Confirmation, our team has been busy. We ordered the stone, and followed production at the factory. We checked the quality, and arranged the inspection and the certificates your goods need. On a C I F order like this one, we booked and insured the sea freight. And we packed your goods in crates, and loaded them on the ship.' },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Debit Note ${st.fdn} — Payment Required Before Shipment`; },
      get lines() { return ['Dear Purchasing Manager,', `Please find attached Debit Note ${st.fdn}.`, 'Payment is required within 3 Calendar Days of this Debit Note\'s date. We will confirm shipment booking only upon receipt of full freight payment.']; },
      get attach() { return [`${st.fdn.replace(/\//g, '-')}.pdf`]; },
      say: 'A step back, to just before shipment. On a C I F order, we book and insure the sea freight for you, so you receive a debit note for the freight and insurance. It is paid within three days, before we confirm the shipment booking. You tell us about that payment the same way as any other: on the Payment Action Link, or in the portal, choosing freight payment.' },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Bill of Lading Copy — Order ${st.ref}`; },
      get lines() { return ['Dear Purchasing Manager,', `Please find attached the scanned copy of the Bill of Lading for your order ${st.ref}.`, 'BL originals will be couriered upon clearance of the balance payment, per the agreed terms.']; },
      attach: ['Bill-of-Lading-scanned-copy.pdf'],
      say: 'When the ship has sailed, the shipping line issues the bill of lading. We e-mail you a scanned copy straight away. The original bills of lading follow by courier once your balance payment has cleared, as agreed in your terms.' },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Commercial Invoice ${st.ci} — ${B.BUYER.company}`; },
      get lines() { return ['Dear Purchasing Manager,', `Please find attached Commercial Invoice ${st.ci} for your order.`, 'Kindly review the invoice value and payment settlement details and arrange the balance payment as per the terms stated.']; },
      get attach() { return [`${st.ci.replace(/\//g, '-')}.pdf`]; },
      say: 'Together with it comes the commercial invoice. It is made from the quantities actually packed and shipped, shows the advance you already paid, and the balance still to pay.' },
    { as: 'buyer', goto: '/client', caption: 'My Orders', say: 'Let us sign in to the portal. The order is now at stage eight: Commercial Invoice and Balance.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: st.ref }), 2500); } },
    { caption: 'Your shipping documents', say: 'Open the order. In Documents, the new ones are at the top: the commercial invoice, the packing list with every crate and its weight, and the debit note for the sea freight and insurance, which our team arranged because this is a C I F order.', do: async (d) => {
      await d.goto(portal(st.order));
      const s = section(d, 'Documents');
      await d.scrollTo(s); await d.point(s.locator('tr').filter({ hasText: 'Commercial Invoice' }), 2200);
      await d.point(s.locator('tr').filter({ hasText: 'Packing List' }), 1800);
      await d.point(s.locator('tr').filter({ hasText: 'Debit Note' }), 1800);
    } },
    { caption: 'Order Updates', say: 'And in Order Updates, further down, you find the news our team posted along the way: production finished and checked, and the vessel and container your goods were loaded on.', do: async (d) => {
      await d.scrollTo('#order-updates'); await d.point('#order-updates h2', 1000);
      await d.point('#order-updates .chat-thread', 4500);
    } },

    // ---------- 2. The balance ----------
    { card: 'chapter', title: 'Paying the balance', subtitle: 'Then tell us about it, with proof.', say: 'Part two. Paying the balance.' },
    { caption: 'Report a Payment', say: 'Pay the balance by bank transfer, to the account on the commercial invoice. Then tell us about it. You can use the Payment Action Link from our e-mail, as in the second video, or, now that you have a login, Report a Payment on the order page.', do: async (d) => {
      const s = section(d, 'Report a Payment');
      await d.scrollTo(s); await d.point(s.locator('h2'), 1200); await d.point(s.locator('p.muted').first(), 2500);
    } },
    { caption: 'Your transfer details', say: 'Choose which payment: the balance. Type the transaction I D, or U T R, from your bank, the amount, and the date you paid. Add which bank you paid from, and attach the bank\'s remittance advice. Then: Submit Payment Details.', do: async (d) => {
      const s = section(d, 'Report a Payment');
      await d.select(s.locator('select[name="payment_type"]'), 'balance');
      await d.type(s.locator('input[name="transaction_ref"]'), 'NSSBANK20261121004');
      await d.type(s.locator('input[name="amount"]'), st.balance);
      await d.point(s.locator('input[name="payment_date"]'), 500);
      await s.locator('input[name="payment_date"]').fill(today());
      await d.type(s.locator('input[name="payer_bank_details"]'), 'Sent from our bank in Rotterdam, account ending 0000');
      await d.upload(s.locator('input[name="screenshot"]'), demo.sampleFile('balance-remittance-sample.pdf', 'SAMPLE REMITTANCE ADVICE - BALANCE - TUTORIAL DEMO'));
      await d.clickNav(s.getByRole('button', { name: 'Submit Payment Details' }));
    } },
    { caption: 'Received — pending review', say: 'Thank you: we have noted your payment details. Below, under Previously Reported, your report shows: received, pending review. This does not mark the money as received. Our accounts team checks the real bank statement first.', do: async (d) => {
      await d.point('.alert', 2200);
      const s = section(d, 'Report a Payment');
      await d.scrollTo(s.locator('h3')); await d.point(s.locator('table.list').last(), 2600);
    } },
    { card: 'list', kicker: 'Paying the balance', title: 'Three tips', items: [
      'The balance falls due by the days in your payment terms, counted from the scanned Bill of Lading.',
      'Pay to the bank account on the Commercial Invoice; quote the invoice number.',
      'Every report stays listed under Previously Reported, with your attachment.',
    ], say: 'Three tips for the balance. It falls due by the number of days in your payment terms, counted from the day we sent you the scanned bill of lading. Pay to the bank account printed on the commercial invoice, and quote the invoice number in your payment. And every payment you report stays listed under Previously Reported, with your attachment, so you always have a record.' },
    { card: 'chapter', title: 'Our side: received, then cleared', subtitle: 'Two people, for every payment.', say: 'A quick look at our side.', do: async () => { B.moves.balanceCleared(demo, st.order); } },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Balance Payment Received — Order ${st.ref}`; },
      get lines() { return ['Dear Purchasing Manager,', `We confirm receipt and clearance of the balance payment for your order ${st.ref}.`, 'Thank you for your business — we are proceeding with final despatch formalities.']; },
      say: 'Our accounts team found your balance on the bank statement and recorded it. Then the Finance Manager confirmed it has really arrived, and cleared it. Two people check every payment. You receive this confirmation.' },
    { as: 'buyer', caption: 'Reviewed by our team', say: 'In the portal, your report now reads: reviewed by our team.', do: async (d) => {
      await d.goto(portal(st.order));
      const s = section(d, 'Report a Payment');
      await d.scrollTo(s.locator('h3')); await d.point(s.locator('table.list').last().locator('tr').last(), 2800);
    } },

    // ---------- 3. Closing and feedback ----------
    { card: 'chapter', title: 'Closing the order', subtitle: 'Original documents by courier — and your feedback.', say: 'Part three. Closing the order.', do: async () => { B.moves.docsSent(demo, st.order); } },
    { card: 'list', kicker: 'After the balance', title: 'Your original documents', items: [
      'The original bills of lading are endorsed over to you.',
      'We courier the complete set of original documents to you.',
      'With them you collect the goods at the port of discharge.',
      'Nothing to do in the portal: the order moves to Complete.',
    ], say: 'Once your balance has cleared, the original bills of lading are endorsed over to you, and we courier the complete set of original documents to you: the endorsed bills of lading, the final commercial invoice, the certificate of origin, and any other certificates your order needs. With them, you collect your goods at the port of discharge. There is nothing to do in the portal: the order simply moves to Complete.' },
    { as: 'buyer', goto: '/client', caption: 'Complete', say: 'And there it is, in My Orders: Complete. Shipped and finished. The order and all its documents stay in your list, for as long as you need them.', do: async (d) => {
      const s = section(d, 'Complete');
      await d.point(s.locator('h2'), 1500); await d.point(s.locator('tr').filter({ hasText: st.ref }), 2000);
    } },
    { caption: 'Order Feedback', say: 'Inside the order, at the bottom, Order Feedback is now open. How did we do? Choose a rating, from one to five stars. We have written a suggestion from your own order to get you started. Change it as you like.', do: async (d) => {
      await d.goto(portal(st.order));
      const s = section(d, 'Order Feedback');
      await d.scrollTo(s); await d.point(s.locator('h2'), 1200);
      await d.select(s.locator('select[name="rating"]'), '5');
      await d.point(s.locator('textarea[name="comments"]'), 3500);
    } },
    { caption: 'Submit Feedback', say: 'Let us add one line of our own, and submit.', do: async (d) => {
      const s = section(d, 'Order Feedback');
      const box = s.locator('textarea[name="comments"]');
      await d.moveTo(box); await box.click(); await d.page.keyboard.press('End');
      await box.pressSequentially(' Thank you to the whole team.', { delay: 45 });
      await d.wait(600);
      await d.clickNav(s.getByRole('button', { name: 'Submit Feedback' }));
    } },
    { caption: 'Thank you', say: 'Thank you: your feedback has been recorded. Our team sees it at once. You can come back and update it any time: it replaces your earlier feedback, it does not add a second one. Prefer to tell us by phone? Our team can record your rating for you.', do: async (d) => {
      await d.point('.alert', 2000);
      const s = section(d, 'Order Feedback');
      await d.scrollTo(s); await d.point(s.getByRole('button', { name: 'Update Feedback' }), 2200);
    } },

    // ---------- 4. Reorder This ----------
    { card: 'chapter', title: 'Ordering again', subtitle: 'Reorder This — the same goods, in a minute.', say: 'Part four. Ordering again.' },
    { caption: 'Reorder This', say: 'Happy with the granite? At the top of any order, click Reorder This.', do: async (d) => {
      await d.scrollTo('h1'); await d.point(d.page.getByRole('link', { name: 'Reorder This' }), 1500);
      await d.clickNav(d.page.getByRole('link', { name: 'Reorder This' }));
    } },
    { caption: 'Your product lines, copied', say: 'The form starts with the product lines of this order: the description, size, finish, quantity and unit. Edit them, remove a line with the cross, or add one with Add product line. Not sure of a quantity yet? Tick quantity to be confirmed. There are no prices here: our team confirms prices when we review your request.', do: async (d) => {
      await d.point('h1', 1200); await d.point('.product-row', 3000);
      await d.point(d.page.getByRole('button', { name: '+ Add product line' }), 1500);
    } },
    { caption: 'Change and add', say: 'This time our buyer wants two hundred square metres of slabs, instead of one hundred and fifty, and adds a note.', do: async (d) => {
      await d.type('input[name="product_quantity[0]"]', '200');
      await d.type('textarea[name="notes"]', 'Same as before, but 200 sqm of slabs. Shipment in March if possible.', { delay: 40 });
    } },
    { caption: 'Submit Reorder Request', say: 'Submit Reorder Request.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Submit Reorder Request' })); await d.point('.alert', 2500); } },
    { as: 'exec', goto: '/reorder-requests', caption: 'Our side: Reorder Requests', say: 'On our side, your request lands in Reorder Requests, with your note. Nothing here becomes an order by itself. The Export Executive opens it to review.', do: async (d) => {
      await d.point('h1', 1000); await d.point(d.page.locator('tr').filter({ hasText: B.BUYER.company }).first(), 2500);
      await d.clickNav(d.page.locator('tr').filter({ hasText: B.BUYER.company }).first().getByRole('link', { name: 'Review' }));
    } },
    { caption: 'Prices and HS codes', say: 'Here are your product lines, as you sent them. Our team sets the customs H S code and the unit price for each line, and then approves it, which creates the new order.', do: async (d) => { await d.point('h1', 1200); await d.scrollBy(400); await d.wait(4000); } },
    { card: 'list', kicker: 'What happens next', title: 'Your reorder request', items: [
      'It does not create an order by itself: our team reviews it first.',
      'We set the prices and the customs (HS) codes.',
      'Once approved, it becomes a new order, with a new quotation for you.',
      'Not right? We tell you why, and you can send a new request.',
    ], say: 'What happens next? Your request does not create an order by itself. Our team reviews it, and sets the prices and the customs codes. Once approved, it becomes a new order, and you receive a new quotation, just like the first time. And if we cannot accept it as it is, we tell you why, and you can send a new request.' },

    { card: 'list', kicker: 'Recap', title: 'Balance, shipping and closing', items: [
      'Scanned BL and Commercial Invoice by e-mail; documents in the portal.',
      'Report a Payment: your balance, with proof; we check the bank statement.',
      'Balance cleared: original documents by courier; the order is Complete.',
      'Order Feedback: a rating and your comments.',
      'Reorder This: your lines copied, prices from our team.',
    ], next: 'D05 · When something goes wrong', say: 'Let us recap. You received the scanned bill of lading and the commercial invoice, and found every shipping document in the portal. You reported the balance payment with proof, and our team checked it against the bank statement. Once cleared, the original documents went to you by courier, and the order is complete. You left your feedback. And with Reorder This, you asked for the same goods again. Next video: what to do when something goes wrong. See you there!' },
  ],
};
