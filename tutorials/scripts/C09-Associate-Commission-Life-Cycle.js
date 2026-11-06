'use strict';
// C09 — Commission from start to finish: expected → earned (buyer paid in full) → due 30 days
// later → paid in the monthly payout run, against the associate's invoice; held and clawed back;
// and the commission period shown in the portal (owner setting).
const { nav, associateSetup, PLAN_A } = require('./_common');

const EMAIL = 'associate.commission@email.com';
const ym = (monthsAgo) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - monthsAgo); return d.toISOString().slice(0, 7); };
const day = (daysAgo) => new Date(Date.now() - daysAgo * 864e5).toISOString().slice(0, 10);

module.exports = {
  code: 'C09',
  file: 'C09-Associate-Commission-Life-Cycle.mp4',
  group: 'Group C · Associates',
  title: 'Your commission, start to finish',
  subtitle: 'Expected, earned, due, paid: how commission is worked out and when it reaches your bank.',
  minutes: '11',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'full', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    s.buyer = demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Demo Stone Buyer BV', country: 'Netherlands', email: 'buyer.demo@email.com', orders: [
      { reference: 'TEST-TUT-0901', lines: [{ description: 'Granite slabs, polished, 20 mm', qty: 500, unit: 'm2', price: 34 }],
        payouts: [{ inr: 28305, fc: 340, month: ym(2), status: 'paid', paidOn: day(40), paidInr: 28305, ref: 'UTR-DEMO-000001', due: day(45), note: 'Level 2 (2%) on invoiced FOB' }] },
      { reference: 'TEST-TUT-0902', lines: [{ description: 'Granite tiles, flamed, 30 mm', qty: 300, unit: 'm2', price: 41 }],
        payouts: [{ inr: 20520, fc: 246, month: ym(0), status: 'due', due: day(-20), note: 'Level 2 (2%) on invoiced FOB' }] },
      { reference: 'TEST-TUT-0903', lines: [{ description: 'Granite blocks', qty: 12, unit: 'CBM', price: 900 }],
        payouts: [{ inr: 9000, fc: 108, month: ym(0), status: 'held', note: 'Level 1' }] },
      { reference: 'TEST-TUT-0904', lines: [{ description: 'Granite slabs, honed, 20 mm', qty: 250, unit: 'm2', price: 33 }] },
    ] });
    demo.sql(`UPDATE incentive_payouts SET hold_reason = 'Buyer claim open on this order' WHERE status = 'held' AND associate_id = ${s.a.id}`);
    s.invoice = demo.sampleFile('commission-invoice-sample.pdf', 'SAMPLE COMMISSION INVOICE - TUTORIAL DEMO');
    return s;
  },

  steps: ({ a = PLAN_A, invoice } = {}) => [
    { card: 'title', say: 'Let us talk about the best part: your commission! In this video you see how it is worked out, when it is earned, when it is due, and how it reaches your bank. With real-looking demo numbers.' },
    { card: 'list', kicker: 'The journey', title: 'Four stages of a commission', items: [
      'Expected: the order is running. The level is fixed when the Proforma Invoice is issued.',
      'Earned: the buyer has paid the order in full. Never on the advance alone.',
      'Due: 30 days after full payment, paid in the next monthly payout run.',
      'Paid: to your verified bank account, against your invoice, with a bank reference.',
    ], say: 'Every commission travels through four stages. Expected, while the order is running. Your commission level is fixed when the Proforma Invoice is issued. Earned, once the buyer has paid the order in full, never on the advance alone. Due, thirty days after that full payment, and paid in the next monthly payout run. And Paid: to your verified bank account, against your invoice, with a bank reference you can check.' },
    { card: 'list', kicker: 'The basis', title: 'What commission is calculated on', items: [
      'The final invoiced FOB value of the goods, line by line.',
      'No freight, insurance or services. Bank charges never reduce it.',
      'An agreed discount or settled claim does reduce it.',
      'An order not paid in full earns nothing.',
    ], say: 'What is it calculated on? The final invoiced FOB value of the goods, line by line. Freight, insurance and services are not included, and bank charges never reduce it. An agreed discount, or a settled buyer claim, does lower the basis. And an order that is not paid in full earns nothing.' },

    { card: 'chapter', title: 'Orders and the commission column', say: 'Part one. Your orders.' },
    { as: 'associate', goto: '/associate/orders', caption: 'Orders: commission per order', say: 'On the Orders page, each order shows its commission state. Paid, with the amount. Earned and waiting. On hold. Or still expected, when the buyer has not paid in full yet.', do: async (d) => { await d.ring(d.page.locator('table.list')); await d.wait(2200); } },
    { caption: 'The statement of one order', say: 'Open an order to see its commission statement: each goods line, whether its price was in range, the commission per line, the level, the rupee basis, GST, the full payment date, and the payout run.', do: async (d) => {
      await d.clickNav(d.page.locator('table.list a', { hasText: 'TEST-TUT-0901' }).first());
      const h = d.page.getByRole('heading', { name: 'Commission statement' });
      if (await h.count()) { await d.scrollTo(h); await d.ring(d.page.locator('.statement')); }
      await d.wait(2000);
    } },
    { caption: 'Below the floor? No commission on that line', say: 'One detail to know. If a line was sold below the lowest price, and NexaCrest approved it anyway, that line earns no commission. That is why the lowest price in your quote matters.', do: async (d) => { await d.wait(1500); } },

    { card: 'chapter', title: 'The Commission page', say: 'Part two. The Commission page.' },
    { goto: '/associate/commission', caption: 'Earned, paid, waiting', say: 'At the top: earned, paid, and waiting to be paid, and anything on hold.', do: async (d) => { await d.point(d.page.locator('h1 + p, h1 ~ p').filter({ hasText: 'Earned' }).first(), 1800); } },
    { caption: 'The period you see', say: 'The line below says which period you are looking at, for example the last twelve months. NexaCrest sets that period for all associates. Commission still waiting to be paid is always shown, however old it is.', do: async (d) => { await d.point('[data-commission-period]', 1800); } },
    { caption: 'One line per commission', say: 'Then one line per commission: the payout month, the order, the client, the amount in the order currency and in rupees, and the status.', do: async (d) => { await d.ring(d.page.locator('table.list').first()); await d.wait(2000); } },
    { caption: 'Paid: date and bank reference', say: 'A paid line shows the date and the bank reference, so you can match it with your bank statement in seconds.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-TUT-0901' }).first()); await d.wait(1800); } },
    { caption: 'On hold, with the reason', say: 'A line on hold shows why. For example, while a buyer claim is open on that order. Once it is settled, NexaCrest releases it.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-TUT-0903' }).first()); await d.wait(1800); } },

    { card: 'chapter', title: 'Upload your invoice', subtitle: 'NexaCrest pays against it.', say: 'Part three. Your invoice.' },
    { caption: 'Invoice number and file', say: 'For a line that is waiting, upload your invoice: type your invoice number, choose the file, and press Upload.', do: async (d) => {
      const row = d.page.locator('tr', { hasText: 'TEST-TUT-0902' }).first();
      await d.type(row.locator('input[name="invoice_no"]'), 'DEMO-INV-001');
      await d.upload(row.locator('input[name="invoice_file"]'), invoice);
      await d.clickNav(row.getByRole('button', { name: 'Upload' }));
    } },
    { caption: 'On file', say: 'Done. The invoice number now shows on that line, and NexaCrest sees it on their side too.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-TUT-0902' }).first()); await d.wait(1500); } },

    { card: 'chapter', title: 'Behind the scenes', subtitle: 'Hold, release, claw back.', say: 'Part four. What NexaCrest does with it.' },
    { as: 'md', goto: `/associates/${a.id}#commission`, caption: 'Staff: Orders and commission', say: 'On the staff side, your record lists every order and payout. The team can put a payout on hold, with a reason that you see, release it later, and, in rare cases like a cancelled shipment, claw an amount back. A claw-back appears in your list as To be deducted.', do: async (d) => { await d.scrollTo('#commission'); await d.wait(2500); } },
    { card: 'list', kicker: 'Recap', title: 'Commission in one breath', items: [
      'Level fixed at the Proforma Invoice; basis = invoiced FOB of the goods.',
      'Earned when the buyer pays in full; due 30 days later; monthly payout run.',
      'Upload your invoice per line; payment only to your verified bank.',
      'Hold shows a reason; paid shows the date and bank reference.',
    ], next: 'C10 · Statements, CSV and printing', say: 'In one breath: the level is fixed at the Proforma Invoice, the basis is the invoiced FOB of the goods, it is earned at full payment, due thirty days later, and paid in the monthly run, against your invoice, to your verified bank. Next, C10: statements, CSV and printing. See you there!' },
  ],
};
