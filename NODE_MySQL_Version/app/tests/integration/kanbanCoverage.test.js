'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
const purchasingRepository = require('../../src/repositories/purchasingRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of KanbanCoverageTest.php. Owner request (1 Nov 2026): everything
 * built for departments, procurement, coordination and Phase 5a works under
 * the Kanban template too, with tests. The Procurement queue and My tasks
 * get their own boards; every board's "Switch to list view" really shows
 * the list; the Orders board hides the order value from a department that
 * may not see selling prices; and the order page shows the new parts
 * (payment schedule, Export Service Invoice, product category) the same in
 * both templates.
 */
describe('Kanban template coverage (owner request 1 Nov 2026)', () => {
  const PASSWORD = 'JestKanban!2026';

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, kanban) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-kb-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Kanban User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    await userRepository.updateUiTemplatePreference(id, kanban ? (await uiTemplateRepository.findByKey('kanban')).id : null);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return { id, agent };
  }

  it('shows Procurement as a board under Kanban', async () => {
    const admin = await user('Admin', true);
    const orderId = await createTestOrder(await createTestClient());
    const neededBy = new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10);
    const prId = await purchasingRepository.create(orderId, null, 'Granite blocks for headstones', null, 12, 'CBM', neededBy, admin.id, null);

    const board = (await admin.agent.get('/procurement')).text;
    expect(board).toContain('Procurement — Kanban Board</h1>');
    for (const column of ['Waiting for quotes', 'Quotes in', 'Awarded — waiting for approval', 'Approved — with the supplier', 'Received']) {
      expect(board).toContain(`<span class="kanban-column-title">${column}</span>`);
    }
    expect(board).toMatch(new RegExp(`data-status="open">[\\s\\S]*?href="/procurement/${prId}"`));

    const list = (await admin.agent.get('/procurement?view=list')).text;
    expect(list).not.toContain('kanban-board');
    expect(list).toContain(`PR-${prId}`);

    const standard = await user('Admin', false);
    expect((await standard.agent.get('/procurement')).text).not.toContain('kanban-board');
  });

  it('shows My tasks as a board under Kanban', async () => {
    const admin = await user('Admin', true);
    await createTestOrder(await createTestClient());
    const board = (await admin.agent.get('/my-tasks')).text;
    expect(board).toContain('My tasks — Kanban Board</h1>');
    for (const column of ['Escalated', 'Overdue', 'Due today', 'Coming up']) {
      expect(board).toContain(`<span class="kanban-column-title">${column}</span>`);
    }
    const list = (await admin.agent.get('/my-tasks?view=list')).text;
    expect(list).not.toContain('kanban-board');
    expect((board.match(/class="entity-card kanban-card"/g) || []).length).toBe((list.match(/>Open<\/a>/g) || []).length);
  });

  it('really shows the list on every board\'s "Switch to list view"', async () => {
    const admin = await user('Admin', true);
    for (const [page, list] of [
      ['/orders', '/orders?view=list&status=all'], ['/disputes', '/disputes?view=list&status=all'], ['/complaints', '/complaints?view=list'],
      ['/reorder-requests', '/reorder-requests?view=list'], ['/client-intake', '/client-intake?view=list'], ['/pi-intake-review', '/pi-intake-review?view=list'],
    ]) {
      expect([page, (await admin.agent.get(page)).text.includes('kanban-board')]).toEqual([page, true]);
      expect([list, (await admin.agent.get(list)).text.includes('kanban-board')]).toEqual([list, false]);
    }
  });

  it('hides the order value on the Orders board from a department without selling prices', async () => {
    const logistics = await user('Logistics Executive', true);
    const admin = await user('Admin', true);
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [logistics.id, admin.id]);
    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');

    expect((await admin.agent.get('/orders')).text).toContain('USD 4,500.00');
    const board = (await logistics.agent.get('/orders')).text;
    expect(board).toContain('Orders — Kanban Board</h1>');
    expect(board).not.toContain('4,500.00');
  });

  it('shows the new parts of the order page under Kanban too', async () => {
    const admin = await user('Admin', true);
    const orderId = await createTestOrder(await createTestClient());
    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
    const id = await orderProductRepository.add(orderId, 2, 'Installation supervision', null, null, '5', false, 'DAY', '180.00');
    await orderProductRepository.setLineType(id, 'service', '998719');
    await paymentMilestoneRepository.replaceSchedule(orderId, [{ pct: 20, due_text: 'on shipment' }], 40);

    const page = (await admin.agent.get(`/orders/${orderId}?from=kanban`)).text;
    expect(page).toContain('id="payment-schedule"');
    expect(page).toContain('2 · Milestone');
    expect(page).toContain('Generate Export Service Invoice');
    expect(page).toContain('name="category_id"');
  });
});
