'use strict';

// Owner decisions (2 Nov 2026):
// (1) Logistics enters the CHA / transport / freight-forwarder bills
//     (manage_logistics_bills); recording a payment stays with
//     manage_supplier_payments (Finance / Accounts / management).
// (2) A buyer remittance can carry "our bank's charges on this credit",
//     booked at once as an order cost "Bank Charges" (D-212 description).
//     Bank Charges cost entries follow the 29 Oct rule: not sent to Zoho
//     Books (the CA books bank charges from the bank statement).
// PHP twin: LogisticsBillsAndOwnBankChargesTest.php.

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const partPaymentService = require('../../src/services/partPaymentService');
const remittanceDifference = require('../../src/services/remittanceDifference');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const PASSWORD = 'Jest-Logistics-Bills-2026!';

describe('Logistics enters logistics bills, Accounts pays; our bank charges on a credit', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-lbill-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Logistics Bills', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email, roleId: role.id, name: 'Jest Logistics Bills' };
  }

  async function signedInAs(roleName, orderId) {
    const u = await user(roleName);
    if (orderId) await db.execute('INSERT IGNORE INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: u.id });
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return { ...u, agent };
  }

  async function csrfFrom(agent, orderId) {
    const page = await agent.get(`/orders/${orderId}`);
    return page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  async function partner() {
    return (await db.execute("INSERT INTO logistics_partners (partner_name, service_type, is_active) VALUES ('Test CHA & Transport', 'both', 1)")).insertId;
  }

  async function bill(orderId, kind, partnerId, no) {
    return (await db.execute(
      `INSERT INTO order_vendor_bills (order_id, vendor_kind, logistics_partner_id, bill_for, bill_no, bill_date, taxable_inr, gst_inr, total_inr, payment_kind, doc_kind)
       VALUES (:o, :k, :p, :f, :n, CURDATE(), 1000, 180, 1180, 'advance', 'invoice')`,
      { o: orderId, k: kind, p: partnerId, f: kind === 'supplier' ? 'advance' : 'cha', n: no }
    )).insertId;
  }

  const count = async (sql, p) => Number((await db.queryOne(sql, p)).n);

  it('gives the new permission to Logistics and to everyone who could already pay', async () => {
    const holders = (await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE p.permission_key = 'manage_logistics_bills'`
    )).map((r) => r.name);
    for (const role of ['Logistics Executive', 'Logistics Manager', 'Logistics Director', 'Accounts Executive', 'Finance Manager', 'Finance Director', 'Admin', 'Managing Director', 'Executive Director']) {
      expect([role, holders.includes(role)]).toEqual([role, true]);
    }
    for (const role of ['Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director', 'Procurement Executive', 'Export Executive']) {
      expect([role, holders.includes(role)]).toEqual([role, false]);
    }
    const payers = (await db.query(
      `SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE p.permission_key = 'manage_supplier_payments'`
    )).map((r) => r.name);
    expect(payers.filter((r) => !holders.includes(r))).toEqual([]);
    expect(payers).not.toContain('Logistics Executive');
  });

  it('lets Logistics add and remove a logistics bill but not pay it', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    const logistics = await signedInAs('Logistics Executive', orderId);
    const csrf = await csrfFrom(logistics.agent, orderId);

    const add = await logistics.agent.post(`/orders/${orderId}/vendor-bills/logistics`).type('form').send({ _csrf: csrf, logistics_partner_id: '0' });
    expect(add.status).not.toBe(403);

    const billId = await bill(orderId, 'logistics', await partner(), 'TEST-LB-1');
    const pay = await logistics.agent.post(`/orders/${orderId}/vendor-bills/${billId}/pay`).type('form')
      .send({ _csrf: csrf, paid_amount_inr: '1180', paid_at: '2026-11-01', payment_mode: 'NEFT / RTGS', payment_ref: 'UTR1' });
    expect(pay.status).toBe(403);
    expect(await count('SELECT COUNT(*) AS n FROM order_vendor_bill_payments WHERE bill_id = :b', { b: billId })).toBe(0);

    const supplierBill = await bill(orderId, 'supplier', null, 'TEST-SB-1');
    const delSupplier = await logistics.agent.post(`/orders/${orderId}/vendor-bills/${supplierBill}/delete`).type('form').send({ _csrf: csrf });
    expect(delSupplier.status).toBe(403);
    expect(await count('SELECT COUNT(*) AS n FROM order_vendor_bills WHERE id = :b', { b: supplierBill })).toBe(1);

    const del = await logistics.agent.post(`/orders/${orderId}/vendor-bills/${billId}/delete`).type('form').send({ _csrf: csrf });
    expect(del.status).toBe(302);
    expect(await count('SELECT COUNT(*) AS n FROM order_vendor_bills WHERE id = :b', { b: billId })).toBe(0);
  });

  it('shows Logistics the card with the add form but no payment button', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    await bill(orderId, 'logistics', await partner(), 'TEST-LB-2');
    const logistics = await signedInAs('Logistics Executive', orderId);
    const html = (await logistics.agent.get(`/orders/${orderId}`)).text;
    const start = html.indexOf('id="logistics-bills"');
    expect(start).toBeGreaterThan(-1);
    const card = html.slice(start, html.indexOf('</details>', html.indexOf('vendor-bills/logistics', start)));
    expect(card).toContain('TEST-LB-2');
    expect(card).toContain('+ Add a bill');
    expect(card).toContain(`action="/orders/${orderId}/vendor-bills/logistics"`);
    expect(card).not.toContain('Mark Paid');
    expect(card).not.toContain('/pay"');
    expect(card).toContain('Accounts records the payment.');
    expect(card).toContain('>Remove</button>');
  });

  it('keeps payment with Accounts and keeps Procurement out of logistics bills', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    const billId = await bill(orderId, 'logistics', await partner(), 'TEST-LB-3');

    const accounts = await signedInAs('Accounts Executive', orderId);
    const csrf = await csrfFrom(accounts.agent, orderId);
    const pay = await accounts.agent.post(`/orders/${orderId}/vendor-bills/${billId}/pay`).type('form')
      .send({ _csrf: csrf, paid_amount_inr: '1180', paid_at: '2026-11-01', payment_mode: 'NEFT / RTGS', payment_ref: 'UTR778899' });
    expect(pay.status).not.toBe(403);
    expect(await count('SELECT COUNT(*) AS n FROM order_vendor_bill_payments WHERE bill_id = :b', { b: billId })).toBe(1);
    expect((await accounts.agent.get(`/orders/${orderId}`)).text).toContain(`action="/orders/${orderId}/vendor-bills/logistics"`);

    const procurement = await signedInAs('Procurement Executive', orderId);
    const pcsrf = await csrfFrom(procurement.agent, orderId);
    expect((await procurement.agent.post(`/orders/${orderId}/vendor-bills/logistics`).type('form').send({ _csrf: pcsrf, logistics_partner_id: '0' })).status).toBe(403);
    expect((await procurement.agent.get(`/orders/${orderId}`)).text).not.toContain(`action="/orders/${orderId}/vendor-bills/logistics"`);
  });

  async function orderAtAdvance() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('UPDATE orders SET advance_pct = 40 WHERE id = :o', { o: orderId });
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'PI'");
    await db.execute("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, 'approved')", { o: orderId, t: type.id, r: `JEST-OBC-${crypto.randomBytes(4).toString('hex')}` });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    return orderId;
  }

  async function recordAdvance(orderId, body, u) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: u.roleId });
    const req = { user: { id: u.id, role_id: u.roleId, name: u.name }, permissions: Object.fromEntries(rows.map((r) => [r.permission_key, true])), body, params: { id: String(orderId) }, session: {} };
    const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
    await ordersController.recordAdvancePayment(req, res);
    const flashes = req.session._flash || [];
    return flashes[flashes.length - 1] || { type: '', message: '' };
  }

  const advance = (extra) => ({ advance_amount: '1800', advance_received_at: '2026-10-20', advance_exchange_rate: '84', advance_bank_reference: 'UTR778801', ...extra });

  it('books our bank charges as an order cost with the receipt', async () => {
    const orderId = await orderAtAdvance();
    const accounts = await user('Accounts Executive');
    const r = await recordAdvance(orderId, advance({ own_bank_charges_inr: '590' }), accounts);
    expect([r.type, r.message]).toEqual(['success', expect.any(String)]);

    const s = await partPaymentService.summary(orderId, 'advance');
    expect([s.received, s.owed]).toEqual([1800, 0]);
    const receipt = s.receipts[0];
    expect(Number(receipt.own_bank_charges_inr)).toBe(590);

    const costs = await orderCostEntryRepository.forOrder(orderId);
    expect(costs).toHaveLength(1);
    const cost = costs[0];
    expect(Number(receipt.own_bank_charges_cost_id)).toBe(Number(cost.id));
    expect([cost.category, Number(cost.amount_inr), cost.paid_from]).toEqual(['bank_charges', 590, 'company_account']);
    const order = await orderRepository.find(orderId);
    const ref = order.buyer_inquiry_ref || order.order_reference || `#${orderId}`;
    expect(cost.description).toBe(`Our bank's inward remittance charges on ${order.company_legal_name}'s advance remittance for order ${ref}, ₹590.00 incl. GST · bank ref UTR778801`);
    expect(orderCostEntryRepository.ZOHO_CATEGORIES).not.toContain('bank_charges');
    expect(await count("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'REMITTANCE_OWN_BANK_CHARGES_RECORDED' AND entity_id = :id", { id: receipt.id })).toBe(1);
    const admin = await signedInAs('Admin', orderId);
    expect((await admin.agent.get(`/orders/${orderId}`)).text).toContain("our bank's charges &#8377;590.00 (order cost)");
  });

  it('treats our bank charges as optional and checks them', async () => {
    expect(remittanceDifference.postedOwnBankCharges({})).toBeNull();
    expect(remittanceDifference.postedOwnBankCharges({ own_bank_charges_inr: '  ' })).toBeNull();
    expect(remittanceDifference.postedOwnBankCharges({ own_bank_charges_inr: '590' })).toEqual({ value: 590 });
    expect(remittanceDifference.postedOwnBankCharges({ own_bank_charges_inr: '-5' }).error).toBe("Our bank's charges must be an amount in rupees above 0 — or leave the field empty.");
    expect(remittanceDifference.postedOwnBankCharges({ own_bank_charges_inr: '250000' }).error).toBe("Our bank's charges of ₹250,000.00 look too high for one credit (more than ₹100,000.00). Check the bank advice and enter the fee only.");

    const accounts = await user('Accounts Executive');
    const orderId = await orderAtAdvance();
    const r = await recordAdvance(orderId, advance({ advance_bank_reference: 'UTR778802' }), accounts);
    expect(r.type).toBe('success');
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
    const bad = await recordAdvance(await orderAtAdvance(), advance({ advance_bank_reference: 'UTR778803', own_bank_charges_inr: 'abc' }), accounts);
    expect([bad.type, bad.message]).toEqual(['error', "Our bank's charges must be an amount in rupees above 0 — or leave the field empty."]);

    const admin = await signedInAs('Admin', orderId);
    const html = (await admin.agent.get(`/orders/${orderId}`)).text;
    expect(html).toMatch(/<label>Our bank's charges on this credit \(&#8377;, incl\. GST\) <small class="muted">[^<]*<\/small><input type="number" step="0\.01" min="0\.01" max="100000\.00" name="own_bank_charges_inr" placeholder="e\.g\. 590\.00"><\/label>/);
  });
});
