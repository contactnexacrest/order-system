'use strict';
// B06 Part 2 — Logistics (Group B, Staff). Owner request 18 Nov 2026. Continues B06 Part 1.
// The same kind of CIF order, packed with its BL Instruction approved. On camera the Logistics Executive logs
// the draft BL from the CHA and reviews it, records the BL issued with the shipping bill, sees the logistics
// partners' bills, marks the scanned BL sent, and after the balance is paid: original BLs received, the BL
// endorsement, and closing the order with the courier details; then Reports › Freight cost.
// Written 18 Nov 2026 from orders/show.php (step-10f-bl-issued, logistics-bills, step-ci-scanned-bl-sent,
// step-11b … step-11g).

const A = require('./_a02');

let demo;
let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const sec = (d, id) => d.page.locator(id);
const today = () => new Date().toISOString().slice(0, 10);

const MOVES = {
  production: (o) => `OrderRepository::setProductionStatus(${o}, 'Production complete');
    OrderRepository::setEstShipmentDate(${o}, date('d M Y', strtotime('+3 days')), date('Y-m-d', strtotime('+3 days'))); echo 'ok';`,
  // Commercial Invoice approved (Sales) — the scanned BL can now go to the buyer.
  ci: (o) => `$docApproved(${o}, 'CI', $uid('export.executive@email.com'), $uid('sales.manager@email.com')); echo 'ok';`,
  // The buyer paid the balance; Accounts recorded and cleared it (Stage 8 passed).
  balancePaid: (o) => `
    $p = Pay::find(${o});
    Pay::recordBalanceReceived(${o}, (float) $p['balance_amount'], date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'balance', 'UTR SAMPLE0000789');
    Pay::markBalanceCleared(${o}, date('Y-m-d'), $uid('finance.manager@email.com'));
    Gate::passAndUnlockNext(${o}, 8, $uid('finance.manager@email.com'));
    $docApproved(${o}, 'COOPREP', $uid('compliance@email.com'), $uid('md@email.com')); echo 'ok';`,
};
const move = (name) => A.php(demo, MOVES[name](order));

module.exports = {
  code: 'B06-2',
  file: 'B06-2-Staff-Logistics-Part-2.mp4',
  group: 'Group B · Staff',
  title: 'Logistics · Part 2',
  subtitle: 'The draft BL, the bill of lading, partner bills, and the original documents to the buyer.',
  minutes: '4',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
      SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
      WHERE u.email = 'logistics@email.com' AND d.code = 'LOG' AND dd.level = 'executive'`);
    A.move(demo, 'part1Done', order);
    A.move(demo, 'supplierPo', order);
    move('production');
    A.move(demo, 'freight', order);
    A.move(demo, 'packing', order);
    ctx.actors.logistics.cookies = await demo.staffCookies('logistics');
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'Welcome to part two of the Logistics video. The goods are packed, the container is loaded, and the bill of lading instruction is approved. Now we check the draft bill of lading, record the real one, look after the partners\' bills, and finally send the original documents to the buyer and close the order.' },

    // ---------- 1. Draft BL ----------
    { card: 'chapter', title: 'The draft bill of lading', subtitle: 'Step 10f · check it before it is issued.', say: 'Chapter one. Checking the draft.' },
    { as: 'logistics', goto: at('#step-10f-bl-issued'), caption: 'Log the draft BL', say: 'Before the shipping line issues the bill of lading, the C H A sends a draft. In Step 10 f, attach it and press Log Draft BL for Review. Every draft is kept, so you can see what was corrected.', do: async (d) => {
      await A.openTo(d, '#step-10f-bl-issued');
      const s = sec(d, '#step-10f-bl-issued');
      const f = s.locator('form').filter({ has: d.page.locator('input[name="draft_bl"]') }).first();
      await d.point(f.locator('label').filter({ hasText: 'Draft BL' }).first(), 900);
      await f.locator('input[name="draft_bl"]').setInputFiles(demo.sampleFile('Draft BL - SAMPLE.pdf', 'SAMPLE DRAFT BILL OF LADING - TUTORIAL DEMO'));
      await d.clickNav(f.getByRole('button', { name: /Log Draft BL/ }));
    } },
    { caption: 'Review the draft', say: 'Now compare the draft with our instruction sheet: shipper, consignee, notify party, ports, the goods, marks, weights and the container. If anything is wrong, ask for corrections; here everything matches, so: approved. Submit Review.', do: async (d) => {
      await A.openTo(d, '#step-10f-bl-issued');
      await d.click(sec(d, '#step-10f-bl-issued').locator('summary.small').filter({ hasText: /^\s*Review\s*$/ }).first());
      const f = sec(d, '#step-10f-bl-issued').locator('form[action*="/draft-bl/"][action$="/review"]').first();
      const opts = await f.locator('select[name="decision"] option').allInnerTexts();
      await d.select(f.locator('select[name="decision"]'), { label: opts.find((t) => /approv/i.test(t)) || opts[1] });
      await d.type(f.locator('textarea[name="notes"], input[name="notes"]').first(), 'Checked against the BL instruction: all details match.', { delay: 25 });
      await d.clickNav(f.getByRole('button', { name: 'Submit Review' }));
    } },

    // ---------- 2. BL issued ----------
    { card: 'chapter', title: 'The bill of lading', subtitle: 'Step 10f · BL issued, with the shipping bill.', say: 'Chapter two. The ship sails.' },
    { goto: at('#step-10f-bl-issued'), caption: 'Record the BL issued', say: 'The ship has sailed and the bill of lading is issued. Record its number and its date, and attach the scanned copy.', do: async (d) => {
      await A.openTo(d, '#step-10f-bl-issued');
      const f = sec(d, '#step-10f-bl-issued').locator('form[action$="/bl-issued"]');
      await d.type(f.locator('input[name="bl_number"]'), 'TESTBL2611007');
      await f.locator('input[name="bl_date"]').fill(today());
      await d.point(f.locator('label').filter({ hasText: 'Scanned BL Copy' }).first(), 800);
      await f.locator('input[name="bl_copy"]').first().setInputFiles(demo.sampleFile('Scanned BL - SAMPLE.pdf', 'SAMPLE BILL OF LADING COPY - TUTORIAL DEMO'));
    } },
    { caption: 'Stage 8 opens', say: 'Save it. The app first checks that the inspection passed and the certificates are ready; if something is missing, it says what. All is in order, so stage seven is done, and stage eight, the commercial invoice and the balance, opens.', do: async (d) => {
      const f = sec(d, '#step-10f-bl-issued').locator('form[action$="/bl-issued"]');
      await d.clickNav(f.locator('button[type=submit]').last());
      await d.point('.alert', 1500); await d.point('.process-step[data-stage="8"]', 1500);
    } },
    { goto: at('#step-10f-bl-issued'), caption: 'The shipping bill', say: 'Then the shipping bill from customs: its number, its date, and the customs exchange rate printed on it. Export benefits and the bank\'s proof of export depend on these, so copy them exactly. Save Shipping Bill.', do: async (d) => {
      await A.openTo(d, '#step-10f-bl-issued');
      const f = sec(d, '#step-10f-bl-issued').locator('form[action$="/shipping-bill"]');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="shipping_bill_no"]'), '7012345');
      await f.locator('input[name="shipping_bill_date"]').fill(today());
      await d.type(f.locator('input[name="customs_exchange_rate"]'), '83.95');
      await d.clickNav(f.locator('button[type=submit]'));
      await d.point('.alert', 1200);
    } },

    // ---------- 3. Partner bills ----------
    { card: 'chapter', title: 'Partners\' bills', subtitle: 'CHA, transport, freight forwarder.', say: 'Chapter three. Paying our partners.' },
    { goto: at('#logistics-bills'), caption: 'Logistics Partner Bills & Payments', say: 'Logistics Partner Bills and Payments collects the bills from the C H A, the transporter and the freight forwarder for this order. Add each bill with its number, date, amounts and G S T, and attach it. Accounts then pays it and records the U T R. Transport under reverse charge is handled for you.', do: async (d) => { await A.openTo(d, '#logistics-bills'); await d.point('#logistics-bills > summary', 1500); await d.point(sec(d, '#logistics-bills').locator('form, table, p').first(), 2000); } },

    // ---------- 4. Scanned BL to buyer ----------
    { card: 'chapter', title: 'The buyer gets the BL copy', subtitle: 'Stage 8 · scanned BL sent.', say: 'Chapter four. Telling the buyer.', do: async () => { move('ci'); } },
    { goto: at('#step-ci-scanned-bl-sent'), caption: 'Mark Scanned BL Copy Sent to Buyer', say: 'Sales has approved the commercial invoice. Send the buyer the scanned bill of lading with it, and press Mark Scanned BL Copy Sent to Buyer. This starts the clock for the balance payment: the buyer must pay before the original documents are released.', do: async (d) => { await A.openTo(d, '#step-ci-scanned-bl-sent'); await d.clickNav(sec(d, '#step-ci-scanned-bl-sent').getByRole('button', { name: /Mark Scanned BL Copy Sent/ })); await d.point('.alert', 1500); } },

    // ---------- 5. Originals and closing ----------
    { card: 'chapter', title: 'The original documents', subtitle: 'Stage 9 · endorse, courier, close.', say: 'Chapter five. Releasing the originals.', do: async () => { move('balancePaid'); } },
    { goto: at('#step-11b-bl-originals-received'), caption: 'Original BLs received from the CHA', say: 'The buyer paid the balance, and Accounts cleared it. Stage nine. First, the C H A hands over the original bills of lading, usually three. Record how many you received.', do: async (d) => {
      await A.openTo(d, '#step-11b-bl-originals-received');
      const s = sec(d, '#step-11b-bl-originals-received');
      await d.type(s.locator('input[name="bl_originals_count"]'), '3');
      await d.clickNav(s.getByRole('button', { name: /Record Original BLs Received/ }));
    } },
    { goto: at('#step-11d-bl-endorsement-save'), caption: 'BL endorsement', say: 'The originals are endorsed by NexaCrest on the back, so the buyer can collect the goods. Save the endorsement details: most fill in by themselves from the order. Save, then Generate and Print the endorsement, sign and stamp it, and mark the originals endorsed.', do: async (d) => {
      await A.openTo(d, '#step-11d-bl-endorsement-save');
      const s = sec(d, '#step-11d-bl-endorsement-save');
      const dt = s.locator('input[name="date_of_endorsement"]'); if (await dt.count() && !(await dt.inputValue())) await dt.fill(today());
      await d.point(s.locator('input[name="bl_number"]'), 900);
      await d.clickNav(s.getByRole('button', { name: /Save BL Endorsement Details/ }));
      await A.openTo(d, '#step-11f-bl-endorsement-generate');
      await d.clickNav(sec(d, '#step-11f-bl-endorsement-generate').getByRole('button', { name: /Generate \/ Print BL Endorsement/ }));
      await A.openTo(d, '#step-11c-bl-endorsed-mark');
      await d.clickNav(sec(d, '#step-11c-bl-endorsed-mark').getByRole('button', { name: /Mark Original BLs Endorsed/ }));
    } },
    { goto: at('#step-11g-close-order'), caption: 'Close the order', say: 'Finally, courier the complete document set to the buyer: the endorsed bills of lading, the invoice, the packing list and the certificate of origin. Record the courier company, the tracking number, the charges and what they were paid from. Then: Close Order, complete document set couriered to buyer.', do: async (d) => {
      await A.openTo(d, '#step-11g-close-order');
      const s = sec(d, '#step-11g-close-order');
      await d.type(s.locator('input[name="courier_company"]'), 'TEST Courier Services');
      await d.type(s.locator('input[name="courier_tracking_number"]'), 'TCS2611778899');
      const ch = s.locator('input[name="courier_charges_inr"]'); if (await ch.count()) await d.type(ch, '3850');
      const pf = s.locator('select[name="courier_paid_from"]'); if (await pf.count()) { const o = (await pf.locator('option').allInnerTexts()).find((t) => /company account/i.test(t)); if (o) await d.select(pf, { label: o }); }
      await d.point(s.getByRole('button', { name: /Close Order — Complete Document Set/ }), 1200);
      await d.clickNav(s.getByRole('button', { name: /Close Order — Complete Document Set/ }));
      await d.point('.alert', 1500);
    } },

    // ---------- 6. Freight cost report ----------
    { card: 'chapter', title: 'Across all orders', subtitle: 'Reports › Freight cost.', say: 'Chapter six. Your report.' },
    { goto: '/reports/freight-cost', caption: 'Reports › Freight cost', say: 'Reports, Freight cost, shows the freight per forwarder across all orders, so you can compare forwarders when you next book.', do: async (d) => { await d.point('h1', 1200); await d.point(d.page.locator('table, form').first(), 1800); } },

    { card: 'list', kicker: 'Recap', title: 'Logistics · Part 2', items: [
      'Log every draft BL from the CHA and review it against the BL instruction.',
      'Record the BL issued with the shipping bill and the customs exchange rate.',
      'Partners\' bills on the order; Accounts pays them with a UTR.',
      'After the balance: originals received, endorsed, couriered, order closed.',
    ], next: 'B07 · Export Compliance', say: 'Let us recap part two. Log every draft bill of lading from the C H A, and review it against our instruction sheet. Record the bill of lading when it is issued, with the shipping bill and the customs exchange rate. Add the partners\' bills to the order, and Accounts pays them. And once the balance is paid: receive the originals, endorse them, courier the complete set, and close the order. Next: Export Compliance.' },
  ]; },
};
