'use strict';

/**
 * Owner requests (3 Nov 2026), part A — Node twin of LinkFocusAndOcLinkTest.php:
 *  1. after any link is generated from the order page, the page comes back to that card,
 *     opened, with the new link focused (…?open=<card>#<card> + data-focus-link + app.js);
 *  2. the OC confirmation link (Step 6b) is created only by its button; Step 6b is done and
 *     the auto-confirm job counts only from that link;
 *  3. a click anywhere in a date box opens the calendar (app.js).
 */
const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const APP = path.join(__dirname, '..', '..');

describe('Link focus, OC confirmation link by button, date pickers (owner, 3 Nov 2026)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Managing Director') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Link User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-linkfocus-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createOcDocument(orderId) {
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'OC'");
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, 'approved')`,
      { o: orderId, t: type.id, r: `JEST-OCLINK-${Math.random().toString(16).slice(2, 10)}` }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = () => res;
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function call(fn, orderId, body = {}) {
    const req = { params: { id: String(orderId) }, body, session: {}, user: { id: await createTestUser() }, permissions: {} };
    const res = fakeRes();
    await ordersController[fn](req, res);
    return res.redirectedTo;
  }

  it('every order-page link action returns to its card, opened (?open=…#…)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect(await call('generatePiFormLink', orderId)).toBe(`/orders/${orderId}?open=step-5a-pi-form-link#step-5a-pi-form-link`);
    expect(await call('generateBuyerActionLink', orderId, { return_to: 'step-4b-buyer-po-link' })).toBe(`/orders/${orderId}?open=step-4b-buyer-po-link#step-4b-buyer-po-link`);
    expect(await call('generateBuyerActionLink', orderId)).toBe(`/orders/${orderId}?open=step-4b-buyer-po-link#step-4b-buyer-po-link`);
    expect(await call('generateBuyerActionLink', orderId, { return_to: 'step-6b-buyer-action-link' })).toBe(`/orders/${orderId}?open=step-6b-buyer-action-link#step-6b-buyer-action-link`);
    for (const anchor of ['step-5d-payment-record', 'step-9c-freight-remittance', 'step-balance-record']) {
      expect(await call('generatePaymentActionLink', orderId, { return_to: anchor })).toBe(`/orders/${orderId}?open=${anchor}#${anchor}`);
    }
    expect(await call('generatePaymentActionLink', orderId, { return_to: 'javascript:alert(1)' })).toBe(`/orders/${orderId}?open=step-5d-payment-record#step-5d-payment-record`);
  });

  it('the supplier link (Step 7c) returns to its card, opened', async () => {
    const src = fs.readFileSync(path.join(APP, 'src', 'controllers', 'ordersController.js'), 'utf8');
    expect(src).toMatch(/Supplier Action link[\s\S]{0,200}res\.redirect\(linkReturnUrl\(orderId, 'step-7c-supplier-po-attach'\)\)/);
    expect(typeof orderSupplierPoRepository.generateActionToken).toBe('function');
  });

  it('the view marks every link input for focus', () => {
    const view = fs.readFileSync(path.join(APP, 'views', 'orders', 'show.njk'), 'utf8');
    for (const v of ['buyerActionFullLink', 'paymentActionFullLink', 'piFormFullLink', 'supplierActionFullLink']) {
      expect(view).toMatch(new RegExp(`value="\\{\\{ ${v} \\}\\}" onclick="this\\.select\\(\\)" data-focus-link`));
    }
    expect(view).toContain("buyerActionLinkWidget('6b', 'step-6b-buyer-action-link', true)");
    expect(view).toContain("'6b': docComplete(documents, 'OC', mailSendingEnabled) and order.oc_link_generated_at,");
    expect(view).toContain("'Generate OC Confirmation Link'");
  });

  it('app.js focuses the link and opens date pickers on click', () => {
    const js = fs.readFileSync(path.join(APP, 'public', 'js', 'app.js'), 'utf8');
    expect(js).toContain("card.querySelector('[data-focus-link]')");
    expect(js).toContain('input.showPicker()');
    expect(js).toContain("['date', 'datetime-local', 'month', 'week', 'time']");
    expect(js).toContain('initDatePickers();');
    expect(js).toContain('initFocusLink();');
  });

  it('only the Step 6b button records the OC link', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await call('generateBuyerActionLink', orderId, { return_to: 'step-4b-buyer-po-link' });
    let order = await orderRepository.find(orderId);
    expect(order.oc_link_generated_at).toBeNull();
    expect(order.buyer_action_token_hash).toBeTruthy();
    await call('generateBuyerActionLink', orderId, { return_to: 'step-6b-buyer-action-link' });
    order = await orderRepository.find(orderId);
    expect(order.oc_link_generated_at).not.toBeNull();
  });

  it('the auto-confirm job waits for the OC link and counts from it', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fmt = (d) => d.toISOString().slice(0, 19).replace('T', ' ');
    await orderOcAcknowledgmentRepository.recordSent(orderId, await createOcDocument(orderId), fmt(new Date(Date.now() - 3 * 86400000)), fmt(new Date(Date.now() - 86400000)));
    const due = async () => (await orderOcAcknowledgmentRepository.dueForAutoConfirm()).map((r) => Number(r.order_id));

    expect(await due()).not.toContain(orderId);
    await db.execute('UPDATE orders SET oc_link_generated_at = NOW() - INTERVAL 1 HOUR WHERE id = :id', { id: orderId });
    expect(await due()).not.toContain(orderId);
    await db.execute('UPDATE orders SET oc_link_generated_at = NOW() - INTERVAL 3 DAY WHERE id = :id', { id: orderId });
    expect(await due()).toContain(orderId);
  });
});
