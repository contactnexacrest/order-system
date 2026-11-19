'use strict';
// C15 — Questions and answers: the questions associates ask most, each answered with the screen.
// Updated 19 Nov 2026 (script only, not recorded): "Forgot password?" (D-282); payout day = last day of the
// month (D-292); status "On hold — a buyer claim on this order is open"; take-back only for fraud or breach,
// "We will take this off your next payment"; paid line labels (D-330); bank change confirmed by the associate,
// checked by one person, used 15 days later (D-293); new questions: only one % (D-292) and the address-proof
// deadline (D-290); levels are in video C04.
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.faq@email.com';

const qa = (q, a, extra = {}) => ({ card: 'list', kicker: 'Question', title: q, items: a.items, say: a.say, ...extra });

module.exports = {
  code: 'C15',
  file: 'C15-Associate-Questions-And-Answers.mp4',
  group: 'Group C · Associates',
  title: 'Questions and answers',
  subtitle: 'The questions associates ask most, answered in a minute each.',
  minutes: '10',

  async setup(demo) {
    return associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' }, { withMd: false });
  },

  steps: () => [
    { card: 'title', say: 'Welcome to the last video of the associate series: questions and answers. These are the questions associates ask most. Let us go through them, quickly.' },
    qa('I forgot my password. What now?', { items: ['Login page → "Forgot password?" → type your e-mail.', 'We e-mail you a link: it works once, for 72 hours.', 'Set a new password: 10+ characters, letters and digits.'],
      say: 'Question one. I forgot my password. On the login page, click Forgot password?, and type your e-mail. We e-mail you a link. It works once, for seventy-two hours, and you set a new password, at least ten characters, with letters and digits. No e-mail? Your relationship manager can send you a set-password link too.' }),
    qa('My login code did not arrive.', { items: ['Check spam / junk.', 'Codes are valid 10 minutes: start again for a new one.', 'Too many wrong tries? Wait 15 minutes.'],
      say: 'Question two. My login code did not arrive. First check your spam folder. Codes are valid for ten minutes, so if it is late, just start again for a fresh one. And after too many wrong tries, the account pauses for fifteen minutes.' }),
    { as: 'associate', goto: '/associate/clients#code', caption: 'Where is my personal code?', say: 'Question three. Where do I find my personal code? Here, under My clients, on the right, with your quotation link. It is also on your home page.', do: async (d) => { await d.scrollTo('#code'); await d.ring(d.page.locator('#code p').first()); await d.wait(2000); } },
    qa('A buyer used my code but is not in my list.', { items: ['Registration is complete only once NexaCrest accepts it.', 'The buyer must confirm you introduced them.', 'The first completed registration of a company counts.', 'Still unsure? Send a message with the company name.'],
      say: 'Question four. A buyer used my code, but they are not in my list. A buyer appears once NexaCrest has accepted the registration, and only if the buyer confirmed you introduced them. Remember, the first completed registration of a company counts. Still unsure? Send a message with the company name.' }),
    qa('When exactly is my commission paid?', { items: ['Earned: by itself, when the buyer has paid the order in full.', 'Paid 30 days later, on our monthly payment day: the last day of the month.', 'Sunday or holiday? The working day before.', 'Against your invoice, only to your checked bank account, with your documents accepted.'],
      say: 'Question five. When exactly is my commission paid? It is earned by itself the day the buyer has paid the order in full, and from then on it never changes, whatever the exchange rate does. We pay it thirty days later, on our monthly payment day, the last day of the month. If that is a Sunday or a holiday, we pay on the working day before. The status shows the exact date: Earned, will be paid on. We pay against your invoice, and only to your checked bank account, once your documents are accepted.' }),
    { goto: '/associate/commission', caption: 'Why is a line On hold?', say: 'Question six. Why is a line on hold? The reason is written in the status, on the Commission page. Most often it says: On hold, a buyer claim on this order is open. Once the claim is settled, it moves on by itself.', do: async (d) => { await d.ring(d.page.locator('table.list').first()); await d.wait(1800); } },
    qa('Why was less paid than my commission?', { items: ['TDS: 2% in India above ₹20,000 a year (20% without a verified PAN).', 'Rarely, an amount taken back: "We will take this off your next payment".', 'Each paid line shows before tax, tax we deducted, and paid to you.', 'Never bank charges: NexaCrest pays them.'],
      say: 'Question seven. Why did my bank receive less than my commission? Usually T D S: two percent in India once you pass twenty thousand rupees in the year, or twenty percent without a verified PAN. Very rarely, in a case of fraud or a breach of the agreement, an amount is taken back, and your list says: we will take this off your next payment. Every paid line shows the amount before tax, the tax we deducted, and the amount paid to you. Never bank charges: NexaCrest pays those. Part three of video C05 explains it all.' }),
    qa('Can I change my bank account?', { items: ['Profile → Bank details → Change my bank details.', 'Confirm it with the code we e-mail you; then NexaCrest checks it.', 'Used 15 days after it is checked; until then, your current account.'],
      say: 'Question eight. Can I change my bank account? Yes. Profile, Bank details, Change my bank details. Then you confirm the new details with a code we e-mail you, and someone at NexaCrest who did not enter them checks them. A changed account is used fifteen days after it is checked. Until then, payments go to your current account.' }),
    qa('Why don\'t I see the Catalogue?', { items: ['Your access level decides which tabs you see.', 'Completed, fully paid orders open the way to Quoting.', 'Only the Managing Director changes the level.'],
      say: 'Question nine. Why do I not see the Catalogue? That depends on your access level. Starter has no catalogue. Completed, fully paid orders open the way to Quoting, and the Managing Director makes that change. Video C04 explains the levels.' }),
    qa('I only see one percentage. Is that right?', { items: ['Yes: your current %, of the FOB value of each order from your clients.', 'Each order keeps the % it was given at its Proforma Invoice.', 'The Managing Director sets your commission rule; you see the new % at once.'],
      say: 'Question ten. I only see one percentage. Is that right? Yes. Your portal shows your current percentage, of the F O B value of each order from the clients you introduced, and How it works. Each order keeps the percentage it was given when its Proforma Invoice was sent. The Managing Director sets your commission rule, and if it changes, you see your new percentage, for new orders.' }),
    qa('Why am I asked for an address proof?', { items: ['ID proof and address proof are two separate documents.', 'Already active? Home and Profile show the date it is needed by.', 'After that date, commission payouts wait until it is accepted.'],
      say: 'Question eleven. Why am I asked for an address proof, when I already sent my I D? Because they are two separate documents, each checked on its own. If one document shows both, upload it twice, once as each. If you were already active when this changed, your home page and profile show the date it is needed by. After that date, commission payouts wait until it is accepted.' }),
    qa('Can I give my buyer a price screenshot?', { items: ['No: prices are for you only.', 'Tell the buyer the price yourself.', 'The final price is confirmed on the Proforma Invoice.'],
      say: 'Question twelve. Can I send my buyer a screenshot of the price? No. Prices are for you only. Tell the buyer the price in your own words, and remember, the final price is confirmed on the Proforma Invoice that NexaCrest sends.' }),
    qa('Who do I contact?', { items: ['Messages: your relationship manager and the associates team.', 'Mention the order number or client.', 'Never send passwords, codes or full bank numbers.'],
      say: 'And question thirteen. Who do I contact? Messages, in your portal. Your relationship manager and the associates team read them. Mention the order number or client, and never send passwords, codes or full bank numbers.' }),
    { card: 'list', kicker: 'The series', title: 'All associate videos', items: [
      'C01 Welcome: login, home page and every tab',
      'C02 Becoming an associate: registration, profile, documents, bank',
      'C03 The Starter level and inviting your first client',
      'C04 Prices, "Other" requests and access levels',
      'C05 Commission, statements, TDS and GST',
      'C06 Messages, changes, privacy, questions and answers',
    ], say: 'That is the end of the associate series. Six videos, from your first login to your first commission. Come back to any of them whenever you need. Thank you for watching, and welcome to the NexaCrest team. Happy selling!' },
  ],
};
