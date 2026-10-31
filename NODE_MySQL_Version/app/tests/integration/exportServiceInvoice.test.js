'use strict';

// Capture the rendered HTML instead of launching a browser.
const renderedHtml = [];
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async (html) => {
    renderedHtml.push(html);
    return Buffer.from('%PDF-fake');
  }),
  closeBrowser: jest.fn(),
}));

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const moneyVisibility = require('../../src/services/moneyVisibility');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ExportServiceInvoiceTest.php. Owner decision D-215 (5a-2,
 * confirmed twice): an order's service lines are invoiced on their own
 * Export Service Invoice (ESI) — under LUT, with SAC codes — so the
 * Commercial Invoice stays goods-only. An order without service lines
 * cannot have one.
 */
describe('Export Service Invoice (D-215, 5a-2)', () => {
  const PASSWORD = 'JestEsi!2026';
  let orderId;
  let userId;
  let agent;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    renderedHtml.length = 0;
    orderId = await createTestOrder(await createTestClient(), 'FOB');
    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-esi-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const res = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    userId = res.insertId;
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
  });

  async function addService() {
    const id = await orderProductRepository.add(orderId, 2, 'Installation supervision', null, null, '5', false, 'DAY', '180.00');
    await orderProductRepository.setLineType(id, 'service', '998719');
  }

  async function postGenerate() {
    const page = (await agent.get(`/orders/${orderId}`)).text;
    const csrf = page.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(`/orders/${orderId}/documents/generate`).type('form')
      .send({ _csrf: csrf, document_type: 'ESI', generate_pdf: '1' });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim() : '';
  }

  it('cannot be made for an order without services', async () => {
    expect(await postGenerate()).toBe('This order has no service lines — the Export Service Invoice is only for services.');
    const row = await db.queryOne(
      "SELECT COUNT(*) AS n FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.order_id = :o AND t.code = 'ESI'",
      { o: orderId }
    );
    expect(Number(row.n)).toBe(0);
  });

  it('carries the service lines only and is selling money', async () => {
    await addService();
    const esi = await documentDataAssembler.assemble(orderId, 'ESI');
    expect(esi.products.map((p) => p.description)).toEqual(['Installation supervision']);
    expect(esi.financial.service_value).toBe('900.00');
    expect(moneyVisibility.documentClass('ESI')).toBe(moneyVisibility.SELLING);
  });

  it('is generated as PDF and DOCX', async () => {
    await addService();
    const result = await documentGenerationService.generate(orderId, 'ESI', userId, null, true);
    const doc = await documentRepository.find(result.document_id);
    expect(doc.document_reference).toMatch(/^SC\/ESI\/\d{2}\/\d{4}\d{3}$/);
    expect(result.docx_file_id).toBeTruthy();

    const html = renderedHtml.join('\n');
    expect(html).toContain('EXPORT SERVICE INVOICE');
    expect(html).toContain('Installation supervision');
    expect(html).toContain('998719');
    expect(html).toContain('export of services without payment of IGST');
    expect(html).not.toContain('Black granite headstone');
  });

  it('is offered on the order page only when there are service lines', async () => {
    expect((await agent.get(`/orders/${orderId}`)).text).not.toContain('Generate Export Service Invoice');
    await addService();
    expect((await agent.get(`/orders/${orderId}`)).text).toContain('Generate Export Service Invoice');
    const src = fs.readFileSync(path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk'), 'utf8');
    expect(src).toContain('<input type="hidden" name="document_type" value="ESI">');
  });
});
