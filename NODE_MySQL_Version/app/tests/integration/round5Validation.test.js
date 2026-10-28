'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const fieldValidator = require('../../src/helpers/fieldValidator');
const ordersController = require('../../src/controllers/ordersController');
const referenceDocController = require('../../src/controllers/referenceDocController');
const dropdownOptionController = require('../../src/controllers/dropdownOptionController');
const clientsController = require('../../src/controllers/clientsController');
const amendmentController = require('../../src/controllers/amendmentController');
const testModeController = require('../../src/controllers/testModeController');
const watermarkController = require('../../src/controllers/watermarkController');
const authController = require('../../src/controllers/authController');
const clientRepository = require('../../src/repositories/clientRepository');
const internalReferenceDocRepository = require('../../src/repositories/internalReferenceDocRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const watermarkSettingsRepository = require('../../src/repositories/watermarkSettingsRepository');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of Round5ValidationTest.php. QA Round 5, Batch 6 (QA package
 * v3/v4): the server refuses what the browser refuses, with a message and
 * nothing saved — never an HTTP 500 (R5-29), never an empty save that wipes
 * data (R5-30), never a malformed value stored (R5-28). Plus the versioned
 * app.js/app.css URLs (R5-26), the COOPREP review message (R5-25) and the
 * forgot-password wording.
 */
describe('Round 5 validation (Batch 6)', () => {
  let userId;

  beforeAll(async () => {
    userId = await createTestUser();
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-r5val-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  const fakeReq = (body = {}, params = {}) => ({ params, body, query: {}, user: { id: userId }, session: {}, permissions: {} });
  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = () => res;
    res.send = () => {};
    res.end = () => {};
    return res;
  }
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1] : { type: null, message: '' };
  };
  async function orderUnlockedTo(stage) {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    for (let s = 1; s < stage; s++) {
      await stageGateService.passAndUnlockNext(orderId, s, userId);
    }
    return orderId;
  }

  it('the shared validator rules', () => {
    expect(fieldValidator.wholeNumber('', 'X')).toBeNull();
    expect(fieldValidator.wholeNumber('45', 'X')).toBeNull();
    expect(fieldValidator.wholeNumber('-1', 'Lead')).toBe('Lead must be a whole number of 0 or more.');
    expect(fieldValidator.wholeNumber('4.5', 'X')).not.toBeNull();
    expect(fieldValidator.decimal('12.5', 'X')).toBeNull();
    expect(fieldValidator.decimal('12,5 kg', 'Rate')).toBe('Rate must be a number of 0 or more (digits and a decimal point only).');
    expect(fieldValidator.decimal('-1', 'Opacity', 0, 1)).toBe('Opacity must be a number from 0 to 1.');
    expect(fieldValidator.email('a@b.co', 'E')).toBeNull();
    expect(fieldValidator.email('not-an-email', 'E')).toBe('E must be a valid e-mail address.');
  });

  // R5-29: a negative lead time on Edit Order used to crash with HTTP 500.
  it('Edit Order refuses a negative lead time and keeps what was typed', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const order = await orderRepository.find(orderId);
    const req = fakeReq({ incoterm_id: String(order.incoterm_id), currency_id: String(order.currency_id), lead_time_min_days: '-1', lead_time_max_days: '60' }, { id: String(orderId) });
    await ordersController.updateDetails(req, fakeRes());

    const old = req.session._flash_old;
    const message = lastFlash(req);
    expect(message.type).toBe('error');
    expect(message.message).toContain('Production lead time (min days) must be a whole number');
    expect((await orderRepository.find(orderId)).lead_time_min_days).toBeNull();
    expect(old.lead_time_min_days).toBe('-1');
  });

  // R5-29: the same numbers on New Order.
  it('New Order refuses text in the CBM', () => {
    expect(fieldValidator.orderNumbers({ estimated_total_cbm: '12,5 kg' })).toBe('Total CBM must be a number of 0 or more (digits and a decimal point only).');
    expect(fieldValidator.orderNumbers({ sailing_lead_time_max_days: '-1' })).toBe('Sailing time (max days) must be a whole number of 0 or more.');
    expect(fieldValidator.orderNumbers({ lead_time_min_days: '45', estimated_total_cbm: '28.5' })).toBeNull();
  });

  // R5-29: text in the freight rate used to crash Freight Terms with HTTP 500.
  it('Freight Terms refuses text in the rate', async () => {
    const orderId = await orderUnlockedTo(6);
    const req = fakeReq({ confirmed_freight_rate: '12,5 kg', freight_forwarder_partner_id: '0' }, { id: String(orderId) });
    await ordersController.saveFreightTerms(req, fakeRes());

    expect(lastFlash(req).message).toBe('Confirmed freight rate must be a number of 0 or more (digits and a decimal point only).');
    const row = await db.queryOne('SELECT COUNT(*) AS n FROM order_freight WHERE order_id = :id', { id: orderId });
    expect(Number(row.n)).toBe(0);
  });

  // R5-28: container count 0 is refused on the server, like the form's min="1".
  it('Shipping refuses zero containers', async () => {
    const orderId = await orderUnlockedTo(7);
    const req = fakeReq({ container_count: '0' }, { id: String(orderId) });
    await ordersController.saveShipping(req, fakeRes());
    expect(lastFlash(req).message).toBe('Number of containers must be a whole number of 1 or more.');
  });

  // R5-30: a built-in reference document saved empty used to wipe it.
  it('a reference document cannot be saved empty', async () => {
    const before = ((await internalReferenceDocRepository.findByCode('STAGEGATE')) || {}).content || '';
    const req = fakeReq({ content: '   ' }, { code: 'STAGEGATE' });
    await referenceDocController.update(req, fakeRes());

    expect(lastFlash(req).type).toBe('error');
    expect(((await internalReferenceDocRepository.findByCode('STAGEGATE')) || {}).content || '').toBe(before);
  });

  // R5-30 / R5-28: a cleared option name or text in a sort order saves nothing.
  it('dropdown options refuse a blank name or a text sort order', async () => {
    const rows = await db.query("SELECT id, option_value, sort_order FROM dropdown_options WHERE list_key = 'unit' ORDER BY id");
    expect(rows.length).toBeGreaterThan(0);
    const first = rows[0];

    for (const [field, bad] of [['option_value', ''], ['sort_order', '12,5 kg']]) {
      const body = { option_value: {}, sort_order: {}, is_active: {} };
      for (const r of rows) {
        body.option_value[r.id] = r.option_value;
        body.sort_order[r.id] = String(r.sort_order);
        body.is_active[r.id] = '1';
      }
      body[field][first.id] = bad;
      const req = fakeReq(body, { listKey: 'unit' });
      await dropdownOptionController.update(req, fakeRes());
      expect([field, lastFlash(req).message]).toEqual([field, expect.stringContaining('nothing was saved')]);
    }
    const after = await db.queryOne('SELECT option_value, sort_order FROM dropdown_options WHERE id = :id', { id: first.id });
    expect(after.option_value).toBe(first.option_value);
    expect(Number(after.sort_order)).toBe(Number(first.sort_order));
  });

  // R5-28: a malformed client e-mail is refused on the server.
  it('Client update refuses a malformed e-mail', async () => {
    const clientId = await createTestClient();
    const client = await clientRepository.find(clientId);
    const req = fakeReq({ company_legal_name: client.company_legal_name, billing_address: client.billing_address || 'Somewhere 1', email: 'not-an-email' }, { id: String(clientId) });
    await clientsController.update(req, fakeRes());

    expect(lastFlash(req).message).toBe('Email must be a valid e-mail address.');
    expect((await clientRepository.find(clientId)).email).not.toBe('not-an-email');
  });

  // R5-28: text in the amended advance % is refused (it used to create the amendment).
  it('Amendment refuses text in the advance %', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ reason: 'Buyer asked to change the advance split.', amended_advance_pct: '12,5 kg' }, { id: String(orderId) });
    await amendmentController.create(req, fakeRes());

    expect(lastFlash(req).message).toBe('Amended advance % must be a number from 0 to 100.');
    const row = await db.queryOne('SELECT COUNT(*) AS n FROM amendments WHERE order_id = :id', { id: orderId });
    expect(Number(row.n)).toBe(0);
  });

  // R5-28: Test Mode's test e-mail and the watermark opacity.
  it('Test Mode e-mail and watermark opacity are checked', async () => {
    let req = fakeReq({ test_email: 'not-an-email' });
    await testModeController.updateTestEmail(req, fakeRes());
    expect(lastFlash(req).message).toBe('Test email must be a valid e-mail address.');

    const before = await watermarkSettingsRepository.findGlobal(true);
    req = fakeReq({ mode: 'text', text_content: 'DRAFT', opacity: '-1' }, { which: 'draft' });
    await watermarkController.update(req, fakeRes());
    expect(lastFlash(req).message).toBe('Text opacity must be a number from 0 to 1.');
    expect(((await watermarkSettingsRepository.findGlobal(true)) || {}).opacity).toEqual((before || {}).opacity);
  });

  // Forgot password with the e-mail left blank no longer answers "If  matches an account…".
  it('Forgot password asks for an e-mail', async () => {
    const req = fakeReq({ email: '' });
    await authController.forgotPassword(req, fakeRes());
    const message = lastFlash(req);
    expect([message.type, message.message]).toEqual(['error', 'Enter your e-mail address.']);
  });

  // R5-25: a COOPREP (no reference of its own) is named by its order, never "COOPREP null".
  it('a COOPREP review message names the order', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const documentId = await createTestDocument(orderId, 'COOPREP', 'draft');
    await db.execute('UPDATE documents SET document_reference = NULL WHERE id = :id', { id: documentId });
    const reviewerId = await createTestUser();

    await reviewWorkflowService.assignReviewers(documentId, [reviewerId], userId);

    const row = await db.queryOne("SELECT message FROM notifications WHERE user_id = :u AND type = 'review_assigned' ORDER BY id DESC LIMIT 1", { u: reviewerId });
    const { order_reference: orderReference } = await orderRepository.find(orderId);
    expect(row.message).toBe(`You've been assigned to review COOPREP for order ${orderReference} Rev.0.`);
  });

  // R5-26: app.js / app.css carry their last-change time, so a deploy reaches every browser at once.
  it('sitewide script and stylesheet URLs are versioned', () => {
    for (const layout of ['base', 'bare', 'client']) {
      const source = fs.readFileSync(path.join(__dirname, '../../views/layout', `${layout}.njk`), 'utf8');
      expect([layout, source.includes("asset('/js/app.js')"), source.includes("asset('/css/app.css')")]).toEqual([layout, true, true]);
    }
    const serverSource = fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8');
    expect(serverSource).toContain("njkEnv.addGlobal('asset',");
  });

  // R5-27: the browser stops these forms first (app.js reads these attributes).
  it('the forms carry their browser rules', () => {
    const views = path.join(__dirname, '../../views');
    const has = (file, needle) => expect([file, fs.readFileSync(path.join(views, file), 'utf8').includes(needle)]).toEqual([file, true]);
    has('dropdown_options/index.njk', '<input type="text" name="option_value" required');
    has('dropdown_options/index.njk', 'name="option_value[{{ o.id }}]" required');
    has('dropdown_options/index.njk', '<input type="number" min="0" step="1" required name="sort_order[');
    has('reference_docs/edit.njk', 'name="content" required');
    has('clients/edit.njk', 'name="agreement_file" required');
    has('orders/show.njk', 'data-required-unless="attachments" data-required-unless-message="Write a message or attach a file before posting."');
    has('orders/show.njk', '<input type="number" min="0" step="any" name="factory_distance_km"');
    has('reports/aggregate.njk', 'name="name" required');
    has('signatories/index.njk', "'' if u.is_signatory_eligible else ' required'");
    has('settings/index.njk', "'number' if row.value_type == 'number' else 'text'");
    has('amendments/index.njk', '<input type="number" min="0" max="100" step="any" name="amended_advance_pct"');
    has('watermarks/index.njk', '<input type="number" min="1" step="1" name="font_size"');
  });
});
