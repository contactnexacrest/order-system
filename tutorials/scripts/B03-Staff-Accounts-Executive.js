'use strict';
// B03 — Accounts Executive (Group B, Staff). Owner request 18 Nov 2026: a tutorial video for every role.
// One CIF order for a sample buyer, moved forward off camera between chapters with the app's own services
// (scripts/_a02.js); on camera the Accounts Executive records and clears every buyer payment (advance,
// freight, balance) with the rupees credited and the bank reference, adds and pays the supplier's bill,
// and uses the INR Settlement Register and the payment reports. Written 18 Nov 2026 from the app's views
// (orders/show.php, _remittance_inr.php, ca/index.php, reports/index.php).

const A = require('./_a02');

let demo;
let order = 0;
const at = (hash = '') => `/orders/${order}${hash}`;
const step = (d, id) => d.page.locator(id);

// Off-camera moves written for this video (the shared ones are in _a02.js).
const MOVES = {
  // Terms, quotation, buyer PO and PI approved: the advance is due (Step 5d open).
  piApproved: (o) => `
    $md = $uid('md@email.com'); $exec = $uid('export.executive@email.com'); $sm = $uid('sales.manager@email.com');
    App\\Services\\PaymentTermsApprovalService::approve(${o}, $md);
    $docApproved(${o}, 'QT', $exec, $md);
    $docApproved(${o}, 'BUYERPO', $exec, $sm);
    OrderRepository::setBuyersPoRef(${o}, 'HSI-PO-2611-07');
    $pdo->prepare('UPDATE orders SET buyer_po_received_on = CURDATE() WHERE id = ?')->execute([${o}]);
    Gate::passAndUnlockNext(${o}, 2, $exec);
    OrderRepository::markPiFormSkipped(${o}, $exec, 'Buyer details already confirmed on the quotation');
    App\\Services\\AssociateCommission::fixLevelAtPi(${o});
    $docApproved(${o}, 'PI', $exec, $sm);`,
  // The buyer acknowledged the OC (Stage 4), the supplier PO is signed (Stage 5), and Logistics saved the
  // freight terms and had the freight Debit Note approved: the buyer's freight payment is due (Step 9c).
  freightDue: (o) => `
    $exec = $uid('export.executive@email.com'); $sm = $uid('sales.manager@email.com'); $log = $uid('logistics@email.com');
    $doc = $docApproved(${o}, 'OC', $exec, $sm);
    App\\Repositories\\OrderOcAcknowledgmentRepository::recordSent(${o}, $doc, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
    App\\Repositories\\OrderOcAcknowledgmentRepository::markAcknowledged(${o}, 'client_portal', null, null);
    Gate::passAndUnlockNext(${o}, 4, null);
    echo 'ok';`,
  freightTerms: (o) => `
    $log = $uid('logistics@email.com');
    // Step 8: production under way, with the estimated shipment date (Sales / Procurement).
    OrderRepository::setProductionStatus(${o}, 'In production at the supplier factory');
    OrderRepository::setEstShipmentDate(${o}, date('d M Y', strtotime('+20 days')), date('Y-m-d', strtotime('+20 days')));
    App\\Repositories\\OrderFreightRepository::upsert(${o}, ['confirmed_freight_rate' => '1450.00', 'insurance_amount' => '185.00',
      'freight_forwarder_name' => 'TEST Forwarder Logistics', 'freight_forwarder_contact' => 'ops@forwarder.example']);
    $docApproved(${o}, 'FDN', $log, $uid('sales.manager@email.com'));`,
  // Inspection passed (Export Compliance): the supplier's balance is next (Step 10a).
  inspected: (o) => `
    $comp = $uid('compliance@email.com'); $order = OrderRepository::find(${o});
    $file = $call('attachSamplePlaceholderFile', null, ${o}, App\\Helpers\\OrderStorage::folder($order, 'inspection'), 'Pre-shipment inspection report - SAMPLE.pdf', 'Inspection agency', 'Inspection report', $comp);
    App\\Services\\ShipmentGateService::saveInspection($order, 'passed', date('Y-m-d'), 'TEST Inspection Agency', $file, null, $comp);`,
  // Packed, BL instruction approved, CI approved, scanned BL sent: the balance is due (Stage 8).
  balanceDue: (o) => `
    $log = $uid('logistics@email.com'); $comp = $uid('compliance@email.com');
    foreach (App\\Repositories\\OrderComplianceTaskRepository::forOrder(${o}) as $t) {
      if (in_array($t['task_key'], ['fumigation_certificate', 'phytosanitary_certificate'], true)) App\\Repositories\\OrderComplianceTaskRepository::setStatus(${o}, (int) $t['task_type_id'], 'approved', null, $comp);
    }
    $sum = OrderProductRepository::orderedQuantitySummary(${o});
    App\\Repositories\\OrderPackingRepository::upsert(${o}, ['actual_quantity_packed' => (string) $sum['total'], 'crate_count' => '2',
      'total_net_weight_kg' => '18400.00', 'total_gross_weight_kg' => '19200.00', 'total_cbm' => '22.500', 'packing_date' => date('Y-m-d'), 'shortfall_pct' => '0.00']);
    $crates = [];
    foreach (OrderProductRepository::forOrder(${o}) as $i => $p) $crates[] = ['crate_no' => 'C-00' . ($i + 1), 'marks_numbers' => 'NEXACREST/HSI/' . ($i + 1),
      'product_description' => $p['description'], 'dimensions_lwh_cm' => '310 x 120 x 90', 'pcs' => $p['quantity'], 'net_weight_kg' => '9200.00',
      'gross_weight_kg' => '9600.00', 'cbm' => '11.250', 'hs_code' => $p['hs_code'] ?? '68022390'];
    App\\Repositories\\OrderCrateRepository::replaceForOrder(${o}, $crates);
    $docApproved(${o}, 'PL', $log, $uid('sales.manager@email.com'));
    Ship::upsert(${o}, ['shipping_line' => 'TEST Shipping Line', 'vessel_name' => 'MV Sample Voyager', 'voyage_number' => 'SV-2611-07',
      'etd' => date('Y-m-d', strtotime('+2 days')), 'eta' => date('Y-m-d', strtotime('+26 days')), 'container_type' => '1x20GP',
      'container_no' => 'SAMU7654321', 'seal_no' => 'SEAL000777']);
    $docApproved(${o}, 'BLI', $log, $uid('md@email.com'));
    Ship::recordBl(${o}, 'TESTBL2611007', date('Y-m-d'));
    Gate::passAndUnlockNext(${o}, 7, $log);
    $docApproved(${o}, 'CI', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    Ship::recordScannedBlSent(${o});
    App\\Services\\BalanceDueService::startFromScannedBl(${o}, date('Y-m-d'));`,
};
const move = (name) => A.php(demo, MOVES[name](order));


module.exports = {
  code: 'B03',
  file: 'B03-Staff-Accounts-Executive.mp4',
  group: 'Group B · Staff',
  title: 'Accounts Executive',
  subtitle: 'Every buyer payment recorded and cleared, supplier bills paid, and the INR register.',
  minutes: '7',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    // The Accounts Executive works as Accounts & Finance (their department membership), as on the live site.
    demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
      SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
      WHERE u.email = 'accounts@email.com' AND d.code = 'FIN' AND dd.level = 'executive'`);
    move('piApproved');
    ctx.actors.accounts.cookies = await demo.staffCookies('accounts');
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'Welcome to the Accounts Executive\'s video. You look after the money on every order: what the buyer pays us, and what we pay our suppliers. In about seven minutes you will record and clear each buyer payment, pay a supplier\'s bill, and find everything again in the registers and reports. We follow one sample order from the first advance to the final balance.' },
    { card: 'list', kicker: 'Your role', title: 'Accounts Executive · Accounts & Finance', items: [
      'Record every buyer payment from our bank\'s credit advice, with the rupees credited.',
      'Clear it once the money has really landed; clearing opens the next stage.',
      'Add the supplier\'s and logistics partners\' bills and record what we paid.',
      'Keep the INR Settlement Register and the bank proof (FIRC / eBRC) complete.',
    ], say: 'Your job in four lines. Record every buyer payment from our own bank\'s credit advice, with the rupees the bank credited. Clear it once the money has really landed: clearing is what opens the next stage of the order. Add the bills of our suppliers and logistics partners, and record what we paid them. And keep the INR settlement register and the bank\'s proof of realisation complete. Every one of these records carries a description the app builds for you, and a bank reference.' },

    // ---------- 1. Your start page ----------
    { card: 'chapter', title: 'Your start page', subtitle: 'Dashboard, My tasks, Orders.', say: 'Chapter one. Where your work comes from.' },
    { as: 'accounts', goto: '/', caption: 'Dashboard', say: 'This is your dashboard. Like everyone, you sign in with your password and a six-digit code from your e-mail. Here: a search box, overdue payments, the orders by stage, and recent activity.', do: async (d) => { await d.point('h1', 900); await d.scrollTo(d.page.getByRole('heading', { name: /Overdue Payments/ })); await d.point(d.page.getByRole('heading', { name: /Overdue Payments/ }), 1500); } },
    { caption: 'Logged in as', say: 'Bottom left, the app shows who you are working as: Accounts Executive, in Accounts and Finance. You see every order, and every amount: selling prices, purchase prices and logistics costs. That is what your work needs.', do: async (d) => { await d.point('.dept-badge', 2200); } },
    { goto: '/my-tasks', caption: 'My tasks', say: 'My tasks lists what is waiting for Accounts. When the Export Executive\'s proforma invoice is approved, recording the advance appears here; when a payment is recorded, clearing it appears. Start your day here.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('.kanban-board, table, .empty-state, p').first(), 1500); } },
    { goto: at(), caption: 'The order', say: 'Here is our sample order: a CIF order for a buyer in the Netherlands, forty percent advance before production. The proforma invoice is approved, so the advance is due. Look at the tracker: we are in stage three.', do: async (d) => { await d.point('h1', 1000); await d.point('.process-step[data-stage="3"]', 1500); } },

    // ---------- 2. The advance ----------
    { card: 'chapter', title: 'The advance', subtitle: 'Step 5d record · Step 5e clear.', say: 'Chapter two. The advance arrives.' },
    { goto: at('#step-5d-payment-record'), caption: 'Step 5d · Record Advance Remittance', say: 'The bank sent its credit advice: the advance has arrived. Open Step 5 d. The app shows how much is due, and fills in the amount. If the buyer paid a different amount, you choose the reason below: bank charges, paid less, or paid more.', do: async (d) => { await A.openTo(d, '#step-5d-payment-record'); await d.point('#step-5d-payment-record .advance-due-note', 1800); await d.point('#step-5d-payment-record input[name="advance_amount"]', 1300); } },
    { caption: 'The rupees credited', say: 'Now the most important number: the rupees our bank actually credited, from the advice. Type it in. The app works out the exchange rate the bank gave us, right below, so nobody has to calculate it by hand.', do: async (d) => {
      const amt = parseFloat(await d.page.inputValue('#step-5d-payment-record input[name="advance_amount"]'));
      await d.type('#step-5d-payment-record input[name="advance_inr_credited"]', (Math.round(amt * 84.10 * 100) / 100).toFixed(2));
      await d.point('#step-5d-payment-record [data-remit-rate-derived]', 2200);
    } },
    { caption: 'The bank reference is required', say: 'And the bank reference: the SWIFT or U T R number on the advice. It is required, and a reference used before is refused, so a payment can never be recorded twice. The line below reads back what you entered. Record Advance Remittance.', do: async (d) => {
      await d.type('#step-5d-payment-record input[name="advance_bank_reference"]', 'SWIFT SAMPLE2611140');
      await d.point('#step-5d-payment-record [data-remit-statement]', 1800);
      await d.clickNav(step(d, '#step-5d-payment-record').getByRole('button', { name: /Record Advance Remittance/ }));
    } },
    { caption: 'Recorded, with its description', say: 'Recorded. The app wrote the description for you: advance, for this order and this buyer, with the bank reference. That same description goes to Zoho Books later. You never type it, so it is never blank or wrong.', do: async (d) => { await d.point('.alert', 1500); await A.openTo(d, '#step-5d-payment-record'); await d.point(step(d, '#step-5d-payment-record').locator('table, .receipt-list, ul').first(), 2000); } },
    { goto: at('#step-5e-payment-confirm'), caption: 'Step 5e · Mark Advance Cleared', say: 'Recording is not clearing. Clearing says: I have checked our bank account, and the money is really there. Only then press Mark Advance Cleared. The app asks: are you sure? Yes.', do: async (d) => { await A.openTo(d, '#step-5e-payment-confirm'); await d.point('#step-5e-payment-confirm', 1200); await d.clickNav(d.page.locator('#step-5e-payment-confirm button[type=submit]').first()); } },
    { caption: 'Stage 4 opens', say: 'Clearing the advance did two things. Stage four opened, so the Export Executive can send the order confirmation. And the buyer\'s portal login was created, so the buyer can now follow the order. If the buyer had paid less than due, clearing would need a manager\'s approval and a reason.', do: async (d) => { await d.point('.alert', 1500); await d.point('.process-step[data-stage="4"]', 1500); } },
    { caption: 'Payment Overview', say: 'Scroll up to Payment Overview: the snapshot of every payment on this order, what is due, what is received and what is cleared, and the ledger, line by line.', do: async (d) => { const sum = d.page.locator('details > summary').filter({ hasText: 'Payment Overview' }).first(); await d.scrollTo(sum); await d.click(sum); await d.wait(600); const box = sum.locator('xpath=..'); await d.point(box.locator('table, .kv-grid, .plan-bar').first(), 2200); } },

    // ---------- 3. Freight ----------
    { card: 'chapter', title: 'The freight payment', subtitle: 'CIF orders · Step 9c record · Step 9d clear.', say: 'Chapter three. On a CIF order, the buyer also pays the freight.', do: async () => { move('freightDue'); A.move(demo, 'supplierPo', order); move('freightTerms'); } },
    { goto: at('#step-9c-freight-remittance'), caption: 'Step 9c · Record Freight Remittance', say: 'Meanwhile the buyer confirmed the order, Procurement placed the supplier\'s order, and Logistics sent the buyer the freight bill, the debit note. The buyer paid it. Step 9 c works exactly like the advance: the amount, the rupees credited, and the bank reference.', do: async (d) => {
      await A.openTo(d, '#step-9c-freight-remittance');
      await d.type('#step-9c-freight-remittance input[name="freight_amount"]', '1635.00');
      await d.type('#step-9c-freight-remittance input[name="freight_inr_credited"]', (1635 * 84.25).toFixed(2));
      await d.point('#step-9c-freight-remittance [data-remit-rate-derived]', 1200);
      await d.type('#step-9c-freight-remittance input[name="freight_bank_reference"]', 'SWIFT SAMPLE2611455');
      await d.clickNav(step(d, '#step-9c-freight-remittance').getByRole('button', { name: /Record Freight Remittance/ }));
    } },
    { goto: at('#step-9d-freight-clear'), caption: 'Step 9d · Mark Freight Cleared', say: 'And once it is in our account: Step 9 d, Mark Freight Cleared. That unlocks stage seven, packing and the bill of lading.', do: async (d) => { await A.openTo(d, '#step-9d-freight-clear'); await d.clickNav(step(d, '#step-9d-freight-clear').getByRole('button', { name: /Mark Freight Cleared/ })); await d.point('.process-step[data-stage="7"]', 1500); } },

    // ---------- 4. Paying the supplier ----------
    { card: 'chapter', title: 'Paying the supplier', subtitle: 'Step 10a · the bill, then the payment.', say: 'Chapter four. Paying our supplier.', do: async () => { move('inspected'); } },
    { goto: at('#step-10a-supplier-balance-paid'), caption: 'Step 10a · Supplier Balance Paid', say: 'The goods passed inspection, so the supplier wants their money before packing. Step 10 a. At the top: the supplier P O, its total, and what is planned for this payment. This supplier asked for no advance, so the whole amount is paid now.', do: async (d) => { await A.openTo(d, '#step-10a-supplier-balance-paid'); await d.point('#step-10a-supplier-balance-paid .plan-bar', 2200); } },
    { caption: 'Add the supplier\'s tax invoice', say: 'First the supplier\'s bill. Its number and date, from the paper. The taxable value and the G S T rate are already filled in from our purchase order: change them only if the bill says something different. Attach the bill, and Add Bill.', do: async (d) => {
      const f = step(d, '#step-10a-supplier-balance-paid').locator('form').filter({ has: d.page.locator('input[name="bill_no"]') }).first();
      await d.type(f.locator('input[name="bill_no"]'), 'TDQ/INV/2611/118');
      await d.point(f.locator('input[name="bill_date"]'), 600);
      await f.locator('input[name="bill_date"]').fill(new Date().toISOString().slice(0, 10));
      await d.point(f.locator('input[name="taxable_inr"]'), 1000);
      await d.point(f.locator('select[name="gst_rate_pct"], input[name="gst_rate_pct"]'), 900);
      // The file box is styled by the app (the real input is hidden): point at its label, attach directly.
      await d.point(f.locator('label').filter({ hasText: 'Attach bill' }), 900);
      await f.locator('input[name="bill_file"]').setInputFiles(demo.sampleFile('Supplier tax invoice - SAMPLE.pdf', 'SAMPLE SUPPLIER TAX INVOICE - TUTORIAL DEMO'));
      await d.clickNav(f.getByRole('button', { name: 'Add Bill' }));
    } },
    { caption: 'Record the payment', say: 'The bill is listed: taxable value, G S T and total, and Unpaid. Once our bank has sent the money, press Mark Paid, and record what we paid: the amount, the date, how we paid, and the U T R from our bank. Save Payment. The bill now shows as paid, and the supplier balance is done.', do: async (d) => {
      await A.openTo(d, '#step-10a-supplier-balance-paid');
      const s = step(d, '#step-10a-supplier-balance-paid');
      await d.point(s.locator('tr').filter({ hasText: 'TDQ/INV/2611/118' }).first(), 1500);
      await d.click(s.locator('summary').filter({ hasText: 'Mark Paid' }).first());
      await d.point(s.locator('input[name="paid_amount_inr"]'), 1200);
      await d.select(s.locator('select[name="payment_mode"]'), { index: 1 });
      await d.type(s.locator('input[name="payment_ref"]'), 'UTR SBIN0000261118');
      await d.clickNav(s.getByRole('button', { name: 'Save Payment' }).first());
    } },
    { caption: 'Logistics partners\' bills', say: 'Bills from the customs agent and the transport company are added and paid the same way, in their own steps further down, with the same bank reference rule.', do: async (d) => { await A.openTo(d, '#step-10a-supplier-balance-paid'); await d.point(step(d, '#step-10a-supplier-balance-paid').locator('.vendor-bill-paid, table').first(), 2000); } },

    // ---------- 5. The balance ----------
    { card: 'chapter', title: 'The balance', subtitle: 'Stage 8 · record and clear.', say: 'Chapter five. The final payment.', do: async () => { move('balanceDue'); } },
    { goto: at('#step-balance-record'), caption: 'Record the balance', say: 'The goods are packed and shipped, the commercial invoice is approved, and the buyer has the scanned bill of lading. They paid the balance. Same three things: the amount, the rupees credited, and the bank reference.', do: async (d) => {
      await A.openTo(d, '#step-balance-record');
      const s = step(d, '#step-balance-record');
      const amt = parseFloat(await s.locator('input[name="balance_amount"]').inputValue()) || 0;
      if (!amt) await d.type(s.locator('input[name="balance_amount"]'), '6720.00');
      const val = parseFloat(await s.locator('input[name="balance_amount"]').inputValue());
      await d.type(s.locator('input[name="balance_inr_credited"]'), (Math.round(val * 84.40 * 100) / 100).toFixed(2));
      await d.type(s.locator('input[name="balance_bank_reference"]'), 'SWIFT SAMPLE2612030');
      await d.point(s.locator('[data-remit-statement]'), 1500);
      await d.clickNav(s.getByRole('button', { name: /Record Balance Remittance/ }).first());
    } },
    { goto: at('#step-balance-clear'), caption: 'Mark Balance Cleared', say: 'And clear it, once it is in our account. Stage nine opens: Logistics can now release the original documents to the buyer. The order is fully paid.', do: async (d) => { await A.openTo(d, '#step-balance-clear'); await d.clickNav(step(d, '#step-balance-clear').locator('button[type=submit]').filter({ hasText: /Mark Balance Cleared/ }).first()); await d.point('.alert', 1200); await d.point('.process-step[data-stage="9"]', 1500); } },

    // ---------- 6. Registers and reports ----------
    { card: 'chapter', title: 'Registers and reports', subtitle: 'Finance & accounts · Reports.', say: 'Chapter six. Finding it all again.' },
    { goto: '/ca', caption: 'Finance & accounts › CA / Accounting', say: 'Under Finance and accounts, CA and Accounting. At the top, Needs attention shows what is waiting, each with a button to the page where it is done. Further down, the INR settlement register lists every cleared payment of every order: the foreign amount, the rupees credited, and the exchange gain or loss against the rate the order assumed. Our three payments are there. The quick filters above the list show only the payments still waiting for the bank\'s proof, or not yet in Zoho.', do: async (d) => { await d.point('.ca-attention', 2500); const h = d.page.getByRole('heading', { name: /INR Settlement Register/ }); await d.scrollTo(h); await d.point(h, 1200); await d.point(d.page.locator('tr').filter({ hasText: 'Harbourline' }).first(), 2000); await d.point('.ca-filters', 1800); } },
    { caption: 'FIRC and eBRC', say: 'The last column but one: the bank\'s proof of realisation, the FIRC or e B R C. When the bank issues it, record its number on the order\'s payment. Until then, the payment shows as not yet recorded, and it appears on the Missing e B R C report.', do: async (d) => { await d.point(d.page.locator('th').filter({ hasText: /FIRC/ }).first(), 2000); } },
    { goto: '/reports', caption: 'Reports › Payments & receivables', say: 'And in Reports, Payments and receivables: collected and outstanding per currency, today\'s payments in and out, the debtors\' ageing, and the missing e B R C list. Open them every week.', do: async (d) => { const h = d.page.getByRole('heading', { name: /Payments & receivables/ }); await d.scrollTo(h); await d.point(h, 2500); } },

    { card: 'list', kicker: 'Recap', title: 'Accounts Executive', items: [
      'Record from our bank\'s advice: amount, rupees credited, bank reference.',
      'Clear only when the money is in our account; clearing opens the next stage.',
      'Supplier and logistics bills: add the bill, then record the payment with its UTR.',
      'The INR Settlement Register, FIRC / eBRC and the payment reports keep it complete.',
    ], next: 'B04 · Finance Manager and Finance Director', say: 'Let us recap. Record every buyer payment from our own bank\'s advice: the amount, the rupees credited, and the bank reference. Clear it only when the money is in our account, because clearing opens the next stage. For suppliers and logistics partners, add the bill first, then record the payment with its U T R. And the INR settlement register, the bank\'s proof of realisation and the payment reports keep everything complete. Next: the Finance Manager and the Finance Director.' },
  ]; },
};
