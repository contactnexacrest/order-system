'use strict';
// B04 — Finance Manager and Finance Director (Group B, Staff). Owner request 18 Nov 2026: a video for every role.
// One CIF order (scripts/_a02.js). On camera: the Finance Director approves the buyer's payment terms (from
// Approvals), later clears a balance that arrived short (with what the shortfall is and why); the Finance
// Manager closes the order's financials (Step 12a costs with a bank reference, 12b export-benefit eligibility,
// 12c profit review) and generates the internal CAFIN; then the profitability report and the cockpit.
// Other departments' steps are done off camera between chapters with the app's own services.
// Written 18 Nov 2026 from orders/show.php (payment-terms box, step-balance-clear, financials-12a/b/c, order-cafin).

const A = require('./_a02');

let demo;
let order = 0;
let ref = '';
const at = (hash = '') => `/orders/${order}${hash}`;
const sec = (d, id) => d.page.locator(id);
const ROLES = {
  fm: { label: 'Finance Manager', sub: 'Accounts & Finance · finance.manager@email.com', initials: 'FM', color: '#5B21B6' },
  fd: { label: 'Finance Director', sub: 'Accounts & Finance · finance.director@email.com', initials: 'FD', color: '#3B0764' },
};

const MOVES = {
  // Everything up to the buyer's confirmation, except the payment-terms approval (done on camera).
  confirmed: (o) => `
    $md = $uid('md@email.com'); $exec = $uid('export.executive@email.com'); $sm = $uid('sales.manager@email.com'); $fm = $uid('finance.manager@email.com');
    $docApproved(${o}, 'QT', $exec, $md);
    $docApproved(${o}, 'BUYERPO', $exec, $sm);
    OrderRepository::setBuyersPoRef(${o}, 'HSI-PO-2611-07');
    $pdo->prepare('UPDATE orders SET buyer_po_received_on = CURDATE() WHERE id = ?')->execute([${o}]);
    Gate::passAndUnlockNext(${o}, 2, $exec);
    OrderRepository::markPiFormSkipped(${o}, $exec, 'Buyer details already confirmed on the quotation');
    App\\Services\\AssociateCommission::fixLevelAtPi(${o});
    $docApproved(${o}, 'PI', $exec, $sm);
    $ord = OrderRepository::find(${o});
    if ((float) $ord['advance_pct'] > 0) {
      $fob = OrderProductRepository::totalFobValue(${o}); $adv = round($fob * ((float) $ord['advance_pct']) / 100, 2);
      Pay::recordAdvanceReceived(${o}, $adv, date('Y-m-d')); Pay::setLegBankReference(${o}, 'advance', 'UTR SAMPLE0000123');
      Pay::setAdvanceInrActual(${o}, round($adv * 84.10, 2), $uid('accounts@email.com')); Pay::setLegExchangeRate(${o}, 'advance', 84.10, $uid('accounts@email.com'));
      Pay::markAdvanceCleared(${o}, date('Y-m-d'), $fm);
    }
    $pay = Pay::find(${o});
    Pay::setBalanceAmount(${o}, Pay::balanceDue(OrderProductRepository::totalFobValue(${o}), (float) $ord['advance_pct'], $pay,
      App\\Repositories\\PaymentMilestoneRepository::balanceDeduction(${o}, OrderProductRepository::totalFobValue(${o}), true)), null);
    Gate::passAndUnlockNext(${o}, 3, $fm);
    App\\Services\\ClientPortalService::provisionIfNeeded((int) $ord['client_id'], ${o});
    $doc = $docApproved(${o}, 'OC', $exec, $sm);
    App\\Repositories\\OrderOcAcknowledgmentRepository::recordSent(${o}, $doc, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
    App\\Repositories\\OrderOcAcknowledgmentRepository::markAcknowledged(${o}, 'client_portal', null, null);
    Gate::passAndUnlockNext(${o}, 4, null);
    echo 'ok';`,
  // Shipped: BL issued, Commercial Invoice approved, scanned BL sent; the buyer paid USD 120 less than the balance.
  shortBalance: (o) => `
    $log = $uid('logistics@email.com');
    OrderRepository::setProductionStatus(${o}, 'Production complete');
    Ship::recordBl(${o}, 'TESTBL2611007', date('Y-m-d'));
    Gate::passAndUnlockNext(${o}, 7, $log);
    $docApproved(${o}, 'CI', $uid('export.executive@email.com'), $uid('sales.manager@email.com'));
    Ship::recordScannedBlSent(${o});
    App\\Services\\BalanceDueService::startFromScannedBl(${o}, date('Y-m-d'));
    $p = Pay::find(${o});
    $paid = round((float) $p['balance_amount'] - 120, 2);
    Pay::recordBalanceReceived(${o}, $paid, date('Y-m-d'));
    Pay::setLegBankReference(${o}, 'balance', 'UTR SAMPLE0000789');
    // Accounts records the rupees the bank credited for each leg (the profit review counts these).
    $acc = $uid('accounts@email.com');
    Pay::setBalanceInrActual(${o}, round($paid * 84.40, 2), $acc); Pay::setLegExchangeRate(${o}, 'balance', 84.40, $acc);
    Pay::setFreightInrActual(${o}, round(1635 * 84.25, 2), $acc); Pay::setLegExchangeRate(${o}, 'freight', 84.25, $acc);
    $pdo->prepare('INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, inr_credited, bank_rate, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([${o}, 'balance', $paid, date('Y-m-d'), 84.40, 'UTR SAMPLE0000789', round($paid * 84.40, 2), 84.40, $uid('accounts@email.com')]);
    echo 'ok';`,
};
const move = (name) => A.php(demo, MOVES[name](order));
const strip = (php) => php.replace(/App\\\\Services\\\\PaymentTermsApprovalService::approve\([^;]*;/, '');

module.exports = {
  code: 'B04',
  file: 'B04-Staff-Finance-Manager-And-Director.mp4',
  group: 'Group B · Staff',
  title: 'Finance Manager and Finance Director',
  subtitle: 'Payment terms, short payments, closing an order\'s financials, CAFIN and profitability.',
  minutes: '6',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    demo.demoStaff('fd');
    const ctx = await A.setup(demo);
    order = ctx.orderId;
    ref = demo.sql(`SELECT order_reference FROM orders WHERE id = ${order}`);
    for (const [email, level] of [['finance.manager@email.com', 'manager'], ['finance.director@email.com', 'director']]) {
      demo.sql(`INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
        SELECT u.id, dd.id, CURDATE(), 1 FROM users u JOIN department_designations dd JOIN departments d ON d.id = dd.department_id
        WHERE u.email = '${email}' AND d.code = 'FIN' AND dd.level = '${level}'`);
    }
    ctx.actors.fm = { ...ROLES.fm, cookies: await demo.staffCookies('fm') };
    ctx.actors.fd = { ...ROLES.fd, cookies: await demo.staffCookies('fd') };
    return ctx;
  },

  steps: ({ orderId = 0 } = {}) => { order = orderId || order; return [
    { card: 'title', say: 'This video is for the Finance Manager and the Finance Director. Accounts records and clears the payments day by day; you look after the decisions and the results: which payment terms we accept, what happens when a buyer pays short, what each order really cost and earned, and the internal cost sheet. We follow one sample order, switching between the two of you.' },
    { card: 'list', kicker: 'Your roles', title: 'Accounts & Finance · manager and director', items: [
      'Both: everything Accounts does, plus every order\'s financials, CAFIN and profit.',
      'Finance Manager: closes each order\'s financials (Step 12) and checks associates\' bank details.',
      'Finance Director: also approves payment terms and clears a short payment, with a reason.',
      'Both see every amount; the CAFIN is internal and never reaches a buyer.',
    ], say: 'Your roles in four lines. Both of you can do everything Accounts does, and on top of that you own every order\'s financials, the internal cost sheet called CAFIN, and the profit figures. The Finance Manager closes each order\'s financials, in Step 12, and checks the bank details of associates. The Finance Director also approves buyers\' payment terms, and is the one who may clear a payment that arrived short. You both see every amount, but remember: the CAFIN is internal, and never reaches a buyer.' },

    // ---------- 1. Payment terms ----------
    { card: 'chapter', title: 'Approving payment terms', subtitle: 'Finance Director · Approvals › Payment terms.', say: 'Chapter one. A buyer asks for easier terms.' },
    { as: 'fd', goto: '/approvals?tab=payment_terms', caption: 'Approvals › Payment terms', say: 'We are the Finance Director. On the Approvals page, the Payment terms tab lists the requests waiting. Ours: this buyer is an established buyer, so Sales offered to take the whole payment after the bill of lading, with no advance. That is a credit risk for us, so it needs a director\'s approval before the proforma invoice.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('table.approvals-table tbody tr').filter({ hasText: ref }).first(), 2000); } },
    { caption: 'Approve the terms', say: 'Approve terms opens the order, with the yellow payment terms box at the top. You check the buyer\'s history and the order value, and press Approve payment terms. You can never approve terms on an order you created yourself: someone else must check your work.', do: async (d) => {
      await d.clickNav(d.page.locator('table.approvals-table tbody tr').filter({ hasText: ref }).first().getByRole('link', { name: /Approve terms/ }));
      const b = d.page.getByRole('button', { name: 'Approve payment terms' }).first();
      await d.scrollTo(b); await d.point(b, 1500); await d.clickNav(b); await d.point('.alert', 1500);
    } },

    // ---------- 2. Short payment ----------
    { card: 'chapter', title: 'A balance that arrived short', subtitle: 'Finance Director · Step 11d.', say: 'Chapter two. The buyer paid less than the invoice.', do: async () => {
      A.php(demo, strip(MOVES.confirmed(order)));
      A.move(demo, 'supplierPo', order); A.move(demo, 'freight', order); A.move(demo, 'packing', order);
      move('shortBalance');
    } },
    { goto: at('#step-balance-record'), caption: 'Recorded short', say: 'Weeks later, the goods are shipped and the buyer has paid the balance. But Accounts recorded a balance one hundred and twenty dollars less than the commercial invoice. The step shows what is still owed. An Accounts Executive cannot clear it: only a manager with the right to approve a short payment may.', do: async (d) => { await A.openTo(d, '#step-balance-record'); await d.point(sec(d, '#step-balance-record').locator('table, .remit-recorded, .notice').first(), 2200); } },
    { goto: at('#step-balance-clear'), caption: 'What is this shortfall?', say: 'You phone the buyer: their bank deducted charges on the way. Under Mark Balance Cleared, the app asks two things. First, what the shortfall is: an agreed discount, a settled claim, or simply not paid. That choice decides the associate\'s commission. Then the reason, in words. Both are kept on the order for ever.', do: async (d) => {
      await A.openTo(d, '#step-balance-clear');
      const s = sec(d, '#step-balance-clear');
      await d.select(s.locator('select[name="short_kind"]').first(), { value: 'unpaid' });
      await d.type(s.locator('textarea[name="short_reason"]').first(), 'Buyer\'s bank deducted USD 120 intermediary charges; agreed with the buyer not to claim it back.', { delay: 30 });
    } },
    { caption: 'Cleared short', say: 'Mark Balance Cleared. The order moves to stage nine, and the shortfall stays visible on the order and in the reports.', do: async (d) => {
      await d.clickNav(sec(d, '#step-balance-clear').locator('button[type=submit]').filter({ hasText: /Mark Balance Cleared/ }).first());
      await d.point('.alert', 1500); await d.point('.process-step[data-stage="9"]', 1200);
    } },

    // ---------- 3. Step 12 ----------
    { card: 'chapter', title: 'Closing the financials', subtitle: 'Finance Manager · Step 12a, 12b, 12c.', say: 'Chapter three. What did this order really cost, and earn?' },
    { as: 'fm', goto: at('#financials-12a'), caption: 'Step 12a · Order Costs', say: 'Now we are the Finance Manager. Step 12 closes the order\'s money. Step 12 a lists every cost: supplier and logistics bills come in by themselves; here you add the rest, such as E C G C insurance, courier, documentation or bank charges.', do: async (d) => { await A.openTo(d, '#financials-12a'); await d.point('#financials-12a > summary', 1500); } },
    { caption: 'Record a cost', say: 'Add the courier that took the original documents to the buyer: the category, the amount in rupees, a description saying who was paid and their invoice number, and what it was paid from. Paid from the company account means a bank reference is required. Record Cost.', do: async (d) => {
      const f = sec(d, '#financials-12a').locator('form[action$="/cost-entries"]').first();
      await d.select(f.locator('select[name="category"]'), { value: 'courier' });
      await d.type(f.locator('input[name="amount_inr"]'), '3850');
      await d.type(f.locator('input[name="description"], textarea[name="description"]').first(), 'TEST Courier Services, invoice CR-2611-55: original documents to the buyer');
      await d.select(f.locator('select[name="paid_from"]'), { value: 'company_account' });
      await d.type(f.locator('input[name="bank_reference"]'), 'HDFC0000261120');
      await d.clickNav(f.getByRole('button', { name: 'Record Cost' }));
    } },
    { caption: 'Costs are complete', say: 'The cost is listed, with the description the app builds for Zoho Books. When nothing else is missing, press Costs are complete.', do: async (d) => {
      await A.openTo(d, '#financials-12a');
      await d.clickNav(sec(d, '#financials-12a').getByRole('button', { name: /Costs are complete/ }));
    } },
    { goto: at('#financials-12b'), caption: 'Step 12b · Export Benefit Eligibility', say: 'Step 12 b: export benefits. For each government scheme, answer whether this order is eligible. Here, R o D T E P: eligible. The others: not eligible. Save All Schemes. Export Compliance then files the claims, and the money received is recorded later in Step 13 g.', do: async (d) => {
      await A.openTo(d, '#financials-12b');
      const sel = sec(d, '#financials-12b').locator('select[data-scheme-status]');
      const n = await sel.count();
      for (let i = 0; i < n; i++) {
        const opts = await sel.nth(i).locator('option').allInnerTexts();
        const want = i === 0 ? opts.find((t) => /^Eligible/i.test(t.trim())) : opts.find((t) => /Not eligible/i.test(t));
        if (want) await d.select(sel.nth(i), { label: want });
        const reason = sec(d, '#financials-12b').locator(`textarea[name="schemes[${i}][reason]"], input[name="schemes[${i}][reason]"]`);
        if (i > 0 && await reason.count() && await reason.first().isVisible()) await d.type(reason.first(), 'Not applicable to this product', { delay: 20 });
      }
      await d.clickNav(sec(d, '#financials-12b').getByRole('button', { name: 'Save All Schemes' }));
    } },
    { goto: at('#financials-12c'), caption: 'Step 12c · Review Profit', say: 'Step 12 c: the profit review. Revenue in rupees, from the payments actually credited, against every direct cost: the supplier, freight, logistics, and the other costs you just confirmed. Then the team incentive and the associate\'s commission. Read it, and if it looks right, Mark Step 12 complete.', do: async (d) => {
      await A.openTo(d, '#financials-12c');
      await d.point(sec(d, '#financials-12c').locator('.kv-grid').first(), 2500);
      await d.clickNav(sec(d, '#financials-12c').getByRole('button', { name: /Mark Step 12 complete/ }));
    } },

    // ---------- 4. CAFIN ----------
    { card: 'chapter', title: 'The CAFIN', subtitle: 'The internal financial annexure.', say: 'Chapter four. The internal cost sheet.' },
    { goto: at('#order-cafin'), caption: 'Internal Financial Annexure (CAFIN)', say: 'On the right of every order sits the CAFIN: the internal financial annexure our chartered accountant uses. The red badge says it: internal only, never shown to the client. Generate CAFIN now builds it as a P D F from the order\'s own figures, at any stage.', do: async (d) => { const p = sec(d, '#order-cafin'); await d.scrollTo(p); await d.point(p.locator('.badge-internal'), 1500); await d.clickNav(p.getByRole('button', { name: /Generate CAFIN now/ })); } },
    { caption: 'Never to the buyer', say: 'It is never attached to an e-mail, never offered in the buyer\'s portal, and never on a buyer link. For the whole year, Finance and accounts has a CAFIN export as one C S V file for the accountant.', do: async (d) => { await d.scrollTo(sec(d, '#order-cafin')); await d.point(sec(d, '#order-cafin').locator('a').first(), 2000); } },

    // ---------- 5. The bigger picture ----------
    { card: 'chapter', title: 'The bigger picture', subtitle: 'Reports · Cockpit · Finance & accounts.', say: 'Chapter five. Across all orders.' },
    { as: 'fd', goto: '/reports', caption: 'Reports › Finance & CA', say: 'Back to the Finance Director. In Reports, Finance and C A: order profitability, revenue against every direct cost for any period; the CAFIN export; and the C A reports. Payments and receivables show what is collected and what is overdue.', do: async (d) => { const h = d.page.getByRole('heading', { name: /Finance & CA/ }); await d.scrollTo(h); await d.point(h, 1500); await d.point(d.page.getByRole('link', { name: /Order profitability/ }).first(), 1500); } },
    { goto: '/cockpit', caption: 'Management cockpit', say: 'The Finance Director also sees the management cockpit: open work per department, what is overdue and what is escalated. Counts only, no money.', do: async (d) => { await d.point(d.page.getByRole('heading', { name: /Management cockpit/ }), 2000); } },
    { goto: '/ca', caption: 'Finance & accounts › CA / Accounting', say: 'And under Finance and accounts: the expenses with their tax deducted at source, bank statements matched line by line, export benefits, the Zoho Books sync, and the financial year lock, once a year is fully reconciled. The Finance Manager also checks the bank details of new associates and suppliers, from Approvals, Bank details.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: /Zoho Books & Financial Year Lock/ }), 1800); } },

    { card: 'list', kicker: 'Recap', title: 'Finance Manager and Finance Director', items: [
      'Director: approve payment terms from Approvals; never on an order you created.',
      'Director: clear a short payment only with what the shortfall is and why.',
      'Manager: Step 12 — costs with bank references, benefit eligibility, profit review.',
      'CAFIN is internal only; reports, the cockpit and the CA pages show the whole picture.',
    ], next: 'B05 · Procurement', say: 'Let us recap. The Finance Director approves buyers\' payment terms from Approvals, but never on an order they created. A short payment is cleared only by a director, saying what the shortfall is, and why. The Finance Manager closes each order in Step 12: the costs with their bank references, the export benefit eligibility, and the profit review. The CAFIN is internal only. And the reports, the cockpit and the C A pages give you the whole picture. Next: Procurement.' },
  ]; },
};
