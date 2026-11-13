'use strict';
// A01 — NexaCrest in ten minutes (Group A, Everyone).
// What the company does, the three portals, departments and roles, the 9 stages and 19 steps,
// Standard vs Kanban, and where help lives. Source of truth: docs/APPLICATION_HANDBOOK.md
// (sections 1.1, 1.3, 3.5, 4.4, 5.1, 6.11, 6.12). Runs on the app's own Sample Data.
// Updated 13 Nov 2026 (docs audit C; not recorded, recording on hold): D-320 short left menu and section
// menu bars ("Help & SOP" entry; "Users & roles › Departments / SOP access"; "Settings › Sample data").

const { ACTORS, cleanStage, clientLogin, clientCookies } = require('./_overview');

const ASSOC = 'associate.full@email.com';
// D-320: the left menu links each section's first page; the section's other pages are on its menu bar.
const side = (d, key) => d.page.locator(`nav.sidebar-nav a[data-nav="${key}"]`).first();
const row = (d, text) => d.page.locator('tr').filter({ hasText: text }).first();

module.exports = {
  code: 'A01',
  file: 'A01-Everyone-NexaCrest-In-Ten-Minutes.mp4',
  group: 'Group A · Everyone',
  title: 'NexaCrest in Ten Minutes',
  subtitle: 'What we do, who uses the app, how an order moves, and where to find help.',
  minutes: '10',
  restoreDb: true,

  async setup(demo) {
    const { ids } = cleanStage(demo);
    const clientEmail = clientLogin(demo);
    const a = demo.associate({ email: ASSOC, name: 'Demo Associate', level: 'full', city: 'Jaipur' });
    return {
      order: ids[2], // [SAMPLE] Meridian Home Collections, FOB, at Stage 8
      clientEmail,
      actors: {
        visitor: { label: 'Visitor', sub: 'not logged in', initials: '··', color: '#6B7280', cookies: [] },
        md: { ...ACTORS.md, cookies: await demo.staffCookies('md') },
        client: { ...ACTORS.client(clientEmail), cookies: await clientCookies(demo, clientEmail) },
        associate: { ...ACTORS.associate(a.email), cookies: await demo.associateCookies(a) },
      },
    };
  },

  steps: ({ order = 0 } = {}) => [
    { card: 'title', say: 'Welcome to NexaCrest! Whether you work in sales, procurement, logistics, finance, or you run the company, this is your starting point. In about ten minutes you will know what we do, who uses the app, how an order travels from first enquiry to the final document, and where to find help whenever you are stuck. Let us go.' },

    // ---------- 1. What NexaCrest does ----------
    { card: 'chapter', title: 'What NexaCrest does', subtitle: 'An Indian export trading company.', say: 'Part one. What does NexaCrest actually do?' },
    { card: 'list', kicker: 'The business in one picture', title: 'From an Indian quarry to a buyer abroad', items: [
      'Today we export natural stone: granite slabs, tiles, monuments and cut-to-size pieces.',
      'We buy from Indian suppliers, check the goods and pack them in wooden crates.',
      'They travel by sea in containers, and the buyer pays in a foreign currency, usually US dollars.',
      'The app is ready for other products later, such as engineering goods.',
    ], say: 'NexaCrest is an Indian export trading company. Today we export natural stone: granite slabs, tiles, monuments and cut-to-size pieces. We buy the stone from Indian quarries and factories, check it, and pack it in wooden crates. It travels by sea in containers, and the buyer abroad pays us, usually in US dollars. The app is already prepared for other products later, such as engineering goods.' },
    { card: 'list', kicker: 'Why this app exists', title: 'One order, one record', items: [
      'Before: 42 Word and Excel templates per order, filled in by hand.',
      'Now: every figure is typed once, and every document is generated from it.',
      'Stage gates stop a later step until the earlier one is really done.',
      'Every document is reviewed and approved before the buyer sees it.',
    ], say: 'Why an app at all? Before, every order meant a folder of forty-two Word and Excel templates, filled in by hand. Mistakes crept in. Now, each order is one record. Every figure is typed once, and every document, the quotation, the proforma invoice, the commercial invoice, the packing list, is generated from that one record. Stage gates stop a later step until the earlier one is really done. And every document is reviewed and approved before the buyer ever sees it.' },

    // ---------- 2. Three doors ----------
    { card: 'chapter', title: 'Three doors in', subtitle: 'Staff, buyers and associates each have their own login.', say: 'Part two. There are three separate doors into the app.' },
    { as: 'visitor', goto: '/login', caption: 'Door 1: staff', say: 'Door number one is for staff: everyone who works at NexaCrest. You sign in here with your e-mail and password, and then a six-digit code that arrives by e-mail, every time you log in. The Managing Director, the Executive Director and the Admin also keep a few one-time backup codes, in case e-mail is ever down.', do: async (d) => { await d.point('h1, h2', 1200); } },
    { goto: '/client/login', caption: 'Door 2: buyers (clients)', say: 'Door number two is the client portal, for our buyers abroad. Buyers also enter a six-digit code from their e-mail when they sign in. A buyer can never open a staff page, and never sees our costs.', do: async (d) => { await d.point('h1, h2', 1200); } },
    { goto: '/associate/login', caption: 'Door 3: associates', say: 'And door number three is for associates: outside agents who introduce buyers to us, and earn a commission when those buyers pay. They sign in with a code from their e-mail too.', do: async (d) => { await d.point('h1', 1200); } },
    { as: 'client', goto: '/client', caption: 'What a buyer sees', say: 'Here is the client portal, from the inside. The buyer sees only their own orders, the stage each one has reached, and the documents we have released to them. They can also report a payment, acknowledge an order confirmation, give feedback and ask for a repeat order.', do: async (d) => { await d.point('h1', 800); await d.point(d.page.locator('table').first(), 1500); } },
    { as: 'associate', goto: '/associate', caption: 'What an associate sees', say: 'And this is the associate portal. Associates see their own clients, the progress of those orders, and their commission. Never our costs, our margins, or supplier prices. The associates have their own video series, Group C.', do: async (d) => { await d.point('h1', 800); await d.point('nav.topbar-nav', 1500); } },

    // ---------- 3. Departments and roles ----------
    { card: 'chapter', title: 'Departments and roles', subtitle: 'Department, designation, role, permissions.', say: 'Part three. Who can do what inside the staff app.' },
    { as: 'md', goto: '/', caption: 'The staff dashboard', say: 'Now we are staff. Look at the badge, top right: we are the Managing Director. This is the dashboard, everyone\'s starting page: a search box, your approvals, active orders by stage, overdue payments, and recent activity. On the left, a short menu: Dashboard, Approvals, My tasks, then Orders, Clients, Associates, Products, Procurement and suppliers, Finance and accounts, Reports, Help and SOP, Users and roles, and Settings. Each section has its own menu bar at the top of its pages.', do: async (d) => { await d.point('h1', 900); await d.scrollTo(d.page.getByRole('heading', { name: /Active Orders by Stage/ })); await d.ring(d.page.getByRole('heading', { name: /Active Orders by Stage/ })); await d.wait(1200); } },
    { goto: '/admin/departments', caption: 'Users & roles › Departments', say: 'Behind the scenes, under Users and roles, Departments, access is organised by departments. Each department has a ladder of designations: executive, manager and director. Each designation carries a role, and the role decides the permissions. So, to give someone access, an admin simply adds them to a department. Nobody edits permissions by hand.', do: async (d) => { await d.point('h1', 1500); } },
    { caption: 'Company Management', say: 'At the top, Company Management: the Managing Director and the Executive Director. They see the whole company and give the final approvals.', do: async (d) => { await d.point(row(d, 'Company Management'), 1800); } },
    { caption: 'Sales & Export', say: 'Sales and Export talks to buyers, prepares quotations and runs the order from the buyer\'s side.', do: async (d) => { await d.point(row(d, 'Sales & Export'), 1500); } },
    { caption: 'Procurement and Logistics', say: 'Procurement buys the goods from our suppliers, within approval limits. Logistics books the freight, the transport and the customs agent.', do: async (d) => { await d.point(row(d, 'Procurement'), 1300); await d.point(row(d, 'Logistics'), 1300); } },
    { caption: 'Export Compliance, Finance, Audit', say: 'Export Compliance handles certificates and government paperwork. Accounts and Finance records every payment. And Audit and Compliance, including our outside chartered accountant, can look, but not change.', do: async (d) => { await d.point(row(d, 'Export Compliance'), 1200); await d.point(row(d, 'Accounts & Finance'), 1200); await d.point(row(d, 'Audit & Compliance'), 1200); } },
    { caption: 'Preview the app as this', say: 'A neat trick for admins: Preview the app as this. It shows the app exactly as that designation sees it, look only, nothing can be saved. Perfect for checking what a new colleague will see.', do: async (d) => { await d.point(d.page.getByRole('button', { name: 'Preview the app as this' }).nth(2), 1500); } },
    { card: 'list', kicker: 'Who sees the money', title: 'Each department sees what it needs', items: [
      'Sales sees selling prices; Procurement sees purchase prices; Logistics sees freight costs.',
      'Only management and finance see the full picture, including margins.',
      'The internal cost sheet (CAFIN) never reaches the buyer, by any route.',
      'Some people belong to two departments and switch with "Log in as".',
    ], say: 'One more important idea: money visibility. Each department sees the money it needs, and nothing more. Sales sees selling prices. Procurement sees purchase prices. Logistics sees freight costs. Only management and finance see the full picture, including margins. Our internal cost sheet, called CAFIN, never reaches a buyer, not by download, link, e-mail or portal. And if you belong to two departments, you choose which one you are working in when you log in, and you can switch at any time.' },

    // ---------- 4. 9 stages, 19 steps ----------
    { card: 'chapter', title: 'How an order moves', subtitle: '9 stages on top, 19 steps underneath.', say: 'Part four. The heart of the app: how an order moves.' },
    { card: 'list', kicker: 'The 9 stages', title: 'Every export order, every time', items: [
      '1 Enquiry & Quotation · 2 Buyer Purchase Order · 3 Proforma Invoice and advance',
      '4 Order Confirmation · 5 Supplier Purchase Order · 6 Freight Payment',
      '7 Packing & BL Instruction · 8 Commercial Invoice & Balance · 9 Despatch & Closure',
    ], say: 'Every export order passes through the same nine stages. One, enquiry and quotation. Two, the buyer\'s purchase order. Three, the proforma invoice, and the advance payment. Four, order confirmation. Five, our purchase order to the supplier. Six, freight payment, which is skipped automatically on FOB orders. Seven, packing and the bill of lading instruction. Eight, the commercial invoice and the balance payment. And nine, sending the original documents, and closing the order.' },
    { goto: '/orders', caption: 'Orders', say: 'Let us open an order. This is the Orders page. Every order is a card, with its reference, the buyer, a progress bar of the nine stages, and the order value.', do: async (d) => { await d.point('h1', 800); await d.point(d.page.locator('a.entity-card').filter({ hasText: /Meridian Home Collections/ }).first(), 1200); } },
    { goto: `/orders/${order}`, caption: 'The order page', say: 'Here is a sample order, for a buyer called Meridian Home Collections. Notice the [SAMPLE] label: this is practice data, loaded from Settings, Sample data, so everyone can try things safely.', do: async (d) => { await d.point('h1', 1500); } },
    { caption: 'The stage tracker', say: 'At the top, the stage tracker. Green dots are stages that are done. The highlighted one, stage eight, is where this order is right now. Stage six is greyed out, because this is an FOB order: the buyer arranges the freight, so there is no freight stage.', do: async (d) => { await d.point('.process-stepper', 1200); await d.point('.process-step[data-stage="8"]', 1300); await d.point('.process-step[data-stage="6"]', 1000); } },
    { caption: 'At a Glance', say: 'Just below, At a Glance: the client, the incoterm and port, the payment terms, and how many payments and documents are done.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'At a Glance' })); await d.ring(d.page.getByRole('heading', { name: 'At a Glance' })); await d.wait(1500); } },
    { caption: 'Internal only', say: 'And here is that red box: CAFIN, the internal cost sheet. Internal only, never shown to the client.', do: async (d) => { await d.point(d.page.locator('.badge-internal').first(), 1500); } },
    { caption: 'The 19 steps, in five sections', say: 'Further down, the real work. The nine stages are split into nineteen steps, in the order you actually do them, grouped in five coloured sections. A, order booking. B, procurement and production. C, shipment and balance. D, closure and settlement. And E, after-sales and records.', do: async (d) => {
      for (const id of ['booking', 'production', 'shipment']) await d.point(`#phase-${id} > summary`, 700);
      await d.point(d.page.locator('details.phase-group > summary').filter({ hasText: 'Closure' }).first(), 700);
      await d.point(d.page.locator('details.phase-group > summary').filter({ hasText: 'After-Sales' }).first(), 700);
    } },
    { caption: 'One step at a time', say: 'Look inside section C. Each step has small sub-steps, and each one waits for the one before it. Complete the previous step first, it says. You cannot skip ahead by accident. And when you do skip something on purpose, the app asks for a reason, and keeps a record.', do: async (d) => {
      await d.point(d.page.getByText('Complete the previous step first').first(), 1800);
    } },
    { card: 'list', kicker: 'Three rules to remember', title: 'How the order page thinks', items: [
      'A stage passes only when its own document is finished: approved, or sent.',
      'Steps run in sequence; skipping one needs a reason and is logged.',
      'Every action is written to the audit log, with who did it and when.',
    ], say: 'So, three rules to remember. One: a stage passes only when its own document is finished, approved, or sent to the buyer. Two: steps run in sequence, and skipping one needs a reason. Three: every action is written to the audit log, with who did it, and when.' },

    // ---------- 5. Standard vs Kanban ----------
    { card: 'chapter', title: 'Standard or Kanban', subtitle: 'Two ways to see the same work. Your choice.', say: 'Part five. Pick the view that suits you.' },
    { goto: '/orders', caption: 'Standard: cards and filters', say: 'This is the Standard view: a card for every order, with filters across the top: active, overdue, documents sent, complete, lost and cancelled.', do: async (d) => { await d.point(d.page.getByRole('link', { name: /^Active/ }).first(), 1500); } },
    { goto: '/account', caption: 'My Account › UI Template', say: 'Prefer a board? Open My Account, and scroll to UI Template.', do: async (d) => { await d.scrollTo(d.page.getByRole('heading', { name: 'UI Template' })); await d.ring(d.page.getByRole('heading', { name: 'UI Template' })); await d.wait(800); } },
    { caption: 'Choose Kanban Board', say: 'Choose Kanban Board, and save.', do: async (d) => {
      await d.select(d.page.getByLabel('Template'), { label: 'Kanban Board' });
      await d.clickNav(d.page.getByRole('button', { name: 'Save UI Template' }));
    } },
    { goto: '/orders', caption: 'Kanban: one column per stage', say: 'Now Orders is a board, with one column per stage. You see at a glance where the work is piling up.', do: async (d) => { await d.point('.kanban-board', 900); await d.point('.kanban-column-header', 1200); } },
    { caption: 'Same features, both ways', say: 'Here is the promise: both views offer exactly the same things. Every filter, count, badge, action and money rule on the Standard page is on the board too, and the other way round. Switch whenever you like. Your colleagues can choose differently.', do: async (d) => { await d.point(d.page.getByRole('link', { name: 'Switch to list view' }), 1500); } },

    // ---------- 6. Help ----------
    { card: 'chapter', title: 'Where help lives', subtitle: 'You are never on your own.', say: 'Part six. Where to find help.' },
    { goto: '/', caption: 'Help & SOP in the menu', say: 'Look at the menu on the left: Help and SOP. It opens Getting Started, and its menu bar has the SOP, or Operations Guide, the reference library and the gallery. Getting Started and the SOP open in a new tab, so you never lose your place.', do: async (d) => { await d.point(side(d, 'help'), 1500); } },
    { goto: '/getting-started', caption: 'Getting Started', do: async (d) => { await d.point('h1', 1200); await d.scrollBy(500); }, say: 'Getting Started is the day-one guide: the big picture, logging in, finding your way, your dashboard, Standard versus Kanban, and a plan for your first week. It takes about eight minutes to read.' },
    { goto: '/sop', caption: 'SOP / Operations Guide', say: 'The SOP is the full manual. One chapter per stage, plus chapters for amendments, disputes, the client portal, reports, departments, procurement, pricing and much more. Each person sees the chapters for their own role, and the Admin decides which, under Users and roles, SOP access. Whenever a screen puzzles you, look up its chapter here.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.getByRole('heading', { name: 'The 9-stage pipeline' }), 1500); } },
    { goto: '/my-tasks', caption: 'My Tasks', say: 'And finally, My Tasks. When a step is done, the next department\'s task appears here automatically, with a due date. And since we chose Kanban, My Tasks is a board too: escalated, overdue, due today, and coming up. Overdue tasks turn red, and are escalated after a few days. Start every day here, and nothing falls through the cracks.', do: async (d) => { await d.point('h1', 1000); await d.point(d.page.locator('.kanban-board, table').first(), 1500); } },

    { card: 'list', kicker: 'Recap', title: 'NexaCrest in ten minutes', items: [
      'We export natural stone from India; each order is one record, every document generated.',
      'Three doors: staff, buyers (client portal) and associates; every login asks for a code e-mailed to you.',
      'Departments decide who sees and does what, including who sees the money.',
      '9 stages, 19 steps; Standard or Kanban; help in Getting Started, the SOP and My Tasks.',
    ], next: 'A02 · One order, end to end', say: 'Let us recap. NexaCrest exports natural stone from India, and every order is one record, with every document generated from it. There are three doors: staff, buyers and associates, and every login asks for a six-digit code sent by e-mail. Departments decide who sees and does what, including who sees the money. Every order moves through nine stages and nineteen steps. You can work in the Standard view or on the Kanban board. And help is always one click away: Getting Started, the SOP, and My Tasks. Next up, video A02: we follow one order from the first enquiry to the very end, meeting every person who touches it. See you there!' },
  ],
};
