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
const { createTestClient, createTestOrder } = require('../support/fixtures');

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
      records: all(/href="(\/(?:orders|clients|reorder-requests|procurement)\/\d+)(?:[?"/])/g, body),
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
    await purchasingRepository.create(orderId, null, 'Parity test blocks', null, 5, 'CBM', new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10), userId, null);
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
