'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const gstRefundRepository = require('../../src/repositories/gstRefundRepository');
const caSyncService = require('../../src/services/caSyncService');
const paymentDescription = require('../../src/services/paymentDescription');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of GstRefundTest.php. Owner decision 2026-10-31 (option A,
 * confirmed): GST under LUT. The GST paid on purchases is claimed back for
 * a whole period; CA → GST refunds (LUT) records each claim and each bank
 * credit (with its RFD-05 / UTR reference), and every credit goes to Zoho
 * Books as a journal at the next sync. The order keeps its GST in the cost.
 */
describe('GST refunds under LUT', () => {
  let admin;
  let adminId;
  let tag;
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const today = () => { const [y, m, d] = todayIst().split('-').map(Number); return `${d} ${MONTHS[m - 1]} ${y}`; };
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    tag = crypto.randomBytes(4).toString('hex').toUpperCase();
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-gst-${tag.toLowerCase()}@nexacrest.test`;
    adminId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestGstRefund!', 10), role_id: role.id }
    )).insertId;
    admin = request.agent(app);
    const csrf = csrfOf((await admin.get('/login')).text);
    await admin.post('/login').type('form').send({ _csrf: csrf, email, password: 'JestGstRefund!' });
  });

  async function post(path, fields) {
    const csrf = csrfOf((await admin.get('/ca/gst-refunds')).text);
    const res = await admin.post(path).type('form').send({ _csrf: csrf, ...fields });
    const m = (await admin.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') } : { type: null, message: '' };
  }

  async function claim(arn, amount = '206000') {
    await post('/ca/gst-refunds', { period_from: '2026-10-01', period_to: '2026-12-31', claim_arn: arn, filed_on: todayIst(), amount_claimed: amount });
    return Number((await db.queryOne('SELECT id FROM gst_refund_claims WHERE claim_arn = :arn', { arn })).id);
  }

  it('records a claim once, with its period and amount', async () => {
    const arn = `AA27${tag}0001`;
    const body = { period_from: '2026-10-01', period_to: '2026-12-31', claim_arn: arn, filed_on: todayIst(), amount_claimed: '206000' };
    expect((await post('/ca/gst-refunds', { ...body, period_to: '2026-09-30' })).message).toContain('period the claim covers');
    expect((await post('/ca/gst-refunds', { ...body, claim_arn: 'AB' })).message).toContain('RFD-01 claim no.');
    expect((await post('/ca/gst-refunds', { ...body, amount_claimed: '0' })).message).toContain('amount claimed must be at least 0.01');
    expect(await gstRefundRepository.arnTaken(arn)).toBe(false); // nothing saved

    expect(await post('/ca/gst-refunds', body)).toEqual({ type: 'success', message: `Claim ${arn} for Oct–Dec 2026 recorded: ₹206,000.00 claimed.` });
    expect((await post('/ca/gst-refunds', body)).message).toBe(`Claim ${arn} is already recorded. Nothing was saved.`);
  });

  it('needs the bank reference on a credit, and never more than the claim', async () => {
    const arn = `AA27${tag}0002`;
    const claimId = await claim(arn);
    const body = { amount: '185400', credited_on: todayIst(), bank_reference: 'RFD05-AA270226004411', part: 'provisional' };
    const credit = (b) => post(`/ca/gst-refunds/${claimId}/credits`, b);

    expect((await credit({ ...body, bank_reference: '' })).message).toContain('bank reference (RFD-05 / UTR) is required');
    expect((await credit({ ...body, part: '' })).message).toContain('provisional part or the final');
    expect((await credit({ ...body, amount: '206000.01' })).message).toContain('more than what is still open on this claim (₹206,000.00)');
    expect(await gstRefundRepository.creditsFor(claimId)).toEqual([]); // nothing saved

    const description = `GST refund under LUT, Oct–Dec 2026, claim ${arn} · provisional · ₹185,400.00 credited ${today()} · bank ref RFD05-AA270226004411`;
    expect(await credit(body)).toEqual({ type: 'success', message: `Recorded: ${description}. It goes to Zoho Books at the next sync.` });

    // The balance, with part of the claim refused: credited + refused may not pass what is still open.
    expect((await credit({ ...body, amount: '20000', amount_refused: '1000', part: 'final' })).message).toContain('more than what is still open');
    await credit({ ...body, amount: '19100', amount_refused: '1500', part: 'final', bank_reference: 'RFD05-AA270326000123' });
    const c = await gstRefundRepository.findClaim(claimId);
    expect([Number(c.amount_credited).toFixed(2), String(c.amount_refused)]).toEqual(['204500.00', '1500.00']);
    expect(c.refused_note).toContain('₹1,500.00 refused');
    expect((await credit({ ...body, amount: '1' })).message).toContain('more than what is still open on this claim (₹0.00)'); // the claim is closed
  });

  it('sends each credit to Zoho once, as a journal, and then fixes it', async () => {
    const journals = {};
    process.env.ZOHO_INTEGRATION_ENABLED = 'true';
    process.env.ZOHO_ENVIRONMENT = 'test';
    process.env.ZOHO_EINVOICE_ENABLED = 'false';
    for (const [k, v] of Object.entries({ zoho_books_enabled: '1', zoho_books_client_id: 'fake-client', zoho_books_client_secret: 'fake-secret', zoho_books_refresh_token: 'fake-refresh', zoho_books_organization_id: '600', zoho_books_deposit_account_id: 'SBI-1' })) {
      await companySettingsRepository.set(k, v);
    }
    zohoBooksService.setTransport((method, url, payload) => {
      const p = new URL(url).pathname;
      const json = (body, status = 200) => ({ status, body: JSON.stringify(body) });
      if (p === '/oauth/v2/token') return json({ access_token: 'fake-access' });
      if (p === '/books/v3/chartofaccounts') return json({ code: 0, chartofaccounts: [{ account_id: 'AC-GSTREF', account_name: 'GST Refund Receivable' }] });
      if (p === '/books/v3/journals' && method === 'POST') {
        const id = `JN-${Object.keys(journals).length + 1}`;
        journals[id] = payload;
        return json({ code: 0, journal: { journal_id: id } }, 201);
      }
      return json({ code: 5, message: `fake Zoho has no ${method} ${p}` }, 400);
    });
    try {
      // The test database is shared by the whole run: earlier tests' credits are not this test's.
      await db.execute("UPDATE gst_refund_credits SET zoho_journal_id = 'OLD' WHERE zoho_journal_id IS NULL");
      const arn = `TEST-${tag}03`;
      const claimId = await claim(arn);
      await post(`/ca/gst-refunds/${claimId}/credits`, { amount: '185400', credited_on: todayIst(), bank_reference: 'RFD05-AA270226004411', part: 'provisional' });
      const creditId = Number((await gstRefundRepository.creditsFor(claimId))[0].id);

      expect(await caSyncService.syncGstRefunds('manual', adminId)).toEqual({ journals: 1, failed: 0, skipped: 0 });
      const description = `GST refund under LUT, Oct–Dec 2026, claim ${arn} · provisional · ₹185,400.00 credited ${today()} · bank ref RFD05-AA270226004411`;
      // bank received, GST Refund Receivable reduced, with the built description (rule D-212)
      expect(journals['JN-1']).toEqual({
        journal_date: todayIst(),
        reference_number: `GSTREF-${arn}-${creditId}`,
        notes: `TEST- ${description}`,
        line_items: [
          { account_id: 'SBI-1', debit_or_credit: 'debit', amount: 185400, description },
          { account_id: 'AC-GSTREF', debit_or_credit: 'credit', amount: 185400, description: `GST refund under LUT, claim ${arn}` },
        ],
      });
      expect((await gstRefundRepository.findCredit(creditId)).zoho_journal_id).toBe('JN-1');

      expect(await caSyncService.syncGstRefunds('manual', adminId)).toEqual({ journals: 0, failed: 0, skipped: 1 }); // sent once
      expect((await post(`/ca/gst-refunds/credits/${creditId}/delete`, {})).message).toContain('already in Zoho Books (journal JN-1)');
      expect(await gstRefundRepository.findCredit(creditId)).not.toBeNull(); // not removed once in Books
    } finally {
      zohoBooksService.setTransport(null);
      delete process.env.ZOHO_INTEGRATION_ENABLED;
      delete process.env.ZOHO_ENVIRONMENT;
      delete process.env.ZOHO_EINVOICE_ENABLED;
      await companySettingsRepository.set('zoho_books_enabled', '0');
    }
  });

  it('removes a credit not yet in Zoho', async () => {
    const claimId = await claim(`AA27${tag}0004`);
    await post(`/ca/gst-refunds/${claimId}/credits`, { amount: '1000', credited_on: todayIst(), bank_reference: 'UTR-TEST-0004', part: 'provisional' });
    const creditId = Number((await gstRefundRepository.creditsFor(claimId))[0].id);
    expect((await post(`/ca/gst-refunds/credits/${creditId}/delete`, {})).type).toBe('success');
    expect(await gstRefundRepository.findCredit(creditId)).toBeNull();
  });

  it('keeps the GST in the order cost and shows it for information', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute(
      `INSERT INTO order_vendor_bills (order_id, vendor_kind, bill_for, doc_kind, bill_no, bill_date, taxable_inr, gst_inr, total_inr, created_by) VALUES
        (:o, 'supplier', 'balance', 'invoice', 'TEST-GST-1', CURDATE(), 300000, 54000, 354000, :u),
        (:o2, 'logistics', 'cha_transport', 'invoice', 'TEST-GST-2', CURDATE(), 20000, 3600, 23600, :u2),
        (:o3, 'supplier', 'advance', 'proforma', 'TEST-GST-3', CURDATE(), 84745.76, 15254.24, 100000, :u3)`,
      { o: orderId, o2: orderId, o3: orderId, u: adminId, u2: adminId, u3: adminId }
    );
    // tax invoices only (a proforma is replaced by its invoice)
    expect(await gstRefundRepository.gstOnOrderBills(orderId)).toEqual({ supplier: 54000, logistics: 3600, total: 57600 });

    const html = (await admin.get(`/orders/${orderId}`)).text;
    expect(html).toContain(`For information — GST paid on this order's purchases (INR)</span><span class="v">57,600.00</span>`);
    expect(html).toContain('Supplier &#8377;54,000.00 · logistics &#8377;3,600.00. Refundable under LUT');
  });

  it('labels the period', () => {
    expect(paymentDescription.periodLabel('2026-10-01', '2026-12-31')).toBe('Oct–Dec 2026');
    expect(paymentDescription.periodLabel('2026-10-01', '2026-10-31')).toBe('Oct 2026');
    expect(paymentDescription.periodLabel('2026-12-01', '2027-01-31')).toBe('Dec 2026–Jan 2027');
  });
});
