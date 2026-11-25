'use strict';
// H01 — Become a NexaCrest Associate (hiring video). Storyboard approved by the owner on 25 Nov 2026
// (tutorials/hiring/H01-storyboard-and-script.md, draft 4; decisions D-415, D-416, D-417, D-418, D-424, D-425,
// D-426, D-292). English only (D-419). Told as a story, in very simple words. Every number is an example:
// for the recording the commission levels are set to the example rates 10/15/20/25/30/35 % (restoreDb puts
// the real levels back), so no real rate can show on screen. No real names; no contact details.
const { nav, associateSetup, PLAN_A } = require('./_common');

const EMAIL = 'associate.hiring@email.com';
const EXAMPLE = 'Example numbers only. These are not NexaCrest\'s real rates. Your real numbers are in your signed agreement.';

module.exports = {
  code: 'H01',
  file: 'H01-Become-A-NexaCrest-Associate.mp4',
  group: 'Join NexaCrest',
  title: 'Become a NexaCrest Associate',
  subtitle: 'Bring buyers. Earn on their orders — again and again.',
  minutes: '10',
  restoreDb: true,

  async setup(demo) {
    // Example rates only (D-415): six example levels, five counting orders each.
    // Levels are referenced by associates, so they are updated in place (never deleted); the ladder is rebuilt.
    [10, 15, 20, 25, 30, 35].forEach((v, i) => demo.sql(
      `INSERT INTO commission_levels (id, name, kind, value, is_active, sort_order) VALUES (${i + 1}, 'Level ${i + 1}', 'pct', ${v}, 1, ${(i + 1) * 10})
       ON DUPLICATE KEY UPDATE name = VALUES(name), kind = 'pct', value = VALUES(value), is_active = 1, sort_order = VALUES(sort_order)`));
    demo.sql(`DELETE FROM commission_ladder_steps`);
    [[1, 5], [6, 10], [11, 15], [16, 20], [21, 25], [26, null]].forEach(([from, to], i) => demo.sql(
      `INSERT INTO commission_ladder_steps (from_order, to_order, level_id) VALUES (${from}, ${to === null ? 'NULL' : to}, ${i + 1})`));
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'full', address: 'Demo address', pan: 'ABCDE1234F' }, { withMd: false });
    s.product = demo.pricedProduct(s.a.id);
    s.buyer = demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Example Buyer 1', country: 'United Arab Emirates', email: 'buyer1@hiring-demo.example', orders: [
      { reference: 'TEST-HIRE-0001', lines: [{ description: 'Granite slabs, polished, 20 mm (example)', qty: 500, unit: 'm2', price: 20 }] },
    ] });
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Imagine this. You introduce one buyer to us. Just one. That buyer orders from us. Then orders again. And again. And every time, you earn. This is what it means to be a NexaCrest Associate. Let us show you how it works.' },

    // 1 · Who we are
    { card: 'chapter', title: 'Who we are', subtitle: 'NexaCrest Group', say: 'Part one. Who we are.' },
    { card: 'list', kicker: 'NexaCrest Group', title: 'An export company in India', items: [
      'We sell goods to buyers in other countries.',
      'We do all the hard work: the price, the export papers, the shipping, and collecting the money.',
    ], say: 'NexaCrest Group is an export company in India. We sell goods to buyers in other countries. We do all the hard work: the price, the export papers, the shipping, and collecting the money.' },

    // 2 · Your story begins
    { card: 'chapter', title: 'Your story begins', subtitle: 'You bring one buyer', say: 'Part two. Your story begins.' },
    { card: 'list', kicker: 'You know a buyer', title: 'Bring us a buyer', items: [
      'You know a shop owner in another country.',
      'They need goods that we sell.',
      'You tell them about NexaCrest. That is your job.',
    ], say: 'You know a shop owner in another country. They need goods that we sell. You tell them about NexaCrest. That is your job: bring us a buyer.' },
    { as: 'associate', goto: '/associate/clients', caption: 'Your link and your code', say: 'You send the buyer your own link, or give them your own code. The buyer signs up with it. Now this buyer is your client.' },
    { card: 'list', kicker: 'One buyer, one associate', title: 'Two people bring the same buyer?', items: [
      'The buyer belongs to the person whose link or code the buyer used.',
      'So send your link early.',
    ], say: 'What if someone else also knows this buyer? The buyer belongs to the person whose link or code the buyer used. So send your link early.' },
    { as: 'associate', goto: '/associate/catalogue', caption: 'Prices come from your portal', say: 'The buyer asks you: how much? Easy. In your portal you pick the product and its details, like size and thickness. The portal shows the price. You give that price to the buyer. You never have to work out a price yourself.' },
    { card: 'list', kicker: 'We do the rest', title: 'You bring the buyer, we do the work', items: [
      'We send the quotation, make the export papers and ship the goods.',
      'The buyer pays NexaCrest, straight into our bank.',
      'You never sign anything for us. You never take money from a buyer.',
    ], say: 'Then we take over. We send the quotation, make the export papers and ship the goods. The buyer pays NexaCrest, straight into our bank. You never sign anything for us, and you never take money from a buyer.' },
    { as: 'associate', goto: '/associate/orders', caption: 'Watch your order', say: 'In your portal you watch your order move: quotation, confirmed, in production, shipping, shipped. You also see if the buyer has paid everything.' },
    { caption: 'Paid in full = you earn', say: 'While the buyer is still paying, it says: not earned yet, buyer still paying. When the buyer has paid everything, your commission is earned. Your first coin drops into the jar.' },

    // 3 · How much you earn on one order
    { card: 'chapter', title: 'How much you earn on one order', subtitle: EXAMPLE, say: 'Part three. How much you earn on one order. All numbers here are examples only. Your real numbers are in your agreement.' },
    { card: 'list', kicker: 'One word first', title: 'What is FOB value?', items: [
      'The value of the goods only.',
      'Not the shipping. Not the insurance.',
      'Your commission is always worked out on the FOB value of the goods on the order.',
    ], say: 'One word first: FOB value. The FOB value is the value of the goods only. It does not include shipping. It does not include insurance. Your commission is always worked out on the FOB value of the goods on the order.' },
    { card: 'list', kicker: EXAMPLE, title: 'Your commission on one order', items: [
      'FOB value of the goods: USD 10,000',
      'Your rate: 10 %',
      'Your commission: USD 1,000',
    ], say: 'Say your buyer\'s goods have an FOB value of ten thousand dollars. Your rate is ten percent. Ten percent of ten thousand dollars is one thousand dollars.' },
    { card: 'list', kicker: EXAMPLE, title: 'Turned into rupees, then fixed', items: [
      'USD 1,000 × ₹80 (the rate on the day the buyer\'s money reached our bank)',
      '= ₹80,000 — fixed',
      'If the dollar goes up or down later, it does not change.',
    ], say: 'We turn the dollars into rupees, at the rate on the day the buyer\'s money reached our bank. Say the rate is eighty rupees for one dollar. One thousand dollars becomes eighty thousand rupees. Now this amount is fixed. If the dollar goes up or down later, it does not change.' },
    { card: 'list', kicker: EXAMPLE, title: 'We pay ₹80,000. Always in rupees.', items: [
      'In India: ₹80,000 reaches your account.',
      'In another country: your bank changes the ₹80,000 into your money, at its own rate, and takes its own fee.',
      'Either way, we pay the same ₹80,000.',
    ], say: 'So we pay you eighty thousand rupees. Only eighty thousand rupees. We always pay in rupees. If you live in India, eighty thousand rupees reach your account. If you live in another country, your bank changes it into your money, at its own rate, and takes its own fee. So you may get a little more or a little less in your money. But we always pay the same eighty thousand rupees.' },

    // 4 · The best part
    { card: 'chapter', title: 'The best part', subtitle: 'Your buyer orders again, and again', say: 'Part four. The best part.' },
    { card: 'list', kicker: 'One client, many orders', title: 'You earn again and again', items: [
      'March: your buyer orders. You earn.',
      'June: they order again. You earn again.',
      'September, December: again and again.',
      'You brought the buyer once. You earn on every order, for as long as your agreement runs.',
    ], say: 'Your buyer was happy. So in three months, they order again. You earn again. Three months later, again. You earn again. You did the work once, when you brought the buyer. But you earn on every order this client places, for as long as your agreement runs.' },
    { card: 'list', kicker: EXAMPLE, title: 'One client in one year', items: [
      '4 orders × ₹80,000',
      '= ₹3,20,000 from one client',
    ], say: 'In this example, one client orders four times in a year. Four times eighty thousand rupees. That is three lakh twenty thousand rupees, from one client.' },
    { card: 'list', kicker: 'Bring more buyers', title: 'Earn more', items: [
      'Bring a second buyer. And a third.',
      'Each of them orders again and again.',
      'You earn on all of their orders.',
    ], say: 'Now imagine you bring a second buyer. And a third. Each of them orders again and again. And you earn on all of their orders.' },
    { card: 'list', kicker: EXAMPLE, title: 'More orders, a higher rate', items: [
      'Level 1 · 10 %  →  15 %  →  20 %  →  25 %  →  30 %  →  35 %',
      'The more orders you bring, the higher your level.',
      'New client or old client: we count orders, not clients.',
    ], say: 'And there is more. The more orders you bring, the higher your level. A higher level means a higher rate, for the orders that follow. New client or old client, it does not matter. We count orders, not clients. In this example, your rate can climb from ten percent all the way to thirty five percent.' },
    { card: 'list', kicker: 'No limit', title: 'The more business you bring, the more you earn', items: [
      'More buyers. More orders. A higher rate.',
      'How much you earn is up to you.',
    ], say: 'There is no limit to what you can earn. More buyers. More orders. A higher rate. It all adds up. How much you earn is up to you.' },

    // 5 · The simple rules
    { card: 'chapter', title: 'The simple rules', subtitle: 'Knowing them means no surprises', say: 'Part five. The simple rules. Knowing them means no surprises.' },
    { card: 'list', kicker: `Rule 1 · ${EXAMPLE}`, title: 'Which orders earn?', items: [
      'The first order of each client always earns, big or small.',
      'After that: only orders at or above the minimum order value in your agreement.',
      'Example: minimum ₹10,00,000 → a ₹12,00,000 order earns ✔ · a ₹6,00,000 order does not ✘',
    ], say: 'Rule one. The first order of each client always earns, big or small. This helps you start. After that, an order earns only if its FOB value is at least the minimum order value in your agreement. Example: the minimum is ten lakh rupees. An order of twelve lakh rupees earns. An order of six lakh rupees does not.' },
    { card: 'list', kicker: 'Rule 2', title: 'Which orders count for your level?', items: [
      '✔ Big enough: at least the minimum order value',
      '✔ Paid: the buyer\'s first payment has reached our bank (no first payment: the buyer has paid everything)',
      '✔ Not cancelled',
      'A small first order still earns, but does not count for your level.',
    ], say: 'Rule two. An order counts for your level when three things are true. It is big enough: at least the minimum order value. The buyer\'s first payment has reached our bank, or, if there is no first payment, the buyer has paid everything. And it is not cancelled. A small first order still earns, but it does not count for your level.' },
    { card: 'list', kicker: `Rule 3 · ${EXAMPLE}`, title: 'Orders close together count as one', items: [
      'Your agreement says: 10 days (example).',
      '1 March and 5 March, same client → count as one for your level.',
      '20 March → more than 10 days after 1 March → a new order for your level.',
      'Each order still earns its own commission.',
    ], say: 'Rule three. Orders from the same client that come close together count as one, for your level. How close is written in your agreement as a number of days. Example: your agreement says ten days. Your client orders on the first of March and again on the fifth of March. That is within ten days, so for your level they count as one. On the twentieth of March the client orders again. That is more than ten days after the first of March, so it counts as a new order. And remember: each order still earns its own commission. This rule is only about your level.' },
    { card: 'list', kicker: 'Rule 4', title: 'Your rate for an order is fixed', items: [
      'It is fixed when we send the buyer the Proforma Invoice.',
      'Later changes to your level do not change it.',
    ], say: 'Rule four. When we send the buyer the Proforma Invoice, the rate for that order is fixed. Later changes to your level do not change it.' },
    { card: 'list', kicker: 'Rule 5', title: 'Cancelled orders', items: [
      'Cancelled before the buyer paid everything → no commission on that order.',
      'It stops counting for your level. Your other orders keep their rate.',
    ], say: 'Rule five. If an order is cancelled before the buyer has paid everything, there is no commission on that order, and it stops counting for your level. Your other orders keep their rate.' },
    { as: 'associate', goto: '/associate/clients', caption: 'Rule 6: your client stays yours', say: 'Rule six. Your client stays yours. Only a new sign-up that places no order for a long time ends. How long is written in your agreement. Then you can invite them again.',
      do: async (d) => { const c = d.page.locator('[data-client-status]').first(); if (await c.count()) await d.point(c, 2500); } },
    { goto: '/associate/commission', caption: 'Your rate in your portal', say: 'Your portal always shows your current rate: the percent your next order earns. And each order shows its own percent.' },

    // 6 · Getting paid
    { card: 'chapter', title: 'Getting paid', subtitle: 'Once a month, by bank transfer', say: 'Part six. Getting paid.' },
    { card: 'list', kicker: 'Paid once a month', title: 'When you get paid', items: [
      'The buyer\'s last payment reaches our bank before the month\'s closing day → paid that month.',
      'It comes later → paid the next month.',
    ], say: 'We pay commission once a month. If the buyer\'s last payment reaches our bank before that month\'s closing day, you are paid that month. If it comes later, you are paid the next month.' },
    { as: 'associate', goto: '/associate/commission', caption: 'See every payment', say: 'Your portal shows every payment. After we pay, you see "Paid to you", with the bank reference, so you can find it on your bank statement.' },
    { card: 'list', kicker: 'Safe and simple', title: 'How we pay', items: [
      'Bank transfer only, into your own bank account. We check the account first.',
      'In India: tax is taken off as the law says, and you get a certificate for it.',
      'In another country: we do the bank paperwork for sending money abroad.',
      'When a commission is earned, upload your invoice for it in your portal.',
    ], say: 'We pay only by bank transfer, into your own bank account. We check the account first. If you live in India, we take off tax as the law says, and you get a certificate for it. If you live in another country, we do the bank paperwork for sending money abroad. When a commission is earned, upload your invoice for it in your portal. Then nothing holds up your payment.' },
    { card: 'list', kicker: 'Safe and clear', title: 'Your money is protected', items: [
      'Once we have paid you, the money is yours to keep.',
      'Everything is written in your portal.',
      'New bank details for you? A yellow notice on every page, and we check them before we pay.',
    ], say: 'Once we have paid you, the money is yours to keep, even if the buyer later gets money back from us. Everything is written in your portal. And if anyone enters new bank details for you, a yellow notice appears on every page, and we check them before we pay. Nobody can send your money somewhere else.' },

    // 7 · Your questions
    { card: 'chapter', title: 'Your questions', subtitle: 'Answered', say: 'Part seven. Your questions, answered.' },
    { card: 'list', kicker: 'Questions', title: 'Joining and earning', items: [
      'Do I have to pay anything? No. Not to join, not to stay.',
      'Do I need export experience? No. We do the export work. If you have it, that helps.',
      'Can I earn from the same client again? Yes, on every order, for as long as your agreement runs.',
      'Is there a limit to what I can earn? No.',
    ], say: 'Do I have to pay anything? No. There is nothing to pay. Not to join, and not to stay. Do I need export experience? No. We do the export work. If you do have export experience, that helps. Can I earn from the same client again? Yes. On every order they place, for as long as your agreement runs. Is there a limit to what I can earn? No. The more business you bring, the more you earn.' },
    { card: 'list', kicker: 'Questions', title: 'Orders and levels', items: [
      'Does every order earn? The first order of each client always does. After that, orders at or above the minimum order value.',
      'Two orders from one client in a few days counted as one? That is the number of days in your agreement. Each still earns its own commission.',
      'The buyer cancels? Cancelled before full payment: no commission. Already paid to you: yours.',
    ], say: 'Does every order earn? The first order of each client always earns. After that, orders at or above the minimum order value in your agreement earn. Two orders from one client in a few days. Why did they count as one for my level? Because orders from the same client within the number of days in your agreement count as one for your level. Each one still earns its own commission. What if the buyer cancels? If the order is cancelled before the buyer has paid everything, there is no commission on it. Commission we have already paid you is yours.' },
    { card: 'list', kicker: 'Questions', title: 'Safety', items: [
      'Can I work from another country? Yes. We send your money by bank transfer.',
      'Who can see my bank details? Only our accounts team. Elsewhere, only the last 4 digits.',
      'Will NexaCrest ask for my password or login code? Never. Your personal code: share it freely.',
    ], say: 'Can I work from another country? Yes. We send your money by bank transfer, as you saw in part three. Who can see my bank details? Only our accounts team. Your account number is locked away, and everywhere else we show only its last four digits. Will NexaCrest ever ask for your password or login code? Never. Keep them to yourself. Your personal code is different: share it with buyers as much as you like.' },

    // 8 · Join us
    { card: 'chapter', title: 'Join us', subtitle: 'Joining is easy', say: 'Part eight. Join us.' },
    { card: 'list', kicker: 'What you need', title: 'Joining is easy', items: [
      'Sign the associate agreement.',
      'Your ID proof and your address proof.',
      'A short live video call: hold up your ID proof and address proof so we can read them.',
      'Your bank details and a bank proof, for example a bank statement.',
    ], say: 'Joining is easy. You sign the associate agreement. Then you send us: your ID proof. Your address proof. A short live video call, where you hold up your ID proof and your address proof so we can read them. And your bank details with a bank proof, for example a bank statement.' },
    { as: 'associate', goto: '/associate/profile', caption: 'Why we ask for your bank details', say: 'Why do we ask for your bank details? So we pay the right account, in your own name. So nobody can send your money somewhere else. And because tax rules and rules for sending money abroad say we must. Only our accounts team can see them.',
      do: async (d) => { const w = d.page.locator('[data-bank-why]').first(); if (await w.count()) { await d.scrollTo(w); await d.point(w, 3000); } } },
    { card: 'list', kicker: 'Your portal', title: 'Your own portal', items: [
      'Log in with your password and a code we send to your e-mail.',
      'You start with the basic portal. More opens up as your orders are completed.',
    ], say: 'When you are approved, you get your own portal. You log in with your password and a code we send to your e-mail. You start with the basic portal, and more opens up as your orders are completed.' },
    { card: 'list', kicker: 'Your story', title: 'Remember', items: [
      'You bring a buyer. We do all the work.',
      'The buyer pays, and you earn. The buyer orders again, and you earn again.',
      'Bring more buyers, and earn more. There is no limit.',
    ], say: 'So, remember your story. You bring a buyer. We do all the work. The buyer pays, and you earn. The buyer orders again, and you earn again. Bring more buyers, and earn more. There is no limit.' },
    { card: 'list', kicker: 'Start your story today', title: 'Contact us', items: [
      process.env.TUT_H01_CONTACT || '[contact details added by NexaCrest]',
      EXAMPLE,
      'This video gives a simple picture of how things work. Your signed agreement is what counts, and NexaCrest may update its terms from time to time.',
    ], say: 'Start your story today. Contact us. We look forward to growing together. Just so you know: this video gives a simple picture of how things work. Your signed agreement is what counts, and our terms may be updated from time to time.' },
  ],
};
