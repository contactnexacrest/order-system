'use strict';
// E01 — Domestic Trade (DOM), Group E · Divisions. Owner request 19 Nov 2026.
// Domestic Trade is a planned division (D-257 Phase 2): off until the MD activates it in Settings › Divisions.
// On camera: the MD looks at the division page (modules, its own settings, GST rates, "Before activating") and
// activates it in the tutorial database; then a Domestic Sales Executive walks one domestic order through every
// stage: create (/domestic/orders/create), lines with GST, quotation, order confirmation, purchase, dispatch (the
// tax invoice number is fixed, e-way bill), tax invoice, payment with its bank reference (D-212 description), closed.
// Set-up (inside the snapshot the runner restores): the division's departments, one staff member moved into it,
// its order number format, and a TEST reseller buyer. Written 19 Nov 2026 from views/divisions/show.php,
// views/domestic/create.php + show.php, DomesticOrderService and DivisionRepository::preflight.

let demo;
let dayIso = '';        // the app's own date (PHP), so the dispatch and receipt dates match the invoice date
let orderId = 0;
const sec = (d, id) => d.page.locator(id);
const BUYER = 'TEST Bengaluru Stone Traders';
const BUYER_GSTIN = '29AAACT0000A1ZB';   // placeholder GSTIN (valid check character, Karnataka)
const DOM_EMAIL = 'domestic.sales@email.com';

const ACTORS = {
  md: { label: 'Managing Director', sub: 'NexaCrest staff · md@email.com', initials: 'MD', color: '#C9962B' },
  dom: { label: 'Domestic Sales Executive', sub: 'Domestic Trade · ' + DOM_EMAIL, initials: 'DS', color: '#2D7D56' },
};

// "Move on to …" (the stage button), then point at the new stage in the strip.
async function moveOn(d, label) {
  await d.clickNav(sec(d, '[data-testid="dom-next"]').getByRole('button', { name: `Move on to ${label}` }));
  await d.point('[data-testid="dom-stages"] li.current', 900);
}
// A "Generate …" button in the Documents section.
async function generate(d, label) {
  await d.scrollTo('#dom-documents');
  await d.clickNav(sec(d, '#dom-documents').getByRole('button', { name: `Generate ${label}` }));
  await d.point('[data-testid="dom-documents"] tr:nth-child(2)', 900); // newest first
}

module.exports = {
  code: 'E01',
  file: 'E01-Divisions-Domestic-Trade.mp4',
  group: 'Group E · Divisions',
  title: 'Domestic Trade',
  subtitle: 'Selling to resellers in India, in rupees, with GST tax invoices.',
  minutes: '10',
  restoreDb: true,

  async setup(dm) {
    demo = dm;
    dayIso = demo.php("echo date('Y-m-d');").split('\n').pop();
    const md = Number(demo.sql("SELECT id FROM users WHERE email = 'md@email.com'"));
    // The staff member who will work in Domestic Trade (role Export Executive: orders, documents, payments).
    const hash = demo.php(`echo password_hash(${JSON.stringify(demo.STAFF.md.password)}, PASSWORD_DEFAULT);`).split('\n').pop();
    demo.sql(`INSERT INTO users (name, email, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
      VALUES ('Domestic Sales Executive', ${demo.q(DOM_EMAIL)}, ${demo.q(hash)}, (SELECT id FROM roles WHERE name = 'Export Executive'), 1, 0, 0, 0)`);
    demo.STAFF.dom = { email: DOM_EMAIL, password: demo.STAFF.md.password };
    // The MD's set-up before activating (SOP 29): its departments, its first staff member, its number format; a TEST reseller.
    const out = demo.php(`
      use App\\Repositories\\DivisionRepository as DR;
      $pdo = App\\Config\\Database::connection();
      $dom = (int) $pdo->query("SELECT id FROM divisions WHERE code = 'DOM'")->fetchColumn();
      DR::createStarterDepartments($dom);
      $u = (int) $pdo->query("SELECT id FROM users WHERE email = '${DOM_EMAIL}'")->fetchColumn();
      DR::setUserDivision($u, $dom);
      DR::setSetting($dom, 'company', 'order_ref_format', 'NC/{DIV}/{YY}/{DDMM}{NNN}', ${md});
      $c = App\\Repositories\\ClientRepository::create(['company_legal_name' => ${JSON.stringify(BUYER)},
        'billing_address' => 'Plot 12, Sample Industrial Area, Bengaluru 560000', 'consignee_name' => 'SAME', 'consignee_address' => 'SAME',
        'contact_person' => 'Purchase Manager', 'email' => 'purchase@bengaluru-stone.example', 'phone' => '+91-0000000000',
        'country_of_destination' => 'India', 'coo_type' => 'Not applicable', 'notify_party' => null], ${md}, App\\Services\\ReferenceNumberService::generateClientUniqueNumber());
      App\\Services\\DivisionService::linkClient($c, $dom);
      echo $dom . '|' . $c;`);
    const [divisionId] = out.split('\n').pop().split('|').map(Number);
    // Only the role logins in the drop-downs.
    demo.sql("UPDATE users SET is_active = 0 WHERE email NOT LIKE '%@email.com'");
    const actors = {
      md: { ...ACTORS.md, cookies: await demo.staffCookies('md') },
      dom: { ...ACTORS.dom, cookies: await demo.staffCookies('dom') },
    };
    return { actors, divisionId };
  },

  steps: ({ divisionId = 2 } = {}) => [
    { card: 'title', say: 'This video is about the Domestic Trade division: selling stone to resellers in India, in rupees, with G S T tax invoices. It is for the staff who will run this division once the Managing Director switches it on.' },
    { card: 'list', kicker: 'In this video', title: 'Domestic Trade · D O M', items: [
      'A planned division: off until the MD activates it in Settings › Divisions.',
      'One order, eight stages: enquiry to closed.',
      'Same state: CGST + SGST. Another state: IGST.',
      'Four documents: quotation, order confirmation, GST tax invoice, delivery challan.',
    ], say: 'Domestic Trade is a planned division. It is built, but nobody sees it until the M D activates it in Settings, Divisions. A domestic order goes through eight stages, from enquiry to closed. G S T is split into C G S T and S G S T inside one state, or charged as I G S T between states. And the order has four documents of its own: a quotation, an order confirmation, a G S T tax invoice and a delivery challan.' },

    // ---------- 1. Switching it on ----------
    { card: 'chapter', title: 'Switching it on', subtitle: 'Settings › Divisions (Managing Director).', say: 'Chapter one. Switching it on.' },
    { as: 'md', goto: '/admin/divisions', caption: 'Settings › Divisions', say: 'We are the Managing Director, in Settings, Divisions. Export is active. Domestic Trade, Merchant Trade and Import and Distribution are planned, so they are greyed out. Open Domestic Trade.', do: async (d) => {
      await d.point('[data-testid="divisions-table"]', 1500);
      await d.clickNav(sec(d, '[data-testid="divisions-table"] tr').filter({ hasText: 'Domestic Trade' }).getByRole('link', { name: 'Open' }));
    } },
    { caption: 'Modules', say: 'At the top: its name, its code D O M, and its order number format. The modules switch whole parts of the app on or off for this division. Quotation and order confirmation, supplier purchase, associates and team incentive are on. Export shipping, L U T, export benefits, F I R C and E C G C are off: a domestic sale does not need them.', do: async (d) => {
      await d.point('[data-testid="division-number-format"]', 1500);
      await d.scrollTo(d.page.getByRole('heading', { name: 'Modules' }));
      await d.point(d.page.locator('form[action$="/modules"]'), 2500);
    } },
    { caption: 'This division\'s own settings', say: 'Below are the division\'s own settings. The G S T rate used when a product has none, eighteen percent. The E way bill threshold, fifty thousand rupees. E invoicing, off. Payment terms, thirty days. Prices are worked out in rupees. These are standard Indian values to start with, and are to be confirmed by the C A before the first sale.', do: async (d) => {
      await d.scrollTo('[data-testid="division-settings"]');
      await d.point('[data-testid="division-settings"]', 3000);
    } },
    { goto: '/admin/gst-rates', caption: 'Settings › Lists › GST rates', say: 'The G S T rates are in Settings, Lists, G S T rates. Each H S N code gets its rate. A line takes the rate of the longest H S N start that matches. Worked stone, H S N 6 8 0 2, is at eighteen percent.', do: async (d) => { await d.point('[data-testid="gst-rates"]', 2500); } },
    { goto: `/admin/divisions/${divisionId}`, caption: 'Before activating', say: 'Back on the division. Create its departments makes Domestic Sales, Domestic Purchase and Domestic Dispatch. Move to Domestic Trade places a staff member in the division. Then the checklist, Before activating: departments, staff, number format, modules, the company G S T I N, the G S T rates and the E way bill settings. All are ticked here.', do: async (d) => {
      await d.scrollTo('[data-testid="division-setup"]');
      await d.point('[data-testid="division-setup"]', 1800);
      await d.scrollTo('[data-testid="division-preflight"] .checklist');
      await d.point('[data-testid="division-preflight"] .checklist', 2500);
    } },
    { caption: 'Activate', say: 'Tick the box to confirm, and press Activate Domestic Trade. In this video we switch it on in a test copy of the app.', do: async (d) => {
      await d.check(sec(d, '[data-testid="division-preflight"] input[name="confirm"]'));
      await d.clickNav(sec(d, '[data-testid="division-preflight"]').getByRole('button', { name: 'Activate Domestic Trade' }));
      await d.point('.alert', 1500);
    } },
    { caption: 'The division switcher', say: 'Domestic Trade is now active. Company level people, like the M D, now see a division switcher in the menu, with All divisions, and the reports get a division filter. Staff in Domestic Trade see only its orders and clients.', do: async (d) => { await d.point('[data-testid="division-switcher"]', 2500); } },

    // ---------- 2. A new domestic order ----------
    { card: 'chapter', title: 'A new domestic order', subtitle: 'The buyer, its GSTIN and the place of supply.', say: 'Chapter two. A new domestic order.' },
    { as: 'dom', goto: '/orders/create', caption: 'Orders › New order', say: 'We are the Domestic Sales Executive. Because we work in Domestic Trade, New order opens the domestic form. A reseller in Bengaluru has asked for polished granite slabs.', do: async (d) => { await d.point('h1', 1200); await d.point('[data-testid="dom-create-form"]', 1200); } },
    { caption: 'The buyer and its GST registration', say: 'Choose the client. Enter the buyer\'s G S T I N: a reseller buys on its G S T registration, and it is needed before the order confirmation. The buyer\'s state is Karnataka. The place of supply is the state the goods are delivered to, also Karnataka.', do: async (d) => {
      const f = sec(d, '[data-testid="dom-create-form"]');
      await d.select(f.locator('select[name="client_id"]'), { label: BUYER });
      await d.type(f.locator('input[name="buyer_gstin"]'), BUYER_GSTIN);
      await d.select(f.locator('select[name="buyer_state_code"]'), '29');
      await d.select(f.locator('select[name="place_of_supply"]'), '29');
    } },
    { caption: 'Delivery, payment terms, PO', say: 'Add the delivery address, the payment terms in days, and the buyer\'s P O number. Create domestic order.', do: async (d) => {
      const f = sec(d, '[data-testid="dom-create-form"]');
      await d.type(f.locator('textarea[name="delivery_address"]'), 'Plot 12, Sample Industrial Area, Bengaluru 560000', { delay: 25 });
      await d.type(f.locator('input[name="payment_terms_days"]'), '30');
      await d.type(f.locator('input[name="buyer_po_ref"]'), 'PO/2026/118');
      await d.clickNav(f.getByRole('button', { name: 'Create domestic order' }));
      orderId = Number(new URL(d.page.url()).pathname.split('/').pop());
    } },
    { caption: 'The domestic order page', say: 'This is the domestic order page. Its number carries the division code, D O M. The strip shows the eight stages, and the line under the title says the tax: same state, C G S T plus S G S T. The box below says what is still needed to move on: at least one line.', do: async (d) => {
      await d.point('h1', 1500);
      await d.point('[data-testid="dom-stages"]', 2000);
      await d.point('[data-testid="dom-next"]', 1500);
    } },
    { caption: 'Add a line', say: 'Add the line: what is sold, its H S N code, the quantity and unit, and the rate in rupees. Leave G S T percent blank: it comes from the G S T rates. Add line.', do: async (d) => {
      const f = sec(d, '[data-testid="dom-add-line"]');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="description"]'), 'Black granite slabs, polished, 18 mm', { delay: 30 });
      await d.type(f.locator('input[name="hsn_code"]'), '68029300');
      await d.type(f.locator('input[name="quantity"]'), '120');
      await d.type(f.locator('input[name="unit"]'), 'SQM');
      await d.type(f.locator('input[name="rate_inr"]'), '1450');
      await d.point(f.locator('input[name="gst_rate_pct"]'), 800);
      await d.clickNav(f.getByRole('button', { name: 'Add line' }));
    } },
    { caption: 'GST on the line', say: 'The line took eighteen percent. Taxable value: one lakh seventy four thousand rupees. Because the buyer is in our own state, the tax is split in two halves: C G S T fifteen thousand six hundred and sixty, and S G S T the same. Total: two lakh five thousand three hundred and twenty rupees.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-lines"]');
      await d.point('[data-testid="dom-lines"]', 2000);
      await d.point('[data-testid="dom-totals"]', 3000);
    } },
    { card: 'list', kicker: 'GST', title: 'CGST + SGST or IGST', items: [
      'NexaCrest\'s GST state: the first two digits of its GSTIN (29 = Karnataka).',
      'Place of supply in the same state: CGST + SGST, each half the rate.',
      'Place of supply in another state: IGST, the full rate.',
      'Tax rounded to the paisa per line; the total to the rupee, with a Round off line.',
    ], say: 'How the app chooses. Our own G S T state is the first two digits of our G S T I N: 29, Karnataka. When the place of supply is in the same state, the tax is C G S T plus S G S T, each half the rate. When it is in another state, it is I G S T at the full rate. Each line\'s tax is rounded to the paisa, and the invoice total to the rupee, with a round off line.' },

    // ---------- 3. Quotation and order confirmation ----------
    { card: 'chapter', title: 'Quotation and confirmation', subtitle: 'Two documents, one stage each.', say: 'Chapter three. Quotation and order confirmation.' },
    { caption: 'Move on to Quotation', say: 'Nothing stops us now. Move on to Quotation, and generate the quotation. It gets its own number, D O M slash Q T, and is listed under Documents.', do: async (d) => {
      await moveOn(d, 'Quotation');
      await generate(d, 'quotation');
    } },
    { caption: 'Move on to Order confirmation', say: 'The buyer accepts. Move on to Order confirmation. The buyer\'s G S T I N is already there, so we only need the document: generate the order confirmation. From this stage on, payments can be recorded too.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-next"]');
      await moveOn(d, 'Order confirmation');
      await generate(d, 'order confirmation');
      await d.scrollTo('#dom-payments');
      await d.point('#dom-payments h2', 1200);
    } },

    // ---------- 4. Purchase and dispatch ----------
    { card: 'chapter', title: 'Purchase and dispatch', subtitle: 'The tax invoice number and the e-way bill.', say: 'Chapter four. Purchase and dispatch.' },
    { caption: 'Purchase', say: 'Move on to Purchase. We buy the slabs from a supplier: record the supplier and the supplier P O number, and save the purchase.', do: async (d) => {
      await moveOn(d, 'Purchase');
      const f = d.page.locator('form[action$="/purchase"]');
      await d.scrollTo(f);
      await d.type(f.locator('input[name="supplier_name"]'), 'TEST Granite Quarry');
      await d.type(f.locator('input[name="supplier_po_ref"]'), 'NC/SPO/0045');
      await d.clickNav(f.getByRole('button', { name: 'Save purchase' }));
    } },
    { caption: 'Move on to Dispatch', say: 'Now the important step. Moving on to Dispatch fixes the tax invoice number and its totals. G S T law wants the invoice issued when the goods leave, and the E way bill needs its number. After this, the lines can no longer change. Move on to Dispatch.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-next"]');
      await d.point(sec(d, '[data-testid="dom-next"] small'), 2500);
      await moveOn(d, 'Dispatch');
    } },
    { caption: 'The tax invoice number', say: 'Here it is: tax invoice D O M slash 26 dash 27 slash 0 0 0 1. The rule: the division code, the financial year, and a running number. It is never longer than sixteen characters, and a new series starts every financial year. Our consignment is above fifty thousand rupees, so an E way bill is needed.', do: async (d) => {
      await d.scrollTo('#dom-dispatch');
      await d.point('[data-testid="dom-invoice-no"]', 2500);
      await d.point(sec(d, '#dom-dispatch > p').first(), 2000);
    } },
    { caption: 'Dispatch and e-way bill', say: 'Record the dispatch: the date, the transporter, the vehicle number, the L R number and the distance. Make the E way bill on the government portal, then enter its twelve digit number and date here. Save dispatch.', do: async (d) => {
      const f = d.page.locator('form[action$="/dispatch"]');
      await f.locator('input[name="dispatch_date"]').fill(dayIso);
      await d.type(f.locator('input[name="transporter_name"]'), 'TEST Road Carriers');
      await d.type(f.locator('input[name="vehicle_no"]'), 'KA01AB1234');
      await d.type(f.locator('input[name="lr_no"]'), 'LR-55821');
      await d.type(f.locator('input[name="distance_km"]'), '25');
      await d.type(f.locator('input[name="eway_bill_no"]'), '331012345678');
      await f.locator('input[name="eway_bill_date"]').fill(dayIso);
      await d.clickNav(f.getByRole('button', { name: 'Save dispatch' }));
    } },
    { caption: 'GST tax invoice and delivery challan', say: 'Generate the G S T tax invoice, and the delivery challan that travels with the goods. The tax invoice shows the H S N summary, the tax split, the total in words in lakh and crore, the transport and E way bill details, and our bank account.', do: async (d) => {
      await generate(d, 'GST tax invoice');
      await generate(d, 'delivery challan');
      await d.point('[data-testid="dom-documents"]', 1500);
    } },

    // ---------- 5. Invoice, payment, closed ----------
    { card: 'chapter', title: 'Invoice, payment, closed', subtitle: 'The receipt with its bank reference.', say: 'Chapter five. The invoice, the payment, and closing.' },
    { caption: 'Move on to Tax invoice', say: 'Move on to Tax invoice. Send the invoice to the buyer, then mark it as sent, with the date. When Zoho Books is switched on, Finance sends the invoice to Books from here, as a G S T invoice.', do: async (d) => {
      await moveOn(d, 'Tax invoice');
      const f = d.page.locator('form[action$="/invoice-sent"]');
      await d.scrollTo(f);
      await d.clickNav(f.getByRole('button', { name: 'Mark as sent' }));
    } },
    { caption: 'Move on to Payment', say: 'Move on to Payment. The buyer pays by bank transfer. Record the receipt: the date the money reached the bank, the amount, and the bank reference, the U T R. The bank reference is required, and one that is already recorded is refused. Record payment.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-next"]');
      await moveOn(d, 'Payment');
      const f = d.page.locator('form[action$="/payments"]');
      await d.scrollTo(f);
      await f.locator('input[name="received_on"]').fill(dayIso);
      await d.type(f.locator('input[name="amount_inr"]'), '205320');
      await d.type(f.locator('input[name="bank_reference"]'), 'UTR SAMPLE0000321');
      await d.clickNav(f.getByRole('button', { name: 'Record payment' }));
    } },
    { caption: 'The receipt\'s description', say: 'The app writes the receipt\'s description itself, from the facts: a domestic sale receipt, the amount, the buyer, the tax invoice it settles, the order, and the bank reference. Your note is added to it, never instead of it. A receipt can never take the total above what the invoice still owes.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-payments"]');
      await d.point(sec(d, '[data-testid="dom-payments"] tr').nth(1).locator('td').last(), 4000);
    } },
    { caption: 'Closed', say: 'The invoice is fully paid, so nothing stops us. Move on to Closed. The order is complete and locked.', do: async (d) => {
      await d.scrollTo('[data-testid="dom-next"]');
      await moveOn(d, 'Closed');
      await d.point('[data-testid="dom-stages"]', 2000);
    } },

    { card: 'list', kicker: 'Good to know', title: 'Domestic Trade', items: [
      'The quotation, order confirmation, tax invoice and challan show prices: only people who may see selling prices open them.',
      'E-way bills and e-invoices are made on the government portals; their numbers are entered here.',
      'Zoho Books: the tax invoice as a GST invoice, each receipt applied to it (Finance).',
      'GST rates, e-way bill threshold and e-invoicing: to be confirmed by the CA.',
    ], say: 'Good to know. The four domestic documents show prices, so only people who may see selling prices can open them. E way bills and e invoices are made on the government portals, and their numbers are entered here. When Zoho Books is on, Finance sends the tax invoice and each receipt to Books. And the G S T rates, the E way bill threshold and e invoicing are to be confirmed by the C A before the first sale.' },
    { card: 'list', kicker: 'Recap', title: 'Domestic Trade', items: [
      'MD: check Before activating, then Activate in Settings › Divisions.',
      'New order: buyer\'s GSTIN, state and place of supply; lines with HSN and rate in ₹.',
      'Quotation, then order confirmation; then the supplier PO.',
      'Dispatch fixes the tax invoice number; e-way bill above ₹50,000.',
      'Mark the invoice sent; record each receipt with its bank reference; close.',
    ], next: 'E02 · Import and Distribution', say: 'Let us recap. The M D works through Before activating, and activates the division. A new domestic order needs the buyer\'s G S T I N, state and place of supply, and lines with H S N and a rupee rate. Generate the quotation, then the order confirmation, and record the supplier P O. Moving on to dispatch fixes the tax invoice number; above fifty thousand rupees, enter the E way bill. Mark the invoice as sent, record each receipt with its bank reference, and close the order. Next: Import and Distribution.' },
  ],
};
