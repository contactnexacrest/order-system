'use strict';

/**
 * Owner decision D-330 (11 Nov 2026, "23 OK", confirmed twice): plain words on every
 * screen and e-mail a buyer, an associate or a supplier sees. Exact wording, a
 * representative set per audience, and the guarded e-mail-template SQL (it updates a
 * template only while its body is still the old seeded text). Parity: PlainLanguageD330Test.php.
 */
const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const associatePortalRepository = require('../../src/repositories/associatePortalRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const associatePayoutService = require('../../src/services/associatePayoutService');
const buyerActionService = require('../../src/services/buyerActionService');
const clientPortalService = require('../../src/services/clientPortalService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const paymentActionService = require('../../src/services/paymentActionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const SQL = path.join(__dirname, '..', 'fixtures', 'd330_plain_language_email_templates.sql');

async function runSql(sql) {
  for (const raw of sql.split(/;\n/)) {
    const statement = raw.split('\n').filter((l) => !l.trim().startsWith('--')).join('\n').trim();
    // pool.query without values: no named-placeholder parsing of the template text.
    if (statement !== '') await db.pool.query(statement);
  }
}

describe('D-330: plain language for buyers, associates and suppliers', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  // ---- Buyer --------------------------------------------------------------------------

  it('the buyer sees the stage in buyer words', () => {
    const stage = (status, n) => associatePortalRepository.stageLabel({ status, current_stage_number: n });
    expect([stage('active', 1), stage('active', 2), stage('active', 3), stage('active', 4), stage('active', 5), stage('active', 6),
      stage('active', 7), stage('active', 8), stage('active', 9), stage('complete', 3), stage('cancelled', 4), stage('lost', 2)])
      .toEqual(['Quotation', 'Order confirmed', 'Order confirmed', 'In production', 'In production', 'Shipping', 'Shipping', 'Shipping', 'Shipped', 'Shipped', 'Cancelled', 'Did not go ahead']);
  });

  it('a buyer never reads the word "lost"', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("UPDATE orders SET status = 'lost' WHERE id = :id", { id: orderId });
    const order = await orderRepository.find(orderId);
    expect(await clientPortalService.paymentReportRefusal(order)).toBe('This order did not go ahead, so there is no payment to tell us about.');
    expect((await buyerActionService.uploadBuyerPo({ body: {} }, order)).message)
      .toBe('This order did not go ahead, so you can no longer upload a purchase order. If you have a question, please contact your NexaCrest contact person.');

    await db.execute("UPDATE orders SET status = 'cancelled' WHERE id = :id", { id: orderId });
    expect(await clientPortalService.paymentReportRefusal(await orderRepository.find(orderId))).toBe('This order was cancelled, so there is no payment to tell us about.');
  });

  it('the payment link words', () => {
    expect(paymentActionService.LEGS.freight).toEqual({ doc: 'FDN', label: 'Freight and insurance', document: 'freight bill (debit note)' });
    expect(emailDispatchService.PAYMENT_LINK_PARAGRAPH.startsWith('PAYMENT DETAILS: once you have paid, please send us a screenshot of the payment and its details (bank reference number, amount and date) on this secure link. You do not need to sign in:')).toBe(true);
  });

  // ---- Associate ----------------------------------------------------------------------

  it('the associate commission words', async () => {
    expect(associatePayoutService.fixedRupeeLine(104312.5, 83.45, 'USD', '2026-11-12 10:00:00'))
      .toBe('We will pay you ₹104,312.50 (at ₹83.45 per USD, set on 12 Nov 2026; this will not change)');
    expect((await associatePayoutService.howFixedBox({ based_in: 'india' })).title).toBe('How we set your commission — it does not change later');
  });

  it('the associate client words', async () => {
    expect(await associatePortalRepository.cameVia({ associate_source: 'md_change' })).toBe('Changed by NexaCrest');
    expect(await associatePortalRepository.cameVia({ associate_source: 'staff' })).toBe('Linked by NexaCrest');
  });

  // ---- The guarded e-mail-template SQL -------------------------------------------------

  it('the template SQL updates only an unedited template and is safe to run twice', async () => {
    const sql = fs.readFileSync(SQL, 'utf8');
    expect(sql).toMatch(/\n-- payment_followup\n/);
    const row = await db.queryOne("SELECT subject, body FROM email_templates WHERE template_key = 'payment_followup'");
    const body = async () => (await db.queryOne("SELECT body FROM email_templates WHERE template_key = 'payment_followup'")).body;
    try {
      // Put the template back to the text seeded before D-330 (taken from the SQL's own guard).
      const m = sql.match(/WHERE template_key = 'payment_followup'\n {3}AND body = ('(?:[^']|'')*');/);
      expect(m).not.toBeNull();
      await db.pool.query(`UPDATE email_templates SET subject = 'Payment Follow-Up — Order {order_reference}', body = ${m[1]} WHERE template_key = 'payment_followup'`);

      await runSql(sql);
      const updated = await body();
      expect(updated).toContain('A payment on your order {order_reference} (your enquiry reference: {buyer_inquiry_ref}) is still open. Please make the payment as soon as you can and send us the bank receipt, so we can continue with your order.');

      await runSql(sql); // a second run changes nothing
      expect(await body()).toBe(updated);

      // An admin's own wording is never overwritten.
      await db.execute("UPDATE email_templates SET body = 'Our own follow-up text.' WHERE template_key = 'payment_followup'");
      await runSql(sql);
      expect(await body()).toBe('Our own follow-up text.');
    } finally {
      await db.execute("UPDATE email_templates SET subject = :s, body = :b WHERE template_key = 'payment_followup'", { s: row.subject, b: row.body });
    }
  });
});
