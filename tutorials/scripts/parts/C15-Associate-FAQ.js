'use strict';
// C15 — Questions and answers: the questions associates ask most, each answered with the screen.
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
    qa('I forgot my password. What now?', { items: ['Ask your relationship manager for a new password link.', 'The link is e-mailed; it works once.', 'Set a new password: 10+ characters, letters and digits.'],
      say: 'Question one. I forgot my password. Simply ask your relationship manager, by phone or message, to send you a new link. It is e-mailed to you, works once, and you set a new password, at least ten characters, with letters and digits.' }),
    qa('My login code did not arrive.', { items: ['Check spam / junk.', 'Codes are valid 10 minutes: start again for a new one.', 'Too many wrong tries? Wait 15 minutes.'],
      say: 'Question two. My login code did not arrive. First check your spam folder. Codes are valid for ten minutes, so if it is late, just start again for a fresh one. And after too many wrong tries, the account pauses for fifteen minutes.' }),
    { as: 'associate', goto: '/associate/clients#code', caption: 'Where is my personal code?', say: 'Question three. Where do I find my personal code? Here, under My clients, on the right, with your quotation link. It is also on your home page.', do: async (d) => { await d.scrollTo('#code'); await d.ring(d.page.locator('#code p').first()); await d.wait(2000); } },
    qa('A buyer used my code but is not in my list.', { items: ['Registration is complete only once NexaCrest accepts it.', 'The buyer must confirm you introduced them.', 'The first completed registration of a company counts.', 'Still unsure? Send a message with the company name.'],
      say: 'Question four. A buyer used my code, but they are not in my list. A buyer appears once NexaCrest has accepted the registration, and only if the buyer confirmed you introduced them. Remember, the first completed registration of a company counts. Still unsure? Send a message with the company name.' }),
    qa('When exactly is my commission paid?', { items: ['Earned: when the buyer has paid the order in full.', 'Due: 30 days later.', 'Paid: in the next monthly payout run, against your invoice.', 'Only to a verified bank account, with KYC complete.'],
      say: 'Question five. When exactly is my commission paid? It is earned when the buyer has paid the order in full, due thirty days later, and paid in the next monthly payout run, against your invoice. And only to a verified bank account, once your documents are accepted.' }),
    { goto: '/associate/commission', caption: 'Why is a line On hold?', say: 'Question six. Why is a line on hold? The reason is written right under the status, on the Commission page. Most often a buyer claim is open on that order. Once it is settled, it is released.', do: async (d) => { await d.ring(d.page.locator('table.list').first()); await d.wait(1800); } },
    qa('Why was less paid than my commission?', { items: ['TDS: 2% in India above ₹20,000 a year (20% without a verified PAN).', 'A claw-back: shown as "To be deducted".', 'Each paid line shows gross, TDS and net.'],
      say: 'Question seven. Why did my bank receive less than my commission? Usually TDS: two percent in India once you pass twenty thousand rupees in the year, or twenty percent without a verified PAN. Sometimes a claw-back, shown as to be deducted. Every paid line shows gross, TDS and net. Video C11 explains it all.' }),
    qa('Can I change my bank account?', { items: ['Profile → Bank details → Change my bank details.', 'Checked by two people at NexaCrest.', 'Used a few days after it is verified; until then, the old account.'],
      say: 'Question eight. Can I change my bank account? Yes. Profile, Bank details, Change my bank details. Two people at NexaCrest check it, and the new account is used a few days after it is verified. Until then, payments go to your old verified account.' }),
    qa('Why don\'t I see the Catalogue?', { items: ['Your access level decides which tabs you see.', 'Completed, fully paid orders open the way to Quoting.', 'Only the Managing Director changes the level.'],
      say: 'Question nine. Why do I not see the Catalogue? That depends on your access level. Starter has no catalogue. Completed, fully paid orders open the way to Quoting, and the Managing Director makes that change. Video C08 explains the levels.' }),
    qa('Can I give my buyer a price screenshot?', { items: ['No: prices are for you only.', 'Tell the buyer the price yourself.', 'The final price is confirmed on the Proforma Invoice.'],
      say: 'Question ten. Can I send my buyer a screenshot of the price? No. Prices are for you only. Tell the buyer the price in your own words, and remember, the final price is confirmed on the Proforma Invoice that NexaCrest sends.' }),
    qa('Who do I contact?', { items: ['Messages: your relationship manager and the associates team.', 'Mention the order number or client.', 'Never send passwords, codes or full bank numbers.'],
      say: 'And question eleven. Who do I contact? Messages, in your portal. Your relationship manager and the associates team read them. Mention the order number or client, and never send passwords, codes or full bank numbers.' }),
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
