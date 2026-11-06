'use strict';
// C10 — Statements: the CSV download and the printable statement (Quoting and Full levels).
const { nav, associateSetup, PLAN_A } = require('../_common');

const EMAIL = 'associate.statement@email.com';
const ym = (m) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - m); return d.toISOString().slice(0, 7); };
const day = (n) => new Date(Date.now() - n * 864e5).toISOString().slice(0, 10);

module.exports = {
  code: 'C10',
  file: 'C10-Associate-Statements-CSV-Print.mp4',
  group: 'Group C · Associates',
  title: 'Statements: CSV and print',
  subtitle: 'Download your commission for your accountant, or print a clean statement.',
  minutes: '8',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: EMAIL, name: 'Demo Associate', level: 'quoting', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Demo Stone Buyer BV', country: 'Netherlands', email: 'purchasing@statement-demo.example', orders: [
      { reference: 'TEST-TUT-1001', payouts: [{ inr: 28305, fc: 340, month: ym(3), status: 'paid', paidOn: day(70), paidInr: 28305, ref: 'UTR-DEMO-000101' }] },
      { reference: 'TEST-TUT-1002', payouts: [{ inr: 15400, fc: 185, month: ym(1), status: 'paid', paidOn: day(20), paidInr: 15092, tds: 308, rate: 2, section: '194H', ref: 'UTR-DEMO-000102' }] },
      { reference: 'TEST-TUT-1003', payouts: [{ inr: 20520, fc: 246, month: ym(0), status: 'due', due: day(-20) }] },
    ] });
    return s;
  },

  steps: () => [
    { card: 'title', say: 'At some point your accountant will ask: can you send me your commission for the year? With two clicks, you can. Let us see the CSV file and the printable statement.' },
    { as: 'associate', goto: '/associate/commission', caption: 'Commission → statement buttons', say: 'On the Commission page, at the Quoting and Full levels, you see two buttons: Statement, CSV, and Printable statement.', do: async (d) => { await d.ring(d.page.locator('p', { has: d.page.getByRole('link', { name: 'Statement (CSV)' }) })); await d.wait(1800); } },
    { card: 'chapter', title: 'The CSV file', subtitle: 'For Excel or your accountant.', say: 'Part one. The CSV file.' },
    { caption: 'Statement (CSV)', say: 'Click Statement, CSV. Your browser saves a file that opens in Excel, Google Sheets or any accounting tool.', do: async (d) => {
      const [dl] = await Promise.all([d.page.waitForEvent('download').catch(() => null), d.click(d.page.getByRole('link', { name: 'Statement (CSV)' }))]);
      if (dl) { const p = await dl.path(); d.csv = require('fs').readFileSync(p, 'utf8').split(/\r?\n/).filter(Boolean).slice(0, 5); }
    } },
    { card: 'list', kicker: 'Inside the file', title: 'One row per commission', items: [
      'Month, order, client, amount (order currency and ₹), status.',
      'For paid lines: the date paid, the amount paid, the TDS and the bank reference.',
      'Same rows as the screen: the period NexaCrest set, plus anything still waiting.',
    ], say: 'Inside, one row per commission, just like the screen: the month, the order, the client, the amounts, the status, and for paid lines the date paid, the amount paid, the TDS, and the bank reference. Exactly what an accountant needs.' },
    { card: 'chapter', title: 'The printable statement', subtitle: 'Clean, on paper or as a PDF.', say: 'Part two. The printable statement.' },
    { goto: '/associate/commission?print=1', caption: 'Printable statement', say: 'Printable statement opens a clean version of the page, without menus or buttons, and the print window opens by itself. Choose your printer, or Save as PDF.', do: async (d) => { await d.point('h1', 1500); await d.ring(d.page.locator('table.list').first()); await d.wait(2000); } },
    { caption: 'Gross, TDS and net', say: 'Look at a paid line with tax deducted: gross, TDS with the section and rate, and the net amount that reached your bank. Part three of this video explains those numbers.', do: async (d) => { await d.ring(d.page.locator('[data-tds-breakdown]').first()); await d.wait(2000); } },
    { card: 'list', kicker: 'Good to know', title: 'Statements, in short', items: [
      'Starter level: no statement buttons; ask NexaCrest if you need one.',
      'The statement shows your commission only, never other associates.',
      'Order statements (per order) are on each order page.',
    ], next: 'Part 3 · TDS and GST explained', say: 'A few notes. At the Starter level there are no statement buttons, but NexaCrest can send you one. A statement only ever shows your own commission. And the detailed statement of one order is on each order page. Next, in part three: TDS and GST, explained simply.' },
  ],
};
