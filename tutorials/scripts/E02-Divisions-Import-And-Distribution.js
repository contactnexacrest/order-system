'use strict';
// E02 — Import & Distribution (IMP), Group E (Divisions). Owner request 19 Nov 2026 ("Group E — Divisions" videos).
// IMP is a planned division: the set-up switches it on in the tutorial database only (division settings, its
// starter departments, two staff placed in it, status active), plus a TEST product kept in stock and a TEST
// local buyer. On camera one import walks its stages (Ordered → Shipped → Cleared → In stock → Closed) with the
// Import Purchase Executive and the Accounts Executive; the Distribution Sales Executive sells part of the stock
// locally. Long forms are filled off camera between chapters (ImportService / DomesticOrderService).
// Written 19 Nov 2026 from views/import/{index,create,show,reports,duty_rates}.php, views/domestic/{create,show}.php,
// ImportService, DomesticOrderService, DivisionRepository and SOP 30.

const { ACTORS } = require('./_overview');

let demo;
let imp = 0;   // the import (import_shipments.id), created on camera
let sale = 0;  // the local sale (orders.id), created on camera
let client = 0;
// Dates as the app sees them (India time): a BL or payment dated "today" in UTC could fall before the PO date.
const plus = (days) => new Date(Date.now() + 330 * 60000 + days * 86400000).toISOString().slice(0, 10);
const today = () => plus(0);
const sec = (d, id) => d.page.locator(id);

const STAFF = {
  imp: { email: 'import.purchase@email.com', name: 'Import Purchase Executive', role: 'Procurement Executive' },
  sales: { email: 'distribution.sales@email.com', name: 'Distribution Sales Executive', role: 'Export Executive' },
};
const ROLES = {
  imp: { label: 'Import Purchase Executive', sub: 'Import & Distribution · import.purchase@email.com', initials: 'IP', color: '#1D6FA8' },
  accounts: { label: 'Accounts Executive', sub: 'Accounts & Finance · accounts@email.com', initials: 'AC', color: '#7C3AED' },
  sales: { label: 'Distribution Sales Executive', sub: 'Import & Distribution · distribution.sales@email.com', initials: 'DS', color: '#2D7D56' },
};

const PRELUDE = `
  use App\\Services\\ImportService as I; use App\\Services\\DomesticOrderService as D; use App\\Services\\DomesticDocumentService as DD;
  $pdo = App\\Config\\Database::connection();
  $uid = function ($email) use ($pdo) { $s = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $s->execute([$email]); return (int) $s->fetchColumn(); };
  $ok = function ($err) { if ($err !== null) { throw new RuntimeException($err); } };
`;
const php = (code) => demo.php(PRELUDE + code);

// Off-camera moves between chapters.
const MOVES = {
  // The customs agent's bill of entry, the customs rate and the clearance charges (the form is long).
  clearance: () => php(`$ok(I::saveClearance(${imp}, ['boe_no' => '7012345', 'boe_date' => date('Y-m-d'), 'port_code' => 'INNSA1',
      'cha_name' => 'TEST Clearing Agency', 'customs_fx_rate' => '90.4000', 'clearance_date' => date('Y-m-d'),
      'freight_inr' => '95000', 'insurance_inr' => '6500', 'cha_inr' => '18000', 'port_inr' => '24000', 'other_inr' => '5000'], $uid('import.purchase@email.com'))); echo 'ok';`),
  // The local sale: quotation and order confirmation generated, then on to Purchase (the stock check).
  toPurchase: () => php(`$s = $uid('distribution.sales@email.com');
      foreach (['accounts@email.com', 'distribution.sales@email.com'] as $e) $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (?, ?, ?)')->execute([${sale}, $uid($e), $s]);
      $ok(D::advance(${sale}, 'enquiry', $s)); DD::generate(${sale}, 'DQT', $s); $ok(D::advance(${sale}, 'quotation', $s));
      DD::generate(${sale}, 'DOC', $s); $ok(D::advance(${sale}, 'order_confirmation', $s)); echo 'ok';`),
  // Dispatched with its e-way bill, tax invoice and challan generated, invoice sent: waiting for payment.
  toPayment: () => php(`$s = $uid('distribution.sales@email.com');
      $ok(D::saveDispatch(${sale}, ['dispatch_date' => date('Y-m-d'), 'transporter_name' => 'TEST Roadways', 'transporter_id' => '', 'vehicle_no' => 'KA01AB0000',
        'lr_no' => 'LR-TEST-01', 'distance_km' => '25', 'eway_bill_no' => '331000000001', 'eway_bill_date' => date('Y-m-d')], $s));
      DD::generate(${sale}, 'GTI', $s); DD::generate(${sale}, 'DCH', $s); $ok(D::advance(${sale}, 'dispatch', $s));
      $ok(D::markInvoiceSent(${sale}, date('Y-m-d'), $s)); $ok(D::advance(${sale}, 'tax_invoice', $s)); echo 'ok';`),
};

module.exports = {
  code: 'E02',
  file: 'E02-Divisions-Import-And-Distribution.mp4',
  group: 'Group E · Divisions',
  title: 'Import & Distribution',
  subtitle: 'Import into India, customs duty and landed cost, stock, and the local sale.',
  minutes: '11',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    const pw = demo.STAFF.md.password;
    // Switch Import & Distribution on in the tutorial database (what the MD does in Settings › Divisions).
    const out = php(`
      use App\\Repositories\\DivisionRepository as DR;
      $md = $uid('md@email.com');
      $div = (int) $pdo->query("SELECT id FROM divisions WHERE code = 'IMP'")->fetchColumn();
      DR::setSetting($div, 'company', 'order_ref_format', 'NC/{DIV}/{YY}/{DDMM}{NNN}', $md);
      DR::setSetting($div, 'company', 'imp_lc_bank', 'TEST AD Bank, Bengaluru', $md);
      DR::setSetting($div, 'company', 'imp_default_cha', 'TEST Clearing Agency', $md);
      DR::createStarterDepartments($div);
      $h = password_hash(${JSON.stringify(pw)}, PASSWORD_BCRYPT, ['cost' => 10]);
      foreach (${JSON.stringify(Object.values(STAFF).map((s) => [s.email, s.name, s.role]))} as [$e, $n, $r]) {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin, division_id)
          VALUES (?, ?, ?, (SELECT id FROM roles WHERE name = ?), 1, 0, 0, 0, ?)')->execute([$n, $e, $h, $r, $div]);
      }
      DR::setStatus($div, 'active', $md);
      // The product kept in stock, and the reseller who buys it.
      $pdo->exec("INSERT INTO products (product_code, name, hs_code, is_active) VALUES ('IMP-CARRARA', 'Carrara White Marble Slab', '68022110', 1)");
      $c = App\\Repositories\\ClientRepository::create(['company_legal_name' => 'TEST Deccan Stone Traders', 'billing_address' => 'Plot 0, Test Industrial Area, Bengaluru 560000, Karnataka, India',
        'consignee_name' => 'SAME', 'consignee_address' => 'SAME', 'contact_person' => 'Purchase Manager', 'email' => 'purchase@deccan-stone.example', 'phone' => '+91-0000000000',
        'country_of_destination' => 'India', 'coo_type' => 'Not applicable', 'notify_party' => null], $md, App\\Services\\ReferenceNumberService::generateClientUniqueNumber());
      App\\Services\\DivisionService::linkClient($c, $div);
      echo $c;`);
    client = Number(out.split('\n').pop());
    demo.demoStaff('accounts');
    demo.sql("UPDATE users SET division_id = NULL WHERE email = 'accounts@email.com'"); // company level: sees every division
    for (const [k, s] of Object.entries(STAFF)) demo.STAFF[k] = { email: s.email, password: pw };
    const actors = { md: { ...ACTORS.md, cookies: await demo.staffCookies('md') } };
    for (const k of Object.keys(ROLES)) actors[k] = { ...ROLES[k], cookies: await demo.staffCookies(k) };
    return { actors, clientId: client };
  },

  async teardown(dm) {
    dm.removeStaff(Object.values(STAFF).map((s) => s.email));
  },

  steps: ({ clientId = 0 } = {}) => { client = clientId || client; return [
    { card: 'title', say: 'This video is for the staff of the Import and Distribution division. We buy goods abroad, import them into India, pay the customs duty, keep them in stock, and sell them to resellers here in India.' },
    { card: 'list', kicker: 'One import, five stages', title: 'Import & Distribution · IMP', items: [
      'Ordered: the import PO, the LC and the advance to the supplier.',
      'Shipped: the bill of lading, then customs clearance.',
      'Cleared: duty and landed cost worked out and frozen.',
      'In stock: the goods go into stock at their landed cost.',
      'Closed: supplier paid in full, IGST credit taken.',
    ], say: 'Every import runs through five stages. Ordered: the import P O, the letter of credit and the advance. Shipped: the bill of lading, then customs clearance. Cleared: the duty and the landed cost are worked out and frozen. In stock: the goods go into stock. And Closed, once the supplier is paid in full and the I G S T credit is taken. In between, we sell from stock.' },

    // ---------- 1. The division ----------
    { card: 'chapter', title: 'A planned division', subtitle: 'Switched on by the MD.', say: 'Chapter one. Switching the division on.' },
    { as: 'md', goto: '/admin/divisions', caption: 'Settings › Divisions', say: 'Import and Distribution is a planned division. It stays off, and nobody sees it, until the M D activates it here, in Settings, Divisions, after its checklist. The Domestic Trade video shows this page in full. For this demo it is already active.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: 'Import & Distribution' }), 2500); } },
    { goto: '/admin/import-duty-rates', caption: 'Import duty rates', say: 'The duty rates are kept per H S N code. For worked stone, basic customs duty is twenty percent, the social welfare surcharge is ten percent of that duty, and I G S T is eighteen percent. These are starting values: the C A and the customs agent confirm them.', do: async (d) => { await d.point(d.page.locator('tr').filter({ hasText: '6802' }), 2500); } },

    // ---------- 2. Ordered ----------
    { card: 'chapter', title: 'Ordered', subtitle: 'The import PO, the LC and the advance.', say: 'Chapter two. Ordering from abroad.' },
    { as: 'imp', goto: '/import', caption: 'Procurement & suppliers › Imports & stock', say: 'We are the Import Purchase Executive. Our imports are under Procurement and suppliers, Imports and stock. The list is empty. Click New import.', do: async (d) => { await d.point('[data-testid="imp-list"] h1', 1200); await d.clickNav(d.page.getByRole('link', { name: 'New import' })); } },
    { caption: 'New import', est: 14, say: 'Enter the supplier abroad, its country and address, and the currency of its invoice: here euros. The Incoterm is F O B, so we pay the sea freight ourselves. Add the payment terms, and create the import.', do: async (d) => {
      const f = d.page.locator('[data-testid="imp-create-form"]');
      await d.type(f.locator('input[name="supplier_name"]'), 'TEST Marmi Apuani S.r.l.', { delay: 30 });
      await d.type(f.locator('input[name="supplier_country"]'), 'Italy', { delay: 30 });
      await d.type(f.locator('textarea[name="supplier_address"]'), 'Via di Prova 1, 54033 Carrara, Italy', { delay: 25 });
      await d.select(f.locator('select[name="currency_code"]'), 'EUR');
      await d.select(f.locator('select[name="incoterm"]'), 'FOB');
      await d.type(f.locator('input[name="payment_terms"]'), '30% advance by TT, 70% by LC at sight', { delay: 25 });
      await d.clickNav(f.getByRole('button', { name: 'Create import' }));
      imp = Number((d.page.url().match(/\/import\/(\d+)/) || [])[1] || 0);
    } },
    { caption: 'Add a line', est: 10, say: 'The import P O number is given at once. Now the lines. Choose the product: stock is kept per product. The quantity, the unit, and the price in euros. Leave the duty rates empty: they come from the duty rates list. Add line.', do: async (d) => {
      await d.point('[data-testid="imp-show"] h1', 1200);
      const f = d.page.locator('[data-testid="imp-add-line"]');
      await d.scrollTo(f);
      await d.select(f.locator('select[name="product_id"]'), { index: 1 });
      await d.type(f.locator('input[name="quantity"]'), '400');
      await d.type(f.locator('input[name="unit"]'), 'SQM');
      await d.type(f.locator('input[name="unit_price_fc"]'), '42.00');
      await d.clickNav(f.getByRole('button', { name: 'Add line' }));
    } },
    { caption: 'Download import PO (PDF)', say: 'The line shows twenty percent basic duty, ten percent surcharge and eighteen percent I G S T. The rupee columns fill in after customs clearance. Download the import P O and send it to the supplier.', do: async (d) => {
      await d.scrollTo('table[data-testid="imp-lines"]');
      await d.point('table[data-testid="imp-lines"]', 2000);
      await d.click(d.page.getByRole('button', { name: 'Download import PO (PDF)' }));
      await d.wait(1500);
      await d.goto(`/import/${imp}#imp-po`);
      await d.scrollTo('#imp-po');
      await d.point(sec(d, '#imp-po').locator('span.muted').last(), 1200);
    } },
    { caption: 'Letter of credit', est: 10, say: 'The balance is paid by a letter of credit, which our bank opens for us. Record it: the L C number, the bank, the amount in euros, the expiry date and the margin money the bank holds. Save L C.', do: async (d) => {
      const f = sec(d, '#imp-lc').locator('form');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="lc_no"]'), 'TESTILC2611001', { delay: 35 });
      await d.point(f.locator('input[name="lc_bank"]'), 600);
      await d.type(f.locator('input[name="lc_amount_fc"]'), '11760.00');
      await f.locator('input[name="lc_expiry"]').fill(plus(60));
      await d.type(f.locator('input[name="lc_margin_inr"]'), '110000');
      await d.clickNav(f.getByRole('button', { name: 'Save LC' }));
    } },
    { as: 'accounts', caption: 'Payments to the supplier', est: 12, say: 'Now the Accounts Executive pays the thirty percent advance abroad. Choose Supplier advance, the date, the amount in euros, the bank\'s exchange rate, and the swift or U T R reference. The reference is required. Record payment.', do: async (d) => {
      await d.goto(`/import/${imp}#imp-payments`);
      const f = sec(d, '#imp-payments').locator('form');
      await d.scrollTo(f);
      await d.select(f.locator('select[name="kind"]'), 'advance');
      await f.locator('input[name="paid_on"]').fill(today());
      await d.type(f.locator('input[name="amount_fc"]'), '5040.00');
      await d.type(f.locator('input[name="fx_rate"]'), '90.15');
      await d.type(f.locator('input[name="bank_reference"]'), 'TESTSWIFT2611001', { delay: 35 });
      await d.clickNav(f.getByRole('button', { name: 'Record payment' }));
    } },
    { caption: 'The description is written for you', say: 'The app writes the description from the facts: an import supplier advance, the amount in euros and in rupees at the bank\'s rate, to whom, against which import P O, and the bank reference. The same reference cannot be recorded twice, and we can never pay more than the P O.', do: async (d) => {
      await d.scrollTo('table[data-testid="imp-payments"]');
      await d.point(d.page.locator('table[data-testid="imp-payments"] tr').nth(1).locator('td').last(), 3000);
    } },
    { as: 'imp', caption: 'Shipment · bill of lading', est: 10, say: 'The goods have sailed. The supplier sends the bill of lading. Enter its number and date, the vessel and the port of loading. Save B L.', do: async (d) => {
      await d.goto(`/import/${imp}#imp-shipment`);
      const f = sec(d, '#imp-shipment').locator('form');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="bl_no"]'), 'TESTBL26110042', { delay: 35 });
      await f.locator('input[name="bl_date"]').fill(today());
      await d.type(f.locator('input[name="vessel"]'), 'TEST Vessel / 112E', { delay: 30 });
      await d.type(f.locator('input[name="port_of_loading"]'), 'La Spezia, Italy', { delay: 30 });
      await d.clickNav(f.getByRole('button', { name: 'Save BL' }));
    } },
    { caption: 'Move on to Shipped', say: 'The lines, the import P O and the bill of lading are done, so the import can move on. Move on to Shipped.', do: async (d) => {
      await d.scrollTo('[data-testid="imp-next"]');
      await d.clickNav(d.page.getByRole('button', { name: 'Move on to Shipped' }));
      await d.point('[data-testid="imp-stages"]', 1000);
    } },

    // ---------- 3. Customs clearance ----------
    { card: 'chapter', title: 'Shipped', subtitle: 'Customs clearance.', say: 'Chapter three. Customs clearance.', do: async () => { MOVES.clearance(); } },
    { goto: '/import', caption: 'Customs clearance', say: 'The ship has arrived in India. Our customs agent filed the bill of entry, and we copied its details into Customs clearance.', do: async (d) => {
      await d.point(d.page.locator('tr, .kanban-card').filter({ hasText: 'TEST Marmi' }).first(), 1200);
      await d.goto(`/import/${imp}#imp-clearance`);
      await d.scrollTo('#imp-clearance');
    } },
    { caption: 'Bill of entry · customs rate · charges', say: 'The bill of entry number and date, the port code, the customs agent, and the customs exchange rate printed on the bill of entry: ninety rupees forty paise per euro. Then the charges in rupees: sea freight and insurance, the agent\'s fee, port and terminal charges, and any other charges.', do: async (d) => {
      const f = sec(d, '#imp-clearance').locator('form').first();
      await d.point(f.locator('input[name="boe_no"]'), 900);
      await d.point(f.locator('input[name="customs_fx_rate"]'), 1500);
      await d.point(f.locator('input[name="freight_inr"]'), 900);
      await d.point(f.locator('input[name="cha_inr"]'), 900);
      await d.point(f.locator('input[name="other_inr"]'), 900);
    } },
    { caption: 'Estimate until cleared', say: 'With the customs rate in, the lines already show the rupee figures, as an estimate. Moving on to Cleared works them out once more and freezes them. Move on to Cleared.', do: async (d) => {
      await d.scrollTo('[data-testid="imp-totals"]');
      await d.point('[data-testid="imp-totals"]', 2200);
      await d.scrollTo('[data-testid="imp-next"]');
      await d.clickNav(d.page.getByRole('button', { name: 'Move on to Cleared' }));
    } },

    // ---------- 4. Duty and landed cost ----------
    { card: 'chapter', title: 'Cleared', subtitle: 'Duty and landed cost, frozen.', say: 'Chapter four. Duty and landed cost.' },
    { caption: 'Landed cost', say: 'Here is how it works. The goods in rupees, plus freight and insurance, make the assessable value. Basic customs duty is twenty percent of it, and the surcharge ten percent of the duty. The landed cost is the assessable value, plus the duty, plus the surcharge, plus the agent, port and other charges. The I G S T is not a cost: we take it back as input credit.', do: async (d) => {
      await d.goto(`/import/${imp}#imp-lines`);
      await d.scrollTo('[data-testid="imp-totals"]');
      await d.point('[data-testid="imp-totals"]', 4000);
    } },
    { caption: 'Per unit · suggested price', say: 'So four hundred square metres cost us about five thousand and fifty-nine rupees each. The suggested selling price adds the division\'s twenty percent margin, before G S T. These figures are now frozen.', do: async (d) => {
      const row = d.page.locator('table[data-testid="imp-lines"] tr').nth(1);
      await row.locator('td').nth(13).evaluate((el) => el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' }));
      await d.wait(800);
      await d.point(row.locator('td').nth(12), 1500);
      await d.point(row.locator('td').nth(13), 1500);
    } },
    { caption: 'Record IGST credit', say: 'When the I G S T paid on import is taken as input credit in the G S T return, record the date. Record I G S T credit.', do: async (d) => {
      const f = sec(d, '#imp-clearance').locator('form[action$="/igst-credit"]');
      await d.scrollTo(f);
      await d.point('[data-testid="imp-igst"]', 900);
      await f.locator('input[name="igst_credit_on"]').fill(today());
      await d.clickNav(f.getByRole('button', { name: 'Record IGST credit' }));
    } },
    { as: 'accounts', caption: 'Duty payment', say: 'Accounts records the duty payment made on the customs portal: the date and the challan or bank reference. Then the app can send the bills, the payments and the I G S T journal to Zoho Books.', do: async (d) => {
      await d.goto(`/import/${imp}#imp-clearance`);
      const f = sec(d, '#imp-clearance').locator('form[action$="/duty-payment"]');
      await d.scrollTo(f);
      await f.locator('input[name="duty_paid_on"]').fill(today());
      await d.type(f.locator('input[name="duty_bank_reference"]'), 'ICEGATE CIN TEST2611050012', { delay: 30 });
      await d.clickNav(f.getByRole('button', { name: 'Record duty payment' }));
      await d.point('[data-testid="imp-duty-paid"]', 1200);
    } },
    { as: 'imp', caption: 'Move on to In stock', say: 'Back to the Import Purchase Executive. The goods have reached our warehouse. Move on to In stock: this puts them into stock at their landed cost.', do: async (d) => {
      await d.goto(`/import/${imp}`);
      await d.point('[data-testid="imp-next"]', 900);
      await d.clickNav(d.page.getByRole('button', { name: 'Move on to In stock' }));
      await d.point('[data-testid="imp-stages"]', 1000);
    } },

    // ---------- 5. Stock ----------
    { card: 'chapter', title: 'In stock', subtitle: 'Stock and the reports.', say: 'Chapter five. Our stock.' },
    { goto: '/import/reports', caption: 'Imports & stock › Reports', say: 'The Reports page shows the stock on hand: quantity, value, average cost and suggested price. Stock is kept per product and valued at the weighted average landed cost, so a second import at another price blends in.', do: async (d) => {
      await d.point('table[data-testid="imp-stock"]', 3000);
    } },
    { caption: 'Duty and movements', say: 'Below are the landed cost per item, the duty and I G S T credit per bill of entry, and every stock movement. Stock never goes below zero: a sale that needs more than is on hand is refused.', do: async (d) => {
      await d.scrollTo('table[data-testid="imp-landed"]');
      await d.point('table[data-testid="imp-landed"]', 1200);
      await d.scrollTo('table[data-testid="imp-duty"]');
      await d.point('table[data-testid="imp-duty"]', 1200);
      await d.scrollTo('table[data-testid="imp-movements"]');
      await d.point('table[data-testid="imp-movements"]', 1200);
    } },

    // ---------- 6. Local sale ----------
    { card: 'chapter', title: 'The local sale', subtitle: 'Selling from stock, with GST.', say: 'Chapter six. Selling to a reseller in India.' },
    { as: 'sales', goto: '/orders/create', caption: 'Orders › New order', est: 12, say: 'We are the Distribution Sales Executive. In Import and Distribution, Orders, New order opens the local sale. Choose the client, enter its G S T I N, its state and the place of supply, the payment terms and the buyer\'s P O. Create local sale.', do: async (d) => {
      const f = d.page.locator('[data-testid="dom-create-form"]');
      await d.point('h1', 900);
      await d.select(f.locator('select[name="client_id"]'), String(client));
      await d.type(f.locator('input[name="buyer_gstin"]'), '29AAAAA0000B1ZW', { delay: 35 });
      await d.select(f.locator('select[name="buyer_state_code"]'), '29');
      await d.select(f.locator('select[name="place_of_supply"]'), '29');
      await d.type(f.locator('input[name="payment_terms_days"]'), '30');
      await d.type(f.locator('input[name="buyer_po_ref"]'), 'TEST-PO-118', { delay: 35 });
      await d.clickNav(f.getByRole('button', { name: 'Create local sale' }));
      sale = Number((d.page.url().match(/\/orders\/(\d+)/) || [])[1] || 0);
    } },
    { caption: 'A line from stock', est: 12, say: 'Each line is a product from stock, and the list shows how much is on hand. Then the description, the H S N code, the quantity, the unit and our rate in rupees. Add line.', do: async (d) => {
      const f = d.page.locator('[data-testid="dom-add-line"]');
      await d.scrollTo(f);
      await d.select(f.locator('select[name="product_id"]'), { index: 1 });
      await d.type(f.locator('input[name="description"]'), 'Carrara White Marble Slab, polished, 18 mm', { delay: 25 });
      await d.type(f.locator('input[name="hsn_code"]'), '68022110');
      await d.type(f.locator('input[name="quantity"]'), '150');
      await d.type(f.locator('input[name="unit"]'), 'SQM');
      await d.type(f.locator('input[name="rate_inr"]'), '6500');
      await d.clickNav(f.getByRole('button', { name: 'Add line' }));
    } },
    { caption: 'Same state: CGST + SGST', say: 'The buyer is in our own state, so the line carries C G S T and S G S T. The sale then follows the Domestic Trade steps: quotation and order confirmation.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-totals"]');
      await d.point('[data-testid="dom-totals"]', 2500);
    } },
    { card: 'chapter', title: 'Out of stock, onto the invoice', subtitle: 'Dispatch fixes the tax invoice.', say: 'Chapter seven. Taking the goods out of stock.', do: async () => { MOVES.toPurchase(); } },
    { caption: 'Stock check', say: 'The quotation and the order confirmation are done. There is no supplier P O in a local sale: at this step the app checks that the stock covers every line. It does. Move on to Dispatch.', do: async (d) => {
      await d.goto(`/orders/${sale}#dom-purchase`);
      await d.scrollTo('[data-testid="imp-sale-stock"]');
      await d.point('[data-testid="imp-sale-stock"]', 2000);
      await d.scrollTo('[data-testid="dom-next"]');
      await d.clickNav(d.page.getByRole('button', { name: 'Move on to Dispatch' }));
    } },
    { caption: 'Tax invoice IMP/26-27/…', say: 'The tax invoice number is fixed now, in the division\'s own series, starting with I M P. And at this moment the one hundred and fifty square metres leave our stock.', do: async (d) => {
      await d.scrollTo('#dom-dispatch');
      await d.point('[data-testid="dom-invoice-no"]', 2500);
    } },
    { card: 'chapter', title: 'Getting paid', subtitle: 'The buyer\'s payment.', say: 'Chapter eight. The buyer pays.', do: async () => { MOVES.toPayment(); } },
    { as: 'accounts', caption: 'Payments · bank reference', est: 10, say: 'The goods are dispatched with their e-way bill, and the tax invoice is sent. The buyer pays the full amount. Accounts records it: the date, the amount in rupees, and the U T R. Record payment.', do: async (d) => {
      await d.goto(`/orders/${sale}#dom-payments`);
      const f = sec(d, '#dom-payments').locator('form');
      await d.scrollTo(f);
      await f.locator('input[name="received_on"]').fill(today());
      await d.type(f.locator('input[name="amount_inr"]'), '1150500');
      await d.type(f.locator('input[name="bank_reference"]'), 'UTR TESTN2611190001', { delay: 30 });
      await d.clickNav(f.getByRole('button', { name: 'Record payment' }));
    } },
    { caption: 'Local sale receipt', say: 'Again the description is written for you: a local sale receipt, the amount, from which buyer, against which tax invoice and order, with the bank reference.', do: async (d) => {
      await d.scrollTo('table[data-testid="dom-payments"]');
      await d.point(d.page.locator('table[data-testid="dom-payments"] tr').nth(1).locator('td').last(), 3000);
    } },

    // ---------- 9. Closing the import ----------
    { card: 'chapter', title: 'Closed', subtitle: 'Pay the supplier, close the import.', say: 'Chapter nine. Closing the import.' },
    { as: 'imp', caption: 'Before moving on to Closed', say: 'Back on the import. It cannot close yet: the app lists what is missing. The supplier is still owed the seventy percent.', do: async (d) => {
      await d.goto(`/import/${imp}`);
      await d.point('[data-testid="imp-next"]', 2500);
    } },
    { as: 'accounts', caption: 'LC retirement', est: 12, say: 'Our bank pays the supplier under the letter of credit. Accounts records it as an L C retirement, with the rate and the swift reference. Its description names the L C as well.', do: async (d) => {
      await d.goto(`/import/${imp}#imp-payments`);
      const f = sec(d, '#imp-payments').locator('form');
      await d.scrollTo(f);
      await d.select(f.locator('select[name="kind"]'), 'lc');
      await f.locator('input[name="paid_on"]').fill(today());
      await d.type(f.locator('input[name="amount_fc"]'), '11760.00');
      await d.type(f.locator('input[name="fx_rate"]'), '90.55');
      await d.type(f.locator('input[name="bank_reference"]'), 'TESTSWIFT2611002', { delay: 35 });
      await d.clickNav(f.getByRole('button', { name: 'Record payment' }));
      await d.scrollTo('table[data-testid="imp-payments"]');
      await d.point(d.page.locator('table[data-testid="imp-payments"] tr').nth(2).locator('td').last(), 1500);
    } },
    { as: 'imp', caption: 'Move on to Closed', say: 'Paid in full, and the I G S T credit is taken. Move on to Closed.', do: async (d) => {
      await d.goto(`/import/${imp}`);
      await d.clickNav(d.page.getByRole('button', { name: 'Move on to Closed' }));
      await d.point('[data-testid="imp-show"] h1', 1500);
    } },
    { goto: '/import/reports', caption: 'Stock after the sale', say: 'On the Reports page, two hundred and fifty square metres are left in stock, and the movements show the receipt from the import and the issue to the local sale.', do: async (d) => {
      await d.point('table[data-testid="imp-stock"]', 1800);
      await d.scrollTo('table[data-testid="imp-movements"]');
      await d.point('table[data-testid="imp-movements"]', 1800);
    } },

    { card: 'list', title: 'Import & Distribution', items: [
      'Ordered: import PO, LC, advance with its SWIFT / UTR reference.',
      'Shipped: BL, then bill of entry, customs rate and charges.',
      'Cleared: landed cost = assessable value + BCD + SWS + charges.',
      'IGST is input credit, not cost. Stock at weighted average, never below zero.',
      'Local sale from stock: tax invoice IMP/…, receipt with its bank reference.',
    ], next: 'E03 · Merchant Trade', say: 'Let us recap. Ordered: the import P O, the letter of credit, and the advance with its swift or U T R reference. Shipped: the bill of lading, then the bill of entry, the customs rate and the charges. Cleared: the landed cost is the assessable value plus basic duty, surcharge and charges. I G S T is input credit, not cost. Stock is valued at the weighted average and never goes below zero. And the local sale takes goods from stock under its own I M P tax invoice. Next: Merchant Trade.' },
  ]; },
};
