'use strict';
// C11 — Tax on commission, simply: India (194H 2% above ₹20,000 a year with catch-up; 20% under
// 206AA without a verified PAN), GST on top when registered, and associates abroad (section 195,
// DTAA: no TDS once TRC, Form 10F and the no-PE declaration are accepted; Form 15CA/15CB per payout).
// Figures follow the current Admin settings (Settings → Associate TDS); re-check before recording.
const { ACTORS, nav, associateSetup, PLAN_A } = require('./_common');

const INDIA = 'associate.india@email.com';
const ABROAD = 'associate.abroad@email.com';
const ym = (m) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - m); return d.toISOString().slice(0, 7); };
const day = (n) => new Date(Date.now() - n * 864e5).toISOString().slice(0, 10);

module.exports = {
  code: 'C11',
  file: 'C11-Associate-TDS-And-GST.mp4',
  group: 'Group C · Associates',
  title: 'TDS and GST, explained simply',
  subtitle: 'In India with or without PAN, GST-registered or not, and associates abroad.',
  minutes: '12',
  restoreDb: true,

  async setup(demo) {
    const s = await associateSetup(demo, { email: INDIA, name: 'Demo Associate India', level: 'full', address: 'Demo address, Jaipur', pan: 'ABCDE1234F' });
    demo.buyerWithOrders({ associateId: s.a.id, company: 'TEST Demo Stone Buyer BV', country: 'Netherlands', email: 'buyer.demo@email.com', orders: [
      { reference: 'TEST-TUT-1101', payouts: [{ inr: 8000, fc: 96, month: ym(4), status: 'paid', paidOn: day(100), paidInr: 8000, ref: 'UTR-DEMO-001101' }] },
      { reference: 'TEST-TUT-1102', payouts: [{ inr: 15400, fc: 185, month: ym(1), status: 'paid', paidOn: day(15), paidInr: 14932, tds: 468, rate: 2, section: '194H', ref: 'UTR-DEMO-001102', note: 'TDS includes catch-up on earlier commission this year' }] },
    ] });
    const ab = demo.associate({ email: ABROAD, name: 'Demo Associate Abroad', level: 'full', basedIn: 'abroad', country: 'United Arab Emirates', city: 'Dubai', currency: 'USD', address: 'Demo address, Dubai' });
    s.actors.abroad = { ...ACTORS.associate(ABROAD), label: 'Associate (abroad)', cookies: await demo.associateCookies(ab) };
    s.ab = ab;
    return s;
  },

  steps: ({ a = PLAN_A } = {}) => [
    { card: 'title', say: 'Tax. The word alone makes people nervous! Relax: NexaCrest works out the tax on your commission automatically, following the rules its chartered accountant sets. This video explains what happens, in plain words, so you can check your numbers with confidence.' },

    { card: 'chapter', title: 'In India: TDS', subtitle: 'Tax deducted at source, section 194H.', say: 'Part one. Associates in India, and TDS.' },
    { card: 'list', kicker: 'India · with PAN', title: 'The TDS rule', items: [
      'Commission up to ₹20,000 in a financial year (April–March): no TDS.',
      'The payment that crosses ₹20,000: 2% on itself plus a catch-up on earlier payments that year.',
      'Every later payment that year: 2% on the full amount.',
      'Rounded to the nearest rupee. Your Form 16A shows it each quarter.',
    ], say: 'Here is the rule for commission in India, under section 194H. As long as your commission in one financial year, April to March, stays within twenty thousand rupees, no TDS is deducted. The payment that takes you past twenty thousand carries two percent on itself, plus a catch-up on the commission paid earlier that year without TDS. Every later payment that year carries two percent on the full amount. Amounts are rounded to the nearest rupee, and each quarter your Form 16A shows what was deducted and paid to the government in your name.' },
    { card: 'list', kicker: 'Worked example', title: 'Crossing ₹20,000', items: [
      'Earlier this year: ₹8,000 paid, no TDS (still under ₹20,000).',
      'Now: ₹15,400. Year total ₹23,400: over the limit.',
      'TDS 2% on ₹15,400 + ₹8,000 catch-up = ₹468.',
      'Paid to your bank: ₹15,400 − ₹468 = ₹14,932.',
    ], say: 'An example. Earlier this year you were paid eight thousand rupees, with no TDS, because you were still under twenty thousand. Now fifteen thousand four hundred rupees is due. Your year total becomes twenty-three thousand four hundred, over the limit. So TDS is two percent of fifteen thousand four hundred, plus the eight thousand catch-up: four hundred and sixty-eight rupees. Your bank receives fourteen thousand nine hundred and thirty-two rupees.' },
    { as: 'associate', goto: '/associate/commission', caption: 'Gross · TDS · net paid', say: 'And here is exactly that payment in the portal. Gross fifteen thousand four hundred, TDS four hundred and sixty-eight under section 194H at two percent, and the net paid, with the bank reference.', do: async (d) => { await d.ring(d.page.locator('[data-tds-breakdown]').first()); await d.wait(2500); } },
    { caption: 'Your Form 16A appears here', say: 'Below, under TDS certificates, NexaCrest places your Form 16A every quarter. You use it in your own income tax return, to claim the tax already deducted.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /TDS certificates/ })); await d.wait(1500); } },
    { card: 'list', kicker: 'India · no PAN', title: 'Why your PAN matters', items: [
      'No PAN on file, or not yet verified: TDS is 20% (section 206AA).',
      'Same ₹15,400 payment: ₹3,080 deducted instead of ₹468.',
      'Add your PAN and upload the PAN card: video C03.',
    ], say: 'Now, why does your PAN matter so much? Without a PAN on file, or before it is verified, the law requires twenty percent, under section 206AA. The same fifteen thousand four hundred rupee payment would lose three thousand and eighty rupees, instead of four hundred and sixty-eight. So add your PAN and upload the card early, exactly as shown in video C03.' },
    { goto: '/associate/profile', caption: 'PAN and GSTIN in your profile', say: 'Both live in your profile, under Contact and tax details: your PAN, and your GSTIN if you have one.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'Contact and tax details' })); await d.ring(d.page.locator('form[action="/associate/profile"]')); await d.wait(1800); } },

    { card: 'chapter', title: 'GST on commission', subtitle: 'Only if you are GST-registered.', say: 'Part two. GST.' },
    { card: 'list', kicker: 'GST', title: 'Registered or not?', items: [
      'Not GST-registered: no GST. Nothing to do.',
      'GST-registered: GST (18%) is added on top of your commission…',
      '…once your tax invoice is on file for that line.',
      'Your GSTIN goes in your profile; the GST certificate in your documents.',
    ], say: 'GST is simpler. If you are not registered for GST, there is no GST, and nothing to do. If you are registered, NexaCrest adds GST, eighteen percent, on top of your commission, once your tax invoice for that line is on file. That is one more reason to upload your invoice on the Commission page. Put your GSTIN in your profile, and your GST certificate in your documents.' },

    { card: 'chapter', title: 'Associates abroad', subtitle: 'Section 195 and the tax treaty (DTAA).', say: 'Part three. Associates outside India.' },
    { card: 'list', kicker: 'Abroad', title: 'No TDS, with the right papers', items: [
      'Payments abroad fall under section 195 of the Income-tax Act.',
      'Under the tax treaty (DTAA), NexaCrest deducts no TDS…',
      '…once your Tax Residency Certificate, Form 10F and No-PE declaration are accepted.',
      'Each payment also needs Form 15CA (and 15CB) before it is sent.',
    ], say: 'If you live outside India, payments to you fall under section 195. Under the tax treaty between India and your country, the DTAA, NexaCrest deducts no TDS, once three papers are on file and accepted: your Tax Residency Certificate, Form 10F, and a declaration that you have no permanent establishment in India. And for every payment, NexaCrest files Form 15CA, and where needed 15CB, before the money leaves India.' },
    { as: 'abroad', goto: '/associate/profile#kyc', caption: 'Abroad: the papers on your checklist', say: 'Here is the profile of a demo associate abroad. The document checklist is different: passport or company registration, bank proof, the agreement, and the tax residency papers. Upload them once, and keep the residency certificate current every year.', do: async (d) => { await d.scrollTo('#kyc'); await d.ring(d.page.locator('#kyc table.list').first()); await d.wait(2200); await d.point(d.page.locator('#kyc p.muted.small').last(), 1500); } },
    { goto: '/associate/commission', caption: 'Forms 15CA / 15CB appear here', say: 'And on the Commission page, the remittance forms 15CA and 15CB for your payments appear in the same list as the certificates.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: /TDS certificates/ })); await d.wait(1500); } },
    { card: 'list', kicker: 'Recap', title: 'Tax, in short', items: [
      'India: no TDS up to ₹20,000 a year, then 2% (194H) with a one-time catch-up.',
      'No verified PAN: 20% (206AA). Add your PAN early!',
      'GST-registered: 18% GST on top, once your invoice is on file.',
      'Abroad: no TDS with TRC, Form 10F and No-PE; 15CA/15CB per payment.',
    ], next: 'C12 · Messages and notices', say: 'In short. In India, no TDS up to twenty thousand rupees a year, then two percent, with a one-time catch-up. No verified PAN means twenty percent, so add it early. GST-registered? GST is added on top once your invoice is on file. Abroad: no TDS, as long as your residency papers are accepted. These figures follow the settings of NexaCrest\'s chartered accountant; if a rule changes, the portal follows automatically. Next, C12: messages and notices. See you there!' },
  ],
};
