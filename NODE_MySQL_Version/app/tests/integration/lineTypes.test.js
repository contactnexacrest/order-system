'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of LineTypesTest.php. Owner decision D-215 (confirmed twice, 1 Nov
 * 2026): an order line is Goods, Service or Charge. A service has a SAC code
 * instead of an HS code, is shown on the QT/PI/OC with "Invoiced separately
 * on our Export Service Invoice", stays out of the goods FOB value (advance,
 * balance, CI) and off the goods documents. Goods-only orders print exactly
 * as before.
 */
describe('Order line types (D-215, 5a)', () => {
  const PASSWORD = 'JestLineTypes!1';
  const templatesDir = path.join(__dirname, '..', '..', 'templates');
  let orderId;
  let agent;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    orderId = await createTestOrder(await createTestClient(), 'FOB');
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-lines-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
  });

  async function addLine(body) {
    const csrf = (await agent.get(`/orders/${orderId}`)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(`/orders/${orderId}/products`).type('form')
      .send({ _csrf: csrf, description: 'Line', quantity: '5', unit: 'PCS', unit_price: '180', ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  const lastLine = async () => db.queryOne('SELECT * FROM order_products WHERE order_id = :o ORDER BY id DESC LIMIT 1', { o: orderId });

  it('checks and saves each line type', async () => {
    expect(await addLine({ line_type: 'service', description: 'Installation supervision', sac_code: '12345' }))
      .toBe('A service line needs its SAC code: 6 digits starting 99 (e.g. 998719).');
    expect(await addLine({ line_type: 'service', description: 'Installation supervision', sac_code: '998719' })).toBe('Product line added.');
    let line = await lastLine();
    expect([line.line_type, line.sac_code]).toEqual(['service', '998719']);

    expect(await addLine({ line_type: 'charge', description: 'Testing fee', quantity: '1', unit_price: '50' })).toBe('Product line added.');
    line = await lastLine();
    expect([line.line_type, line.sac_code]).toEqual(['charge', null]);

    expect(await addLine({ line_type: 'goods', hs_code: '' })).toContain('is not on the HS Code master list');
    expect(await addLine({ line_type: 'bundle' })).toBe('Choose whether the line is goods, a service or a charge.');
  });

  it('keeps services out of the goods value and the goods documents', async () => {
    const goodsFob = await orderProductRepository.totalFobValue(orderId);
    await addLine({ line_type: 'service', description: 'Installation supervision', sac_code: '998719' });
    expect(await orderProductRepository.totalFobValue(orderId)).toBe(goodsFob); // the FOB value (advance, balance, CI) counts goods only
    expect(await orderProductRepository.totalServiceValue(orderId)).toBe(900);

    const pi = await documentDataAssembler.assemble(orderId, 'PI');
    expect(pi.products.map((p) => p.line_type)).toContain('service');
    expect(pi.financial.has_services).toBe(true);
    const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    expect(pi.financial.total_with_services).toBe(fmt(goodsFob + 900));
    expect(pi.financial.fob_value).toBe(fmt(goodsFob));

    for (const code of ['CI', 'PL', 'COOPREP', 'SUPPO', 'BLI']) {
      const doc = await documentDataAssembler.assemble(orderId, code);
      expect([code, doc.products.map((p) => p.line_type).includes('service')]).toEqual([code, false]);
    }
  });

  it('prints a goods-only order as before', async () => {
    const pi = await documentDataAssembler.assemble(orderId, 'PI');
    expect(pi.financial.has_services).toBe(false);
    for (const template of ['QT/quotation.njk', 'PI/proforma_invoice.njk']) {
      const src = fs.readFileSync(path.join(templatesDir, template), 'utf8');
      expect(src).toContain("{% if p.line_type == 'service' %}");
      expect(src).toContain('Invoiced separately on our Export Service Invoice');
      expect(src).toContain('{% elseif p.dimensions or p.finish %}');
    }
    expect(fs.readFileSync(path.join(templatesDir, 'PI/proforma_invoice.njk'), 'utf8'))
      .toContain('{{ financial.total_with_services if financial.has_services else financial.fob_value }}');
  });

  it('shows the service line the same way in the DOCX', () => {
    const { buildProductRows } = require('../../src/services/docx/docxDocumentBuilder');
    const rows = buildProductRows([
      { description: 'Headstone', dimensions: '60x30', finish: 'Polished', hs_code: '68022310', quantity: '100', unit: 'PCS', unit_price: '45.00', amount: '4,500.00', line_type: 'goods' },
      { description: 'Installation supervision', dimensions: null, finish: null, hs_code: '', sac_code: '998719', quantity: '5', unit: 'DAY', unit_price: '180.00', amount: '900.00', line_type: 'service' },
    ]);
    const text = (spec) => spec.map((part) => part[0]).join('');
    expect(text(rows[0].specText)).toBe('1. Dimensions: 60x30   ·   Finish: Polished   ·   HS Code: 68022310   ·   Country of Origin: India');
    expect(text(rows[1].specText)).toBe('2. Service   ·   SAC Code: 998719   ·   Invoiced separately on our Export Service Invoice');
  });
});
