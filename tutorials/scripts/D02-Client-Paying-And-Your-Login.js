'use strict';
// D02 — For buyers: paying the advance and your own login (scenarios 6–9).
// The buyer reports the advance on the Payment Action Link, sets a password when the advance has
// cleared, signs in, and resets a forgotten password; then the same buyer pays a second order by
// Letter of Credit. Staff hand-offs are short; routine staff steps run off camera (scripts/_buyer.js).
// Source of truth: docs/APPLICATION_HANDBOOK.md 5.5 and 5.8, SOP 05; ClientPortalService.

const demo = require('../lib/demo');
const cfg = require('../lib/config');
const B = require('./_buyer');

const NEW_PASSWORD = 'Northsea#Portal2026';
const st = { lcLink: '', ref: '', client: 0, order: 0, lcOrder: 0, pi: '', lcPi: '', payLink: '', setLink: '', resetLink: '' };
const at = (o, hash = '') => `/orders/${o}${hash}`;

module.exports = {
  code: 'D02',
  file: 'D02-Client-Paying-And-Your-Login.mp4',
  group: 'Group D · Buyers',
  title: 'Paying, and Your Own Login',
  subtitle: 'For buyers: telling us about your payment, your client portal login, and paying by Letter of Credit.',
  minutes: '10',
  restoreDb: true,

  async setup(d) {
    const ctx = await B.setup(d, { withClient: true });
    st.client = ctx.clientId;
    st.order = B.createOrder(d, st.client);
    st.pi = B.moves.readyForPayment(d, st.order, 'NSS-PO-2611-01');
    st.payLink = B.moves.paymentLink(d, st.order);
    st.ref = d.sql(`SELECT order_reference FROM orders WHERE id = ${st.order}`);
    // The no-login link pages run in their own session, so the buyer's portal login is not disturbed.
    ctx.actors.buyerLink = { ...ctx.actors.buyer, cookies: [] };
    return ctx;
  },

  steps: () => [
    { card: 'title', say: 'Welcome back! In the last video, our buyer, Northsea Stone Trading in Rotterdam, received a Proforma Invoice. Now it is time to pay. In this video you will see how to tell us about your payment, how your own login to the client portal is created, what to do if you forget your password, and how an order paid by Letter of Credit works.' },
    { card: 'list', kicker: 'In this video', title: 'Four things to know', items: [
      'Payment Action Link: tell us about your transfer, with proof.',
      'Your login: created automatically once the advance has cleared.',
      'Forgot your password? Reset it yourself, in a minute.',
      'Paying by Letter of Credit instead of an advance.',
    ], say: 'Four things. The Payment Action Link, where you tell us about your transfer. Your own login, which is created automatically once your advance has cleared. Resetting a forgotten password, by yourself. And paying by Letter of Credit, instead of a cash advance.' },

    // ---------- 1. Payment Action Link ----------
    { card: 'chapter', title: 'Telling us you have paid', subtitle: 'The Payment Action Link.', say: 'Part one. Telling us you have paid.' },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `NexaCrest — send us your payment details for order ${st.ref}`; },
      get lines() { return ['Hello,', `For order ${st.ref}: once you have made the payment, please send us the payment screenshot together with the transaction details (Transaction ID / UTR, amount and date) on this secure link — no login needed.`, 'Our team will check them against our bank statement and confirm. The same link works for every payment on this order.']; },
      link: 'Payment Action Link (secure — opens without a login)',
      say: 'You pay the advance by bank transfer, to the account printed on the Proforma Invoice. With the payment request we send you a Payment Action Link. Once you have paid, open it, and tell us about the transfer. It helps us find your money on our bank statement much faster.' },
    { card: 'list', kicker: 'Before you pay', title: 'Three tips for a smooth payment', items: [
      'Pay to the bank account printed on the Proforma Invoice — nowhere else.',
      'Put the Proforma Invoice number in the payment reference.',
      'An e-mail says our bank details changed? Call your NexaCrest contact before paying.',
    ], say: 'Three tips before you pay. Pay only to the bank account printed on the Proforma Invoice. Put the Proforma Invoice number in the payment reference, so we can match your money at once. And if you ever receive an e-mail saying our bank details have changed, please call us before paying. Fraudsters copy e-mails like ours.' },
    { as: 'buyer', do: async (d) => { await d.goto(`/payment-action/${st.payLink}`); await d.point('h1', 1200); await d.point('legend', 1500); }, caption: 'Payment Details', say: 'Here is the page. It shows one payment at a time: right now, the advance, with the amount due already filled in.' },
    { caption: 'Your transfer details', say: 'Type the transaction I D, or U T R, from your bank. Check the amount and the date. Add which bank and account you paid from. And attach the bank\'s remittance advice, or a screenshot of the transfer. Then: Send payment details.', do: async (d) => {
      await d.type('input[name="transaction_ref"]', 'NSSBANK20261107001');
      await d.point('input[name="amount"]', 900);
      await d.type('input[name="payer_bank_details"]', 'Sent from our bank in Rotterdam, account ending 0000');
      await d.upload(d.page.locator('input[name="screenshot"]'), demo.sampleFile('remittance-advice-sample.pdf', 'SAMPLE REMITTANCE ADVICE - TUTORIAL DEMO'));
      await d.clickNav(d.page.getByRole('button', { name: 'Send Payment Details' }));
    } },
    { caption: 'We are checking your payment', say: 'Thank you: we are checking your advance. Please note: this does not mark the payment as received. Our accounts team always checks the real bank statement. Your details simply help them match it quickly.', do: async (d) => { await d.point('h2', 2500); } },
    { card: 'chapter', title: 'Our side: received, then cleared', subtitle: 'Two people, for every payment.', say: 'A quick look at our side.', do: async () => { B.moves.advanceRecorded(demo, st.order); } },
    { as: 'fm', goto: at(st.order, '#step-5e-payment-confirm'), caption: 'Our Finance Manager clears the advance', say: 'Our accounts team recorded your payment from the bank statement. Then a second person, the Finance Manager, confirms the money has really arrived, and marks the advance cleared. Two people check every payment.', do: async (d) => {
      await B.openTo(d, '#step-5e-payment-confirm');
      await d.clickNav(d.page.locator('#step-5e-payment-confirm button[type=submit]').first());
      await d.point('.alert', 1800);
    } },

    { as: 'buyer', do: async (d) => { await d.goto(`/payment-action/${st.payLink}`); await d.point('h1', 1000); await d.point('h2', 2000); }, caption: 'The same link, for every payment', say: 'If our buyer opens the Payment Action Link again now, it says: nothing to pay right now. The advance is done. Keep this link: the same one is used later for the freight, and for the balance, when they fall due.' },
    // ---------- 2. Your login ----------
    { card: 'chapter', title: 'Your own login', subtitle: 'Created when the advance has cleared.', say: 'Part two. Your own login.', do: async () => { st.setLink = B.setPasswordLink(demo, st.client); } },
    { card: 'email', to: B.BUYER_EMAIL, subject: 'Your NexaCrest order portal access',
      lines: ['Hello,', 'Your advance payment has been received and cleared — thank you.', 'You can now log in to track your order and download your documents at any time.', 'To set up your login, open this link within 72 hours. Your login email will be: ' + B.BUYER_EMAIL],
      link: 'Set your password (link valid for 72 hours)',
      say: 'The moment your advance clears, your login to our client portal is created automatically, and this e-mail arrives. Your login is your e-mail address. Open the link within seventy two hours, to choose your own password.' },
    { as: 'buyer', do: async (d) => {
      await d.goto(st.setLink); await d.point('h1', 1000);
      await d.type('input[name="new_password"]', cfg.PASSWORD, { delay: 70 });
      await d.type('input[name="confirm_password"]', cfg.PASSWORD, { delay: 70 });
      await d.clickNav(d.page.getByRole('button', { name: 'Set Password' }));
    }, caption: 'Set your password', say: 'Choose a password of at least ten characters, and type it twice, so a typing mistake cannot lock you out. Then: Set Password.' },
    { caption: 'Sign in', say: 'Password set. Now sign in: your e-mail address, and your new password.', do: async (d) => {
      await d.point('.alert', 900);
      await d.type('input[name="email"]', B.BUYER_EMAIL);
      await d.type('input[name="password"]', cfg.PASSWORD, { delay: 70 });
      await d.clickNav(d.page.getByRole('button', { name: 'Sign in' }));
    } },
    { caption: 'My Orders', say: 'And here you are, in the client portal. My Orders lists every order you have with us, and its stage. In the next video we look inside an order: your documents, the order confirmation, and messages with our team.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('tr').filter({ hasText: 'NC/' }).first(), 2200); } },

    { goto: '/client/account', caption: 'My Account', say: 'One more page worth knowing: My Account. Here you can change your password whenever you like: your current password, then the new one, twice.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('form').filter({ has: d.page.locator('input[name="current_password"]') }), 2200); } },
    { card: 'list', kicker: 'Good to know', title: 'Your portal login', items: [
      'Your login is your e-mail address: one login for all your orders.',
      'Passwords have at least 10 characters; change yours any time in My Account.',
      'Five wrong passwords in a row lock the login for 15 minutes.',
      'The portal shows final, approved documents only — never drafts.',
    ], say: 'Good to know about your login. It is your e-mail address, and one login covers all your orders with us. Your password has at least ten characters, and you can change it any time in My Account. Five wrong passwords in a row lock the login for fifteen minutes, to keep it safe. And the portal only ever shows final, approved documents, never a draft.' },
    // ---------- 3. Forgot password ----------
    { card: 'chapter', title: 'Forgot your password?', subtitle: 'Reset it yourself, in a minute.', say: 'Part three. Forgot your password?' },
    { goto: '/client', caption: 'Log out', say: 'Let us log out, and imagine you come back weeks later, and cannot remember your password.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Log out' })); } },
    { caption: 'Forgot your password?', say: 'On the client login page, click: forgot your password? Type your e-mail address, and click: send reset link.', do: async (d) => {
      await d.goto('/client/login');
      await d.clickNav(d.page.getByRole('link', { name: 'Forgot your password?' }));
      await d.type('input[name="email"]', B.BUYER_EMAIL);
      await d.clickNav(d.page.getByRole('button', { name: 'Send reset link' }));
      await d.point('.alert', 1500);
    } },
    { caption: 'The same message for everyone', say: 'Notice the message. It always says: if this address matches an account, a link has been sent. We never confirm whether an e-mail address has an account. That keeps your account private.', do: async (d) => { await d.point('.alert', 2500); } },
    { card: 'email', to: B.BUYER_EMAIL, subject: 'Reset your NexaCrest order portal password',
      lines: ['Hello,', `A password reset was requested for your NexaCrest order portal account (${B.BUYER_EMAIL}).`, 'To set a new password, open this link within 72 hours.', 'If you didn\'t request this, you can ignore this email — your password will not be changed.'],
      link: 'Set a new password (link valid for 72 hours)',
      say: 'The reset e-mail arrives. If you did not ask for it, just ignore it: nothing changes.', do: async () => { st.resetLink = B.setPasswordLink(demo, st.client); } },
    { as: 'buyer', do: async (d) => {
      await d.goto(st.resetLink);
      await d.type('input[name="new_password"]', NEW_PASSWORD, { delay: 70 });
      await d.type('input[name="confirm_password"]', NEW_PASSWORD, { delay: 70 });
      await d.clickNav(d.page.getByRole('button', { name: 'Set Password' }));
      await d.type('input[name="email"]', B.BUYER_EMAIL);
      await d.type('input[name="password"]', NEW_PASSWORD, { delay: 70 });
      await d.clickNav(d.page.getByRole('button', { name: 'Sign in' }));
    }, caption: 'A new password', say: 'Open the link, choose a new password, type it twice, and sign in again. One more tip: after five wrong passwords in a row, your login is locked for fifteen minutes, to keep it safe. Wait, or reset your password.' },

    // ---------- 4. Letter of Credit ----------
    { card: 'chapter', title: 'Paying by Letter of Credit', subtitle: 'No advance: your bank guarantees the payment.', say: 'Part four. Paying by Letter of Credit.', do: async () => {
      st.lcOrder = B.createOrder(demo, st.client, 'Irrevocable Letter of Credit at Sight');
      st.lcPi = B.moves.readyForPayment(demo, st.lcOrder, 'NSS-PO-2611-02');
    } },
    { card: 'email', to: B.BUYER_EMAIL, get subject() { return `Proforma Invoice ${st.lcPi} — ${B.BUYER.company}`; },
      lines: ['Dear Purchasing Manager,', 'Please find attached our Proforma Invoice for your second order.', 'NO ADVANCE PAYABLE — the full value of this order is payable by Letter of Credit.', 'Please open an Irrevocable Letter of Credit at Sight in our favour, as shown on the invoice, and send us a copy.'],
      get attach() { return [`${st.lcPi.replace(/\//g, '-')}.pdf`]; },
      say: 'Some buyers pay by Letter of Credit: a promise from your bank to pay us when we present the shipping documents. Then there is no advance at all. The Proforma Invoice says so clearly: no advance payable, the full value is payable by Letter of Credit. You ask your bank to open the L C, exactly as the invoice describes, and send us a copy.' },
    { as: 'buyerLink', do: async (d) => { st.lcLink = B.moves.buyerLinkOnly(demo, st.lcOrder); await d.goto(`/buyer-action/${st.lcLink}`); await d.point('h1', 1000); await d.point('.action-progress', 1800); }, caption: 'Waiting for your Letter of Credit', say: 'On the Buyer Action Link for this second order, the payment step is current, waiting for your Letter of Credit. There is nothing to transfer: your bank does the work. As soon as we have checked your L C, the link moves on to the order confirmation.' },
    { as: 'accounts', caption: 'Our side: the LC is recorded', say: 'When your L C arrives, our accounts team records it: the issuing bank, the L C number and the amount.', do: async (d) => {
      await d.goto(at(st.lcOrder, '#step-5d-payment-record'));
      await B.openTo(d, '#step-5d-payment-record');
      await d.type('input[name="lc_bank_name"]', 'Sample Bank Rotterdam');
      await d.type('input[name="lc_number"]', 'LC/SAMPLE/2026/0001');
      await d.type('input[name="lc_amount"]', '16200.00');
      await d.clickNav(d.page.getByRole('button', { name: 'Record LC Details' }));
    } },
    { as: 'fm', caption: 'Confirmed usable', say: 'And the Finance Manager confirms it is usable: the terms match, and we can present documents under it. That does the same job as clearing an advance: production can start.', do: async (d) => {
      await d.goto(at(st.lcOrder, '#step-5e-payment-confirm'));
      await B.openTo(d, '#step-5e-payment-confirm');
      await d.clickNav(d.page.getByRole('button', { name: /Confirm LC Usable/ }));
      await d.point('.alert', 1500);
    } },
    { as: 'buyer', goto: '/client', caption: 'Both orders in your portal', say: 'And back in the client portal, our buyer now sees both orders in My Orders: the first one paid by advance, and the second one paid by Letter of Credit. One login, every order.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('table.list').first(), 2500); } },
    { card: 'list', kicker: 'Letter of Credit', title: 'Dates that matter on your LC', items: [
      'Latest shipment date: we must ship before it.',
      'Presentation deadline: we present the documents to the bank before it.',
      'Expiry date: after it, the LC can no longer be used.',
      'Need a change? Ask your bank for an amendment, and send us a copy.',
    ], say: 'Three dates on your L C matter to both of us. The latest shipment date, the presentation deadline, and the expiry date. We are reminded of each one a week before. And if anything needs to change, ask your bank for an amendment, and send us a copy.' },

    { card: 'list', kicker: 'Recap', title: 'Paying, and your own login', items: [
      'Payment Action Link: your transfer reference, amount, date and proof.',
      'We record your payment from our bank statement; a second person clears it.',
      'Your login is created when the advance clears: set your password within 72 hours.',
      'Forgot it? "Forgot your password?" on the login page.',
      'Letter of Credit: no advance; we record and confirm your LC.',
    ], next: 'D03 · Inside your order', say: 'Let us recap. On the Payment Action Link you told us about your transfer, with proof. We recorded it from our bank statement, and a second person cleared it. That created your portal login, and you set your own password. If you forget it, use forgot your password on the login page. And on a Letter of Credit order there is no advance: we record your L C, and confirm it usable. Next video: inside your order, in the client portal. See you there!' },
  ],
};
