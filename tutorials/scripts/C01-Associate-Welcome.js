'use strict';
// C01 — Welcome to the Associate Portal (Group C, Associates).
// One associate (Full level so every menu shows), freshly set up, logs in and tours the portal.

const EMAIL = 'associate.full@email.com';
const nav = (d, name) => d.page.locator('nav.topbar-nav').getByRole('link', { name, exact: false }).first();

module.exports = {
  code: 'C01',
  file: 'C01-Associate-Welcome.mp4',
  group: 'Group C · Associates',
  title: 'Welcome to the Associate Portal',
  subtitle: 'What an associate does, how to log in safely, and a first tour of every page.',
  minutes: '9',

  async setup(demo) {
    const a = demo.associate({ email: EMAIL, name: 'Demo Associate', level: 'full', city: 'Jaipur' });
    return {
      a,
      actors: {
        associate: { label: 'Associate', sub: EMAIL, initials: 'AS', color: '#013090' },
        md: { label: 'Managing Director', sub: 'NexaCrest staff · md@email.com', initials: 'MD', color: '#C9962B', cookies: await demo.staffCookies('md') },
      },
    };
  },

  steps: ({ a = { email: EMAIL, password: 'x', code: 'NXA-DEMO-XX' } } = {}) => [
    { card: 'title', say: 'Hi, and welcome! This is the very first video for NexaCrest associates. In the next few minutes you will find out what an associate does, how to log in safely, and where everything lives in your portal. Grab a coffee, and let us dive in.' },

    { card: 'chapter', title: 'What an associate does', subtitle: 'You bring the buyer. We do the export. You earn.', say: 'Part one. What does an associate actually do?' },
    { card: 'list', kicker: 'The idea in one picture', title: 'Three simple steps', items: [
      'You introduce an overseas buyer to NexaCrest, with your own link or personal code.',
      'NexaCrest quotes, makes the documents, ships the goods and collects the money.',
      'When the buyer has paid, your commission is worked out and paid to your bank.',
    ], say: 'It really is this simple. Step one: you introduce a buyer, using your own registration link or your personal code. Step two: NexaCrest does all the heavy lifting. We quote, prepare the export documents, ship the goods and collect the payment. Step three: once the buyer has paid, your commission is worked out automatically, and it is paid straight to your bank account. Your portal lets you follow every one of those steps, live.' },

    { card: 'chapter', title: 'Logging in safely', subtitle: 'Password first, then a 6-digit code from your e-mail.', say: 'Part two. Logging in, the safe way.' },
    { as: 'associate', goto: '/associate/login', caption: 'Associate Login page', say: 'Your login page lives at your NexaCrest web address, slash associate, slash login. Bookmark it! Notice it says Associate Login. Staff and clients have their own doors, so you can never end up in the wrong place.', do: async (d) => { await d.point('h1', 1200); } },
    { caption: 'Step 1: e-mail and password', say: 'First, type the e-mail address NexaCrest registered for you, and your password.', do: async (d) => {
      await d.type(d.page.getByLabel('E-mail'), a.email);
      await d.type(d.page.getByLabel('Password'), a.password, { delay: 40 });
    } },
    { caption: 'Continue', say: 'Then press Continue.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Continue' })); } },
    { caption: 'Step 2: the 6-digit code', say: 'Now, here is the safety net. NexaCrest e-mails you a fresh six-digit code, every single time you log in. It is valid for ten minutes. So even if someone guessed your password, they still could not get in without your mailbox.', do: async (d) => { await d.point('h1', 800); } },
    { caption: 'Type the code from your e-mail', say: 'Open the e-mail, type the code here, and press Log in.', do: async (d) => {
      const code = await d.page.evaluate(() => window.__tutDevCode);
      await d.type(d.page.getByLabel('Code'), code, { delay: 120 });
      await d.clickNav(d.page.getByRole('button', { name: 'Log in' }));
    } },
    { caption: 'Too many wrong tries?', say: 'Quick tip. If the password is typed wrongly too many times, the account pauses for fifteen minutes, to keep it safe. And if you ever forget your password, just ask your NexaCrest relationship manager to send you a new link.' },

    { card: 'chapter', title: 'Your home page', subtitle: 'Everything important on one screen.', say: 'Part three. Your home page.' },
    { goto: '/associate', caption: 'Home', say: 'And we are in! The home page greets you by name. Think of it as your dashboard: the money, your clients, your latest orders and your notices, all on one screen.', do: async (d) => { await d.point('h1', 1000); } },
    { caption: 'First job: complete your profile', say: 'See this yellow note? On your first visit, NexaCrest asks you to complete your profile and upload your documents, like your PAN card and bank proof. Commission is only paid once those documents are accepted, so this is the very first thing to do. We cover it step by step in video C03.', do: async (d) => { await d.point(d.page.locator('.alert-warning').first(), 1500); } },
    { caption: 'Your money at a glance', say: 'These three boxes are your money at a glance. Earned in the recent months. Waiting to be paid. And already paid. A brand-new account starts at zero, of course, but these fill up as your clients place orders.', do: async (d) => { await d.point('.stat-row', 1500); } },
    { caption: 'Clients and your personal code', say: 'Below that, you see how many clients you have, how many of your registration links are still open, and your personal code. That code is yours alone. Buyers who use it are linked to you, and to nobody else.', do: async (d) => { await d.point(d.page.locator('p').filter({ hasText: 'personal code' }).first(), 1500); } },
    { caption: 'Invite a client', say: 'And this button, Invite a client, is the quickest way to bring in a new buyer. Video C03 shows exactly how.', do: async (d) => { await d.point(d.page.getByRole('link', { name: 'Invite a client' }), 1200); } },
    { caption: 'Latest orders and notices', say: 'Further down are your latest orders, with the stage each one has reached, and your notices. Whenever something happens, a new client registers, an order moves forward, or commission is paid, a notice appears here, and the Home tab shows how many are new.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Notices' })); await d.ring(d.page.getByRole('heading', { name: 'Notices' })); } },

    { card: 'chapter', title: 'The menu, tab by tab', subtitle: 'Six places to know.', say: 'Part four. A quick walk through the menu.' },
    { goto: '/associate', caption: 'The menu bar', say: 'The menu across the top takes you everywhere. Let us visit each tab, briefly. Each one has its own video later in this series.', do: async (d) => { await d.point('nav.topbar-nav', 1200); } },
    { caption: 'My clients', say: 'My clients. Here you create buyer registration links, share your personal code, and see every client who registered through you.', do: async (d) => { await d.clickNav(nav(d, 'My clients')); await d.point('h1', 600); } },
    { caption: 'Orders', say: 'Orders. Every order of your clients, with its stage, so you always know where things stand, without having to chase anyone.', do: async (d) => { await d.clickNav(nav(d, 'Orders')); await d.point('h1', 600); } },
    { caption: 'Catalogue', say: 'Catalogue. The products NexaCrest has opened for you. Pick the options a buyer wants, and get a price in seconds.', do: async (d) => { await d.clickNav(nav(d, 'Catalogue')); await d.point('h1', 600); } },
    { caption: 'Commission', say: 'Commission. What you earned on each order, what is waiting, what was paid, and your tax certificates. You can download a statement here too.', do: async (d) => { await d.clickNav(nav(d, 'Commission')); await d.point('h1', 600); } },
    { caption: 'Messages', say: 'Messages. A direct line to the NexaCrest team. Ask a question, and the answer lands right here.', do: async (d) => { await d.clickNav(nav(d, 'Messages')); await d.point('h1', 600); } },
    { caption: 'Profile', say: 'And Profile. Your contact and tax details, your bank account, your agreement and your documents.', do: async (d) => { await d.clickNav(nav(d, 'Profile')); await d.point('h1', 600); } },
    { caption: 'Contact, tax, bank and documents', say: 'As you scroll, you see the sections: contact and tax details, bank details, and my documents. We fill all of these in, together, in video C02.', do: async (d) => {
      await d.scrollTo(d.page.getByRole('heading', { name: 'Bank details' }));
      await d.scrollTo(d.page.getByRole('heading', { name: 'My documents and KYC' }));
    } },

    { card: 'chapter', title: 'Say hello!', subtitle: 'Your first message, and what NexaCrest sees on the other side.', say: 'Part five. Let us say hello to NexaCrest, and peek at what happens on the other side.' },
    { goto: '/associate/messages', caption: 'Messages', say: 'Back to Messages. At the top you see the name of your relationship manager, your personal contact at NexaCrest. Let us send a first message.', do: async (d) => { await d.point(d.page.locator('p.muted').first(), 1000); } },
    { caption: 'Type and send', say: 'Type your message in the box, and press Send.', do: async (d) => {
      await d.type(d.page.getByLabel('Your message'), 'Hello! I have just watched the welcome video and I am ready to bring in my first buyer.', { delay: 28 });
      await d.clickNav(d.page.getByRole('button', { name: 'Send' }));
    } },
    { caption: 'Sent', say: 'Done. Your message is saved here, with the date and time, so the whole conversation stays in one place.', do: async (d) => { await d.point(d.page.locator('.comment').last(), 1200); } },
    { as: 'md', goto: `/associates/${a.id}`, caption: 'Meanwhile, at NexaCrest…', say: 'Now let us switch sides for a moment. See the badge, top right? We are now the Managing Director at NexaCrest. This is your record in the staff system: your name, your status, and your personal code.', do: async (d) => { await d.point('h1', 1500); } },
    { caption: 'Your message arrives', say: 'Your message has arrived in the Messages section of your record. The team reads it, and replies right here.', do: async (d) => {
      await d.scrollTo('#messages');
      await d.ring(d.page.locator('#messages .comment').last());
    } },
    { caption: 'NexaCrest replies', say: 'Let us write a friendly reply.', do: async (d) => {
      await d.type(d.page.locator('#messages textarea[name="body"]'), 'Welcome aboard! Start with your profile and documents, then send your first buyer registration link. We are here to help.', { delay: 22 });
      await d.clickNav(d.page.locator('#messages').getByRole('button', { name: 'Send' }));
    } },
    { caption: 'A read-only view of your portal', say: 'One more thing staff can do: view your portal, read-only, exactly as you see it. That is how your relationship manager can help you over the phone, without ever needing your password.', do: async (d) => {
      await d.page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' })); await d.wait(700);
      await d.point(d.page.getByRole('link', { name: 'View their portal (read-only)' }), 1500);
    } },
    { as: 'associate', goto: '/associate', caption: 'Back to you', say: 'Back to you, the associate. Look at the Home tab: it shows one new item.', do: async (d) => { await d.point(nav(d, 'Home'), 1300); } },
    { caption: 'The reply is here', say: 'Open Messages, and there is the reply from NexaCrest. Easy!', do: async (d) => {
      await d.clickNav(nav(d, 'Messages'));
      await d.ring(d.page.locator('.comment').last()); await d.wait(1500);
    } },

    { card: 'chapter', title: 'Your access level', subtitle: 'Starter, Quoting, Full: you grow with your track record.', say: 'Part six. Why your menu might look different.' },
    { card: 'list', kicker: 'Access levels', title: 'Your portal grows with you', items: [
      'Starter: your clients, their orders, your commission, links and messages.',
      'Quoting: adds the catalogue, get a price, price requests and statements.',
      'Full: adds order documents and order amounts in notices.',
    ], say: 'Not every associate sees the same menu, and that is on purpose. New associates usually start at the Starter level: clients, orders, commission, registration links and messages. Once a few of your orders are completed and paid, the Managing Director can move you up to Quoting, which adds the catalogue and pricing. The Full level adds order documents too. So if a tab from this video is missing in your portal, that is simply your current level. Video C08 explains the levels in detail.' },

    { card: 'list', kicker: 'A good routine', title: 'Ten minutes a week', items: [
      'Check Home: new notices are in bold, and the Home tab counts them.',
      'Look at Orders: which stage has each order reached? Has the buyer paid in full?',
      'Invite at least one new buyer: a link for one company, or your personal code for anyone.',
      'Upload your invoice for any commission line that is waiting.',
    ], say: 'Here is a simple routine that successful associates follow, about ten minutes a week. First, check Home: new notices are in bold, and the Home tab tells you how many there are. Second, look at Orders: which stage has each order reached, and has the buyer paid in full? Third, invite at least one new buyer, with a registration link for one company, or your personal code for anyone. And fourth, if a commission line is waiting, upload your invoice, so nothing holds up your payment.' },

    { card: 'list', kicker: 'Golden rules', title: 'Three things to remember', items: [
      'Your password and your login codes are yours alone. Never share them.',
      'Your personal code is meant to be shared, with buyers.',
      'Keep your profile, bank and documents up to date, or payouts wait.',
    ], say: 'Before we finish, three golden rules. One: your password and your six-digit codes are yours alone. NexaCrest will never ask you for them. Two: your personal code is the opposite. Share it freely with buyers, that is how they get linked to you. Three: keep your profile, bank details and documents up to date, because commission can only be paid to a checked bank account.' },

    { goto: '/associate', caption: 'Log out', say: 'Finished for the day? Press Log out, top right. Always do this on a shared computer.', do: async (d) => { await d.clickNav(d.page.getByRole('button', { name: 'Log out' })); } },
    { caption: '', say: 'And you are safely back at the login page.', do: async (d) => { await d.point('h1', 600); } },

    { card: 'list', kicker: 'Recap', title: 'You are ready to start', items: [
      'Log in with your password plus the 6-digit code from your e-mail.',
      'Home shows your money, clients, personal code, orders and notices.',
      'The menu: My clients, Orders, Catalogue, Commission, Messages, Profile.',
      'Your access level decides which tabs you see.',
    ], next: 'C02 · How you become an associate', say: 'That is it for the welcome tour! You know how to log in, what your home page tells you, and where every tab leads. Up next, video C02: how you become an associate, from the first invitation to your active account. See you there!' },
  ],
};
