'use strict';
// D05 — For buyers: when something goes wrong (scenario 18).
// A change of payment terms (amendment, done on paper), reporting a process issue and talking it
// through, raising a dispute once our team has switched it on for the order, cancelled and lost
// orders in the portal, and staff "Log in as this client" with its banner.
// Source of truth: docs/SOP/12-client-portal.md, 10-amendments.md, 11-disputes.md, 19-complaints.md;
// ClientPortalController, clients/show.php, layout/client.php.

const demo = require('../lib/demo');
const A = require('./_a02');
const B = require('./_buyer');
const { ACTORS, clientCookies } = require('./_overview');

const st = { client: 0, order: 0, ref: '', cancelled: 0, cancelledRef: '' };
const portal = (o, hash = '') => `/client/orders/${o}${hash}`;
const section = (d, title) => d.page.locator('.section').filter({ has: d.page.locator('h2', { hasText: title }) }).first();

module.exports = {
  code: 'D05',
  file: 'D05-Client-When-Something-Goes-Wrong.mp4',
  group: 'Group D · Buyers',
  title: 'When Something Goes Wrong',
  subtitle: 'For buyers: changing payment terms, process issues, disputes, cancelled orders, and help from our team.',
  minutes: '9',
  restoreDb: true,

  async setup(d) {
    const ctx = await B.setup(d, { withClient: true });
    st.client = ctx.clientId;
    // An order that did not go ahead, and one called off after the Proforma Invoice.
    const lost = B.createOrder(d, st.client);
    B.moves.quotation(d, lost);
    A.php(d, `OrderRepository::markLost(${lost}, 'Buyer chose to postpone the project', $uid('export.executive@email.com'));`);
    st.cancelled = B.createOrder(d, st.client);
    B.moves.readyForPayment(d, st.cancelled, 'NSS-PO-2609-03');
    A.php(d, `OrderRepository::cancel(${st.cancelled});`);
    st.cancelledRef = d.sql(`SELECT order_reference FROM orders WHERE id = ${st.cancelled}`);
    // The running order: advance cleared, Order Confirmation acknowledged, in production.
    st.order = B.createOrder(d, st.client);
    B.moves.readyForPayment(d, st.order, 'NSS-PO-2611-01');
    B.moves.advanceRecorded(d, st.order);
    B.moves.ocSent(d, st.order);
    B.moves.ocAcknowledged(d, st.order);
    st.ref = d.sql(`SELECT order_reference FROM orders WHERE id = ${st.order}`);
    // "Log in as this client": the company-wide switch is turned on by the Admin in Settings (off camera).
    A.php(d, `App\\Repositories\\CompanySettingsRepository::set('client_impersonation_enabled', '1', $uid('md@email.com'));`);
    B.portalPassword(d);
    ctx.actors.buyer.cookies = await clientCookies(d, B.BUYER_EMAIL);
    ctx.actors.md = { ...ACTORS.md, cookies: await d.staffCookies('md') };
    return ctx;
  },

  steps: () => [
    { card: 'title', say: 'Welcome back! Most orders run smoothly. But sometimes something needs to change, or something goes wrong. In this video: changing the payment terms, telling us about a problem, raising a dispute, what you see for a cancelled or lost order, and how our team can help you inside the portal.' },
    { card: 'list', kicker: 'In this video', title: 'Five situations', items: [
      'Payment terms need to change: an amendment, on paper.',
      'Something could be better: Report a Process Issue.',
      'You want to cancel: Raise a Dispute, when switched on.',
      'Cancelled and lost orders in your portal.',
      'Our team helps you inside the portal: the staff banner.',
    ], say: 'Five situations. Payment terms that need to change. Something that could be better. Wanting to cancel an order. Cancelled and lost orders. And our team helping you inside your portal.' },

    // ---------- 1. Amendment ----------
    { card: 'chapter', title: 'Changing the payment terms', subtitle: 'An amendment agreement, signed on paper.', say: 'Part one. Changing the payment terms.' },
    { card: 'list', kicker: 'Amendments', title: 'When payment terms must change', items: [
      'Once the Proforma Invoice is issued, the payment terms are binding.',
      'To change them — advance, balance terms, due days — we agree an amendment.',
      'You receive an Amendment Agreement to sign, stamp and send back scanned.',
      'The new terms apply from the moment we receive your signed copy.',
      'Every later document carries a note naming the amendment in effect.',
    ], say: 'Once the Proforma Invoice is issued, the payment terms are binding for both of us. If they must change, for example the advance percentage, the balance terms, or the number of days, we agree an amendment. You or we can ask for it. You receive an Amendment Agreement, sign and stamp it, and send us the scanned copy. The new terms apply from the moment we receive your signed copy, and every document we issue after that carries a note naming the amendment in effect.' },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Payment Terms Amendment — Order ${st.ref}`; },
      get lines() { return ['Dear Purchasing Manager,', `As agreed, please find attached the Payment Terms Amendment Agreement for your order ${st.ref}.`, 'Please sign and stamp it, and send us the scanned copy. The amended terms apply from the moment we receive it.']; },
      attach: ['Payment-Terms-Amendment-Agreement.pdf'],
      say: 'It arrives by e-mail from your NexaCrest contact. There is no screen for amendments in the portal: it is done on paper, with your signature, so it is binding for both of us.' },

    { card: 'list', kicker: 'Amendments', title: 'What an amendment can change', items: [
      'The advance: percentage or amount.',
      'The balance terms, and what triggers the balance (before shipment, or against the BL).',
      'The number of days to pay the balance, and the balance amount.',
      'Each change records the reason, and the date it takes effect.',
    ], say: 'What can an amendment change? The advance, as a percentage or an amount. The balance terms, and what triggers the balance: before shipment, or against the bill of lading. The number of days you have to pay the balance, and the balance amount. Anything not mentioned stays exactly as it was. And every amendment records why it was made, and from which date it applies.' },

    // ---------- 2. Process issue ----------
    { card: 'chapter', title: 'Something could be better', subtitle: 'Report a Process Issue — always available.', say: 'Part two. Something could be better.' },
    { as: 'buyer', caption: 'Report a Process Issue', say: 'Something about the order that we could do better? Open the order, and go to Report a Process Issue. It is always there, on every order. Describe the issue.', do: async (d) => {
      await d.goto(portal(st.order));
      const s = section(d, 'Report a Process Issue');
      await d.scrollTo(s); await d.point(s.locator('h2'), 1200);
      await d.type(s.locator('form').last().locator('textarea[name="description"]'), 'The packing list reached us two days after the commercial invoice. Please send them together next time.', { delay: 35 });
    } },
    { caption: 'Report This Issue', say: 'Report This Issue.', do: async (d) => {
      await d.clickNav(section(d, 'Report a Process Issue').getByRole('button', { name: 'Report This Issue' }));
      await d.point('.alert', 2200);
    } },
    { as: 'md', caption: 'Our side: the Complaint Log', say: 'On our side, management is notified at once. The order\'s complaints page lists every issue you raised, with its status: open, acknowledged, or resolved. Here the Managing Director opens your issue, and writes a reply.', do: async (d) => {
      await d.goto(`/orders/${st.order}/complaints`);
      await d.point('h1', 1000);
      const c = d.page.locator('details').filter({ hasText: 'packing list' }).first();
      await c.evaluate((el) => { el.open = true; });
      await d.scrollTo(c); await d.point(c.locator('summary'), 1800);
      await d.type(c.locator('form[action$="/replies"] textarea[name="body"]'), 'Thank you for telling us. From your next shipment we will send the packing list and the commercial invoice in one e-mail.', { delay: 30 });
    } },
    { caption: 'Post Reply', say: 'Post Reply. The reply goes straight to your portal.', do: async (d) => {
      const c = d.page.locator('details').filter({ hasText: 'packing list' }).first();
      await d.clickNav(c.getByRole('button', { name: 'Post Reply' }));
      await d.point('.alert', 1500);
    } },
    { as: 'buyer', caption: 'The conversation', say: 'In the portal, the issue now shows our team\'s reply, underneath. You can answer in the same place, and the conversation continues until it is resolved.', do: async (d) => {
      await d.goto(portal(st.order, '#order-complaints'));
      const s = section(d, 'Report a Process Issue');
      await d.scrollTo(s.locator('.card-nested')); await d.point(s.locator('.card-nested'), 3500);
      await d.type(s.locator('.card-nested textarea[name="body"]'), 'Thank you, that will help us a lot.', { delay: 40 });
      await d.clickNav(s.locator('.card-nested').getByRole('button', { name: 'Send' }));
    } },
    { caption: 'Sent', say: 'Sent. A process issue never stops or cancels your order. It simply helps us do better next time.', do: async (d) => { await d.point('.alert', 2500); } },

    // ---------- 3. Dispute ----------
    { card: 'chapter', title: 'You want to cancel', subtitle: 'Raise a Dispute — when our team switches it on.', say: 'Part three. When you want to cancel.' },
    { card: 'list', kicker: 'Disputes', title: 'Not the same as a process issue', items: [
      'A dispute means: you want to cancel the order and not go ahead.',
      'Talk to your NexaCrest contact first.',
      'Our team switches on "Raise a Dispute" for that order.',
      'We respond within the time set in your order terms.',
    ], say: 'A dispute is different. It means you want to cancel the order and not go ahead with us. Please talk to your NexaCrest contact first. If it comes to that, our team switches on the Raise a Dispute section for that order in your portal. We then respond within the time set out in your order terms. And of course you can still write to us in Order Updates at any time.' },
    { as: 'md', caption: 'Our side: switched on', say: 'On our side, the Managing Director, or another manager who handles disputes, ticks: show Raise a Dispute to the client, for this order.', do: async (d) => {
      await d.goto(`/orders/${st.order}#disputes`);
      await B.openTo(d, '#disputes form');
      const box = d.page.locator('#disputes input[name="dispute_button_visible_to_client"]');
      await d.point(box, 1500);
      await d.clickNav(box);
    } },
    { as: 'buyer', caption: 'Raise a Dispute', say: 'Now the order page shows Raise a Dispute. Describe the issue, and click Raise a Dispute.', do: async (d) => {
      await d.goto(portal(st.order));
      const s = section(d, 'Raise a Dispute');
      await d.scrollTo(s); await d.point(s.locator('p.muted'), 2000);
      await d.type(s.locator('textarea[name="description"]'), 'Our project has been stopped by the city. We would like to cancel this order. Please call us to discuss.', { delay: 35 });
      await d.clickNav(s.getByRole('button', { name: 'Raise a Dispute' }));
    } },
    { caption: 'Logged, with a date', say: 'Your dispute has been logged, and the message shows the date by which our team will respond. Our management is notified at once. The date is counted in our working days: our weekly day off and public holidays are not counted.', do: async (d) => { await d.point('.alert', 3500); } },
    { as: 'md', caption: 'Our side: the Dispute Log', say: 'On our side, your dispute is in the order\'s Dispute Log: the date of your notice, that it came from you through the portal, and the date our response is due. Our team works it through from here: they contact you, keep every letter and document with the dispute, and record how it was resolved.', do: async (d) => {
      await d.goto(`/orders/${st.order}/disputes`);
      await d.point('h1', 1000);
      const x = d.page.locator('details').filter({ hasText: 'cancel this order' }).first();
      await x.evaluate((el) => { el.open = true; });
      await d.scrollTo(x); await d.point(x.locator('.kv-grid'), 5000);
    } },

    // ---------- 4. Cancelled and lost ----------
    { card: 'chapter', title: 'Cancelled and lost orders', subtitle: 'What stays in your portal.', say: 'Part four. Cancelled and lost orders.' },
    { as: 'buyer', goto: '/client', caption: 'Lost and Cancelled', say: 'In My Orders, an order that did not go ahead is under Lost. And an order that was called off is under Cancelled. They stay in your list, so you keep the full history.', do: async (d) => {
      await d.point(section(d, 'Lost').locator('h2'), 2000);
      await d.point(section(d, 'Cancelled').locator('h2'), 2000);
    } },
    { card: 'list', kicker: 'Good to know', title: 'Cancelled and lost', items: [
      'Lost: an order that did not go ahead, before any payment was made.',
      'Cancelled: an order called off; a second person at our side approves it.',
      'Both stay in My Orders, with every document you received.',
      'Changed your mind? Talk to your NexaCrest contact: we start a new order.',
    ], say: 'What is the difference? Lost is an order that did not go ahead, before any payment was made. Cancelled is an order that was called off, and every cancellation at our side is approved by a second person, with any refund recorded. Both stay in My Orders, with every document you received. And if you change your mind later, simply talk to your NexaCrest contact, and we start a new order.' },
    { caption: 'Inside a cancelled order', say: 'Inside a cancelled order, the documents you already received are still there to download. The Buyer PO box no longer offers an upload: it says the order is cancelled, so a Buyer PO can no longer be uploaded. And there is nothing to pay: Report a Payment says this order is cancelled, so there is no payment to report on it.', do: async (d) => {
      await d.clickNav(section(d, 'Cancelled').getByRole('link', { name: 'View documents' }));
      await d.point(section(d, 'Documents').locator('table'), 2200);
      const po = section(d, 'Buyer PO');
      await d.scrollTo(po); await d.point(po.getByText('can no longer be uploaded'), 3200);
      const s = section(d, 'Report a Payment');
      await d.scrollTo(s); await d.point(s.locator('strong'), 3000);
    } },

    // ---------- 5. Staff in your portal ----------
    { card: 'chapter', title: 'Our team helps you', subtitle: '"Log in as this client" — always shown, always logged.', say: 'Part five. When our team helps you inside your portal.' },
    { card: 'list', kicker: 'Log in as this client', title: 'Three switches, all needed', items: [
      'Allowed for your company, on your client record (off by default).',
      'Switched on company-wide by our Admin (off by default).',
      'Only senior staff may use it: Admin, Managing and Executive Director.',
      'Every start and end is written to our audit log.',
    ], say: 'Sometimes a buyer prefers our team to do something in the portal for them, for example on a phone call. Our team can open your portal on your behalf, without your password. Three switches must all be on. It is allowed for your company, on your client record, and it is off by default. It is switched on company-wide by our Admin. And only senior staff may use it. Every start and end is written to our audit log.' },
    { as: 'md', caption: 'Client Portal Access', say: 'On your client page, in Client Portal Access, the Managing Director ticks: allow staff to log in as this client. The company-wide switch is already on.', do: async (d) => {
      await d.goto(`/clients/${st.client}`);
      const s = section(d, 'Client Portal Access');
      await d.scrollTo(s); await d.point(s.locator('h2'), 1200);
      const box = s.locator('input[name="allow_staff_impersonation"]');
      await d.point(box, 1200);
      await d.clickNav(box);
    } },
    { caption: 'Log in as this client', say: 'Now the button appears: Log in as this client. They confirm, and your portal opens.', do: async (d) => {
      const s = section(d, 'Client Portal Access');
      await d.scrollTo(s);
      const f = s.locator('form[action$="/impersonate"]');
      // The app opens the portal in a new tab after a confirmation; for the camera it opens in this tab.
      await f.evaluate((el) => { el.removeAttribute('target'); el.removeAttribute('onsubmit'); });
      await d.point(f.locator('button'), 1800);
      await d.clickNav(f.locator('button'));
    } },
    { caption: 'The staff banner', say: 'Look at the top: an amber banner on every page. A staff member is viewing this portal on this client\'s behalf. So it is always clear when it is our team, and not you. Our team can do what you can do here, but they can never change your password.', do: async (d) => {
      await d.point('.impersonation-banner', 4000);
      await d.point('h1', 1200);
    } },
    { caption: 'End impersonation', say: 'When they are done, they click End impersonation, and they are back on our side.', do: async (d) => {
      await d.point(d.page.getByRole('button', { name: 'End impersonation' }), 1500);
      await d.clickNav(d.page.getByRole('button', { name: 'End impersonation' }));
      await d.point('h1', 1500);
    } },
    { caption: 'What you see in your portal', say: 'In the same section, our team can also set two things for your portal: whether My Orders shows your full history, or only your current orders, and whether the Documents section is shown. These only change what you see. Your orders and documents themselves never change.', do: async (d) => {
      const s = section(d, 'Client Portal Access');
      await d.scrollTo(s);
      await d.point(s.locator('select[name="order_history_visibility_override"]'), 2500);
      await d.point(s.locator('select[name="document_downloads_visibility_override"]'), 2500);
    } },

    { card: 'list', kicker: 'Good to know', title: 'Locked out?', items: [
      'Forgot your password? Use "Forgot your password?" on the login page.',
      'No access to that e-mail? Call your NexaCrest contact.',
      'We can give you a one-time temporary password, by phone — never by e-mail.',
      'You choose a new password at your next sign-in.',
    ], say: 'One last situation: you are locked out. First, use forgot your password on the login page, as shown in the second video. If you cannot receive that e-mail, call your NexaCrest contact. Our team can give you a one-time temporary password, by phone or in person, never by e-mail. At your next sign-in, you choose a new password of your own.' },
    { card: 'list', kicker: 'Recap', title: 'When something goes wrong', items: [
      'New payment terms: a signed Amendment Agreement, on paper.',
      'Report a Process Issue: always there, answered in the same place.',
      'Raise a Dispute: to cancel, once our team switches it on.',
      'Cancelled and lost orders stay in your list, with their documents.',
      'Our team in your portal: always with the amber banner, always logged.',
    ], say: 'Let us recap. Payment terms change only with a signed amendment agreement. Report a Process Issue is always there, and our team answers in the same place. To cancel, talk to us; Raise a Dispute appears once our team switches it on. Cancelled and lost orders stay in your list, with their documents. And when our team works in your portal for you, the amber banner always shows it, and it is always logged. That completes our videos for buyers. Thank you for watching, and thank you for your business!' },
  ],
};
