'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const requestContext = require('../../src/helpers/requestContext');
const orderRepository = require('../../src/repositories/orderRepository');
const orderCancellationRepository = require('../../src/repositories/orderCancellationRepository');
const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
const clientIntakeRepository = require('../../src/repositories/clientIntakeRepository');
const importRepository = require('../../src/repositories/importRepository');
const merchantRepository = require('../../src/repositories/merchantTradeRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const ordersController = require('../../src/controllers/ordersController');
const amendmentController = require('../../src/controllers/amendmentController');
const paymentMilestoneController = require('../../src/controllers/paymentMilestoneController');
const disputeController = require('../../src/controllers/disputeController');
const clientIntakeReviewController = require('../../src/controllers/clientIntakeReviewController');
const amendmentService = require('../../src/services/amendmentService');
const partPaymentService = require('../../src/services/partPaymentService');
const importService = require('../../src/services/importService');
const domesticOrderService = require('../../src/services/domesticOrderService');
const merchantTradeService = require('../../src/services/merchantTradeService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA batch 2 (5 Nov 2026): order/workflow findings ORD-01…ORD-15, the
 * Merchant Trade money gate ACC-4, and the UI findings UI-6/7/8/13/14 on
 * the new division pages. Port of QaBatch2Test.php.
 */
describe('QA batch 2 — orders, workflow, Merchant Trade money and division pages', () => {
  afterAll(async () => {
    await require('../support/fixtures').clearDivisionTradeData(); // leave no division-trade records for later files
    await db.pool.end();
  });

  afterEach(async () => {
    await db.execute("UPDATE divisions SET status = 'planned' WHERE id <> 1");
    await db.execute('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
  });

  async function createUser(roleName, divisionId = 1) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-qa2-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestQaBatch2!23';
    const r = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, division_id)
       VALUES ('Jest QA2 User', :email, NULL, :hash, :role_id, 1, 0, 0, :d)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id, d: divisionId }
    );
    return { id: r.insertId, email, password, division_id: divisionId };
  }

  async function loginAs(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: user.password });
    return agent;
  }

  async function csrfOf(agent, page) {
    return (await agent.get(page)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  }

  function as(user, fn) {
    const seesAll = user.division_id === null;
    return requestContext.run({ userId: user.id, divisionIds: seesAll ? null : [user.division_id], divisionSeesAll: seesAll, divisionUser: { division_id: user.division_id } }, fn);
  }

  function fakeReq(userId, params = {}, body = {}) {
    return { params, body, query: {}, user: { id: userId }, session: {}, permissions: new Proxy({}, { get: () => true }) };
  }

  function fakeRes() {
    const res = { statusCode: 200, body: '' };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (b) => { res.body = b; };
    res.redirect = () => {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  const lastError = (req) => {
    const errors = (req.session._flash || []).filter((f) => f.type === 'error');
    return errors.length ? errors[errors.length - 1].message : null;
  };

  async function addReceipt(orderId, leg = 'advance', amount = 3000) {
    await db.execute(
      "INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', 84, :i, :r)",
      { o: orderId, l: leg, a: amount, i: amount * 84, r: `UTR-QA2-${orderId}-${leg}` }
    );
  }

  async function requestCancellation(orderId) {
    const requester = await createUser('Export Executive');
    await ordersController.requestCancellation(fakeReq(requester.id, { id: String(orderId) }, { reason: 'The buyer has cancelled the purchase in writing.' }), fakeRes());
    return Number((await orderCancellationRepository.findPendingForOrder(orderId)).id);
  }

  async function approve(orderId, cancellationId, body) {
    const md = await createUser('Managing Director');
    const req = fakeReq(md.id, { id: String(orderId), cancellationId: String(cancellationId) }, body);
    await ordersController.approveCancellation(req, fakeRes());
    return lastError(req);
  }

  // ------------------------------------------------------------ ORD-01, ORD-03, ORD-04

  it('an old cancellation request cannot cancel a shipped, settled or lost order (ORD-01)', async () => {
    for (const status of ['shipped_docs_sent', 'complete', 'lost']) {
      const orderId = await createTestOrder(await createTestClient());
      const cid = await requestCancellation(orderId);
      await db.execute('UPDATE orders SET status = :s WHERE id = :id', { s: status, id: orderId });
      expect(await approve(orderId, cid, { refund_status: 'not_applicable' })).toBe(`This order is now "${status}", so the cancellation can no longer be approved. Reject the request instead.`);
      expect((await orderRepository.find(orderId)).status).toBe(status);
      expect((await orderCancellationRepository.find(cid)).status).toBe('pending');
      expect(await orderRepository.cancel(orderId)).toBe(false);
    }
  });

  it('approval never marks a refund completed and checks the amount (ORD-03)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const cid = await requestCancellation(orderId);
    expect(await approve(orderId, cid, { refund_status: 'completed', refund_amount: '100' })).toBe('Approve with "Refund pending", then record the refund with the date it was paid and its bank reference.');
    expect(await approve(orderId, cid, { refund_status: 'pending', refund_amount: '-500' })).toBe('Enter the refund amount as a number above zero, or leave it empty.');
    expect(await approve(orderId, cid, { refund_status: 'pending', refund_amount: 'abc' })).toBe('Enter the refund amount as a number above zero, or leave it empty.');
    await addReceipt(orderId, 'advance', 3000);
    expect(await partPaymentService.orderReceivedTotal(orderId)).toBe(3000);
    expect(await approve(orderId, cid, { refund_status: 'pending', refund_amount: '3000.01' })).toBe('The refund (3,000.01) is more than the buyer has paid on this order (3,000.00).');
    expect((await orderRepository.find(orderId)).status).toBe('active');

    expect(await approve(orderId, cid, { refund_status: 'pending', refund_amount: '3000' })).toBeNull();
    const c = await orderCancellationRepository.find(cid);
    expect([c.status, c.refund_status, String(c.refund_amount)]).toEqual(['approved', 'pending', '3000.00']);
    expect((await orderRepository.find(orderId)).status).toBe('cancelled');
  });

  it('Mark Lost is refused once the buyer has paid or a cancellation is pending (ORD-04)', async () => {
    const exec = await createUser('Export Executive');
    const markLost = async (orderId) => {
      const req = fakeReq(exec.id, { id: String(orderId) }, { reason: 'The buyer stopped answering our emails.' });
      await ordersController.markLost(req, fakeRes());
      return lastError(req);
    };
    const paid = await createTestOrder(await createTestClient());
    await addReceipt(paid);
    expect(await markLost(paid)).toBe('The buyer has already paid on this order, so it cannot be marked lost. Use Request Cancellation: a second person approves it and the refund is recorded.');
    expect((await orderRepository.find(paid)).status).toBe('active');

    const pending = await createTestOrder(await createTestClient());
    await requestCancellation(pending);
    expect(await markLost(pending)).toBe('This order has a cancellation request awaiting a decision — approve or reject it instead of marking the order lost.');

    const clean = await createTestOrder(await createTestClient());
    expect(await markLost(clean)).toBeNull();
    expect((await orderRepository.find(clean)).status).toBe('lost');
  });

  // ------------------------------------------------------------ ORD-02, ORD-06

  it('an amendment cannot leave a negative balance on a scheduled order (ORD-02)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const advance = parseFloat((await orderRepository.find(orderId)).advance_pct);
    await paymentMilestoneRepository.replaceSchedule(orderId, [{ pct: 40, due_text: 'on dispatch' }], advance);
    expect(await amendmentService.milestoneOverflowError(orderId, 70)).toBe('Advance 70% + payment milestones 40% leave nothing for the balance — together they must stay below 100%. Change the payment schedule first.');
    expect(await amendmentService.milestoneOverflowError(orderId, 50)).toBeNull();

    const admin = await createUser('Admin');
    const req = fakeReq(admin.id, { id: String(orderId) }, { reason: 'The buyer asked for a larger advance.', requested_by: 'importer', amended_advance_pct: '70' });
    await amendmentController.create(req, fakeRes());
    expect(lastError(req)).toContain('leave nothing for the balance');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM amendments WHERE order_id = :o', { o: orderId })).n)).toBe(0);
  });

  it("an LC order's schedule is fixed once the LC is confirmed (ORD-06)", async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute('INSERT INTO order_lc_details (order_id, lc_amount, lc_confirmed_at) VALUES (:o, 1000, NOW())', { o: orderId });
    const admin = await createUser('Admin');
    const req = fakeReq(admin.id, { id: String(orderId) }, { milestone_pct: ['30'], milestone_due: ['on dispatch'] });
    await paymentMilestoneController.saveSchedule(req, fakeRes());
    expect(lastError(req)).toBe('The payment schedule is fixed once the LC is confirmed usable — change it with a payment terms amendment.');
    expect(await paymentMilestoneRepository.forOrder(orderId)).toEqual([]);
  });

  // ------------------------------------------------------------ ORD-07, ORD-10, ORD-11, ORD-15

  it('the incoterm cannot change once the freight stage is settled (ORD-07)', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    await db.execute("UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'skipped' WHERE os.order_id = :o AND sm.stage_number = 6", { o: orderId });
    const order = await orderRepository.find(orderId);
    const cfr = (await db.queryOne("SELECT id FROM incoterms WHERE code = 'CFR'")).id;
    const admin = await createUser('Admin');
    const req = fakeReq(admin.id, { id: String(orderId) }, { incoterm_id: String(cfr), currency_id: String(order.currency_id), reason: 'The buyer now wants CFR terms.' });
    await ordersController.updateDetails(req, fakeRes());
    expect(lastError(req)).toBe('The incoterm can no longer change: the freight stage (Stage 6) is already skipped for the current incoterm. Agree the change with the buyer through an amendment, and ask an administrator to correct the order.');
    expect(Number((await orderRepository.find(orderId)).incoterm_id)).toBe(Number(order.incoterm_id));
  });

  it('the LC route is refused on a cash-advance order (ORD-10)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect((await orderRepository.find(orderId)).payment_method).toBe('CASH_ADVANCE');
    const admin = await createUser('Admin');
    const r1 = fakeReq(admin.id, { id: String(orderId) }, { lc_amount: '1000', lc_number: 'LC-QA2' });
    await ordersController.recordLcDetails(r1, fakeRes());
    expect(lastError(r1)).toBe('This order is not paid by Letter of Credit.');
    const r2 = fakeReq(admin.id, { id: String(orderId) }, {});
    await ordersController.confirmLcUsable(r2, fakeRes());
    expect(lastError(r2)).toBe('This order is not paid by Letter of Credit.');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM order_lc_details WHERE order_id = :o', { o: orderId })).n)).toBe(0);
  });

  it('a dispute needs a real past notice date and an active assignee (ORD-11)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const admin = await createUser('Admin');
    const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM disputes WHERE order_id = :o', { o: orderId })).n);
    const tomorrow = new Date(Date.now() + 36 * 3600 * 1000);
    const future = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;
    for (const bad of ['31/10/2026', '2026-02-30', future]) {
      const req = fakeReq(admin.id, { id: String(orderId) }, { notice_date: bad, description: 'Colour variation on two crates.' });
      await disputeController.create(req, fakeRes());
      expect(lastError(req)).toBe('Notice date: enter the date the notice was received (YYYY-MM-DD, today or earlier).');
    }
    const inactive = await createUser('Export Executive');
    await db.execute('UPDATE users SET is_active = 0 WHERE id = :id', { id: inactive.id });
    const today = require('../../src/helpers/dbDateTime').todayIst();
    const r = fakeReq(admin.id, { id: String(orderId) }, { notice_date: today, description: 'Colour variation on two crates.', assigned_to: String(inactive.id) });
    await disputeController.create(r, fakeRes());
    expect(lastError(r)).toBe('Assign the dispute to an active user, or leave it unassigned.');
    expect(await count()).toBe(0);
    await disputeController.create(fakeReq(admin.id, { id: String(orderId) }, { notice_date: today, description: 'Colour variation on two crates.' }), fakeRes());
    expect(await count()).toBe(1);
  });

  it('the archived list follows the assignment rule (ORD-15)', async () => {
    const mine = await createUser('Export Executive');
    const assigned = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, mine.id);
    const other = await createTestOrder(await createTestClient());
    await db.execute(`UPDATE orders SET is_archived = 1, archived_at = NOW() WHERE id IN (${Number(assigned)}, ${Number(other)})`);
    const ids = (await orderRepository.allArchived(mine.id, { view_archived_orders: true })).map((o) => Number(o.id));
    expect(ids).toContain(Number(assigned));
    expect(ids).not.toContain(Number(other));
    expect((await orderRepository.allArchived(mine.id, { view_archived_orders: true, view_all_orders: true })).map((o) => Number(o.id))).toContain(Number(other));
  });

  // ------------------------------------------------------------ ORD-05, ORD-13

  it("the quotation-intake queue is Export's and a reject needs a pending submission (ORD-05, ORD-13)", async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = 2");
    const res = fakeRes();
    await requestContext.run({ userId: 1, divisionIds: [2], divisionSeesAll: false }, () => clientIntakeReviewController.index(fakeReq(1), res));
    expect([res.statusCode, res.body]).toEqual([403, 'The quotation enquiry queue belongs to the Export division.']);

    const email = `qa2-${Math.random().toString(16).slice(2, 8)}@buyer.example`;
    const r = await db.execute("INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, status) VALUES ('QA2 Buyer', 'Addr', 'Ann', :e, 'Germany', 'converted')", { e: email });
    const converted = r.insertId;
    const admin = await createUser('Admin');
    const req = fakeReq(admin.id, { id: String(converted) }, { reason: 'Duplicate of an existing enquiry.' });
    await clientIntakeReviewController.reject(req, fakeRes());
    expect(lastError(req)).toBe('Submission not found, or already resolved.');
    expect((await clientIntakeRepository.find(converted)).status).toBe('converted');
    const req2 = fakeReq(admin.id, { id: '999999999' }, { reason: 'Duplicate of an existing enquiry.' });
    await clientIntakeReviewController.reject(req2, fakeRes());
    expect(lastError(req2)).toBe('Submission not found, or already resolved.');
    expect(await clientIntakeRepository.markRejected(converted, 1, 'x')).toBe(false);
  });

  // ------------------------------------------------------------ ORD-08, ORD-12

  it('an import PO cannot change under its payments (ORD-08, ORD-12)', async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = 4");
    await db.execute("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 4 THEN 1 ELSE 0 END WHERE module_key = 'import_stock'");
    const staff = await createUser('Procurement Manager', 4);
    const code = `QA2-${Math.random().toString(16).slice(2, 8)}`;
    const product = (await db.execute("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'QA2 granite', '68029300')", { c: code })).insertId;
    const d5 = new Date(Date.now() - 5 * 86400000);
    const header = { supplier_name: 'TEST-QA2 Supplier', supplier_country: 'China', currency_code: 'USD', po_date: `${d5.getFullYear()}-${String(d5.getMonth() + 1).padStart(2, '0')}-${String(d5.getDate()).padStart(2, '0')}` };
    const today = require('../../src/helpers/dbDateTime').todayIst();
    await as(staff, async () => {
      const [id, error] = await importService.create(header, staff.id);
      expect(error).toBeNull();
      expect(await importService.addLine(id, { product_id: product, quantity: '10', unit: 'sqm', unit_price_fc: '100' }, staff.id)).toBeNull();
      expect(await importService.addLine(id, { product_id: product, quantity: '1', unit: 'sqm', unit_price_fc: '50' }, staff.id)).toBeNull();
      await importService.markPoGenerated(id, staff.id);
      expect(await importService.recordPayment(id, { kind: 'advance', paid_on: today, amount_fc: '1000', fx_rate: '84', bank_reference: `SWIFT-QA2-${id}` }, staff.id)).toBeNull();

      expect(await importService.saveHeader(id, { ...header, currency_code: 'EUR' }, staff.id)).toBe('The supplier has already been paid in USD, so the PO currency can no longer change.');
      const lines = await importRepository.lines(id);
      expect(await importService.removeLine(id, Number(lines[0].id), staff.id)).toBe('Removing this line would take the PO value (USD 50.00) below what the supplier has already been paid (USD 1,000.00).');
      expect((await importRepository.find(id)).ipo_generated_at).not.toBeNull();
      expect(await importService.removeLine(id, Number(lines[1].id), staff.id)).toBeNull();
      expect((await importRepository.find(id)).ipo_generated_at).toBeNull();
      // Two people recording the same SWIFT/UTR at the same moment: exactly one is accepted.
      expect(await importService.addLine(id, { product_id: product, quantity: '1', unit: 'sqm', unit_price_fc: '50' }, staff.id)).toBeNull();
      const both = await Promise.all([1, 2].map(() => importService.recordPayment(id, { kind: 'balance', paid_on: today, amount_fc: '1', fx_rate: '84', bank_reference: `SWIFT-QA2-SAME-${id}` }, staff.id)));
      expect(both.filter((e) => e === null)).toHaveLength(1);
      expect(both.filter((e) => e === 'This bank reference is already recorded. Check the payment is not entered twice.')).toHaveLength(1);
    });
  });

  it('a domestic receipt never takes the payments above the tax invoice (ORD-12)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("INSERT INTO domestic_orders (order_id, stage, place_of_supply, seller_state_code, tax_invoice_no, invoice_total) VALUES (:o, 'payment', '29', '29', :n, '50000.00')", { o: orderId, n: `DOM/QA2/${orderId}` });
    const admin = await createUser('Admin');
    const today = require('../../src/helpers/dbDateTime').todayIst();
    const pay = (amount, ref) => domesticOrderService.recordPayment(orderId, { received_on: today, amount_inr: amount, bank_reference: ref }, admin.id);
    expect(await pay('30000', `UTR-QA2-A-${orderId}`)).toBeNull();
    expect(await pay('20000.01', `UTR-QA2-B-${orderId}`)).toBe(`This is more than the buyer still owes on tax invoice DOM/QA2/${orderId} (₹20000.00).`);
    expect(await pay('20000', `UTR-QA2-C-${orderId}`)).toBeNull();
    expect(await domesticOrderService.paidTotal(orderId)).toBe('50000.00');
    await db.execute('DELETE FROM domestic_payments WHERE order_id = :o', { o: orderId });
    await db.execute('DELETE FROM domestic_orders WHERE order_id = :o', { o: orderId });
  });

  // ------------------------------------------------------------ ACC-4, UI-6, UI-8, UI-13

  async function merchantDeal(user) {
    const day = (n) => {
      const d = new Date(Date.now() - n * 86400000);
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };
    return as(user, async () => {
      const [id, error] = await merchantTradeService.create({
        deal_date: day(40), supplier_name: 'TEST-QA2 Marble', supplier_country: 'Turkey', purchase_currency: 'EUR',
        buyer_name: 'TEST-QA2 Gulf Buyer', buyer_country: 'United Arab Emirates', sale_currency: 'USD', origin_country: 'Turkey',
      }, user.id);
      expect(error).toBeNull();
      expect(await merchantTradeService.addLine(id, { description: 'Marble', hsn_code: '68022100', quantity: '10', unit: 'sqm', buy_price_fc: '30', sell_price_fc: '45' }, user.id)).toBeNull();
      expect(await merchantTradeService.recordPayment(id, { leg: 'buy', moved_on: day(30), amount_fc: '100', fx_rate: '91', bank_reference: `QA2-B-${id}` }, user.id)).toBeNull();
      expect(await merchantTradeService.recordPayment(id, { leg: 'sell', moved_on: day(29), amount_fc: '200', fx_rate: '84', bank_reference: `QA2-S-${id}` }, user.id)).toBeNull();
      return Number(id);
    });
  }

  it('Procurement sees neither the sale nor the buyer of a merchant deal (ACC-4)', async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id = 3");
    await db.execute("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 3 THEN 1 ELSE 0 END WHERE module_key = 'merchant_trade'");
    const md = await createUser('Managing Director', null);
    const id = await merchantDeal(md);
    const proc = await createUser('Procurement Manager', 3);

    const full = (await (await loginAs(md)).get(`/merchant/${id}`)).text;
    for (const text of ['TEST-QA2 Gulf Buyer', 'Sell USD', 'Received from the buyer', `QA2-S-${id}`, 'Download sale invoice']) expect(full).toContain(text);

    const agent = await loginAs(proc);
    const page = (await agent.get(`/merchant/${id}`)).text;
    expect(page).toContain(`QA2-B-${id}`);
    for (const text of ['TEST-QA2 Gulf Buyer', 'Sell USD', 'Received from the buyer', `QA2-S-${id}`, 'Download sale invoice', 'margin ₹']) expect(page).not.toContain(text);
    for (const url of ['/merchant', '/merchant?view=list']) {
      const list = (await agent.get(url)).text;
      expect(list).not.toContain('TEST-QA2 Gulf Buyer');
      expect(list).not.toContain('Sell USD');
    }
    const reports = (await agent.get('/merchant/reports')).text;
    expect(reports).not.toContain('TEST-QA2 Gulf Buyer');
    expect(reports).not.toContain('Margin per deal');
    const csrf = await csrfOf(agent, `/merchant/${id}`);
    const res = await agent.post(`/merchant/${id}/document`).type('form').send({ _csrf: csrf, kind: 'invoice' });
    expect(res.status).toBe(302);
    expect((await merchantRepository.find(id)).sale_invoice_no).toBeNull();
    expect((await agent.get(`/merchant/${id}`)).text).toContain('The sale invoice carries selling prices your department does not see.');
  });

  it('the merchant and import cards carry what their lists show, and an empty stage says so (UI-6, UI-8, UI-13)', async () => {
    await db.execute("UPDATE divisions SET status = 'active' WHERE id IN (3, 4)");
    await db.execute("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 3 THEN 1 ELSE 0 END WHERE module_key = 'merchant_trade'");
    await db.execute("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 4 THEN 1 ELSE 0 END WHERE module_key = 'import_stock'");
    const md = await createUser('Managing Director', null);
    await userRepository.updateUiTemplatePreference(md.id, (await uiTemplateRepository.findByKey('kanban')).id);
    const deal = await merchantDeal(md);
    const dealDate = String((await merchantRepository.find(deal)).deal_date);
    const d9 = new Date(Date.now() - 9 * 86400000);
    const [imp] = await as(md, () => importService.create({ supplier_name: 'TEST-QA2 Card Supplier', supplier_country: 'China', currency_code: 'USD', po_date: `${d9.getFullYear()}-${String(d9.getMonth() + 1).padStart(2, '0')}-${String(d9.getDate()).padStart(2, '0')}` }, md.id));
    await importRepository.update(imp, { bl_no: 'QA2BL123', boe_no: '7654321' }, md.id);

    const agent = await loginAs(md);
    const board = (await agent.get('/merchant')).text;
    expect(board).toContain('kanban-board');
    expect(board).toContain(`Deal date ${dealDate}`);
    expect((await agent.get('/import')).text).toContain('BL QA2BL123 · Bill of entry 7654321');
    expect((await agent.get('/merchant?view=list&stage=closed')).text).toContain('None at this stage.');
    expect((await agent.get('/import?view=list&stage=closed')).text).toContain('None at this stage.');

    const show = (await agent.get(`/merchant/${deal}`)).text;
    expect(show).toMatch(/name="switch_bl_no"[^>]*data-required-when="switch_bl=1"/);
    expect(show).toMatch(new RegExp(`name="bl_date" required min="${dealDate}"`));
    expect((await agent.get(`/import/${imp}`)).text).toMatch(/name="supplier_country"[^>]*data-pattern-message="An import comes from a supplier abroad: the country cannot be India."/);
  });
});
