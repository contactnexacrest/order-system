'use strict';
// C12 — Messages and notices: a conversation with NexaCrest, the notices on Home, the "(n new)"
// counter, e-mail copies, and what notices may show at each access level.
const { nav, associateSetup, PLAN_A } = require('./_common');

const EMAIL = 'associate.messages@email.com';

module.exports = {
  code: 'C12',
  file: 'C12-Associate-Messages-And-Notices.mp4',
  group: 'Group C · Associates',
  title: 'Messages and notices',
  subtitle: 'Talk to NexaCrest, and never miss what happens on your clients\' orders.',
  minutes: '8',

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'starter', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    demo.sql(`INSERT INTO associate_events (associate_id, event, message, is_notice, created_at) VALUES
      (${s.a.id}, 'client', 'Your client TEST Demo Stone Buyer BV is registered and protected for 6 months.', 1, NOW() - INTERVAL 3 DAY),
      (${s.a.id}, 'order', 'Order TEST-TUT-1201 of TEST Demo Stone Buyer BV moved to: Production.', 1, NOW() - INTERVAL 1 DAY)`);
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Good communication makes great partnerships. In this video: messages, your direct line to NexaCrest, and notices, the little updates that tell you what is happening, without you having to ask.' },
    { card: 'chapter', title: 'Notices', subtitle: 'Updates that come to you.', say: 'Part one. Notices.' },
    { as: 'associate', goto: '/associate', caption: 'Home (2 new)', say: 'See the Home tab? It says two new. That counter shows notices you have not seen yet.', do: async (d) => { await d.point(nav(d, 'Home'), 1800); } },
    { caption: 'New notices are bold', say: 'Scroll to Notices. New ones are in bold: a client registered, an order moved forward. Each with its date. When you have looked at them, they turn normal, and the counter goes back to zero.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Notices' })); await d.ring(d.page.locator('h2:has-text("Notices") + table')); await d.wait(2200); } },
    { card: 'list', kicker: 'Notices', title: 'What you are told about', items: [
      'A buyer registered through your link or code, and was accepted.',
      'An order of your client moved to a new stage.',
      'Commission earned, paid, held or released.',
      'Your documents or bank details accepted or rejected; your level changed.',
    ], say: 'You are told about everything that matters to you. A buyer registered and was accepted. An order moved to a new stage. Commission earned, paid, held, or released. And your documents or bank details accepted or rejected. Most notices also arrive by e-mail, so you do not even have to log in.' },
    { card: 'list', kicker: 'By level', title: 'Amounts in notices', items: [
      'Starter and Quoting: notices tell you what happened, without order amounts.',
      'Full: notices and e-mails may include the order amounts.',
      'Your own commission is always on the Commission page.',
    ], say: 'One detail. At the Starter and Quoting levels, notices say what happened, without the order amounts. At the Full level, notices and e-mails can include the amounts. Your own commission, of course, is always on the Commission page.' },

    { card: 'chapter', title: 'Messages', subtitle: 'A direct line to your relationship manager.', say: 'Part two. Messages.' },
    { goto: '/associate/messages', caption: 'Your relationship manager', say: 'Open Messages. At the top, the name of your relationship manager. Every message you send here reaches the NexaCrest associates team, and stays in one tidy conversation, with dates.', do: async (d) => { await d.point(d.page.locator('p.muted').first(), 1500); } },
    { caption: 'Ask a question', say: 'Let us ask something real. Type your message, and press Send.', do: async (d) => {
      await d.type(d.page.getByLabel('Your message'), 'My buyer asks when order TEST-TUT-1201 will be shipped. Can you tell me the expected date?', { delay: 25 });
      await d.clickNav(d.page.getByRole('button', { name: 'Send' }));
    } },
    { as: 'md', goto: `/associates/${a.id}#messages`, caption: 'NexaCrest reads and replies', say: 'On the NexaCrest side, the message appears in your record, under Messages. The team replies right there.', do: async (d) => {
      await d.scrollTo('#messages'); await d.ring(d.page.locator('#messages .comment').last()); await d.wait(1200);
      await d.type(d.page.locator('#messages textarea[name="body"]'), 'Production finishes this week; the container is booked for the 20th. We will update the order stage as soon as it sails.', { delay: 20 });
      await d.clickNav(d.page.locator('#messages').getByRole('button', { name: 'Send' }));
    } },
    { as: 'associate', goto: '/associate', caption: 'A new notice', say: 'Back in your portal: a new notice, and an e-mail, tell you there is a reply.', do: async (d) => { await d.point(nav(d, 'Home'), 1500); } },
    { caption: 'The whole conversation', say: 'Open Messages, and the whole conversation is there, question and answer, with dates.', do: async (d) => { await d.clickNav(nav(d, 'Messages')); await d.ring(d.page.locator('.comment').last()); await d.wait(1800); } },
    { card: 'list', kicker: 'Tips', title: 'Messages that get fast answers', items: [
      'Mention the order number or the client name.',
      'One topic per message.',
      'Never send passwords, codes or full bank numbers in a message.',
    ], next: 'C13 · When things change', say: 'Three tips for fast answers. Mention the order number or the client. One topic per message. And never send passwords, login codes or full bank numbers in a message; NexaCrest will never ask for them. Next, C13: what happens when things change. See you there!' },
  ],
};
