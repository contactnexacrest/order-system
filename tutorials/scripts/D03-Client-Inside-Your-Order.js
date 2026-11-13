'use strict';
// D03 — For buyers: inside your order in the client portal (scenarios 10–13).
// My Orders and its status groups, acknowledging the Order Confirmation, the documents the buyer may
// download (final, customer-facing copies only — never internal ones), and the Order Updates chat
// with an Export Executive reply. Staff routine steps run off camera (scripts/_buyer.js).
// Source of truth: docs/SOP/12-client-portal.md, docs/APPLICATION_HANDBOOK.md 5.8; ClientPortalController.
// Updated 13 Nov 2026 (docs audit C; not recorded, recording on hold): D-330 portal wording — "Open order",
// "Did not go ahead", "Please confirm your order", "Yes, this is correct — please go ahead", "Your purchase
// order", "Tell us what we could do better"; buyer stage names (Quotation · Order confirmed · In production ·
// Shipping · Shipped); demo finish "Natural Polish" (D-357).

const path = require('path');
const fs = require('fs');
const { spawnSync } = require('child_process');
const demo = require('../lib/demo');
const cfg = require('../lib/config');
const A = require('./_a02');
const B = require('./_buyer');

const st = { client: 0, order: 0, ref: '', oc: '', pi: '' };
const portal = (o, hash = '') => `/client/orders/${o}${hash}`;
const group = (d, label) => d.page.locator('.section').filter({ has: d.page.locator('h2', { hasText: label }) }).first();

/** A plain placeholder photo for the chat (no real product photo is used in the tutorials). */
function samplePhoto() {
  const dir = path.join(cfg.WORK, 'samples'); fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, 'slabs-before-packing-sample.png');
  const font = '/usr/share/fonts/truetype/dejavu/DejaVuSans';
  spawnSync('ffmpeg', ['-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=0x2b2b2b:s=800x500', '-vf',
    `drawbox=x=60:y=60:w=680:h=380:color=0x3a3a3a:t=fill,drawtext=fontfile=${font}-Bold.ttf:text='SAMPLE PHOTO':fontcolor=white:fontsize=48:x=(w-text_w)/2:y=190,`
    + `drawtext=fontfile=${font}.ttf:text='Black Galaxy slabs before packing':fontcolor=0xdddddd:fontsize=30:x=(w-text_w)/2:y=270`,
    '-frames:v', '1', file]);
  return file;
}

module.exports = {
  code: 'D03',
  file: 'D03-Client-Inside-Your-Order.mp4',
  group: 'Group D · Buyers',
  title: 'Inside Your Order',
  subtitle: 'For buyers: My Orders, confirming your order, your documents, and messages with our team.',
  minutes: '9',
  restoreDb: true,

  async setup(d) {
    const ctx = await B.setup(d, { withClient: true });
    st.client = ctx.clientId;
    const paid = (po) => { const o = B.createOrder(d, st.client); B.moves.readyForPayment(d, o, po); B.moves.advanceRecorded(d, o); B.moves.ocSent(d, o); return o; };
    // Earlier orders of the same buyer, one in every group of My Orders (created first, so the
    // order this video follows is the newest). Complete: every stage done and documents couriered.
    const done = paid('NSS-PO-2605-01');
    B.moves.ocAcknowledged(d, done); B.moves.shipped(d, done); B.moves.balanceCleared(d, done); B.moves.docsSent(d, done);
    // Disputed: set by our team (Admin) while a dispute is reviewed.
    const disputed = paid('NSS-PO-2607-02');
    B.moves.ocAcknowledged(d, disputed);
    A.php(d, `App\\Repositories\\AdminOverrideRepository::updateOrderStatusLock(${disputed}, 'disputed', false);`);
    // Lost: a quotation that did not go ahead.
    const lost = B.createOrder(d, st.client);
    B.moves.quotation(d, lost);
    A.php(d, `OrderRepository::markLost(${lost}, 'Buyer chose to postpone the project', $uid('export.executive@email.com'));`);
    // Cancelled: called off after the Proforma Invoice.
    const cancelled = B.createOrder(d, st.client);
    B.moves.readyForPayment(d, cancelled, 'NSS-PO-2609-03');
    A.php(d, `OrderRepository::cancel(${cancelled});`);
    // The order this video follows: advance cleared, Order Confirmation sent, waiting for the buyer.
    st.order = B.createOrder(d, st.client);
    st.pi = B.moves.readyForPayment(d, st.order, 'NSS-PO-2611-01');
    B.moves.advanceRecorded(d, st.order);
    st.oc = B.moves.ocSent(d, st.order);
    st.ref = d.sql(`SELECT order_reference FROM orders WHERE id = ${st.order}`);
    B.portalPassword(d);
    return ctx;
  },

  steps: () => [
    { card: 'title', say: 'Welcome back! Our buyer, Northsea Stone Trading in Rotterdam, has paid the advance, and now has a login to our client portal. In this video we look inside the portal: your list of orders, confirming your order, the documents you can download, and talking to our team about your order.' },
    { card: 'list', kicker: 'In this video', title: 'Four things to know', items: [
      'My Orders: every order, grouped by where it stands.',
      'Your Order Confirmation: acknowledge it, and production starts.',
      'Documents: final copies, ready to download any time.',
      'Order Updates: a running conversation with our team.',
    ], say: 'Four things. My Orders, where every order is grouped by where it stands. Your Order Confirmation, which you acknowledge so production can start. Your documents, ready to download. And Order Updates, a running conversation with our team about the order.' },

    // ---------- 1. My Orders ----------
    { card: 'chapter', title: 'My Orders', subtitle: 'Every order, grouped by where it stands.', say: 'Part one. My Orders.' },
    { as: 'buyer', caption: 'Sign in', say: 'Sign in to the client portal with your e-mail address and your password. Then type the six-digit code we e-mail you, and click Verify.', do: async (d) => { await B.signIn(d, 2200); } },
    { caption: 'My Orders', say: 'This is My Orders, the first page you see. It lists every order you have with us, grouped by where it stands. Each group has a one-line explanation, and each order shows its number, where it is now, and the date it was ordered. Where it is now is said in plain words: quotation, order confirmed, in production, shipping, or shipped.', do: async (d) => { await d.point('h1', 1200); await d.point(group(d, 'Active'), 2500); } },
    { caption: 'Active', say: 'Active orders are moving through our process right now. Here is the newest one: your advance is in, so it reads in production, and it is waiting for you to confirm the order. We will open it in a moment.', do: async (d) => { await d.point(group(d, 'Active').locator('tr').filter({ hasText: st.ref }), 2800); } },
    { caption: 'Complete', say: 'Complete: shipped and finished. Once the original documents have been sent to you, the order moves here.', do: async (d) => { await d.scrollTo(group(d, 'Complete')); await d.point(group(d, 'Complete').locator('h2'), 2500); } },
    { caption: 'Disputed, Did not go ahead, Cancelled', say: 'Disputed: our team is reviewing a dispute on the order. Did not go ahead: for example a quotation you decided not to take. And cancelled: an order that was called off. A group only appears when it has an order in it. We look at cancelled and lost orders more closely in the last video for buyers.', do: async (d) => {
      await d.scrollTo(group(d, 'Disputed')); await d.point(group(d, 'Disputed').locator('h2'), 2200);
      await d.point(group(d, 'Did not go ahead').locator('h2'), 2200);
      await d.scrollTo(group(d, 'Cancelled')); await d.point(group(d, 'Cancelled').locator('h2'), 2200);
    } },
    { card: 'list', kicker: 'Good to know', title: 'Your list of orders', items: [
      'One login covers every order you have with us.',
      'Complete includes orders whose original documents have been sent to you.',
      'Your NexaCrest contact may set your list to show current orders only.',
      'Click "Open order" to open an order.',
    ], say: 'Good to know. One login covers every order you have with us. Complete also includes orders whose original documents have just been sent to you. Your NexaCrest contact can set your list to show only current orders, if you prefer. And to open an order, click Open order.' },

    // ---------- 2. Order Confirmation ----------
    { card: 'chapter', title: 'Your Order Confirmation', subtitle: 'Acknowledge it, and production starts.', say: 'Part two. Your Order Confirmation.' },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Order Confirmation ${st.oc} — ${B.OUR_COMPANY}`; },
      get lines() { return ['Dear Purchasing Manager,', `We are pleased to confirm your order. Please find attached Order Confirmation ${st.oc}, based on proforma invoice ${st.pi}.`, 'We will make your goods on the dates given in the attached document.']; },
      get attach() { return [`${st.oc.replace(/\//g, '-')}.pdf`]; },
      say: 'Once your advance has cleared, we send you the Order Confirmation. It confirms the products, quantities, price and terms we will produce and ship. Before production starts, we need your acknowledgement. You can give it on the Buyer Action Link in our e-mail, or in the portal, as we do now.' },
    { goto: '/client', caption: 'Open order', say: 'In My Orders, open the active order: Open order.', do: async (d) => {
      await d.clickNav(group(d, 'Active').locator('tr').filter({ hasText: st.ref }).getByRole('link', { name: 'Open order' }));
    } },
    { caption: 'Please confirm your order', say: 'At the top of the order: Please confirm your order. It sums up the order: the products and quantities, the total order value, the delivery terms, and the port where the goods arrive. The full Order Confirmation is in your documents, just below, to download and check.', do: async (d) => {
      const s = d.page.locator('.section').filter({ hasText: 'Please confirm your order' });
      await d.point(s.locator('h2'), 1500); await d.point(s.locator('table'), 4000);
    } },
    { card: 'list', kicker: 'Before you acknowledge', title: 'Check these on the Order Confirmation', items: [
      'Products, sizes, finish and quantities.',
      'Unit prices and the total order value.',
      'Incoterm, port of discharge and the shipment schedule.',
      'Payment terms: what is paid, and when the balance is due.',
    ], say: 'Before you acknowledge, open the Order Confirmation and check it carefully. The products, sizes, finish and quantities. The unit prices and the total value. The Incoterm, the port of discharge, and the shipment schedule. And the payment terms: what you have paid, and when the balance is due. Your acknowledgement tells us to start production on exactly these terms.' },
    { caption: 'Yes, this is correct — please go ahead', say: 'If everything is right, click: Yes, this is correct, please go ahead. If something is not right, do not click. Write to us in Order Updates instead, and we will correct it first.', do: async (d) => {
      await d.point(d.page.getByRole('button', { name: 'Yes, this is correct — please go ahead' }), 3500);
      await d.clickNav(d.page.getByRole('button', { name: 'Yes, this is correct — please go ahead' }));
    } },
    { caption: 'Production starts', say: 'Thank you: your acknowledgement has been recorded, and your order is moving to production. The question at the top is gone. Next, we buy the stone for your order, and production begins.', do: async (d) => { await d.point('.alert', 3000); } },

    // ---------- 3. Documents ----------
    { card: 'chapter', title: 'Your documents', subtitle: 'Final copies only — download any time.', say: 'Part three. Your documents.', do: async () => {
      // Off camera: Procurement places the supplier purchase order (an internal document).
      A.move(demo, 'supplierPo', st.order);
    } },
    { caption: 'Documents', do: async (d) => {
      await d.goto(portal(st.order));
      const s = d.page.locator('.section').filter({ has: d.page.locator('h2', { hasText: 'Documents' }) }).first();
      await d.scrollTo(s); await d.point(s.locator('h2'), 1200); await d.point(s.locator('table'), 4500);
    }, say: 'Documents lists every document we have finalised for your order so far: the quotation, the Buyer P O, the Proforma Invoice, and the Order Confirmation. Each has its reference and date, and a Download P D F link. As the order moves on, more appear here: the freight debit note, the packing list, and the commercial invoice.' },
    { caption: 'Download PDF', say: 'Click Download P D F to save a copy, as often as you like. Open it with any P D F reader, and print it if you need to. These are the same final documents we e-mail you.', do: async (d) => { await d.point(d.page.getByRole('link', { name: 'Download PDF' }).first(), 3000); } },
    { card: 'list', kicker: 'Your documents', title: 'Only final, buyer documents', items: [
      'You see a document once it is approved — never a draft.',
      'Our internal papers never appear: supplier orders, checklists, cost sheets.',
      'Our purchase order to the factory was placed today — and is not in your list.',
      'Every download is checked again, not just the list.',
    ], say: 'What you see here, and what you never see. A document appears only once it has been checked and approved, never as a draft. Our internal papers never appear: our purchase orders to suppliers, our internal checklists, our cost sheets. For example, our team placed the purchase order with the factory for this order today, and it is not in your list. And this is checked again on every single download, not just hidden from the list.' },
    { goto: '/client', caption: 'A completed order', say: 'For comparison, let us open the completed order from My Orders. Its document list is full: from the quotation and the Proforma Invoice, to the freight debit note, the packing list and the commercial invoice. Everything we sent you for that order, in one place, long after it was shipped.', do: async (d) => {
      await d.clickNav(group(d, 'Complete').getByRole('link', { name: 'Open order' }));
      const s = d.page.locator('.section').filter({ has: d.page.locator('h2', { hasText: 'Documents' }) }).first();
      await d.point(s.locator('table'), 4500);
      await d.point(s.locator('tr').filter({ hasText: 'Commercial Invoice' }), 2500);
    } },
    { caption: 'Back to the active order', say: 'Back to our active order.', do: async (d) => { await d.goto(portal(st.order)); await d.point('h1', 1200); } },
    { caption: 'Your purchase order', say: 'Further down is Your purchase order, for a scanned copy of your signed purchase order. Each upload is kept, so a corrected copy never replaces the earlier one. Now that we have received your advance payment, uploading here is closed. If you need to send us a new copy, please contact your NexaCrest contact person.', do: async (d) => {
      const s = d.page.locator('.section').filter({ has: d.page.locator('h2', { hasText: 'Your purchase order' }) }).first();
      await d.scrollTo(s); await d.point(s.locator('h2'), 1200); await d.point(s.locator('p.muted.small').last(), 3000);
    } },

    // ---------- 4. Order Updates ----------
    { card: 'chapter', title: 'Order Updates', subtitle: 'A running conversation with our team.', say: 'Part four. Order Updates.' },
    { caption: 'Order Updates', say: 'Order Updates is a running conversation with our team about this order. Post a message any time, and attach photos, videos or P D F files if you like. Let us ask for photos of the slabs before they are packed.', do: async (d) => {
      await d.scrollTo('#order-updates'); await d.point('#order-updates h2', 1500);
      await d.type('#order-updates textarea[name="body"]', 'Hello, could you please send us photos of the Black Galaxy slabs before they are packed? Thank you.', { delay: 35 });
    } },
    { caption: 'Send', say: 'Send.', do: async (d) => { await d.clickNav(d.page.locator('#order-updates').getByRole('button', { name: 'Send' })); await d.scrollTo('#order-updates'); await d.point('#order-updates .chat-bubble-row', 2200); } },
    { as: 'exec', caption: 'Our side: Order Communication', say: 'On our side, the Export Executive is notified. On our order page, your message is in Order Communication, where everyone in our team who works on your order can see it. They reply, and attach a photo from the factory.', do: async (d) => {
      await d.goto(`/orders/${st.order}#order-updates`);
      await B.openTo(d, '#order-updates');
      await d.point('#order-updates .chat-bubble-row', 1800);
      await d.type('#order-updates textarea[name="body"]', 'Hello, here is a photo of your slabs at the factory today. Packing starts next week; we will share more photos then.', { delay: 30 });
      await d.upload(d.page.locator('#order-updates input[type="file"]'), samplePhoto());
    } },
    { caption: 'Posted', say: 'And post it.', do: async (d) => {
      await d.clickNav(d.page.locator('#order-updates button[type="submit"]'));
      await B.openTo(d, '#order-updates'); await d.point('#order-updates .chat-bubble-row:last-child', 1800);
    } },
    { as: 'buyer', caption: 'The reply, from our team', say: 'Back in the portal, the reply is there, from Our Team, with the photo. Click a photo to see it full size. Nothing is ever deleted from this conversation, by either side, so it is a complete record of the order.', do: async (d) => {
      await d.goto(portal(st.order, '#order-updates'));
      await d.scrollTo('#order-updates .chat-thread'); await d.point('#order-updates .chat-bubble-row:last-child', 4000);
    } },
    { card: 'list', kicker: 'Good to know', title: 'Order Updates', items: [
      'Questions, changes, photos: one conversation per order.',
      'Our team is notified of every message you post.',
      'Paid us? Use "Report a Payment" on the same page (next video).',
      'Something we could do better? "Tell us what we could do better".',
    ], say: 'Good to know. Use Order Updates for questions, small changes and photos: one conversation for each order. Our team is notified of every message you post. To tell us about a payment, use Report a Payment on the same page; we show it in the next video. And if something about the order could have gone better, use Tell us what we could do better.' },

    { card: 'list', kicker: 'Recap', title: 'Inside your order', items: [
      'My Orders: Active, Complete, Disputed, Did not go ahead, Cancelled.',
      'Acknowledge your Order Confirmation, and production starts.',
      'Documents: final copies only, never our internal papers.',
      'Order Updates: talk to our team, with photos and files.',
    ], next: 'D04 · Balance, shipping and closing', say: 'Let us recap. My Orders groups your orders: active, complete, disputed, did not go ahead, and cancelled. You acknowledged the Order Confirmation, and production started. Your documents are final copies, ready to download, and never our internal papers. And in Order Updates, you talk to our team, with photos and files. Next video: paying the balance, your shipping documents, and closing the order. See you there!' },
  ],
};
