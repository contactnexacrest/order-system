'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
const prospectService = require('../../src/services/prospectService');
const settingValueValidator = require('../../src/helpers/settingValueValidator');
const settingLabels = require('../../src/helpers/settingLabels');
const { short } = require('../../src/helpers/dates');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ProspectD394Test.php. Owner decision D-394 (20 Nov 2026):
 * quick-quote prospects — the client added with only a name, an e-mail and
 * a country; its badge and filter on the Clients list and on the Orders list
 * (Standard and Kanban alike); one reminder before it expires; the daily
 * expiry that moves its quotation order to "Did not go ahead" (never one
 * Mark Lost would refuse) and deactivates it; Keep for N more days;
 * reactivating with a fresh date; a Quotation request from its e-mail
 * updating it; the settings. Nothing is ever deleted.
 */
describe('Quick-quote prospects (D-394)', () => {
  const PASSWORD = 'JestProspect!2026';
  let userId;
  let agent;

  const day = (n) => prospectService.addDays(prospectService.today(), n);
  const randomEmail = () => `prospect-${crypto.randomBytes(4).toString('hex')}@buyer.example`;
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  async function makeUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-d394-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest D-394 User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function login(user) {
    const a = request.agent(app);
    const csrf = csrfOf((await a.get('/login')).text);
    await a.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return a;
  }

  async function post(url, fields, page = '/clients') {
    const csrf = csrfOf((await agent.get(page)).text);
    return agent.post(url).type('form').send({ _csrf: csrf, ...fields });
  }

  async function flashAfter(res) {
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') } : { type: null, message: '' };
  }

  async function addProspect(name = 'Quick Quote Granite Ltd', email = null) {
    const res = await post('/clients', { is_prospect: '1', company_legal_name: name, email: email || randomEmail(), country_of_destination: 'Germany', consignee_same_as_buyer: '1', notify_party_same_as_consignee: '1' }, '/clients/create');
    return Number(res.headers.location.split('/').pop());
  }

  async function setExpiry(clientId, date, remindedOn = null) {
    await db.execute('UPDATE clients SET prospect_expires_on = :d, prospect_reminded_on = :r WHERE id = :id', { d: date, r: remindedOn, id: clientId });
  }

  beforeEach(async () => {
    await db.execute("UPDATE company_settings SET setting_value = '60' WHERE setting_key = 'prospect_expiry_days'");
    await db.execute("UPDATE company_settings SET setting_value = '7' WHERE setting_key = 'prospect_reminder_days'");
    await db.execute("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'prospect_auto_close'");
    await db.execute('UPDATE clients SET is_prospect = 0 WHERE is_prospect = 1'); // each test sees only its own prospects
    const user = await makeUser('Admin');
    userId = user.id;
    agent = await login(user);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  // ---------------------------------------------------------------- 1. + Add client

  it('a prospect needs only the name, e-mail and country (server)', async () => {
    const email = randomEmail();
    const id = await addProspect('Quick Quote Granite Ltd', email);
    const c = await clientRepository.find(id);
    expect([c.company_legal_name, Number(c.is_prospect), c.prospect_expires_on, c.billing_address, c.billing_address_line1, c.country_of_destination, Number(c.is_active)])
      .toEqual(['Quick Quote Granite Ltd', 1, day(60), 'To Be Confirmed', null, 'Germany', 1]);

    const before = Number((await db.queryOne('SELECT COUNT(*) AS n FROM clients')).n);
    let res = await post('/clients', { is_prospect: '1', company_legal_name: 'No Mail Ltd', country_of_destination: 'Germany' }, '/clients/create');
    expect(await flashAfter(res)).toEqual({ type: 'error', message: prospectService.REQUIRED_MESSAGE });
    res = await post('/clients', { is_prospect: '1', company_legal_name: 'No Country Ltd', email: randomEmail() }, '/clients/create');
    expect((await flashAfter(res)).message).toBe(prospectService.REQUIRED_MESSAGE);
    res = await post('/clients', { company_legal_name: 'Ordinary Ltd', email: randomEmail(), country_of_destination: 'Germany' }, '/clients/create');
    expect((await flashAfter(res)).message).toBe('Company legal name, Billing Address Line 1, City / Town and Billing Country are required.');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM clients')).n)).toBe(before);
    // D-129: one e-mail per client, prospects included.
    res = await post('/clients', { is_prospect: '1', company_legal_name: 'Same Mail Ltd', email: email.toUpperCase(), country_of_destination: 'Germany' }, '/clients/create');
    expect((await flashAfter(res)).message).toContain('Another client already uses the email');
  });

  it('the form checks the prospect rules in the browser', async () => {
    const html = (await agent.get('/clients/create')).text;
    expect(html).toContain('Quick quote — the buyer has not sent their details yet (prospect)');
    for (const name of ['billing_address_line1', 'billing_city', 'billing_country']) {
      expect(html).toMatch(new RegExp(`name="${name}"[^>]*required data-required-unless-ticked="is_prospect"`));
    }
    expect(html).toMatch(/name="email"[^>]*data-required-if-ticked="is_prospect"/);
    expect(html).toMatch(/name="country_of_destination"[^>]*data-required-if-ticked="is_prospect"/);
    expect(html).toContain('optional for a prospect');
    const js = fs.readFileSync(path.join(__dirname, '../../public/js/app.js'), 'utf8');
    expect(js).toContain('function requiredWhenTickedApply(form)');
    expect(js).toContain("var need = el.hasAttribute('data-required-if-ticked') ? ticked : !ticked;");
    expect(js).toMatch(/function init\(\) \{[\s\S]*initRequiredWhenTicked\(\);/);
  });

  it('filling in every detail ends the prospect', async () => {
    const id = await addProspect();
    const c = await clientRepository.find(id);
    const fingerprint = async () => csrfOf((await agent.get(`/clients/${id}/edit`)).text) && (await agent.get(`/clients/${id}/edit`)).text.match(/name="edit_fingerprint" value="([^"]*)"/)[1];
    const base = { company_legal_name: c.company_legal_name, email: c.email, country_of_destination: 'Germany', consignee_same_as_buyer: '1', notify_party_same_as_consignee: '1' };
    let res = await post(`/clients/${id}/update`, { ...base, is_prospect: '1', phone: '+49 30 000000', edit_fingerprint: await fingerprint() }, `/clients/${id}/edit`);
    expect((await flashAfter(res)).type).toBe('success');
    expect(Number((await clientRepository.find(id)).is_prospect)).toBe(1);

    res = await post(`/clients/${id}/update`, { ...base, is_prospect: '1', billing_address_line1: '1 Quarry Road', billing_city: 'Berlin', billing_country: 'Germany', edit_fingerprint: await fingerprint() }, `/clients/${id}/edit`);
    expect((await flashAfter(res)).message).toContain('no longer a prospect');
    const after = await clientRepository.find(id);
    expect([Number(after.is_prospect), after.prospect_expires_on, after.billing_address]).toEqual([0, null, '1 Quarry Road, Berlin, Germany']);
  });

  it('"To be confirmed" counts as empty, so the prospect stays', async () => {
    // Owner, 20 Nov 2026 (option A): the placeholder staff typed before D-394 is not a real detail.
    for (const v of ['', '  ', 'To be confirmed', 'TO BE CONFIRMED', 'to be  confirmed.', ' To Be Confirmed ', null, undefined]) {
      expect(prospectService.isPlaceholder(v)).toBe(true);
    }
    for (const v of ['1 Quarry Road', 'To be confirmed by phone', 'Confirmed']) {
      expect(prospectService.isPlaceholder(v)).toBe(false);
    }
    const id = await addProspect();
    const c = await clientRepository.find(id);
    const page = (await agent.get(`/clients/${id}/edit`)).text;
    const fingerprint = page.match(/name="edit_fingerprint" value="([^"]*)"/)[1];
    const res = await post(`/clients/${id}/update`, {
      company_legal_name: c.company_legal_name, email: c.email, country_of_destination: 'Germany', consignee_same_as_buyer: '1', notify_party_same_as_consignee: '1',
      is_prospect: '1', billing_address_line1: 'To be confirmed', billing_city: 'To Be Confirmed', billing_country: 'To be confirmed', edit_fingerprint: fingerprint,
    }, `/clients/${id}/edit`);
    expect((await flashAfter(res)).message || '').not.toContain('no longer a prospect');
    const after = await clientRepository.find(id);
    expect(Number(after.is_prospect)).toBe(1);
    expect(after.prospect_expires_on).not.toBeNull();
  });

  // ---------------------------------------------------------------- 2. Clients list

  it('the Clients list filters prospects and shows their badge and date', async () => {
    const prospect = await addProspect('Badge Prospect Ltd');
    await setExpiry(prospect, day(5));
    const ordinary = await createTestClient(randomEmail());
    const all = (await agent.get('/clients')).text;
    expect(all).toMatch(/href="\/clients\?show=prospects">Prospects \(1\)/);
    expect(all).toMatch(/Badge Prospect Ltd<\/span><\/a>\s*<span class="tag tag-gold" data-testid="prospect-badge">Prospect<\/span>/);
    expect(all).toContain('prospect-expiry prospect-expiry-urgent');
    expect(all).toContain(`Quote expires ${short(day(5))} (in 5 days)`);
    const only = (await agent.get('/clients?show=prospects')).text;
    expect(only).toContain(`/clients/${prospect}"`);
    expect(only).not.toContain(`/clients/${ordinary}"`);
    const clients = (await agent.get('/clients?show=clients')).text;
    expect(clients).not.toContain(`/clients/${prospect}"`);
    expect(clients).toContain(`/clients/${ordinary}"`);
  });

  it('the prospect page explains the dates and offers its buttons', async () => {
    const id = await addProspect('Page Prospect Ltd');
    const orderId = await createTestOrder(id, 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    const html = (await agent.get(`/clients/${id}`)).text;
    expect(html).toContain('Prospect — details not confirmed yet.');
    expect(html).toContain(`/orders/${orderId}"`);
    expect(html).toContain(`this client to Deactivated on ${short(day(60))} (in 60 days)`);
    expect(html).toContain(`reminded on ${short(day(53))}`);
    expect(html).toContain('Fill in the details');
    expect(html).toContain(`/clients/${id}/send-quotation-form`);
    expect(html).toContain('Keep for 60 more days');
  });

  it('the Quotation Form e-mail is held in testing mode and logged', async () => {
    const id = await addProspect();
    await db.execute("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'email_testing_mode'");
    require('../../src/services/emailPolicy').resetCache && require('../../src/services/emailPolicy').resetCache();
    const res = await post(`/clients/${id}/send-quotation-form`, {}, `/clients/${id}`);
    expect((await flashAfter(res)).message).toContain('Not e-mailed: testing mode holds e-mails to buyers and suppliers');
    const row = await db.queryOne("SELECT action_type FROM audit_log WHERE entity_type = 'clients' AND entity_id = :id AND action_type LIKE 'CLIENT_QUOTATION_FORM%'", { id });
    expect(row.action_type).toBe('CLIENT_QUOTATION_FORM_NOT_EMAILED');
    expect(prospectService.quotationFormEmailBody('X Ltd', 'x@buyer.example', 'https://example.test/quotation-details', 'Co')).toContain('/quotation-details');
  });

  // ---------------------------------------------------------------- 4/5. Orders list, Standard and Kanban

  it('the Orders list and board both filter prospects with the same badge and line', async () => {
    const prospect = await addProspect('Board Prospect Ltd');
    await setExpiry(prospect, day(3));
    const pOrder = await createTestOrder(prospect, 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    const other = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [userId]);
    const list = (await agent.get('/orders?view=list&status=prospects')).text;
    await userRepository.updateUiTemplatePreference(userId, (await uiTemplateRepository.findByKey('kanban')).id);
    const board = (await agent.get('/orders?status=prospects')).text;
    expect(board).toContain('kanban-board');
    for (const html of [list, board]) {
      expect(html).toMatch(/class="filter-chip active" href="\/orders\?status=prospects[^"]*">Prospects \(1\)/);
      expect(html).toContain(`/orders/${pOrder}`);
      expect(html).not.toContain(`/orders/${other}"`);
      expect(html).not.toContain(`/orders/${other}?`);
      expect(html).toContain('data-testid="prospect-badge">Prospect</span>');
      expect(html).toContain('prospect-expiry prospect-expiry-urgent">No reply yet · expires in 3 days');
    }
    const all = (await agent.get('/orders?view=list&status=all')).text;
    expect(all.split('data-testid="prospect-badge"').length - 1).toBe(1);
  });

  // ---------------------------------------------------------------- 6. Reminder

  it('the owner is reminded once with a notification, a task and an e-mail', async () => {
    const owner = await makeUser('Sales/Export Manager');
    const id = await addProspect('Remind Prospect Ltd');
    const orderId = await createTestOrder(id, 'FOB', 'Standard — New Buyer', 'USD', null, null, [owner.id]);
    await setExpiry(id, day(20));
    expect(await prospectService.sendReminders()).toBe(0);
    await setExpiry(id, day(7));
    expect(await prospectService.sendReminders()).toBe(1);
    expect(await prospectService.sendReminders()).toBe(0);

    const n = await db.query("SELECT * FROM notifications WHERE type = 'prospect_expiry_reminder' AND related_order_id = :o", { o: orderId });
    expect(n).toHaveLength(1);
    expect(Number(n[0].user_id)).toBe(owner.id);
    expect(n[0].message.startsWith(`Reminder: quotation for Remind Prospect Ltd expires on ${short(day(7))}`)).toBe(true);
    expect(n[0].message).toContain('Nothing is deleted, and the client can be activated again.');
    expect(require('../../src/services/emailPolicy').kindForNotification('prospect_expiry_reminder')).toBe('staff_compliance');
    expect((await clientRepository.find(id)).prospect_reminded_on).toBe(prospectService.today());

    const tasks = (await departmentTaskRepository.open(['SALES'])).filter((t) => t.task_key === 'prospect_quote_expiring' && Number(t.order_id) === orderId);
    expect(tasks).toHaveLength(1);
    expect(tasks[0].subject).toContain('Remind Prospect Ltd — quotation expires on');

    const ownerAgent = await login(owner);
    const html = (await ownerAgent.get('/notifications')).text;
    expect(html).toContain(`<a href="/orders/${orderId}">Open the order</a>`);
    expect(html).toContain(`/clients/${id}/prospect-keep`);
    expect(html).toContain('Keep for 60 more days');
  });

  // ---------------------------------------------------------------- 7. Automatic expiry

  it('the daily job closes the quotation order and deactivates the prospect', async () => {
    const id = await addProspect('Expire Prospect Ltd');
    const orderId = await createTestOrder(id);
    await setExpiry(id, prospectService.today());
    const result = await prospectService.expireDue();
    expect([result.clients, result.orders]).toEqual([1, 1]);
    const o = await orderRepository.find(orderId);
    expect([o.status, Number(o.is_locked), o.lost_reason, o.lost_category, o.lost_by]).toEqual(['lost', 1, 'Quotation expired — no reply in 60 days', 'quotation_expired', null]);
    const c = await clientRepository.find(id);
    expect([Number(c.is_active), Number(c.is_prospect), c.prospect_expired_reason]).toEqual([0, 1, `Quote expired ${short(prospectService.today())} — no reply in 60 days`]);
    const audit = (await db.query("SELECT action_type FROM audit_log WHERE (entity_type = 'orders' AND entity_id = :o) OR (entity_type = 'clients' AND entity_id = :c)", { o: orderId, c: id })).map((r) => r.action_type);
    expect(audit).toEqual(expect.arrayContaining(['ORDER_MARKED_LOST', 'CLIENT_DEACTIVATED']));
    expect(await prospectService.expireDue()).toEqual({ clients: 0, orders: 0, skipped: [] });

    const page = (await agent.get(`/orders/${orderId}`)).text;
    expect(page).toContain(`Closed automatically on ${short(prospectService.today())}`);
    expect(page).toContain('use Duplicate order for a fresh quotation');
    const inactive = (await agent.get('/clients/inactive')).text;
    expect(inactive).toContain('Expire Prospect Ltd <span class="tag tag-gold" data-testid="prospect-badge">Prospect</span>');
    expect(inactive).toContain(`Quote expired ${short(prospectService.today())} — no reply in 60 days`);
    expect(inactive).toContain('Reactivate (fresh 60 days)');
  });

  it('the daily job never closes an order Mark Lost would refuse, or one past the quotation', async () => {
    const paid = await addProspect('Paid Prospect Ltd');
    const paidOrder = await createTestOrder(paid);
    await db.execute("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 1000, '2026-11-01', 84, 84000, 'UTR-D394-1')", { o: paidOrder });
    const ahead = await addProspect('Ahead Prospect Ltd');
    const aheadOrder = await createTestOrder(ahead);
    await require('../../src/services/stageGateService').passAndUnlockNext(aheadOrder, 1, userId);
    for (const c of [paid, ahead]) await setExpiry(c, day(-1));
    const result = await prospectService.expireDue();
    expect(result.clients).toBe(0);
    expect(result.skipped).toHaveLength(2);
    expect((await orderRepository.find(paidOrder)).status).toBe('active');
    expect((await orderRepository.find(aheadOrder)).status).toBe('active');
    expect(Number((await clientRepository.find(paid)).is_active)).toBe(1);
    expect(Number((await clientRepository.find(ahead)).is_active)).toBe(1);

    const quiet = await addProspect('Off Prospect Ltd');
    const quietOrder = await createTestOrder(quiet);
    await setExpiry(quiet, day(-1));
    await db.execute("UPDATE company_settings SET setting_value = '0' WHERE setting_key = 'prospect_auto_close'");
    expect(await prospectService.expireDue()).toEqual({ clients: 0, orders: 0, skipped: [] });
    expect((await orderRepository.find(quietOrder)).status).toBe('active');
  });

  // ---------------------------------------------------------------- 3/9. Keep, reactivate

  it('Keep for more days and Reactivate give a fresh date', async () => {
    const id = await addProspect('Keep Prospect Ltd');
    await setExpiry(id, day(3), prospectService.today());
    let res = await post(`/clients/${id}/prospect-keep`, {}, `/clients/${id}`);
    expect((await flashAfter(res)).message).toContain(`is kept until ${short(day(63))}`);
    let c = await clientRepository.find(id);
    expect([c.prospect_expires_on, c.prospect_reminded_on]).toEqual([day(63), null]);
    const last = await db.queryOne("SELECT action_type FROM audit_log WHERE entity_type = 'clients' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id });
    expect(last.action_type).toBe('CLIENT_PROSPECT_EXTENDED');

    await setExpiry(id, prospectService.today());
    await prospectService.expireDue();
    res = await post(`/clients/${id}/toggle-active`, {}, `/clients/${id}`);
    expect((await flashAfter(res)).message).toBe(`Keep Prospect Ltd is active again. It stays a prospect until its details are filled in; it now expires on ${short(day(60))}`
      + ' unless an order goes ahead. To quote again, open the old order (Did not go ahead) and use Duplicate order.');
    c = await clientRepository.find(id);
    expect([Number(c.is_active), Number(c.is_prospect), c.prospect_expires_on, c.prospect_expired_reason]).toEqual([1, 1, day(60), null]);
  });

  // ---------------------------------------------------------------- 10. Quotation requests

  it('a request from the prospect\'s e-mail updates that prospect', async () => {
    const email = randomEmail();
    const id = await addProspect('Quick Name', email);
    const orderId = await createTestOrder(id);
    await setExpiry(id, prospectService.today());
    await prospectService.expireDue();
    const ref = (await clientRepository.find(id)).client_unique_number;
    const sid = await clientIntakeRepository.create({
      company_legal_name: 'Returning Buyer GmbH', billing_address: '5 Stone Street, Hamburg 20095, Germany', billing_address_line1: '5 Stone Street', billing_address_line2: null,
      billing_city: 'Hamburg', billing_postcode: '20095', billing_country: 'Germany', vat_eori_tax_no: 'DE000000000', contact_person: 'Purchasing Manager',
      email: email.toUpperCase(), phone: '+49 40 000000', country_of_destination: 'Germany',
      port_of_discharge_text: null, coo_type: null, incoterm_preference: null, container_type_text: null, buyer_own_reference: null, notes: null,
    }, '127.0.0.1');

    const list = (await agent.get('/client-intake?view=list')).text;
    expect(list).toContain('Matches prospect');
    expect(list).toContain(`/client-intake/${sid}/update-prospect`);
    expect(list).toContain('Accept as a new client');
    const preview = (await agent.get(`/client-intake/${sid}/update-prospect`)).text;
    expect(preview).toMatch(/Company Legal Name<\/td>\s*<td class="muted">Quick Name<\/td>\s*<td>&rarr;<\/td>\s*<td><strong>Returning Buyer GmbH<\/strong>/);

    let res = await post(`/client-intake/${sid}/accept`, {}, '/client-intake?view=list');
    expect((await flashAfter(res)).message).toContain('Another client already uses the email');

    res = await post(`/client-intake/${sid}/update-prospect`, { client_id: String(id) }, `/client-intake/${sid}/update-prospect`);
    expect((await flashAfter(res)).message).toContain(`the same Buyer Inquiry Ref ${ref}`);
    const c = await clientRepository.find(id);
    expect([c.company_legal_name, c.billing_address_line1, c.billing_city, c.vat_eori_tax_no, c.client_unique_number, Number(c.is_prospect), Number(c.is_active), c.prospect_expires_on])
      .toEqual(['Returning Buyer GmbH', '5 Stone Street', 'Hamburg', 'DE000000000', ref, 0, 1, null]);
    expect(Number((await orderRepository.find(orderId)).client_id)).toBe(id);
    const s = await clientIntakeRepository.find(sid);
    expect([s.status, Number(s.converted_client_id)]).toEqual(['converted', id]);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM clients WHERE LOWER(email) = :e', { e: email.toLowerCase() })).n)).toBe(1);
  });

  // ---------------------------------------------------------------- 11. Settings

  it('the settings are validated and labelled', async () => {
    expect(settingValueValidator.check('number', '60', 'prospect_expiry_days')).toBeNull();
    expect(settingValueValidator.check('number', '6', 'prospect_expiry_days')).not.toBeNull();
    expect(settingValueValidator.check('number', '366', 'prospect_expiry_days')).not.toBeNull();
    expect(settingValueValidator.check('number', '30', 'prospect_reminder_days')).toBeNull();
    expect(settingValueValidator.check('number', '0', 'prospect_reminder_days')).not.toBeNull();
    expect(settingValueValidator.check('number', '31', 'prospect_reminder_days')).not.toBeNull();
    expect(settingLabels.sectionTitle('prospects')).toBe('Quick-quote prospects');
    expect(settingLabels.label('prospect_expiry_days')).toBe('Close a prospect\'s quotation after (days)');
    expect(settingLabels.label('prospect_reminder_days')).toBe('Remind the order\'s owner before (days)');
    expect(settingLabels.label('prospect_auto_close')).toBe('Automatic closing');
    expect((await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = 'quotation_validity_days'")).setting_value).toBe('30');
    const html = (await agent.get('/settings')).text;
    expect(html).toMatch(/min="7" max="365" step="1" required\s+name="settings\[prospect_expiry_days\]"[^>]*value="60"[^>]*placeholder="e.g. 60"/);
    expect(html).toMatch(/min="1" max="30" step="1" required\s+name="settings\[prospect_reminder_days\]"[^>]*value="7"/);
    expect(html).toContain('data-key="prospect_auto_close"');
  });
});
