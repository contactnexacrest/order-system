'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
const orderAssigneeRepository = require('../../src/repositories/orderAssigneeRepository');
const orderCommentRepository = require('../../src/repositories/orderCommentRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderCommentService = require('../../src/services/orderCommentService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of B01RecordingFixesTest.php. Owner decision D-268 (8 Nov 2026): four
 * fixes found while recording the B01 tutorial (Export Executive).
 *  1. Dashboard Recent Activity: without the audit-log permission, only your
 *     own actions and actions on orders you can open — never someone else's login.
 *  2. Order Communication: the true reason a staff post was not e-mailed.
 *  3. New Order: the product's specification options never push "Pick from
 *     Products" down (CSS hook).
 *  4. My Tasks: no department membership = only the tasks on orders you own.
 */
describe('D-268 fixes found while recording B01', () => {
  const PASSWORD = 'JestB01Fixes!1';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  let mailSetting;

  beforeAll(async () => {
    mailSetting = (await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = 'email_testing_mode'")).setting_value;
  });

  afterAll(async () => {
    await db.execute("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'email_testing_mode'", { v: mailSetting });
    await db.pool.end();
  });

  const setMailSending = (v) => db.execute("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'email_testing_mode'", { v });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-b01-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function signIn(user) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  // ---- 1. Dashboard Recent Activity ---------------------------------------------------------

  it('shows an Export Executive only their own actions and their orders on the dashboard', async () => {
    const tag = crypto.randomBytes(3).toString('hex').toUpperCase();
    const exec = await createUser('Export Executive');
    const other = await createUser('Export Executive');
    const mine = await createTestOrder(await createTestClient());
    const notMine = await createTestOrder(await createTestClient());
    await orderAssigneeRepository.add(mine, exec.id, null);

    await auditLogRepository.log(other.id, `T${tag}_OTHER_LOGIN`, 'users', other.id);
    await auditLogRepository.log(other.id, `T${tag}_OTHER_ON_HIDDEN_ORDER`, 'orders', notMine);
    await auditLogRepository.log(other.id, `T${tag}_OTHER_ON_MY_ORDER`, 'orders', mine);
    await auditLogRepository.log(exec.id, `T${tag}_MY_OWN_ACTION`, 'users', exec.id);

    let html = (await (await signIn(exec)).get('/')).text;
    expect(html).toContain('Recent Activity');
    expect(html).toContain(`T${tag}_MY_OWN_ACTION`); // their own action
    expect(html).toContain(`T${tag}_OTHER_ON_MY_ORDER`); // an action on an order they can open
    expect(html).not.toContain(`T${tag}_OTHER_LOGIN`); // never another person's login
    expect(html).not.toContain(`T${tag}_OTHER_ON_HIDDEN_ORDER`); // never an order they cannot open

    html = (await (await signIn(await createUser('Admin'))).get('/')).text;
    for (const what of ['OTHER_LOGIN', 'OTHER_ON_HIDDEN_ORDER', 'OTHER_ON_MY_ORDER', 'MY_OWN_ACTION']) {
      expect(html).toContain(`T${tag}_${what}`); // Admin (audit-log permission) keeps the full feed
    }
  });

  // ---- 2. Order Communication: the true reason ----------------------------------------------

  async function orderFor(clientEmail) {
    const orderId = await createTestOrder(await createTestClient(clientEmail));
    await db.execute('UPDATE orders SET client_email = :e WHERE id = :id', { e: clientEmail, id: orderId });
    if (clientEmail === null) { // the fixture always gives the client an e-mail
      await db.execute('UPDATE clients SET email = NULL WHERE id = (SELECT client_id FROM orders WHERE id = :id)', { id: orderId });
    }
    return { orderId, staff: await createUser('Admin') };
  }

  async function noteFor(commentId, orderId) {
    return (await orderCommentRepository.forOrder(orderId)).find((c) => Number(c.id) === Number(commentId)).email_note;
  }

  it('gives "sending switched off" as the reason, not a missing e-mail', async () => {
    await setMailSending('1') /* D-280: testing mode on */;
    const { orderId, staff } = await orderFor(`b01-${crypto.randomBytes(4).toString('hex')}@buyer.test`);
    const id = await orderCommentService.postAsStaff(orderId, staff.id, 'Your goods are packed.', []);
    const row = await db.queryOne('SELECT email_sent, email_status FROM order_comments WHERE id = :id', { id });
    expect(Number(row.email_sent)).toBe(0);
    expect(row.email_status).toBe('testing_mode');
    expect(await noteFor(id, orderId)).toBe('not e-mailed — testing mode holds e-mails to buyers and suppliers');
  });

  it('gives "no client e-mail on file" as the reason', async () => {
    await setMailSending('0') /* testing mode off */;
    const { orderId, staff } = await orderFor(null);
    const id = await orderCommentService.postAsStaff(orderId, staff.id, 'Your goods are packed.', []);
    expect((await db.queryOne('SELECT email_status FROM order_comments WHERE id = :id', { id })).email_status).toBe('no_email');
    expect(await noteFor(id, orderId)).toBe('not e-mailed — no client e-mail on file');
  });

  it('has the exact wording for every note', () => {
    const staff = { author_type: 'staff' };
    const note = orderCommentRepository.emailNote;
    expect(note({ ...staff, email_sent: 1, email_status: 'sent' })).toBe('e-mailed');
    expect(note({ ...staff, email_sent: 0, email_status: 'sending_off' })).toBe('not e-mailed — e-mail sending is switched off in Admin');
    expect(note({ ...staff, email_sent: 0, email_status: 'no_email' })).toBe('not e-mailed — no client e-mail on file');
    expect(note({ ...staff, email_sent: 0, email_status: 'failed' })).toBe('not e-mailed — the e-mail could not be sent');
    expect(note({ ...staff, email_sent: 0, email_status: null })).toBe('not e-mailed'); // an older post claims no reason
    expect(note({ author_type: 'client', email_sent: 0, email_status: null })).toBe(''); // a client's own post carries no note

    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    expect(view).not.toContain('no client email on file'); // the old fixed label is gone
    expect(view).toContain('{{ c.email_note }}');
  });

  // ---- 3. New Order: specification options never push the picker down ------------------------

  it('puts the specification options in a responsive grid below the picker', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');
    expect(css).toContain('.product-row > label.full,.product-row > .spec-options{grid-column:1 / -1;}');
    expect(css).toMatch(/\.spec-options\{display:grid;grid-template-columns:repeat\(auto-fill, minmax\(\d+px, 1fr\)\)/);
    const picker = fs.readFileSync(path.join(__dirname, '../../views/orders/_product_picker.njk'), 'utf8');
    expect(picker).toMatch(/<label class="full">Pick from Products[\s\S]*<\/label>\s*(\{#[\s\S]*?#\}\s*)?<div class="full spec-options"/); // the options come after the picker
  });

  // ---- 4. My Tasks without a department membership ------------------------------------------

  it('lists only tasks on orders you own when you are in no department', async () => {
    const exec = await createUser('Export Executive');
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000, todayIst());
    await orderAssigneeRepository.add(orderId, exec.id, null);
    const ref = (await db.queryOne('SELECT order_reference FROM orders WHERE id = :id', { id: orderId })).order_reference;

    let agent = await signIn(exec);
    for (const url of ['/my-tasks?view=list', '/my-tasks']) {
      const html = (await agent.get(url)).text;
      expect(html).not.toContain(ref); // another department's task on an order they do not own
      expect(html).toContain('You are not a member of any department at the moment, so only tasks on orders you own are listed here.');
      expect(html).not.toContain('All of my department&#39;s tasks');
      expect(html).not.toContain("All of my department's tasks");
    }

    const fin = await db.queryOne("SELECT id FROM departments WHERE code = 'FIN'");
    await departmentTaskRepository.setOwner(orderId, fin.id, exec.id, exec.id);
    expect((await agent.get('/my-tasks?view=list')).text).toContain(ref); // a task on an order they own

    const admin = await createUser('Admin');
    await departmentTaskRepository.setOwner(orderId, fin.id, admin.id, admin.id);
    const adminHtml = (await (await signIn(admin)).get('/my-tasks?view=list')).text;
    expect(adminHtml).toContain(ref); // Admin without a membership keeps the company-wide view
    expect(adminHtml).not.toContain('You are not a member of any department');

    agent = await signIn(exec);
    expect((await agent.get('/my-tasks?view=list')).text).not.toContain(ref); // once someone else owns it, it leaves their list
  });
});
