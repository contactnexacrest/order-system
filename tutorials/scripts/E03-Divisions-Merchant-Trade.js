'use strict';
// E03 — Merchant Trade (MTT), Group E · Divisions. Owner request 19 Nov 2026 ("Group E — Divisions" videos).
// The planned Merchant Trade division is made ready in setup (its departments, one staff member, the number
// format and the AD bank's approval) and activated on camera by the MD (Settings › Divisions › Merchant Trade).
// Then the Merchant Trade Executive walks one deal through its four stages on the real screens:
// Contracted (deal, goods, PO, supplier advance, BL / switch BL) → Shipped (sale invoice, the buyer's payment
// recorded by Accounts) → Settled (the AD bank's document checklist, Reports) → Closed (all RBI checks green).
// Off camera between steps: the supplier's balance and the last bank documents (same service calls as the app).
// Written 19 Nov 2026 from views/merchant/*.php, views/divisions/show.php, MerchantTradeService,
// DivisionRepository::preflight() and docs/SOP/31-merchant-trade.md. Last video of the series (no "next").

const { ACTORS } = require('./_overview');

const DESK_EMAIL = 'mtt.desk@email.com';
const ROLES = {
  desk: { label: 'Merchant Trade Executive', sub: `Merchant Trade · ${DESK_EMAIL}`, initials: 'MT', color: '#1D6FA8' },
  accounts: { label: 'Accounts Executive', sub: 'Accounts & Finance · accounts@email.com', initials: 'AC', color: '#7C3AED' },
};

let demo;
let mtt = 3;      // the Merchant Trade division's id (read in setup)
let deal = 0;     // the deal made on camera (read from the page after "Create deal")
const at = (hash = '') => `/merchant/${deal}${hash}`;
const sec = (d, id) => d.page.locator(id);
const day = (back = 0) => { const t = new Date(Date.now() + 5.5 * 3600e3 - back * 86400e3); return t.toISOString().slice(0, 10); };

// Off-camera moves: the same service calls the deal page makes.
const MOVES = {
  // The supplier's balance, paid by Accounts through the bank (EUR 8,000 at 91.40).
  supplierBalance: (o) => `
    $acc = (int) App\\Config\\Database::connection()->query("SELECT id FROM users WHERE email = 'accounts@email.com'")->fetchColumn();
    $e = App\\Services\\MerchantTradeService::recordPayment(${o}, ['leg' => 'buy', 'moved_on' => date('Y-m-d'), 'amount_fc' => '8000.00', 'fx_rate' => '91.4000',
      'bank_reference' => 'TEST SWIFT OUT 0002', 'note' => 'Balance after the BL'], $acc);
    echo $e ?? 'ok';`,
  // The AD bank has every document of the checklist.
  allDocs: (o) => `
    $desk = (int) App\\Config\\Database::connection()->query("SELECT id FROM users WHERE email = '${DESK_EMAIL}'")->fetchColumn();
    $d = App\\Repositories\\MerchantTradeRepository::find(${o}); $have = App\\Repositories\\MerchantTradeRepository::documents(${o});
    foreach (App\\Services\\MerchantTradeService::dealBankDocuments($d) as $k => $label) if (!isset($have[$k])) App\\Services\\MerchantTradeService::saveDocument(${o}, $k, date('Y-m-d'), $desk);
    echo 'ok';`,
};
const move = (name) => { const out = demo.php(MOVES[name](deal)); if (!/ok$/.test(out)) throw new Error(`${name}: ${out}`); };

// One row of the AD bank's checklist: enter the date the bank received it and save.
async function bankDoc(d, label) {
  const row = sec(d, '#mtt-bank').locator('tr').filter({ hasText: label }).first();
  await d.scrollTo(row);
  await row.locator('input[name="received_on"]').fill(day(0));
  await d.clickNav(row.getByRole('button', { name: 'Save' }));
}

module.exports = {
  code: 'E03',
  file: 'E03-Divisions-Merchant-Trade.mp4',
  group: 'Group E · Divisions',
  title: 'Merchant Trade',
  subtitle: 'Buy abroad, sell abroad: one deal from contract to closing, both money legs, the margin and the RBI checks.',
  minutes: '10',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    demo.demoStaff('accounts');
    mtt = Number(demo.sql("SELECT id FROM divisions WHERE code = 'MTT'"));
    const pw = demo.STAFF.md.password;
    // The Merchant Trade Executive: works in Merchant Trade, sees and manages both sides of a deal
    // (selling side from the role, purchasing added for this person), records payments.
    demo.php(`
      $pdo = App\\Config\\Database::connection();
      $role = (int) $pdo->query("SELECT id FROM roles WHERE name = 'Sales/Export Manager'")->fetchColumn();
      $pdo->prepare("DELETE FROM users WHERE email = ?")->execute([${JSON.stringify(DESK_EMAIL)}]);
      $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin, division_id) VALUES (?, ?, ?, ?, 1, 0, 0, 0, ?)')
        ->execute(['Merchant Trade Executive', ${JSON.stringify(DESK_EMAIL)}, password_hash(${JSON.stringify(pw)}, PASSWORD_BCRYPT), $role, ${mtt}]);
      $uid = (int) $pdo->lastInsertId();
      $md = (int) $pdo->query("SELECT id FROM users WHERE email = 'md@email.com'")->fetchColumn();
      foreach (['manage_purchasing', 'view_purchase_prices'] as $k)
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled, granted_by, reason) SELECT ?, id, 1, ?, ? FROM permissions WHERE permission_key = ?')
          ->execute([$uid, $md, 'Merchant Trade desk (tutorial demo)', $k]);
      // Settings › Divisions › Merchant Trade: its departments, the number format and the AD bank's approval (the RBI limits are seeded).
      App\\Repositories\\DivisionRepository::createStarterDepartments(${mtt});
      App\\Repositories\\DivisionRepository::setSetting(${mtt}, 'company', 'order_ref_format', 'NC/MTT/{YY}/{DDMM}{NNN}', $md);
      App\\Repositories\\DivisionRepository::setSetting(${mtt}, 'company', 'mtt_ad_bank_approval', 'TEST AD Bank - merchanting trade approval letter TEST/MT/2026/001', $md);
      $missing = array_filter(App\\Repositories\\DivisionRepository::preflight(${mtt}), static fn ($i) => !$i['ok']);
      echo $missing ? 'missing: ' . implode('; ', array_column($missing, 'label')) : 'ok';`);
    demo.STAFF.desk = { email: DESK_EMAIL, password: pw };
    const actors = { md: { ...ACTORS.md, cookies: await demo.staffCookies('md') } };
    for (const k of Object.keys(ROLES)) actors[k] = { ...ROLES[k], cookies: await demo.staffCookies(k) };
    return { actors };
  },

  async teardown(dm) { dm.removeStaff([DESK_EMAIL]); },

  steps: () => [
    { card: 'title', say: 'This video is about the Merchant Trade division. In a merchant trade, NexaCrest buys goods from a supplier abroad and sells them to a buyer abroad. The goods go straight from one to the other, and never enter India. The Reserve Bank of India calls this merchanting trade. Both legs are paid in foreign currency, through our A D bank, the authorised dealer bank.' },
    { card: 'list', kicker: 'In short', title: 'What makes a merchant trade different', items: [
      'Supplier abroad, buyer abroad: the goods never enter India.',
      'Two money legs, each in its own currency: buy and sell.',
      'Margin = ₹ received from the buyer − ₹ paid to the supplier, at the bank\'s rates.',
      'RBI checks: the whole deal within 9 months, an outlay covered within 4 months.',
      'An advance above USD 5,00,000 only against an LC or bank guarantee; every document with the AD bank.',
    ], say: 'What makes it different? Both sides are abroad. There are two money legs, each in its own currency: we pay the supplier, and the buyer pays us. Our margin is the rupees received from the buyer, minus the rupees paid to the supplier, at the rates the bank actually gave. And the R B I sets limits: the whole deal must finish within 9 months, money we pay out must be covered by the buyer within 4 months, an advance above 5 lakh U S dollars needs a letter of credit or a bank guarantee, and every document must reach the A D bank. The app watches all of this for you.' },

    // ---------- 1. Switching it on ----------
    { card: 'chapter', title: 'Switching it on', subtitle: 'Settings › Divisions › Merchant Trade', say: 'Chapter one. Switching the division on.' },
    { as: 'md', goto: `/admin/divisions/${mtt}`, caption: 'Settings › Divisions › Merchant Trade', say: 'Merchant Trade is a planned division: it is built, but nobody sees it until the Managing Director switches it on, here in Settings, Divisions. The Domestic Trade video shows this page in full. Its own settings hold the R B I limits: 9 months, 4 months, and the advance limit, all to be confirmed with the A D bank and the C A.', do: async (d) => {
      await d.point('h1', 1200);
      await d.scrollTo('[data-testid="division-settings"]');
      await d.point('[data-testid="division-settings"]', 2500);
    } },
    { caption: 'Before activating', say: 'Before activating, every line of the checklist must be ticked: its departments and staff, the number format, the modules, our I E C, the A D bank\'s approval for merchanting, and the limits. Tick the box, and press Activate Merchant Trade.', do: async (d) => {
      const p = sec(d, '[data-testid="division-preflight"]');
      await d.scrollTo(p);
      await d.point(p.locator('ul.checklist'), 3500);
      await d.check(p.locator('input[name="confirm"]'));
      await d.clickNav(p.getByRole('button', { name: /Activate Merchant Trade/ }));
      await d.point('.alert', 1500);
    } },

    // ---------- 2. A new deal ----------
    { card: 'chapter', title: 'A new deal', subtitle: 'Stage 1 · Contracted', say: 'Chapter two. A new deal.' },
    { as: 'desk', caption: 'Procurement & suppliers › Merchant trade', say: 'We are now the Merchant Trade Executive, who works in the new division. Procurement and suppliers has a new entry: Merchant trade. The deals show here, with their stage, both sides, the buy and sell totals, and their R B I status. There are none yet. Press New deal.', do: async (d) => {
      await d.goto('/merchant');
      await d.point('nav.sidebar-nav a[data-nav="procurement"]', 1000);
      await d.point('nav.section-nav a[href="/merchant"]', 1200);
      await d.point('[data-testid="mtt-list"] table.list', 2000);
      await d.clickNav(d.page.getByRole('link', { name: 'New deal' }));
    } },
    { caption: 'New merchant trade deal', say: 'Enter the deal date, the supplier abroad with its country, and the purchase currency. Our supplier is a quarry in Turkey, and it invoices in euro. Then the buyer abroad: a stone trader in the United Arab Emirates, who pays in U S dollars.', do: async (d) => {
      const f = sec(d, '[data-testid="mtt-create-form"]');
      await f.locator('input[name="deal_date"]').fill(day(30));
      await d.type(f.locator('input[name="supplier_name"]'), 'TEST Aegean Marble Quarry', { delay: 35 });
      await d.type(f.locator('input[name="supplier_country"]'), 'Turkey', { delay: 35 });
      await d.select(f.locator('select[name="purchase_currency"]'), 'EUR');
      await d.select(f.locator('select[name="purchase_incoterm"]'), 'FOB');
      await d.type(f.locator('input[name="buyer_name"]'), 'TEST Gulf Stone Trading LLC', { delay: 35 });
      await d.type(f.locator('input[name="buyer_country"]'), 'United Arab Emirates', { delay: 35 });
      await d.select(f.locator('select[name="sale_currency"]'), 'USD');
      await d.select(f.locator('select[name="sale_incoterm"]'), 'CIF');
    } },
    { caption: 'Where the goods come from', say: 'Then the country the goods come from, and the two ports. If India were the origin, or either side were in India, the app would refuse the deal: that is an export, an import or a domestic sale. Create deal.', do: async (d) => {
      const f = sec(d, '[data-testid="mtt-create-form"]');
      await d.type(f.locator('input[name="origin_country"]'), 'Turkey', { delay: 35 });
      await d.type(f.locator('input[name="port_of_loading"]'), 'Izmir, Turkey', { delay: 35 });
      await d.type(f.locator('input[name="port_of_discharge"]'), 'Jebel Ali, UAE', { delay: 35 });
      await d.clickNav(f.getByRole('button', { name: 'Create deal' }));
      deal = Number(new URL(d.page.url()).pathname.split('/').pop());
    } },
    { caption: 'The deal page', say: 'The deal has its number, and the purchase order number is given at once. The strip shows the four stages: Contracted, Shipped, Settled and Closed. Below it, the app lists what still stops the deal from moving on: the goods, the purchase order, and the bill of lading.', do: async (d) => {
      await d.point('h1', 1500);
      await d.point('[data-testid="mtt-stages"]', 1800);
      await d.point('[data-testid="mtt-next"]', 2500);
    } },
    { caption: 'Goods · buy and sell', say: 'Add the goods: the description, the H S code, the quantity and unit, the buy price in euro, and the sell price in dollars. Add line.', do: async (d) => {
      const f = sec(d, '[data-testid="mtt-add-line"]');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="description"]'), 'White marble slabs, 2 cm, polished', { delay: 30 });
      await d.type(f.locator('input[name="hsn_code"]'), '68022100', { delay: 40 });
      await d.type(f.locator('input[name="quantity"]'), '500', { delay: 40 });
      await d.type(f.locator('input[name="unit"]'), 'SQM', { delay: 40 });
      await d.type(f.locator('input[name="buy_price_fc"]'), '32.00', { delay: 40 });
      await d.type(f.locator('input[name="sell_price_fc"]'), '38.50', { delay: 40 });
      await d.clickNav(f.getByRole('button', { name: 'Add line' }));
    } },
    { caption: 'Download purchase order (PDF)', say: 'The totals show the purchase in euro and the sale in dollars. Now Download purchase order, the P D F we send to the supplier. The page then shows when it was generated.', do: async (d) => {
      await d.goto(at('#mtt-lines'));
      await d.point('[data-testid="mtt-totals"]', 2000);
      const f = sec(d, '#mtt-deal').locator('form[action$="/document"]');
      await d.scrollTo(f);
      await d.click(f.getByRole('button', { name: /Download purchase order/ }));
      await d.wait(1500);
      await d.goto(at('#mtt-deal'));
      await d.scrollTo(sec(d, '#mtt-deal').locator('form[action$="/document"]'));
      await d.point(sec(d, '#mtt-deal').locator('span.muted').last(), 1500);
    } },

    // ---------- 3. Money and the RBI checks ----------
    { card: 'chapter', title: 'Money and the RBI checks', subtitle: 'Both legs, each with its bank reference.', say: 'Chapter three. Money, and the R B I checks.' },
    { caption: 'RBI checks', say: 'Before any money moves, look at the R B I checks. The whole deal must finish within 9 months of its first payment or receipt. Money paid to the supplier must be covered by the buyer\'s money within 4 months. An advance to the supplier has its limit. And the documents must reach the A D bank. Nothing has moved yet.', do: async (d) => {
      await d.goto(at('#mtt-rbi'));
      await d.scrollTo('#mtt-rbi');
      await d.point('#mtt-rbi ul', 4000);
    } },
    { caption: 'Paid to the supplier', say: 'Our supplier asks for a fifty percent advance. Record it under Money on both legs: the leg, paid to the supplier; the date; the amount in euro; the bank\'s rate, in rupees per euro; and the SWIFT reference, which is required. A note adds to the description, it never replaces it. Record.', do: async (d) => {
      await d.goto(at('#mtt-payments'));
      const f = sec(d, '#mtt-payments').locator('form[action$="/payments"]');
      await d.scrollTo(f);
      await d.select(f.locator('select[name="leg"]'), 'buy');
      await f.locator('input[name="moved_on"]').fill(day(25));
      await d.type(f.locator('input[name="amount_fc"]'), '8000', { delay: 40 });
      await d.type(f.locator('input[name="fx_rate"]'), '91.20', { delay: 40 });
      await d.type(f.locator('input[name="bank_reference"]'), 'TEST SWIFT OUT 0001', { delay: 35 });
      await d.type(f.locator('input[name="note"]'), '50% advance against proforma', { delay: 30 });
      await d.clickNav(f.getByRole('button', { name: 'Record' }));
    } },
    { caption: 'The description writes itself', say: 'The app writes the description from the facts: a supplier advance of 8,000 euro, the rupees at the bank\'s rate, to whom, for which deal and purchase order, and the SWIFT reference. The same text goes to Zoho Books. A bank reference already recorded, or more than is still owed, is refused.', do: async (d) => {
      await d.goto(at('#mtt-payments'));
      await d.scrollTo('[data-testid="mtt-payments"]');
      await d.point(sec(d, '[data-testid="mtt-payments"]').locator('tr').nth(1).locator('td').last(), 5000);
    } },
    { caption: 'The checks count now', say: 'The checks now count. The deal must finish within 9 months of this payment, and the app shows the date. The outlay is open: the buyer\'s money must cover it within 4 months. The advance, in dollars, is within the limit. A payment that would take an advance above the limit without an L C or bank guarantee is refused: record the backing first, under Advance backed by.', do: async (d) => {
      await d.goto(at('#mtt-rbi'));
      await d.scrollTo('#mtt-rbi');
      await d.point('#mtt-rbi li[data-check="total"]', 1800);
      await d.point('#mtt-rbi li[data-check="outlay"]', 2200);
      await d.point('#mtt-rbi li[data-check="advance"]', 2200);
      const b = sec(d, '#mtt-payments').locator('select[name="advance_backing"]');
      await d.scrollTo(b);
      await d.point(b, 2000);
    } },
    { caption: 'Bill of lading', say: 'The goods sail from Izmir. Record the bill of lading: its number, its date and the vessel. Where the A D bank allows it, the shipping line issues a switch B L, so the buyer does not see our supplier. Tick it, then enter its number, who issued it, and the bank\'s consent. Save B L.', do: async (d) => {
      await d.goto(at('#mtt-shipment'));
      const f = sec(d, '#mtt-shipment').locator('form[action$="/bl"]');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="bl_no"]'), 'TESTIZ2611001', { delay: 40 });
      await f.locator('input[name="bl_date"]').fill(day(10));
      await d.type(f.locator('input[name="vessel"]'), 'MV Sample Aurora / 245E', { delay: 35 });
      await d.check(f.locator('input[name="switch_bl"]'));
      await d.type(f.locator('input[name="switch_bl_no"]'), 'TESTIZ2611001-S', { delay: 35 });
      await d.type(f.locator('input[name="switch_bl_ref"]'), 'TEST carrier agent, Dubai; TEST AD Bank letter MT/2026/118', { delay: 25 });
      await d.clickNav(f.getByRole('button', { name: 'Save BL' }));
    } },
    { caption: 'Move on to Shipped', say: 'Nothing stops the deal now. Move on to Shipped.', do: async (d) => {
      await d.goto(at(''));
      await d.clickNav(sec(d, '[data-testid="mtt-next"]').getByRole('button', { name: /Move on to Shipped/ }));
      await d.point('.alert', 1500);
    } },

    // ---------- 4. Shipped ----------
    { card: 'chapter', title: 'Shipped', subtitle: 'Stage 2 · the sale invoice and the money.', say: 'Chapter four. The goods are on the water.' },
    { caption: 'Download sale invoice (PDF)', say: 'Download the sale invoice to the buyer. Its number is fixed the first time, and it needs the B L, because the invoice goes with the shipping documents.', do: async (d) => {
      await d.goto(at('#mtt-shipment'));
      const f = sec(d, '#mtt-shipment').locator('form[action$="/document"]');
      await d.scrollTo(f);
      await d.click(f.getByRole('button', { name: /Download sale invoice/ }));
      await d.wait(1500);
      await d.goto(at('#mtt-shipment'));
      await d.scrollTo(sec(d, '#mtt-shipment').locator('form[action$="/document"]'));
      await d.point(sec(d, '#mtt-shipment').locator('span.muted').last(), 2000);
    } },
    { as: 'accounts', caption: 'Received from the buyer', say: 'Accounts records the money. The buyer has paid the whole invoice: received from the buyer, 19,250 dollars, at the bank\'s rate of 84.10 rupees, with the inward SWIFT reference. Record.', do: async (d) => {
      await d.goto(at('#mtt-payments'));
      const f = sec(d, '#mtt-payments').locator('form[action$="/payments"]');
      await d.scrollTo(f);
      await d.select(f.locator('select[name="leg"]'), 'sell');
      await f.locator('input[name="moved_on"]').fill(day(0));
      await d.type(f.locator('input[name="amount_fc"]'), '19250', { delay: 40 });
      await d.type(f.locator('input[name="fx_rate"]'), '84.10', { delay: 40 });
      await d.type(f.locator('input[name="bank_reference"]'), 'TEST SWIFT IN 0001', { delay: 35 });
      await d.clickNav(f.getByRole('button', { name: 'Record' }));
    } },
    { caption: 'Sale receipt', say: 'This one is a sale receipt, from the buyer, against the deal and our invoice number. The supplier\'s balance of 8,000 euro was paid the same way, at 91.40.', do: async (d) => {
      move('supplierBalance');
      await d.goto(at('#mtt-payments'));
      await d.scrollTo('[data-testid="mtt-payments"]');
      await d.point(sec(d, '[data-testid="mtt-payments"]').locator('tr').filter({ hasText: 'TEST SWIFT IN 0001' }).locator('td').last(), 3000);
      await d.point(sec(d, '[data-testid="mtt-payments"]').locator('tr').filter({ hasText: 'TEST SWIFT OUT 0002' }), 1500);
    } },
    { caption: 'Margin', say: 'Both legs are now complete, so the margin is final. Rupees received from the buyer, minus rupees paid to the supplier, at the rates the bank actually gave: 1 lakh 58 thousand 125 rupees, about 1,880 dollars, or 9.77 percent of what we received.', do: async (d) => {
      await d.scrollTo('[data-testid="mtt-margin"]');
      await d.point('[data-testid="mtt-margin"]', 5000);
    } },
    { as: 'desk', caption: 'Move on to Settled', say: 'Back to the Merchant Trade Executive. The invoice is out, the buyer has paid in full, and the supplier is paid in full. Move on to Settled.', do: async (d) => {
      await d.goto(at(''));
      await d.clickNav(sec(d, '[data-testid="mtt-next"]').getByRole('button', { name: /Move on to Settled/ }));
      await d.point('.alert', 1500);
    } },

    // ---------- 5. Settled and closed ----------
    { card: 'chapter', title: 'Settled', subtitle: 'Stage 3 · the AD bank\'s document checklist.', say: 'Chapter five. The papers for the bank.' },
    { caption: 'AD bank\'s document checklist', say: 'The A D bank needs a set of documents for every merchant trade: the sale and purchase contracts, the supplier\'s invoice, the bill of lading, our sale invoice, and the bank advices of both legs. When the bank has one, enter the date it received it, and save.', do: async (d) => {
      await d.goto(at('#mtt-bank'));
      await d.scrollTo('[data-testid="mtt-bank"]');
      await d.point('[data-testid="mtt-bank"]', 2500);
      await bankDoc(d, 'Sale contract or buyer');
      await bankDoc(d, 'Purchase contract or supplier');
    } },
    { goto: '/merchant/reports', caption: 'Merchant trade › Reports', say: 'Merchant trade Reports shows every open deal with its four checks, and the margin of every deal. Amber means due soon, red means act now. Our documents are amber: the deal is settled, and five are still missing. When a check turns amber or red, the daily alert job also reminds the team, in the app and by e-mail.', do: async (d) => {
      await d.point('[data-testid="mtt-open"]', 4500);
      await d.scrollTo('[data-testid="mtt-margins"]');
      await d.point('[data-testid="mtt-margins"]', 3000);
    } },
    { caption: 'Move on to Closed', say: 'When the bank has every document, the deal can close. Move on to Closed.', do: async (d) => {
      move('allDocs');
      await d.goto(at('#mtt-bank'));
      await d.scrollTo('[data-testid="mtt-bank"]');
      await d.point('[data-testid="mtt-bank"]', 1500);
      await d.scrollTo('[data-testid="mtt-next"]');
      await d.clickNav(sec(d, '[data-testid="mtt-next"]').getByRole('button', { name: /Move on to Closed/ }));
    } },
    { caption: 'Closed · all checks green', say: 'The deal is closed, and all four checks are green: finished within 9 months, the outlay covered in time, the advance within the limit, and all seven documents with the bank. The closing date is what counts for the 9 month check.', do: async (d) => {
      await d.goto(at('#mtt-rbi'));
      await d.point('h1', 1200);
      await d.scrollTo('#mtt-rbi');
      await d.point('#mtt-rbi ul', 4000);
    } },

    { card: 'list', kicker: 'Who sees what', title: 'Merchant Trade and its people', items: [
      'Staff of Merchant Trade see only its deals; company-level people see every division.',
      'Procurement sees the purchase side only: no sale prices, margin, buyer or buyer payments.',
      'Accounts and Finance record the money on both legs, each with its bank reference.',
      'Finance sends the sale invoice, the supplier\'s bill and both legs to Zoho Books.',
    ], say: 'Who sees what. Staff of Merchant Trade see only its deals, while company-level people see every division. Procurement sees the purchase side only: no sale prices, no margin, no buyer, and no buyer payments. Accounts and Finance record the money on both legs, always with the bank reference. And Finance sends the sale invoice, the supplier\'s bill and both legs to Zoho Books.' },
    { card: 'list', kicker: 'Group E · Divisions', title: 'One company, three new divisions', items: [
      'Domestic Trade (DOM): sale to Indian resellers in ₹, with GST tax invoices.',
      'Import & Distribution (IMP): import, customs duty, landed cost, stock and local sale.',
      'Merchant Trade (MTT): buy abroad, sell abroad, both legs in foreign currency.',
      'Each starts planned; the MD activates it in Settings › Divisions after its checklist.',
      'Every record belongs to one division for life; staff see only their own division.',
    ], say: 'Group E in one picture. NexaCrest is one company, and Export now has three sister divisions. Domestic Trade sells to Indian resellers in rupees, with G S T tax invoices. Import and Distribution imports goods, works out the customs duty and the landed cost, keeps stock and sells locally. Merchant Trade buys abroad and sells abroad. Each starts as planned, and the Managing Director activates it in Settings, Divisions, once its checklist is complete. Every record belongs to one division for life, and staff see only their own.' },
    { card: 'list', kicker: 'Recap', title: 'Merchant Trade', items: [
      'Contracted: the deal, the goods with buy and sell prices, the PO, the BL or switch BL.',
      'Shipped: the sale invoice; both legs paid, each with its SWIFT / UTR reference.',
      'Settled: every document of the checklist with the AD bank.',
      'Closed: margin final at the bank\'s rates; the RBI checks green.',
    ], say: 'Let us recap. Contracted: the deal, the goods with their buy and sell prices, the purchase order, and the bill of lading, or a switch B L. Shipped: the sale invoice, and both legs paid, each with its bank reference. Settled: every document of the checklist with the A D bank. Closed: the margin is final at the bank\'s rates, and the R B I checks are green. That completes our tutorial videos. Thank you for watching!' },
  ],
};
