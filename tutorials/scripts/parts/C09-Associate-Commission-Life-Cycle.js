'use strict';
// C09 — Commission from start to finish (D-264): expected → fixed by itself when the buyer has paid
// in full (order currency; rupees for India at the rate of the buyer's payments) → due 30 days later →
// paid in the monthly payout run, against the associate's invoice; held while a claim is open;
// and the commission period shown in the portal (owner setting).
// Updated 19 Nov 2026 (script only, not recorded): D-292 statuses ("Not earned yet — buyer still paying",
// "Earned — will be paid on <date>", "Paid on <date> (ref)", "On hold — a buyer claim on this order is open"),
// the current % + 4-step "How it works", never levels or the ladder for the associate; payout day = last day
// of the month (Sunday / holiday: the working day before); D-291 the MD's commission rule on the staff page
// (new step, selector #commission-rule); take-back only for fraud or a breach ("We will take this off your
// next payment"). Demo data: payout notes no longer name a level, the hold uses the app's claim reason, and
// the payout date is the last day of the month.
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.commission@email.com';
const ym = (monthsAgo) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - monthsAgo); return d.toISOString().slice(0, 7); };
const day = (daysAgo) => new Date(Date.now() - daysAgo * 864e5).toISOString().slice(0, 10);
// The payout day (D-292 default): the last day of the month the commission falls due in; a Sunday moves to
// the working day before (holidays of the Holiday Calendar are not modelled here).
const runLast = (daysAgo) => {
  const due = new Date(Date.now() - daysAgo * 864e5);
  const lastOf = (y, m) => { const d = new Date(Date.UTC(y, m + 1, 0)); if (d.getUTCDay() === 0) d.setUTCDate(d.getUTCDate() - 1); return d; };
  let r = lastOf(due.getUTCFullYear(), due.getUTCMonth());
  if (r.toISOString().slice(0, 10) < due.toISOString().slice(0, 10)) r = lastOf(due.getUTCFullYear(), due.getUTCMonth() + 1);
  return r.toISOString().slice(0, 10);
};
// The app's own hold reason for a buyer claim (AssociatePayoutService::CLAIM_HOLD_REASON), so the portal shows
// "On hold — a buyer claim on this order is open".
const CLAIM_HOLD = 'A buyer claim on this order is open — the commission waits until it is resolved.';

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
    s.buyer = demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Demo Stone Buyer BV', country: 'Netherlands', email: 'purchasing@commission-demo.example', orders: [
      { reference: 'TEST-TUT-0901', lines: [{ description: 'Granite slabs, polished, 20 mm', qty: 500, unit: 'm2', price: 34 }],
        payouts: [{ inr: 28305, fc: 340, month: ym(2), status: 'paid', paidOn: day(40), paidInr: 28305, ref: 'UTR-DEMO-000001', due: day(45), fixedRate: 83.25, fixedOn: day(75), runOn: day(40) }] },
      { reference: 'TEST-TUT-0902', lines: [{ description: 'Granite tiles, flamed, 30 mm', qty: 300, unit: 'm2', price: 41 }],
        payouts: [{ inr: 20518.86, fc: 246, month: ym(0), status: 'due', due: day(-20), fixedRate: 83.41, fixedOn: day(10), runOn: runLast(-20) }] },
      { reference: 'TEST-TUT-0903', lines: [{ description: 'Granite blocks', qty: 12, unit: 'CBM', price: 900 }],
        payouts: [{ inr: 8999.64, fc: 108, month: ym(0), status: 'held', fixedRate: 83.33, fixedOn: day(5) }] },
      { reference: 'TEST-TUT-0904', lines: [{ description: 'Granite slabs, honed, 20 mm', qty: 250, unit: 'm2', price: 33 }] },
    ] });
    demo.sql(`UPDATE incentive_payouts SET hold_reason = '${CLAIM_HOLD}' WHERE status = 'held' AND associate_id = ${s.a.id}`);
    s.invoice = demo.sampleFile('commission-invoice-sample.pdf', 'SAMPLE COMMISSION INVOICE - TUTORIAL DEMO');
    return s;
  },

  steps: ({ a = PLAN_A, invoice } = {}) => [
    { card: 'title', say: 'Let us talk about the best part: your commission! In this video you see how it is worked out, when it is earned, and how it reaches your bank. With real-looking demo numbers.' },
    { card: 'list', kicker: 'The journey', title: 'What your commission says', items: [
      'Not earned yet — buyer still paying. Your % for the order is fixed when we send the buyer the Proforma Invoice.',
      'Earned — will be paid on <date>: the buyer has paid in full. The amount never changes afterwards.',
      'We pay it 30 days after full payment, on our monthly payment day: the last day of the month.',
      'Paid on <date>, with the bank reference: to your checked bank account, against your invoice.',
    ], say: 'Every commission shows one of a few simple statuses. Not earned yet, buyer still paying, while the order is running. Your percentage for that order is fixed when we send the buyer the Proforma Invoice, and it does not change later. Then: Earned, will be paid on a date. The day the buyer has paid the order in full, the app sets your commission by itself, never on the advance alone, and from then on the amount never changes. We pay it thirty days after that full payment, on our monthly payment day, which is the last day of the month. If that day is a Sunday or a holiday, we pay on the working day before. And finally: Paid on a date, with the bank reference, to your checked bank account, against your invoice. One more status you may see: On hold, a buyer claim on this order is open.' },
    { card: 'list', kicker: 'The exchange rate', title: 'Fixed once, never changes', items: [
      'Your commission is in the order\'s currency, usually US dollars.',
      'Based in India: it is fixed in rupees at the rate the buyer\'s payments were converted at.',
      'Later changes in the exchange rate never raise or lower it.',
      'NexaCrest pays all bank charges. They never come out of your commission.',
    ], say: 'A word about the exchange rate, because it matters. Your commission is worked out in the order\'s currency, usually US dollars. If you are based in India, on the day the buyer completes payment it is fixed in rupees, at the exact rate the buyer\'s payments were converted at by the bank. That rupee amount is what you are paid. Whatever the exchange rate does later, it never goes up or down. And NexaCrest pays all the bank charges, so they never come out of your commission.' },
    { card: 'list', kicker: 'The basis', title: 'What commission is calculated on', items: [
      'The FOB value of the goods on the Commercial Invoice, line by line.',
      'No freight, insurance or services. Bank charges never reduce it.',
      'An agreed discount or settled claim does reduce it.',
      'An order not paid in full earns nothing.',
    ], say: 'What is it calculated on? The F O B value of the goods on the commercial invoice, line by line. Freight, insurance and services are not included, and bank charges never reduce it. An agreed discount, or a settled buyer claim, does lower it. And an order that is not paid in full earns nothing.' },

    { card: 'chapter', title: 'Orders and the commission column', say: 'Part one. Your orders.' },
    { as: 'associate', goto: '/associate/orders', caption: 'Orders: commission per order', say: 'On the Orders page, each order shows its commission, with the percentage fixed on that order, and its status. Paid on a date. Earned, will be paid on a date. On hold, a buyer claim on this order is open. Or not earned yet, buyer still paying.', do: async (d) => { await d.ring(d.page.locator('table.list')); await d.wait(2200); } },
    { caption: 'The statement of one order', say: 'Open an order to see its commission statement: each goods line, whether its price was within range, the commission per line, your commission rate on this order, the amount it is worked out on, G S T, the date of full payment, and when we pay.', do: async (d) => {
      await d.clickNav(d.page.locator('table.list a', { hasText: 'TEST-TUT-0901' }).first());
      const h = d.page.getByRole('heading', { name: 'Commission statement' });
      if (await h.count()) { await d.scrollTo(h); await d.ring(d.page.locator('.statement')); }
      await d.wait(2000);
    } },
    { caption: 'Below the floor? No commission on that line', say: 'One detail to know. If a line was sold below the lowest price, and NexaCrest approved it anyway, that line earns no commission. That is why the lowest price in your quote matters.', do: async (d) => { await d.wait(1500); } },

    { card: 'chapter', title: 'The Commission page', say: 'Part two. The Commission page.' },
    { goto: '/associate/commission', caption: 'Your % and How it works', say: 'At the top, your current percentage: of the F O B value of each order from the clients you introduced. That is the percentage for your next order; each order keeps the one it was given at its Proforma Invoice. You never see levels or steps here, only your own percentage. Below it, How it works, in four steps, ending with our payment day.', do: async (d) => { await d.ring(d.page.locator('[data-commission-headline]')); await d.wait(1800); await d.point('[data-how-it-works]', 2200); } },
    { caption: 'How we set your commission', say: 'Then a box explains in plain words how we set your commission, and that it does not change later. The same rule we just went through.', do: async (d) => { await d.ring(d.page.locator('[data-how-fixed]')); await d.wait(2600); } },
    { caption: 'Earned, paid, waiting', say: 'Below it: earned, paid, and waiting to be paid, and anything on hold. These totals are in the order currency, here US dollars.', do: async (d) => { await d.point(d.page.locator('p').filter({ hasText: 'Earned in the last' }).first(), 1800); } },
    { caption: 'The period you see', say: 'The line below says which period you are looking at, for example the last twelve months. NexaCrest sets that period for all associates. Commission still waiting to be paid is always shown, however old it is.', do: async (d) => { await d.point('[data-commission-period]', 1800); } },
    { caption: 'One line per commission', say: 'Then one line per commission: the month, the order, the client, your percentage on that order, the amount in the order currency, marked Fixed, and the status.', do: async (d) => { await d.ring(d.page.locator('table.list').first()); await d.wait(2000); } },
    { caption: 'Fixed in rupees, for India', say: 'Because this associate is based in India, a waiting line also shows one rupee line: You will be paid this amount, fixed at this rate per US dollar on this date, and it will not change. The status says: Earned, will be paid on, and the date, our payment day at the end of the month.', do: async (d) => { const row = d.page.locator('tr', { hasText: 'TEST-TUT-0902' }).first(); await d.ring(row); await d.wait(1500); await d.point(row.locator('[data-fixed-rupees]'), 2200); } },
    { caption: 'Paid: date and bank reference', say: 'A paid line says Paid on, the date, and the bank reference, so you can match it with your bank statement in seconds.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-TUT-0901' }).first()); await d.wait(1800); } },
    { caption: 'On hold, with the reason', say: 'A line on hold says why: On hold, a buyer claim on this order is open. Once the claim is settled, it moves on by itself; if the claim lowers the goods value, the commission is worked out again at the same fixed rate.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-TUT-0903' }).first()); await d.wait(1800); } },

    { card: 'chapter', title: 'Upload your invoice', subtitle: 'NexaCrest pays against it.', say: 'Part three. Your invoice.' },
    { caption: 'Invoice number and file', say: 'For a line that is waiting, upload your invoice: type your invoice number, choose the file, and press Upload.', do: async (d) => {
      const row = d.page.locator('tr', { hasText: 'TEST-TUT-0902' }).first();
      await d.type(row.locator('input[name="invoice_no"]'), 'DEMO-INV-001');
      await d.upload(row.locator('input[name="invoice_file"]'), invoice);
      await d.clickNav(row.getByRole('button', { name: 'Upload' }));
    } },
    { caption: 'On file', say: 'Done. The invoice number now shows on that line, and NexaCrest sees it on their side too.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: 'TEST-TUT-0902' }).first()); await d.wait(1500); } },

    { card: 'chapter', title: 'Behind the scenes', subtitle: 'Your commission rule, hold, release, take back.', say: 'Part four. What NexaCrest does with it.' },
    { as: 'md', goto: `/associates/${a.id}#commission-rule`, caption: 'Staff: your commission rule', say: 'On the staff side, your record shows your commission rule. Only the Managing Director sets it: the standard ladder, which every associate starts on, an own ladder just for you, or a fixed percentage. Every change needs a reason, which is kept, and it applies to new orders only: an order keeps the percentage fixed at its Proforma Invoice. In your portal you simply see your new percentage.', do: async (d) => { await d.scrollTo('#commission-rule'); await d.ring(d.page.locator('#commission-rule')); await d.wait(2500); } },
    { goto: `/associates/${a.id}#commission`, caption: 'Staff: Orders and commission', say: 'Below, your record lists every order and payout. The Managing Director can change a commission before it is paid, only with a reason that is recorded and told to you. The team can put a payout on hold, with a reason that you see, and release it later. Only in the rare case of fraud, or a breach of the agreement, can an amount already paid be taken back, never because of a later buyer claim. Your list then says: We will take this off your next payment.', do: async (d) => { await d.scrollTo('#commission'); await d.wait(2500); } },
    { card: 'list', kicker: 'Recap', title: 'Commission in one breath', items: [
      'Your % is fixed for an order at its Proforma Invoice; worked out on the FOB value of the goods.',
      'Earned when the buyer pays in full; never changes; paid on the last day of the month, 30 days or more later.',
      'Upload your invoice per line; payment only to your checked bank account.',
      'On hold shows the reason; Paid on shows the date and bank reference.',
    ], next: 'Part 2 · Statements, CSV and printing', say: 'In one breath: your percentage is fixed for an order at its Proforma Invoice, and worked out on the F O B value of the goods. Your commission is earned when the buyer has paid in full, and then it never changes. We pay it thirty days later, on our payment day at the end of the month, against your invoice, to your checked bank account, with NexaCrest paying the bank charges. Next, in part two: statements, CSV and printing.' },
  ],
};
