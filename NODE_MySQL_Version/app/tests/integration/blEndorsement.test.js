'use strict';

// documentGenerationService.generateBlEndorsement() renders a real PDF via
// pdfRenderService (headless Chrome through puppeteer-core), which Jest
// deliberately stubs out — mocked here the same way caInternalDoc.test.js
// does, so these tests exercise the actual BLE generation/approval logic,
// not real PDF rendering.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const db = require('../../src/config/db');
const documentRepository = require('../../src/repositories/documentRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const orderBlEndorsementRepository = require('../../src/repositories/orderBlEndorsementRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of BlEndorsementTest.php. docs/schema.sql Section BC — the Bill of
 * Lading Endorsement print feature: a small CRUD record
 * (orderBlEndorsementRepository) backing a genuinely buyer-facing
 * document (category='customer_facing', unlike CAFIN/BLI/SUPPO) that
 * self-approves instead of going through the normal draft->review cycle.
 */
describe('BL Endorsement (Section BC)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-ble-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  function fakeRes() {
    const res = { statusCode: 200, body: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  it('upsert creates then updates the same row', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    await orderBlEndorsementRepository.upsert(orderId, {
      bl_number: 'MSCU1234567',
      vessel_voyage: 'MSC MAYA / 012W',
      port_of_loading: 'Chennai, India',
      port_of_discharge: 'Rotterdam, Netherlands',
      date_of_endorsement: '2026-06-15',
    }, userId);

    let row = await orderBlEndorsementRepository.find(orderId);
    expect(row.bl_number).toBe('MSCU1234567');

    await orderBlEndorsementRepository.upsert(orderId, {
      bl_number: 'MSCU7654321',
      vessel_voyage: 'MSC MAYA / 012W',
      port_of_loading: 'Chennai, India',
      port_of_discharge: 'Rotterdam, Netherlands',
      date_of_endorsement: '2026-06-16',
    }, userId);

    const count = await db.queryOne('SELECT COUNT(*) AS c FROM order_bl_endorsements WHERE order_id = :id', { id: orderId });
    expect(parseInt(count.c, 10)).toBe(1);

    row = await orderBlEndorsementRepository.find(orderId);
    expect(row.bl_number).toBe('MSCU7654321');
  });

  it('generate refuses when no endorsement details saved yet', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    await expect(documentGenerationService.generateBlEndorsement(orderId, userId)).rejects.toThrow(/have not been saved/);
  });

  it('generate produces a real PDF and an approved customer_facing document', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderBlEndorsementRepository.upsert(orderId, {
      bl_number: 'MSCU1234567',
      vessel_voyage: 'MSC MAYA / 012W',
      port_of_loading: 'Chennai, India',
      port_of_discharge: 'Rotterdam, Netherlands',
      date_of_endorsement: '2026-06-15',
    }, userId);

    const result = await documentGenerationService.generateBlEndorsement(orderId, userId);
    expect(result.document_reference).toContain('BLE');

    const document = await documentRepository.find(result.document_id);
    expect(document.document_type_code).toBe('BLE');
    expect(document.document_type_category).toBe('customer_facing');
    expect(document.status).toBe('approved');

    const file = await fileStoreRepository.find(result.pdf_file_id);
    expect(!!file.internal_only).toBe(false);
    expect(fs.existsSync(file.server_path)).toBe(true);
    const pdfBytes = fs.readFileSync(file.server_path);
    expect(pdfBytes.slice(0, 4).toString()).toBe('%PDF');

    fs.unlinkSync(file.server_path);
  });

  it('generated BLE appears in customerFacingForOrder', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderBlEndorsementRepository.upsert(orderId, { bl_number: 'MSCU1234567', vessel_voyage: 'MSC MAYA / 012W' }, userId);

    const result = await documentGenerationService.generateBlEndorsement(orderId, userId);

    const customerFacing = await documentRepository.customerFacingForOrder(orderId);
    expect(customerFacing.map((d) => d.document_type_code)).toContain('BLE');

    const file = await fileStoreRepository.find(result.pdf_file_id);
    fs.unlinkSync(file.server_path);
  });

  it('each generation bumps the revision number on the same reference', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderBlEndorsementRepository.upsert(orderId, { bl_number: 'MSCU1111111' }, userId);

    const first = await documentGenerationService.generateBlEndorsement(orderId, userId);
    await orderBlEndorsementRepository.upsert(orderId, { bl_number: 'MSCU2222222' }, userId);
    const second = await documentGenerationService.generateBlEndorsement(orderId, userId);

    expect(second.document_reference).toBe(first.document_reference);
    expect(second.revision_number).toBe(first.revision_number + 1);

    fs.unlinkSync((await fileStoreRepository.find(first.pdf_file_id)).server_path);
    fs.unlinkSync((await fileStoreRepository.find(second.pdf_file_id)).server_path);
  });

  it('saveBlEndorsement controller action upserts from the request body', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = {
      params: { id: String(orderId) },
      body: {
        bl_number: 'MSCU9999999',
        vessel_voyage: 'EVER GIVEN / 099E',
        port_of_loading: 'Chennai, India',
        port_of_discharge: 'Hamburg, Germany',
        date_of_endorsement: '2026-07-01',
      },
      user: { id: userId },
      session: {},
    };
    await ordersController.saveBlEndorsement(req, fakeRes());

    const row = await orderBlEndorsementRepository.find(orderId);
    expect(row.bl_number).toBe('MSCU9999999');
    expect(row.vessel_voyage).toBe('EVER GIVEN / 099E');
  });

  it('generateBlEndorsement controller action generates when details saved', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());
    await orderBlEndorsementRepository.upsert(orderId, { bl_number: 'MSCU3333333' }, userId);

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.generateBlEndorsement(req, fakeRes());

    const doc = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'BLE');
    expect(doc).not.toBeNull();

    const file = await fileStoreRepository.find(doc.pdf_file_id);
    fs.unlinkSync(file.server_path);
  });

  it('generateBlEndorsement controller action flashes an error when not saved', async () => {
    const userId = await createTestUser();
    const orderId = await createTestOrder(await createTestClient());

    const req = { params: { id: String(orderId) }, body: {}, user: { id: userId }, session: {} };
    await ordersController.generateBlEndorsement(req, fakeRes());

    expect(await documentRepository.findLatestForOrderAndTypeCode(orderId, 'BLE')).toBeNull();
  });

  it('order show page exposes blEndorsement/blEndorsementDoc to the view', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    await orderBlEndorsementRepository.upsert(orderId, { bl_number: 'MSCU5555555' }, userId);

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.blEndorsement.bl_number).toBe('MSCU5555555');
    expect(res.renderedData.blEndorsementDoc).toBeNull();
  });
});
