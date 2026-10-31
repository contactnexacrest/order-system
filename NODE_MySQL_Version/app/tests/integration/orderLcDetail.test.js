'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const orderFinancialsController = require('../../src/controllers/orderFinancialsController');
const fileUploadService = require('../../src/services/fileUploadService');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderLcDetailTest.php. docs/schema.sql Section BR — export
 * process steps 5-1a/5-2a/5-3a: Letter of Credit upload + LC number, and
 * bank loan amount/interest rate/interest paid recorded as an order
 * expense.
 */
describe('Order LC details + bank loan interest (Section BR)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest LC Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-lc-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { params: {}, body: {}, session: {}, ...overrides };
  }

  // ---------------------------------------------------------------
  // orderLcDetailRepository
  // ---------------------------------------------------------------

  it('upsert creates then updates the same row rather than duplicating', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    await orderLcDetailRepository.upsert(orderId, {
      lc_number: 'LC-001', lc_file_id: null, bank_loan_amount: 50000,
      bank_loan_interest_rate: 9.5, bank_loan_interest_paid: 1200,
      interest_cost_entry_id: null, recorded_by: userId,
    });
    const first = await orderLcDetailRepository.find(orderId);
    expect(first.lc_number).toBe('LC-001');

    await orderLcDetailRepository.upsert(orderId, {
      lc_number: 'LC-001-REVISED', lc_file_id: null, bank_loan_amount: 55000,
      bank_loan_interest_rate: 10.0, bank_loan_interest_paid: 1400,
      interest_cost_entry_id: null, recorded_by: userId,
    });
    const second = await orderLcDetailRepository.find(orderId);
    expect(second.lc_number).toBe('LC-001-REVISED');
    expect(Number(second.id)).toBe(Number(first.id));

    const row = await db.queryOne('SELECT COUNT(*) AS c FROM order_lc_details WHERE order_id = :id', { id: orderId });
    expect(Number(row.c)).toBe(1);
  });

  // ---------------------------------------------------------------
  // orderFinancialsController.saveLcDetails()
  // ---------------------------------------------------------------

  it('rejects a non-numeric loan amount', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { lc_number: 'LC-X', bank_loan_amount: 'not-a-number' } });

    await orderFinancialsController.saveLcDetails(req, fakeRes());

    const msgs = flash.pull(req);
    expect(msgs[0].type).toBe('error');
    expect(await orderLcDetailRepository.find(orderId)).toBeNull();
  });

  it('rejects a negative interest rate', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { bank_loan_interest_rate: '-5' } });

    await orderFinancialsController.saveLcDetails(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
  });

  // External QA report N-11: an interest rate is a percentage — anything
  // above 100% (the report's own example: 250%) is never plausible.
  it('rejects an interest rate over 100%', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { bank_loan_interest_rate: '250' } });

    await orderFinancialsController.saveLcDetails(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('error');
    expect(await orderLcDetailRepository.find(orderId)).toBeNull();
  });

  // External QA report N-11: interest paid larger than the loan amount
  // is unusual, not impossible — warned about, saved either way.
  it('warns but still saves when interest paid exceeds the loan amount', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      body: { lc_number: 'LC-OVER', bank_loan_amount: '1000', bank_loan_interest_paid: '1500' },
    });

    await orderFinancialsController.saveLcDetails(req, fakeRes());

    const msgs = flash.pull(req);
    expect(msgs[0].type).toBe('warning');
    expect(msgs[0].message).toMatch(/more than the loan amount/);
    const saved = await orderLcDetailRepository.find(orderId);
    expect(Number(saved.bank_loan_interest_paid)).toBe(1500);
  });

  it('saves LC number and loan fields with no file uploaded', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      body: { lc_number: 'LC-2026-047', bank_loan_amount: '50000', bank_loan_interest_rate: '9.5', bank_loan_interest_paid: '' },
    });

    await orderFinancialsController.saveLcDetails(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    const saved = await orderLcDetailRepository.find(orderId);
    expect(saved.lc_number).toBe('LC-2026-047');
    expect(Number(saved.bank_loan_amount)).toBe(50000);
    expect(Number(saved.bank_loan_interest_rate)).toBe(9.5);
    expect(saved.bank_loan_interest_paid).toBeNull();
    expect(saved.interest_cost_entry_id).toBeNull();
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);

    const row = await db.queryOne(
      "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'ORDER_LC_DETAILS_SAVED' AND entity_id = :id",
      { id: orderId }
    );
    expect(Number(row.c)).toBe(1);
  });

  /**
   * Export process step 5-3a: "this loan interest can be considered /
   * record as an expense for that order" — recording an interest-paid
   * amount must create exactly one matching order_cost_entries row
   * (category bank_loan_interest), and re-saving with a different amount
   * must update that SAME row rather than creating a second one.
   */
  it('saving interest paid creates a cost entry and re-saving updates the same one', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req1 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { lc_number: 'LC-1', bank_loan_interest_paid: '1200' } });

    await orderFinancialsController.saveLcDetails(req1, fakeRes());

    let entries = await orderCostEntryRepository.forOrder(orderId);
    expect(entries.length).toBe(1);
    expect(entries[0].category).toBe('bank_loan_interest');
    expect(Number(entries[0].amount_inr)).toBe(1200);
    expect(entries[0].description).toBe('Bank loan interest (LC order) · LC LC-1'); // owner rule D-212: names the LC
    const firstEntryId = Number(entries[0].id);

    const saved = await orderLcDetailRepository.find(orderId);
    expect(Number(saved.interest_cost_entry_id)).toBe(firstEntryId);

    const req2 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { lc_number: 'LC-1', bank_loan_amount: '250000', bank_loan_interest_rate: '9.5', bank_loan_interest_paid: '1500' } });
    await orderFinancialsController.saveLcDetails(req2, fakeRes());

    entries = await orderCostEntryRepository.forOrder(orderId);
    expect(entries.length).toBe(1);
    expect(Number(entries[0].id)).toBe(firstEntryId);
    expect(Number(entries[0].amount_inr)).toBe(1500);
    expect(entries[0].description).toBe('Bank loan interest (LC order) · LC LC-1 · loan ₹250,000.00 at 9.5%'); // and the loan it was charged on
  });

  it('clearing interest paid back out removes the cost entry', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req1 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { bank_loan_interest_paid: '800' } });
    await orderFinancialsController.saveLcDetails(req1, fakeRes());
    expect((await orderCostEntryRepository.forOrder(orderId)).length).toBe(1);

    const req2 = fakeReq({ user: { id: userId }, params: { id: String(orderId) }, body: { bank_loan_interest_paid: '' } });
    await orderFinancialsController.saveLcDetails(req2, fakeRes());

    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
    const saved = await orderLcDetailRepository.find(orderId);
    expect(saved.interest_cost_entry_id).toBeNull();
  });

  it('returns 404 for an unknown order', async () => {
    const userId = await createTestUser();
    const req = fakeReq({ user: { id: userId }, params: { id: '999999999' } });
    const res = fakeRes();

    await orderFinancialsController.saveLcDetails(req, res);

    expect(res.statusCode).toBe(404);
  });

  /**
   * Unlike PHP's move_uploaded_file() (which requires a genuine HTTP
   * upload), Node's multer-based upload already hands handleUpload() an
   * in-memory buffer, so the LC file upload can be exercised end to end
   * through the real controller action, not just the context-resolution
   * check below.
   */
  it('saves an uploaded LC file end to end and links it on the order', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    const req = fakeReq({
      user: { id: userId },
      params: { id: String(orderId) },
      body: { lc_number: 'LC-UPLOAD-1' },
      file: { buffer: Buffer.from('%PDF-1.4 fake lc pdf for test'), originalname: 'lc-scan.pdf', mimetype: 'application/pdf' },
    });

    await orderFinancialsController.saveLcDetails(req, fakeRes());

    expect(flash.pull(req)[0].type).toBe('success');
    const saved = await orderLcDetailRepository.find(orderId);
    expect(saved.lc_file_id).not.toBeNull();
  });

  // ---------------------------------------------------------------
  // View — the order page offers the LC/loan form
  // ---------------------------------------------------------------

  const templatePath = path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk');
  const source = fs.readFileSync(templatePath, 'utf8');

  it('the order page offers the LC/loan form with a file upload field', () => {
    expect(source).toContain('name="lc_file"');
    expect(source).toContain('name="lc_number"');
    expect(source).toContain('name="bank_loan_interest_paid"');
    expect(source).toContain('/lc-details');
  });

  /**
   * External QA report L-22 pattern: prove the 'order_lc_copy' upload
   * context actually resolves (seeded in seed.sql) rather than failing
   * "Unknown upload context" — and here, since Node's upload is already
   * an in-memory buffer, the file is actually stored too.
   */
  it('handleUpload resolves the order_lc_copy context and actually stores the file', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const req = { file: { buffer: Buffer.from('%PDF-1.4 fake lc pdf for test'), originalname: 'lc-scan.pdf', mimetype: 'application/pdf' } };

    const fileId = await fileUploadService.handleUpload(req, 'lc_file', 'order_lc_copy', 'test/lc-copy', null, orderId, null);

    expect(fileId).toBeTruthy();
  });
});
