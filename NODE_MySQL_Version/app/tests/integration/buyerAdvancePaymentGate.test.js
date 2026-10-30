'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderBuyerPoDocumentRepository = require('../../src/repositories/orderBuyerPoDocumentRepository');
const clientPaymentReportRepository = require('../../src/repositories/clientPaymentReportRepository');
const buyerActionController = require('../../src/controllers/buyerActionController');
const clientPortalController = require('../../src/controllers/clientPortalController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of BuyerAdvancePaymentGateTest.php. 90-item backlog #19/#48.
 *
 * #19: buyerActionService.uploadBuyerPo() used to be callable "at any
 * time" on both the client-portal and no-login Buyer Action Link
 * channels — this adds a gate so a further client self-upload is refused
 * once the advance payment has already cleared (the Buyer PO's whole job
 * is done by then).
 *
 * #48: the client portal's own payment self-report
 * (clientPortalController.reportPayment()) only exists for a buyer who
 * can log in, but the portal login itself isn't provisioned until the
 * advance payment clears — so the advance leg specifically could never
 * actually be self-reported through it. This adds a narrow no-login
 * equivalent (buyerActionService.reportAdvancePayment(), scoped to the
 * advance leg only) on the same Buyer Action Link page, which itself
 * closes once the advance clears (there is nothing left to report).
 */
describe('Buyer advance-payment gate (90-item backlog #19/#48)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null, renderedView: null, renderedData: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  function fakeReq(params, body = {}, session = {}) {
    return { params, body, session };
  }

  async function markAdvanceCleared(orderId) {
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, new Date(), 1);
  }

  // ---------------------------------------------------------------
  // #19 — uploadBuyerPo, via the no-login Buyer Action Link
  // ---------------------------------------------------------------

  it('uploadBuyerPo() still reaches the upload path before the advance clears', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token });
    const res = fakeRes();

    await buyerActionController.uploadBuyerPo(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('No file was uploaded');
  });

  it('uploadBuyerPo() via token is refused once the advance has cleared', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await markAdvanceCleared(orderId);
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token });
    const res = fakeRes();

    await buyerActionController.uploadBuyerPo(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('already cleared');
    expect(await orderBuyerPoDocumentRepository.forOrder(orderId)).toEqual([]);
  });

  it('uploadBuyerPo() via the portal is also refused once the advance has cleared', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await markAdvanceCleared(orderId);
    const req = { params: { id: String(orderId) }, body: {}, session: { _client_portal_client_id: clientId } };
    const res = fakeRes();

    await clientPortalController.uploadBuyerPo(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('already cleared');
  });

  // ---------------------------------------------------------------
  // #48 — reportAdvancePayment, via the no-login Buyer Action Link
  // ---------------------------------------------------------------

  it('reportPayment() via token records the advance payment before it clears', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'PI', 'sent'); // QA Round 6, R6-N24: a payment is reported against an issued PI.
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token }, {
      transaction_ref: 'UTR123456789',
      amount: '5000.00',
      payment_date: '2026-10-10',
      payer_bank_details: 'Sent from HSBC UK',
    });
    const res = fakeRes();

    await buyerActionController.reportPayment(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');

    const reports = await clientPaymentReportRepository.forOrder(orderId);
    expect(reports).toHaveLength(1);
    expect(reports[0].payment_type).toBe('advance');
    expect(reports[0].transaction_ref).toBe('UTR123456789');
  });

  // External QA report L-30/M-07: neither buyer self-service payment
  // report ever told staff at all — the only way to notice was to happen
  // to reopen the order and see the row in clientPaymentReports.
  it('reportPayment() via token is audit-logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'PI', 'sent'); // QA Round 6, R6-N24: a payment is reported against an issued PI.
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token }, { transaction_ref: 'UTR123456789' });

    await buyerActionController.reportPayment(req, fakeRes());

    const log = await db.queryOne('SELECT * FROM audit_log WHERE action_type = :t ORDER BY id DESC LIMIT 1', { t: 'CLIENT_PAYMENT_REPORTED' });
    expect(log).toBeTruthy();
    expect(log.entity_id).toBe(orderId);
    expect(log.new_value).toBe('advance');
    expect(log.reason).toContain('UTR123456789');
  });

  it('clientPortalController.reportPayment() is audit-logged', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await createTestDocument(orderId, 'PI', 'sent'); // QA Round 6, R6-N24: a payment is reported against an issued PI.
    const req = fakeReq({ id: String(orderId) }, { payment_type: 'balance', transaction_ref: 'UTR999888777' }, { _client_portal_client_id: clientId });

    await clientPortalController.reportPayment(req, fakeRes());

    const log = await db.queryOne('SELECT * FROM audit_log WHERE action_type = :t ORDER BY id DESC LIMIT 1', { t: 'CLIENT_PAYMENT_REPORTED' });
    expect(log).toBeTruthy();
    expect(log.entity_id).toBe(orderId);
    expect(log.new_value).toBe('balance');
    expect(log.reason).toContain('UTR999888777');
  });

  it('reportPayment() via token requires a transaction reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token });
    const res = fakeRes();

    await buyerActionController.reportPayment(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(await clientPaymentReportRepository.forOrder(orderId)).toEqual([]);
  });

  it('reportPayment() via token is refused once the advance has cleared', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await markAdvanceCleared(orderId);
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const req = fakeReq({ token }, { transaction_ref: 'UTR999' });
    const res = fakeRes();

    await buyerActionController.reportPayment(req, res);

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('already been marked cleared');
    expect(await clientPaymentReportRepository.forOrder(orderId)).toEqual([]);
  });

  it('reportPayment() with an invalid token is refused', async () => {
    const res = fakeRes();

    await buyerActionController.reportPayment(fakeReq({ token: 'not-a-real-token' }), res);

    expect(res.renderedView).toBe('buyer_action/link_expired');
  });

  // ---------------------------------------------------------------
  // View rendering — buyer_action/show data
  // ---------------------------------------------------------------

  it('show() exposes advanceCleared=false before the advance clears', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const res = fakeRes();

    await buyerActionController.show(fakeReq({ token }), res);

    expect(res.renderedData.advanceCleared).toBe(false);
  });

  it('show() exposes advanceCleared=true once the advance has cleared', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await markAdvanceCleared(orderId);
    const token = await orderRepository.generateBuyerActionToken(orderId);
    const res = fakeRes();

    await buyerActionController.show(fakeReq({ token }), res);

    expect(res.renderedData.advanceCleared).toBe(true);
  });
});
