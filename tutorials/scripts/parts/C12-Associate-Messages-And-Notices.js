'use strict';
// C12 — Messages and notices: a conversation with NexaCrest, the notices on Home, the "(n new)"
// counter, e-mail copies, and what notices may show at each access level.
// Updated 19 Nov 2026 (script only, not recorded): demo notices use the app's own wording (D-330: "… is now
// registered as your client. It stays your client until …"; new order, with the % blanked as "[rate in your
// agreement]" for a Starter); the app sends no notice when an order only changes stage, so the narration no
// longer says so; e-mails follow Admin › E-mail settings (D-280).
const { nav, associateSetup, PLAN_A } = require('../_common');

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
    const until = new Date(Date.now() + 182 * 864e5).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    demo.sql(`INSERT INTO associate_events (associate_id, event, message, is_notice, created_at) VALUES
      (${s.a.id}, 'client_accepted', 'TEST Demo Stone Buyer BV is now registered as your client. Once they place an order, they stay your client while your agreement runs; if they place no order by ${until}, the registration ends. Your registration certificate is in the portal (My clients).', 1, NOW() - INTERVAL 3 DAY),
      (${s.a.id}, 'order_created', 'New order TEST-TUT-1201 from your client TEST Demo Stone Buyer BV. Your commission rate for now is [rate in your agreement]. It becomes final when we send the buyer the proforma invoice.', 1, NOW() - INTERVAL 1 DAY)`);
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Good communication makes great partnerships. In this video: messages, your direct line to NexaCrest, and notices, the little updates that tell you what is happening, without you having to ask.' },
    { card: 'chapter', title: 'Notices', subtitle: 'Updates that come to you.', say: 'Part one. Notices.' },
    { as: 'associate', goto: '/associate', caption: 'Home (2 new)', say: 'See the Home tab? It says two new. That counter shows notices you have not seen yet.', do: async (d) => { await d.point(nav(d, 'Home'), 1800); } },
    { caption: 'New notices are bold', say: 'Scroll to Notices. New ones are in bold: a client registered, and a new order from that client. Each with its date. When you have looked at them, they turn normal, and the counter goes back to zero.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Notices' })); await d.ring(d.page.locator('h2:has-text("Notices") + .table-scroll table')); await d.wait(2200); } },
    { card: 'list', kicker: 'Notices', title: 'What you are told about', items: [
      'A buyer registered through your link or code, and was accepted, or was not.',
      'A new order from your client; the proforma invoice sent, with your % now final.',
      'Commission earned, paid, on hold or released.',
      'Documents accepted or to upload again; bank details to confirm or checked; a new message.',
    ], say: 'You are told about everything that matters to you. A buyer registered and was accepted, or was not. A new order from your client, and later, that we sent the proforma invoice, so your percentage on that order is now final. Commission earned, paid, on hold, or released. Your documents accepted, or to upload again. New bank details to confirm, or checked. And a new message from NexaCrest. The stage of each order is always on the Orders page. Most notices also arrive by e-mail, so you do not even have to log in. NexaCrest decides which e-mails go out; your login codes, password links and bank alerts always do.' },
    { card: 'list', kicker: 'By level', title: 'Amounts in notices', items: [
      'Starter and Quoting: notices tell you what happened, without order amounts.',
      'Full: notices and e-mails may include the order amounts.',
      'Where your level hides something, the notice says "[amount in your portal]" or "[rate in your agreement]".',
      'Your own commission is always on the Commission page.',
    ], say: 'One detail. At the Starter and Quoting levels, notices say what happened, without the order amounts. At the Full level, notices and e-mails can include the amounts. Where your level hides a figure, the notice says so in square brackets: amount in your portal, or rate in your agreement. You saw that a moment ago. Your own commission, of course, is always on the Commission page.' },

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
    ], next: 'Part 2 · When things change', say: 'Three tips for fast answers. Mention the order number or the client. One topic per message. And never send passwords, login codes or full bank numbers in a message; NexaCrest will never ask for them. Next, in part two: what happens when things change.' },
  ],
};
