'use strict';
// C05 — Inviting a client: the buyer registration link (one buyer), the personal code and
// quotation link (any buyer), the buyer's side, NexaCrest accepting, and the certificate.
const { ACTORS, nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.inviter@email.com';
const BUYER = 'TEST Demo Harbour Stone BV';

module.exports = {
  code: 'C05',
  file: 'C05-Associate-Inviting-Clients.mp4',
  group: 'Group C · Associates',
  title: 'Inviting a client',
  subtitle: 'Your buyer registration link, your personal code, and what happens on the buyer\'s side.',
  minutes: '11',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    s.actors.buyer = { ...ACTORS.client('buyer.demo@email.com'), cookies: [] };
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => {
    const st = {};
    return [
      { card: 'title', say: 'This is the big one! Bringing in a client is how you earn. In this video you send a buyer registration link, you meet your personal code, and then we watch the buyer register, and NexaCrest accept them as your client.' },
      { card: 'list', kicker: 'Two tools', title: 'Link or code?', items: [
        'Buyer registration link: for one named buyer. Valid 30 days, one registration.',
        'Personal code and quotation link: for any buyer, any time. Never expires.',
        'Both end the same way: the buyer registers and confirms you introduced them.',
      ], say: 'You have two tools. A buyer registration link is made for one named company. It is valid for thirty days, for one registration. Your personal code, and the quotation link that carries it, work for any buyer, any time. Both end the same way: the buyer registers with their own details, and confirms that you introduced them.' },

      { card: 'chapter', title: 'Create a buyer registration link', say: 'Part one. A link for one buyer.' },
      { as: 'associate', goto: '/associate/clients', caption: 'My clients → Invite a client', say: 'Go to My clients. Under Invite a client, on the left, is the buyer registration link.', do: async (d) => { await d.point(d.page.getByRole('heading', { name: 'Invite a client' }), 1000); } },
      { caption: 'Company and country', say: 'Type the company name of your buyer and their country. Then press Create buyer registration link.', do: async (d) => {
        await d.type(d.page.getByLabel('Company name *'), BUYER);
        await d.type(d.page.getByLabel('Country *'), 'Netherlands');
        await d.clickNav(d.page.getByRole('button', { name: 'Create buyer registration link' }));
      } },
      { caption: 'Copy it and send it', say: 'Here is your link. Copy it, and send it to your buyer, by e-mail, or on WhatsApp. Below, the link appears in your list, with its status, so you can always see if the buyer opened it.', do: async (d) => {
        st.link = await d.text('.alert-success code');
        await d.point('.alert-success', 1500);
        await d.scrollTo(d.page.getByRole('heading', { name: 'My buyer registration links and codes used' }));
      } },

      { card: 'chapter', title: 'Your personal code', subtitle: 'For any buyer, any time.', say: 'Part two. Your personal code.' },
      { caption: 'Code and quotation link', say: 'On the right is your personal code, and your quotation link, which already contains the code. Hand them out freely: on your business card, in your e-mail signature, at a trade fair.', do: async (d) => { await d.scrollTo('#code'); await d.ring(d.page.locator('#code p').first()); await d.wait(1800); } },
      { caption: 'A built-in typo check', say: 'A clever detail: the last letter of the code is a check letter. If a buyer mistypes the code, the system notices, instead of linking the buyer to someone else.', do: async (d) => { await d.ring(d.page.locator('#code p.muted').first()); await d.wait(1500); } },
      { caption: 'Change my code', say: 'You can choose a new code, a few times a year. Type a readable part, like your company name, and press New ending until you like it. Your clients stay yours, and old codes keep working, because clients are linked to you, not to the code.', do: async (d) => {
        await d.click(d.page.locator('#code summary'));
        await d.type(d.page.getByLabel(/Readable part/), 'DEMOEXIM');
        await d.clickNav(d.page.getByRole('button', { name: /New ending/ }));
        await d.scrollTo('#code'); await d.ring(d.page.locator('#code details p').first()); await d.wait(1200);
      } },

      { card: 'chapter', title: 'The buyer registers', subtitle: 'What your buyer sees.', say: 'Part three. Now let us be the buyer.' },
      { as: 'buyer', caption: 'The buyer opens your link', say: 'The badge shows we are now the buyer. They open your link and see NexaCrest\'s registration form. Your link travels with it invisibly, so the buyer has nothing extra to type.', do: async (d) => { await d.goto(new URL(st.link).pathname); await d.point('h1', 1200); } },
      { caption: 'Company and address', say: 'The buyer fills in their company, address, and contact person.', do: async (d) => {
        await d.type(d.page.locator('input[name="company_legal_name"]'), BUYER, { delay: 30 });
        await d.type(d.page.locator('input[name="billing_address_line1"]'), '1 Harbour Road', { delay: 30 });
        await d.type(d.page.locator('input[name="billing_city"]'), 'Rotterdam', { delay: 30 });
        await d.select(d.page.locator('#billing_country_select'), { label: 'Netherlands' });
        await d.type(d.page.locator('input[name="contact_person"]'), 'Purchasing Manager', { delay: 30 });
      } },
      { caption: 'E-mail, tax number, terms', say: 'Their e-mail, their VAT number, where the goods should go, and the delivery terms they prefer. Then, Submit Request.', do: async (d) => {
        await d.type(d.page.locator('input[name="email"]'), 'buyer.demo@email.com', { delay: 30 });
        await d.type(d.page.locator('input[name="vat_eori_tax_no"]'), 'NL000000000B01', { delay: 30 });
        await d.select(d.page.locator('#country_of_destination_select'), { label: 'Netherlands' });
        await d.select(d.page.locator('#incoterm_select'), { label: 'CIF' });
        await d.clickNav(d.page.getByRole('button', { name: 'Submit Request' }));
      } },
      { caption: 'The buyer confirms their e-mail', say: 'NexaCrest e-mails the buyer a code, to prove the e-mail address is real. The buyer types it, and confirms. This is also where the buyer confirms that you introduced them.', do: async (d) => {
        await d.point('h1', 800);
        await d.type(d.page.getByLabel('Code from the e-mail *'), await d.devCode(), { delay: 110 });
        await d.clickNav(d.page.getByRole('button', { name: 'Confirm' }));
      } },
      { caption: 'Request received', say: 'Thank you! The buyer\'s request is with NexaCrest.', do: async (d) => { await d.point('h1', 1200); } },

      { card: 'chapter', title: 'NexaCrest accepts', subtitle: 'From that moment, the client is yours.', say: 'Part four. NexaCrest reviews the new buyer.' },
      { as: 'md', goto: '/client-intake?view=list', caption: 'Staff: Quotation intake', say: 'On the staff side, the request appears in the quotation intake list. Notice the line: introduced by your associate name, buyer\'s e-mail verified. Staff can see at a glance that this buyer came through you.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: BUYER }).first()); await d.wait(2000); } },
      { caption: 'Accept', say: 'They accept it. And the message confirms: linked to the associate, protected until a date six months from now.', do: async (d) => {
        await d.clickNav(d.page.locator('tr', { hasText: BUYER }).getByRole('button', { name: /Accept/ }).first());
        await d.point('.alert-success', 1800);
      } },

      { card: 'chapter', title: 'Your new client', say: 'Part five. Back to you.' },
      { as: 'associate', goto: '/associate/clients', caption: 'Registered clients (1)', say: 'Your new client is in your list, with the date and the protected-until date. And in your links list, the status now says accepted, your client. You also get a notice on your home page.', do: async (d) => {
        await d.scrollTo(d.page.getByRole('heading', { name: /Registered clients/ }));
        await d.ring(d.page.locator('tr', { hasText: BUYER }).first()); await d.wait(1800);
      } },
      { caption: 'Registration certificate', say: 'Open the registration certificate. It records who introduced whom, and when. It is your proof, should there ever be a question.', do: async (d) => {
        const href = await d.page.locator('tr', { hasText: BUYER }).getByRole('link', { name: 'Registration certificate' }).first().getAttribute('href');
        await d.point(d.page.locator('tr', { hasText: BUYER }).getByRole('link', { name: 'Registration certificate' }).first(), 600);
        await d.goto(href); await d.point('h1', 1500);
      } },
      { card: 'list', kicker: 'Good to know', title: 'Rules that protect you', items: [
        'The first completed registration of a company counts.',
        'Your client is protected for 6 months; with no order by then, it lapses.',
        'Commission comes from the first orders: those within 10 days of the first purchase order.',
        'You can cancel an unused link at any time.',
      ], say: 'A few rules that protect you. The first completed registration of a company counts, so invite early. Your client is protected for six months, and if they place no order in that time, the protection lapses. Commission comes from the client\'s first orders, those within ten days of their first purchase order. And you can cancel a link you no longer need.' },
      { card: 'list', kicker: 'Recap', title: 'Inviting, step by step', items: [
        'My clients → Company + country → Create buyer registration link → send it.',
        'Or share your personal code / quotation link with any buyer.',
        'Buyer registers, verifies their e-mail, confirms you introduced them.',
        'NexaCrest accepts: client protected, certificate ready.',
      ], next: 'Video C04 · Prices, special requests and access levels', say: 'Great work! You created a link, met your personal code, and watched a buyer become your client. Next, in video C04, we get a price for that buyer, straight from the catalogue. See you there!' },
    ];
  },
};
