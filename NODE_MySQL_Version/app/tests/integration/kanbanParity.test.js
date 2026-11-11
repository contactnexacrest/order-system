'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const disputeRepository = require('../../src/repositories/disputeRepository');
const orderReorderRequestRepository = require('../../src/repositories/orderReorderRequestRepository');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const purchasingRepository = require('../../src/repositories/purchasingRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const orderComplaintService = require('../../src/services/orderComplaintService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of KanbanParityTest.php. Owner hard rule (2026-11-01): the Standard
 * and Kanban templates stay parallel — any user may pick either, so every
 * list page offers the same on its board: every link to a record, every
 * action (form), every filter, and a way to switch to the other view. For
 * each of the eight list pages this renders the list (?view=list) and the
 * board for the same Kanban user and the same data, and compares them.
 */
describe('Standard / Kanban parity (owner hard rule 2026-11-01)', () => {
  const PASSWORD = 'JestParity!2026';
  let userId;
  let agent;

  afterAll(async () => {
    await require('../support/fixtures').clearDivisionTradeData(); // leave no division-trade records for later files
    await db.pool.end();
  });

  beforeEach(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-parity-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    userId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Parity User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    await userRepository.updateUiTemplatePreference(userId, (await uiTemplateRepository.findByKey('kanban')).id);
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
  });

  const unique = (arr) => [...new Set(arr)];
  const all = (re, html) => unique([...html.matchAll(re)].map((m) => m[1]));
  // The parts a user acts on: links to records, form actions, filter chips / status options.
  function parts(html) {
    const body = html.replace(/<(header|nav)\b[\s\S]*?<\/\1>/g, '');
    return {
      records: all(/href="(\/(?:orders|clients|reorder-requests|procurement|associates|agreements)\/\d+)(?:[?"/])/g, body),
      actions: all(/<form method="post" action="([^"]+)"/g, body),
      filters: [...all(/class="filter-chip[^"]*" href="[^"]*">([^<(]+)/g, body), ...all(/<option value="([a-z_]*)"[^>]*>/g, body)].map((f) => f.trim()),
    };
  }
  const missing = (a, b) => a.filter((x) => !b.includes(x));

  async function seed() {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    await disputeRepository.create(orderId, new Date().toISOString().slice(0, 10), 'Buyer', 'Parity test dispute', null, null);
    await orderComplaintService.raiseAsClient(orderId, clientId, 'Parity test complaint.');
    await orderReorderRequestRepository.create(clientId, orderId, 'Parity test reorder.');
    await clientIntakeRepository.create({
      company_legal_name: 'Parity Intake Ltd', billing_address: '1 Parity Street', billing_address_line1: null, billing_address_line2: null,
      billing_city: null, billing_postcode: null, billing_country: null, vat_eori_tax_no: null, contact_person: 'Jane Buyer',
      email: `parity-${crypto.randomBytes(4).toString('hex')}@intake.example`, phone: null, country_of_destination: 'Netherlands',
      port_of_discharge_text: null, coo_type: null, incoterm_preference: null, container_type_text: null, buyer_own_reference: null, notes: null,
    }, '127.0.0.1');
    const piOrderId = await createTestOrder(await createTestClient());
    const token = await piIntakeRepository.createLink(piOrderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);
    await piIntakeRepository.submit(submission.id, {
      company_legal_name: submission.company_legal_name,
      billing_address: submission.billing_address,
      billing_address_line1: submission.billing_address_line1,
      billing_address_line2: submission.billing_address_line2,
      billing_city: submission.billing_city,
      billing_postcode: submission.billing_postcode,
      billing_country: submission.billing_country,
      vat_eori_tax_no: submission.vat_eori_tax_no,
      contact_person: submission.contact_person,
      email: submission.email,
      phone: submission.phone,
      port_of_discharge_text: submission.port_of_discharge_text,
      country_of_destination: submission.country_of_destination,
      incoterm_confirmed: submission.incoterm_confirmed,
      container_type_text: submission.container_type_text,
      payment_terms_confirmation: 'CONFIRMED — parity test.',
      quotation_acceptance_reference: 'We accept Quotation X',
      coo_type: submission.coo_type,
      buyer_po_ref: null,
      changes_from_quotation: null,
      special_document_requirements: null,
      consignee_same_as_buyer: 1,
      consignee_name: null, consignee_address_line1: null, consignee_address_line2: null,
      consignee_city: null, consignee_postcode: null, consignee_country: null,
      consignee_vat_eori_tax_no: null, consignee_contact_person: null,
      consignee_phone: null, consignee_email: null,
      notify_party_same_as_consignee: 1,
      notify_party: null, notify_party_address_line1: null, notify_party_address_line2: null,
      notify_party_city: null, notify_party_postcode: null, notify_party_country: null,
      notify_party_contact_person: null, notify_party_phone: null, notify_party_email: null,
    }, '127.0.0.1');
    const associateId = await require('../../src/repositories/associateRepository').create({ name: 'Parity Associate', based_in: 'india', currency_code: 'INR', status: 'active' }, userId);
    await require('../../src/services/associateService').linkClient(clientId, associateId, 'staff', null, new Date().toISOString().slice(0, 10), userId);
    await purchasingRepository.create(orderId, null, 'Parity test blocks', null, 5, 'CBM', new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10), userId, null);
    // Owner decision 2 Nov 2026 (point 4): the Agreements register.
    await require('../../src/repositories/agreementRepository').create({ party_type: 'client', party_id: clientId, agreement_type: 'Commercial NDA', signed_on: new Date(Date.now() - 10 * 86400000).toISOString().slice(0, 10), expires_on: new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10), file_id: await createTestFile(null, null) }, userId);
  }

  const PAGES = [
    ['Orders', '/orders?view=list&status=all', '/orders?status=all', '/orders'],
    ['Disputes', '/disputes?view=list', '/disputes', '/disputes'],
    ['Complaints', '/complaints?view=list', '/complaints', '/complaints'],
    ['Reorder Requests', '/reorder-requests?view=list', '/reorder-requests', '/reorder-requests'],
    ['Quotation Intake', '/client-intake?view=list', '/client-intake', '/client-intake'],
    ['PI Intake', '/pi-intake-review?view=list', '/pi-intake-review', '/pi-intake-review'],
    ['Procurement', '/procurement?view=list', '/procurement', '/procurement'],
    ['My tasks', '/my-tasks?view=list', '/my-tasks', '/my-tasks'],
    // Owner decision 1 Nov 2026: the associates register, list and board.
    ['Associates', '/associates?view=list', '/associates', '/associates'],
    // Owner decision 2 Nov 2026 (point 4): the Agreements register, list and board.
    ['Agreements', '/agreements?view=list', '/agreements', '/agreements'],
  ];

  it('offers on every board what its list offers', async () => {
    await seed();
    for (const [page, listUrl, boardUrl, home] of PAGES) {
      const list = (await agent.get(listUrl)).text;
      const board = (await agent.get(boardUrl)).text;
      expect([page, list.includes('kanban-board')]).toEqual([page, false]);
      expect([page, board.includes('kanban-board')]).toEqual([page, true]);
      const l = parts(list);
      const b = parts(board);
      expect([page, 'records only on the list', missing(l.records, b.records)]).toEqual([page, 'records only on the list', []]);
      expect([page, 'actions only on the list', missing(l.actions, b.actions)]).toEqual([page, 'actions only on the list', []]);
      expect([page, 'actions only on the board', missing(b.actions, l.actions)]).toEqual([page, 'actions only on the board', []]);
      expect([page, 'filters only on the list', missing(l.filters, b.filters)]).toEqual([page, 'filters only on the list', []]);
      expect([page, board.includes('Switch to list view'), list.includes('Switch to board view'), list.includes(`href="${home}`)]).toEqual([page, true, true, true]);
    }
  });

  // Owner decision D-320 (point 26): the section menu bar is one shared partial
  // drawn by the layout, so the list and the board of every page show the very
  // same bar (same items, same counts, same page marked) and the same left menu.
  it('shows the same section menu bar on the list and the board', async () => {
    await seed();
    const bar = (html) => (html.match(/<nav class="section-nav"[\s\S]*?<\/nav>/) || [''])[0];
    const side = (html) => (html.match(/<nav class="sidebar-nav"[\s\S]*?<\/nav>/) || [''])[0];
    for (const [page, listUrl, boardUrl] of [...PAGES, ['Approvals', '/approvals?view=list', '/approvals']]) {
      const list = (await agent.get(listUrl)).text;
      const board = (await agent.get(boardUrl)).text;
      if (page !== 'My tasks') expect([page, bar(list) !== '']).toEqual([page, true]);
      expect([page, bar(list)]).toEqual([page, bar(board)]);
      expect([page, side(list)]).toEqual([page, side(board)]);
    }
  });

  // QA Round 7, R7-N08: with no filter chosen, the list and the board open on
  // the same orders (Active) — the board used to add Docs Sent and Complete.
  it('opens the Orders list and board on the same orders', async () => {
    const active = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    const complete = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    const docsSent = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :id", { id: complete });
    await db.execute("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id", { id: docsSent });
    const mine = [`/orders/${active}`, `/orders/${complete}`, `/orders/${docsSent}`];
    const list = parts((await agent.get('/orders?view=list')).text).records.filter((r) => mine.includes(r));
    const boardHtml = (await agent.get('/orders')).text;
    const board = parts(boardHtml).records.filter((r) => mine.includes(r));
    expect(board).toEqual([`/orders/${active}`]);
    expect(list).toEqual(board);
    expect(boardHtml).toContain('class="filter-chip active" href="/orders?status=active">Active');
  });

  // D-257: a Domestic Trade order (or local sale) shows its own stage (of 8) and its rupee amount on the
  // list and the board alike; the board adds Domestic columns only when there are such orders. Export cards unchanged.
  it('shows a Domestic Trade order with its own stage and rupee amount on the list and the board', async () => {
    await db.execute('UPDATE users SET division_id = NULL WHERE id = :id', { id: userId });
    const exportOrder = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    expect((await agent.get('/orders')).text).not.toContain('Domestic · ');
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = 2");
    try {
      const dom = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
      await db.execute("UPDATE orders SET division_id = 2, currency_code = 'INR' WHERE id = :id", { id: dom });
      await db.execute("INSERT INTO domestic_orders (order_id, stage, place_of_supply, seller_state_code) VALUES (:o, 'purchase', '29', '29')", { o: dom });
      await db.execute("INSERT INTO domestic_order_lines (order_id, line_no, description, hsn_code, quantity, unit, rate_inr, gst_rate_pct) VALUES (:o, 1, 'Granite', '6802', 10, 'm2', 1500.50, 18)", { o: dom });
      for (const [what, url] of [['list', '/orders?view=list'], ['board', '/orders']]) {
        const html = (await agent.get(url)).text;
        const card = html.slice(html.indexOf(`/orders/${dom}`), html.indexOf(`/orders/${dom}`) + 2500);
        expect([what, card.includes('Stage 4 of 8 &middot; Purchase')]).toEqual([what, true]);
        expect([what, card.includes('INR 15,005.00 + GST')]).toEqual([what, true]);
        const exportCard = html.slice(html.indexOf(`/orders/${exportOrder}`), html.indexOf(`/orders/${exportOrder}`) + 2500);
        expect([what, exportCard.includes('of 9 &middot;')]).toEqual([what, true]);
      }
      const board = (await agent.get('/orders')).text;
      expect(board).toContain('data-column="dom_purchase"');
      expect(board).toMatch(new RegExp(`data-column="dom_purchase"[\\s\\S]*?/orders/${dom}\\?from=kanban`));
      await db.execute("UPDATE domestic_orders SET stage = 'payment', invoice_total = 17706.00 WHERE order_id = :o", { o: dom });
      const list = (await agent.get('/orders?view=list')).text;
      expect(list.slice(list.indexOf(`/orders/${dom}`), list.indexOf(`/orders/${dom}`) + 2500)).toContain('INR 17,706.00</span>');
    } finally {
      await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    }
  });

  // Owner decision 1 Nov 2026 (A-5, Q-R7-2): the board pages like the list — 25 per column, then "Show more".
  it('pages the Orders board like the list', async () => {
    // A Kanban user who sees only their own orders (an Admin sees every order in the test database).
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    const email = `jest-parity-x-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    userId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Parity Exec', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    await userRepository.updateUiTemplatePreference(userId, (await uiTemplateRepository.findByKey('kanban')).id);
    agent = request.agent(app);
    const loginCsrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: loginCsrf, email, password: PASSWORD });
    const ids = [];
    for (let i = 0; i < 27; i++) {
      ids.push(await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]));
    }
    const mine = ids.map((id) => `/orders/${id}`);
    expect(parts((await agent.get('/orders?view=list')).text).records.filter((r) => mine.includes(r))).toHaveLength(25);
    const html = (await agent.get('/orders')).text;
    const board = parts(html).records.filter((r) => mine.includes(r));
    expect(board).toHaveLength(25);
    expect(html).toContain('<span class="kanban-column-count">27</span>');
    expect(html).toContain('href="/orders?status=active&amp;more_stage1=50#col-stage1">Show more (2 more)</a>');
    expect(board).not.toContain(`/orders/${ids[0]}`);
    expect(parts((await agent.get('/orders?status=active&more_stage1=50')).text).records.filter((r) => mine.includes(r))).toHaveLength(27);
  });

  it('offers a Standard user no switch to the board', async () => {
    await userRepository.updateUiTemplatePreference(userId, null);
    for (const [page, , boardUrl] of PAGES) {
      const list = (await agent.get(boardUrl)).text;
      expect([page, list.includes('kanban-board'), list.includes('Switch to board view')]).toEqual([page, false, false]);
    }
  });

  it('has a list template for every board template', () => {
    const views = path.join(__dirname, '..', '..', 'views');
    const boards = [];
    for (const dir of fs.readdirSync(views)) {
      const d = path.join(views, dir);
      if (fs.statSync(d).isDirectory()) {
        for (const f of fs.readdirSync(d)) if (f.endsWith('_kanban.njk')) boards.push(path.join(d, f));
      }
    }
    expect(boards.length).toBeGreaterThan(0);
    for (const board of boards) {
      const list = board.replace('_kanban.njk', '.njk');
      expect([board, fs.existsSync(list)]).toEqual([board, true]);
      expect([board, fs.readFileSync(board, 'utf8').includes('Switch to list view')]).toEqual([board, true]);
      expect([list, fs.readFileSync(list, 'utf8').includes('Switch to board view')]).toEqual([list, true]);
    }
  });
});
