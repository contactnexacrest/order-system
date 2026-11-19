'use strict';
// A01 Part 2 — Everything NexaCrest can do (Group A, Everyone). Owner request 18 Nov 2026: one general video
// on what the application is and everything it has. Part 1 (A01-1) covers the business, the three doors,
// departments, the 9 stages / 19 steps, Standard vs Kanban and help; this part tours every other area of
// the staff app as the Managing Director (who sees everything), on the app's own Sample Data.
// Written 18 Nov 2026 from the app's pages (D-378 … D-392). Narration says only what the screens show.

const { ACTORS, cleanStage } = require('./_overview');

const ASSOC = 'associate.full@email.com';
const h = (d, name) => d.page.getByRole('heading', { name }).first();
const link = (d, name) => d.page.getByRole('link', { name }).first();
const text = (d, t) => d.page.getByText(t).first();

module.exports = {
  code: 'A01-2',
  file: 'A01-2-Everyone-Everything-NexaCrest-Can-Do.mp4',
  group: 'Group A · Everyone',
  title: 'Everything NexaCrest Can Do',
  subtitle: 'Part 2 of 2 · A tour of every area of the app, from clients to backups.',
  minutes: '10',
  restoreDb: true,

  async setup(demo) {
    cleanStage(demo);
    // One associate abroad and one priced product, so the Associates and Products pages are not empty.
    demo.associate({ email: ASSOC, name: 'Demo Associate', level: 'full', basedIn: 'abroad', country: 'USA', city: 'New York', currency: 'USD' });
    const md = Number(demo.sql("SELECT id FROM users WHERE email = 'md@email.com'"));
    demo.php(`
      use App\\Repositories\\ProductRepository; use App\\Repositories\\PricingRepository; use App\\Repositories\\ProductSupplierRepository;
      $p = ProductRepository::create(['name' => 'Black Galaxy Granite Slab', 'specifications' => 'Granite slabs', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], ${md});
      PricingRepository::saveProductOverrides($p, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
        'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
      $s = ProductSupplierRepository::create(['product_id' => $p, 'supplier_name' => 'TEST Demo Quarry', 'location' => 'Demo location', 'contact_person' => null,
        'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 3280.0, 'factory_cost' => null, 'transportation_cost' => null,
        'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 1, 'notes' => null], ${md});
      PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
      $sup = App\\Repositories\\SupplierRepository::create(['supplier_legal_name' => 'TEST Demo Quarry', 'city' => 'Demo city', 'state' => 'Demo state', 'types' => ['Quarry Owner']], ${md});
      ProductSupplierRepository::linkSupplier($s, $sup, 'TEST Demo Quarry');
      App\\Services\\SupplierOffers::setConfiguredBase($p, 3280.0, null, 'SQM', ${md});
      echo $p;`);
    return { actors: { md: { ...ACTORS.md, cookies: await demo.staffCookies('md') } } };
  },

  steps: () => [
    { card: 'title', say: 'Welcome to part two. In part one you saw what NexaCrest does, the three doors into the app, and how an order moves through nine stages. Now we take a tour of everything else the app can do. We are logged in as the Managing Director, who can see every corner, so you will see more here than your own role shows. Let us go.' },

    // ---------- 1. Approvals ----------
    { card: 'chapter', title: 'Approvals in one place', subtitle: 'Everything waiting for you, on one page.', say: 'Stop one. Approvals.' },
    { as: 'md', goto: '/approvals', caption: 'Approvals', say: 'Every approval in the company lands on this one page. Prices, payment terms, documents, buyer e-mails, bank details, and other approvals, each on its own tab, with a count. You only ever see what you are allowed to approve.', do: async (d) => { await d.point('h1', 900); await d.point('.filter-chip', 1500); } },
    { caption: 'One click to the right place', say: 'Each row says what is waiting, for which order or person, and since when. The button opens the exact place where you approve it, and the row disappears once it is approved or refused. Here, a buyer\'s payment terms wait for the Managing Director.', do: async (d) => { await d.point('table.approvals-table tbody tr', 1500); } },

    // ---------- 2. Clients and agreements ----------
    { card: 'chapter', title: 'Clients and agreements', subtitle: 'Every buyer, every signed agreement.', say: 'Stop two. Clients and agreements.' },
    { goto: '/clients', caption: 'Clients', say: 'Clients lists every buyer. Each one carries one Buyer Inquiry Reference for the whole relationship, and every order and document for that buyer reuses it.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, '[SAMPLE] Regal Memorials'), 1400); } },
    { caption: 'The public quotation form', say: 'Up here is the link to the public quotation form. Send it to a new lead, and they type in their own details. Their request then waits for you under Orders, Quotation requests, where one click turns it into a client.', do: async (d) => { await d.point(text(d, 'Public Quotation Form'), 1800); } },
    { goto: '/agreements', caption: 'Clients › Agreements', say: 'Agreements keeps every signed agreement in one register: with clients, suppliers, logistics partners, associates and staff, with the signed copy and its dates. The app sends a reminder thirty days and seven days before an agreement expires, and on the day itself.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, 'Expiring within'), 1500); } },

    // ---------- 3. Associates ----------
    { card: 'chapter', title: 'Associates and commission', subtitle: 'Outside agents, their clients and their pay.', say: 'Stop three. Associates.' },
    { goto: '/associates', caption: 'Associates', say: 'Associates are outside agents who introduce buyers to us under a signed agreement. This page lists them all, with their status, where they are based, their personal code, their clients, their commission rule and whether their bank details are verified.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, 'Demo Associate'), 1500); } },
    { caption: 'Registrations and price requests', say: 'Along the menu bar: new registrations waiting for review, price requests from associates, commission levels, payouts, the TDS register, access levels, and a report.', do: async (d) => { await d.point('.section-menu, nav.section-nav, .subnav', 1800); } },
    { goto: '/associates/ladder', caption: 'Commission settings', say: 'Commission settings decide when commission is paid. For example: thirty days after the buyer has paid in full, in a monthly payment run on the last day of the month, moved to the working day before if that day is a Sunday or a holiday. Every change here needs a reason, and is kept in the audit log.', do: async (d) => { await d.point('h1', 900); await d.point(d.page.locator('legend').filter({ hasText: /When commission is paid/i }).first(), 1500); } },
    { goto: '/incentives/payouts', caption: 'Incentive payouts', say: 'Incentive payouts lists what is due, to staff and to associates, for each payout run. For associates, the app works out the tax deducted at source, and for associates abroad it lists the forms that must be on file before anyone pays.', do: async (d) => { await d.point('h1', 1800); } },

    // ---------- 4. Products and pricing ----------
    { card: 'chapter', title: 'Products and pricing', subtitle: 'The catalogue, and how a price is worked out.', say: 'Stop four. Products and pricing.' },
    { goto: '/products', caption: 'Products', say: 'The product catalogue has one row per product: its HS code, its unit, its suppliers and their prices in rupees, and the selling prices in US dollars that come out of them. These prices are internal. Buyers never see them, and an associate only ever sees their own price list.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, 'Black Galaxy Granite Slab'), 1500); } },
    { caption: 'Today\'s exchange rate', say: 'Look at this line: the pricing exchange rate. Every rupee cost is turned into dollars at today\'s rate, which the Managing Director keeps a little below the market rate on purpose, so our prices stay safe if the rupee gets stronger before the buyer pays.', do: async (d) => { await d.point(text(d, /Pricing rate/), 2000); } },
    { caption: 'Cost, minimum, lowest, quote', say: 'For each product you see the real cost, the minimum price that we never go below, the lowest price staff may offer, and the normal quote. Sales sees the selling prices. Only management sees the cost.', do: async (d) => { await d.point(d.page.locator('th').filter({ hasText: /Min price/ }), 1200); await d.point(d.page.locator('th').filter({ hasText: /Staff quote/ }), 1200); } },
    { goto: '/pricing/specifications', caption: 'What changes the price', say: 'And here is what changes the price: grade, thickness, surface finish, size, edge work, quantity, packing and treatment. Each option adds a percentage or an amount to the cost, and the margin is then added as usual, so every option keeps our margin.', do: async (d) => { await d.point(d.page.locator('table').first(), 2200); } },

    // ---------- 5. Procurement and logistics ----------
    { card: 'chapter', title: 'Procurement and logistics', subtitle: 'Buying the goods, and moving them.', say: 'Stop five. Procurement and logistics.' },
    { goto: '/procurement', caption: 'Procurement', say: 'When Sales confirms an order, it raises a purchase requirement. Procurement lists them here, earliest need first. Open one to record the suppliers\' quotes, compare them side by side, award the purchase, get it approved within the approval limits, and finally record the receipt of the goods and the quality check.', do: async (d) => { await d.point('h1', 1000); await d.point('select[name="status"], select', 1500); } },
    { goto: '/suppliers', caption: 'Suppliers', say: 'Suppliers is the directory of quarries, factories and traders, with the products each one offers and the price they quoted. A supplier\'s bank details are checked before we ever pay them.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, 'TEST Demo Quarry'), 1500); } },
    { goto: '/logistics-partners', caption: 'Logistics partners', say: 'Logistics partners are the customs house agents, the transport companies and the freight forwarders. One company can be tagged for more than one service, so nobody enters it twice.', do: async (d) => { await d.point('h1', 1800); } },

    // ---------- 6. Finance ----------
    { card: 'chapter', title: 'Finance and accounts', subtitle: 'Money in, money out, and the books.', say: 'Stop six. Finance and accounts.' },
    { goto: '/ca', caption: 'CA / Accounting', say: 'This is the accounting area, for Finance and for our chartered accountant. It is kept separate from the order pipeline, so nothing here can move an order\'s stage.', do: async (d) => { await d.point('h1', 1500); } },
    { caption: 'Revenue and benefits', say: 'Revenue reports by financial or calendar year. Export benefits claimed from the government, linked to each order. GST refunds under the letter of undertaking. And the CAFIN export, the internal cost sheet for a whole year, which never goes to a buyer.', do: async (d) => { await d.point(h(d, /Revenue & Benefits/), 2000); } },
    { caption: 'Expenses, bank and year lock', say: 'Every expense with its tax deducted at source, and a summary of what is payable. Bank statements uploaded and matched line by line. And once a financial year is fully reconciled, it can be locked.', do: async (d) => { await d.point(h(d, /Expenses & TDS/), 1300); await d.point(h(d, /Bank & Reconciliation/), 1300); } },
    { caption: 'INR Settlement Register', say: 'The INR settlement register lists every buyer payment that has cleared: the foreign amount, the rupees actually credited, the exchange gain or loss, and the bank\'s proof of realisation.', do: async (d) => { await d.point(h(d, /INR Settlement Register/), 1800); } },
    { goto: '/ca/zoho-sync', caption: 'Zoho Books sync', say: 'And the app talks to Zoho Books, our accounting software. Approved invoices, buyer payments, supplier bills, payments to suppliers, expenses and export benefits are sent across, and expenses entered in Zoho are brought back. Only records from the go-live date on are sent. A meter counts the calls, so we never go over Zoho\'s daily limit.', do: async (d) => { await d.point('h1', 1000); await d.point(text(d, /Zoho calls today/), 1800); } },

    // ---------- 7. Reports ----------
    { card: 'chapter', title: 'Reports and the cockpit', subtitle: 'See how the company is doing.', say: 'Stop seven. Reports.' },
    { goto: '/reports', caption: 'Reports', say: 'Reports are grouped into sections. Sales and orders: an aggregate report you can filter and save, per-client reports, sales performance, trends, the conversion rate, and product sales. Payments and receivables, including who is overdue and which payments still lack their bank proof. Disputes and amendments. Freight, suppliers, people, incentives, finance and management. Each person sees only the reports their role allows.', do: async (d) => { await d.point('h1', 900); for (const n of [/Sales & orders/, /Payments & receivables/, /Finance & CA/, /Management/]) await d.point(h(d, n), 900); } },
    { goto: '/cockpit', caption: 'Management cockpit', say: 'The management cockpit shows open work per department: open tasks, overdue tasks, escalations, and orders without an owner, together with any supplier who is running late. Counts only, no money.', do: async (d) => { await d.point(h(d, /Management cockpit/), 1000); await d.point(d.page.locator('table').filter({ hasText: 'Open tasks' }).first(), 1500); } },
    { goto: '/sales-targets', caption: 'Sales targets', say: 'Sales targets set a target in containers for each salesperson, each quarter, and follow the progress: orders confirmed, orders shipped, and new buyers won. A small calculator helps work out a fair target. Associates are credited too, but they never see targets.', do: async (d) => { await d.point('h1', 1800); } },
    { goto: '/audit-log', caption: 'Audit log', say: 'And behind everything, the audit log. Every action, by every person, with the time. Nobody, not even the Admin, can edit or delete a line here.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, /Immutable/), 1500); } },

    // ---------- 8. Settings and safety ----------
    { card: 'chapter', title: 'Settings and safety', subtitle: 'How the app is set up, and kept safe.', say: 'Last stop. Settings, and keeping things safe.' },
    { goto: '/admin/setup-checklist', caption: 'Settings › Setup checklist', say: 'For the Admin, the setup checklist lists what a new installation still needs before real use: company details, bank details, the e-mail server, backups, pricing confirmed by the Managing Director, and this year\'s holidays. Each button opens the page where it is set.', do: async (d) => { await d.point('h1', 900); await d.point('table', 1500); } },
    { goto: '/settings', caption: 'Settings › Company', say: 'Company settings hold every value our documents print, such as company details, bank account, licences and reference formats. Nothing is hard-coded in a template. Sensitive fields are locked until someone unlocks them, and every change is kept in the audit log with its reason.', do: async (d) => { await d.point('h1', 1800); } },
    { goto: '/admin/email-settings', caption: 'Settings › E-mail', say: 'E-mail settings list every e-mail the app sends, and to whom. Log-in codes and account e-mails must always go out. Others can be switched on or off. And Testing mode holds every e-mail to buyers and suppliers, so you can practise without anyone outside receiving a thing.', do: async (d) => { await d.point('h1', 900); await d.point(text(d, /Testing mode/), 1800); } },
    { goto: '/admin/backups', caption: 'Settings › Backups', say: 'Backups. On the live server, every night an extra copy is made of the database and of every order document, kept for ten years, and a test restore runs every month. This page shows what those backup jobs report. On this practice copy no backups are set up, so the page says so.', do: async (d) => { await d.point('h1', 1800); } },
    { goto: '/admin/divisions', caption: 'Settings › Divisions', say: 'And Divisions. Today NexaCrest has one division: export. The app is already prepared for three more: domestic trade within India with GST, merchant trade, buying abroad and selling abroad, and import and distribution. Each stays planned, and invisible to everyone, until it is switched on.', do: async (d) => { await d.point('h1', 900); await d.point('table', 1800); } },

    { card: 'list', kicker: 'Recap', title: 'Everything NexaCrest can do', items: [
      'Approvals in one place; clients, agreements and associates with their commission.',
      'Products and pricing; procurement, suppliers and logistics partners.',
      'Finance: settlements, expenses, bank matching, export benefits and Zoho Books.',
      'Reports, the cockpit, sales targets and the audit log; settings, e-mail, backups and divisions.',
    ], say: 'Let us recap. Approvals wait for you in one place. Clients, agreements and associates each have their own area, with commission worked out for you. Products and pricing turn supplier costs into safe selling prices. Procurement buys the goods, and logistics moves them. Finance tracks every rupee and dollar, and keeps Zoho Books in step. Reports, the cockpit and sales targets show how we are doing, the audit log remembers everything, and the settings keep the whole app safe.' },
    { card: 'list', kicker: 'What to watch next', title: 'Find your own video', items: [
      'A02 · One order, end to end: everyone should watch it.',
      'Group B · Staff: one video for each role, from Export Executive to Admin.',
      'Group C · Associates · Group D · Buyers: the portals, step by step.',
    ], next: 'A02 · One order, end to end', say: 'So, what next? Everyone should watch video A02, where we follow one order from the first enquiry to the very end. Then find your own role. Group B has a video for each staff role, from the Export Executive to the Admin. Group C is for associates, and Group D for our buyers. See you there!' },
  ],
};
