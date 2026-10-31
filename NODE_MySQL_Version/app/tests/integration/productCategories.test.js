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
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const productCategoryRepository = require('../../src/repositories/productCategoryRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { buildProductRows } = require('../../src/services/docx/docxDocumentBuilder');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ProductCategoryTest.php. Owner decision D-215 (5a, confirmed
 * twice): product categories with their own attributes (Products →
 * Categories). Natural Stone is today's products — Dimensions and Finish,
 * printed word for word as before. A line of another category prints its
 * own attributes in the same place, followed by the HS code.
 */
describe('Product categories (D-215, 5a)', () => {
  const PASSWORD = 'JestCategories!1';
  let orderId;
  let userId;
  let agent;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    renderedHtml.length = 0;
    orderId = await createTestOrder(await createTestClient(), 'FOB');
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-cat-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    userId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
  });

  async function post(url, body, from = `/orders/${orderId}`) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  async function tiles() {
    const name = `Ceramic Tiles ${crypto.randomBytes(3).toString('hex')}`;
    expect(await post('/products/categories', { name, attributes: ['Size', 'Grade', 'Colour', '', '', ''] }, '/products/categories'))
      .toBe(`Category "${name}" added with Size, Grade, Colour.`);
    return Number((await db.queryOne('SELECT id FROM product_categories WHERE name = :n', { n: name })).id);
  }

  it('keeps Natural Stone as today\'s products', async () => {
    const stone = (await productCategoryRepository.all()).filter((c) => Number(c.is_system) === 1);
    expect([stone[0].name, stone[0].attributes]).toEqual(['Natural Stone', ['Dimensions', 'Finish']]);
    expect(await post(`/products/categories/${stone[0].id}`, { name: 'Stone', attributes: ['Size'] }, '/products/categories'))
      .toBe("Natural Stone is today's products — its Dimensions and Finish stay as they are.");

    await orderProductRepository.add(orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
    const pi = await documentDataAssembler.assemble(orderId, 'PI');
    expect(pi.products[0].attributes).toEqual([]);
  });

  it('checks a category when it is saved', async () => {
    const from = '/products/categories';
    expect(await post(from, { name: 'Tiles X', attributes: ['', ''] }, from)).toBe('Give the category at least one attribute (e.g. Size, Grade, Colour).');
    expect(await post(from, { name: 'Tiles X', attributes: ['Size', 'size'] }, from)).toBe('"size" is listed twice — each attribute once.');
    expect(await post(from, { name: 'Natural Stone', attributes: ['Size'] }, from)).toBe('There is already a category called "Natural Stone".');
    const id = await tiles();
    expect((await productCategoryRepository.find(id)).attributes).toEqual(['Size', 'Grade', 'Colour']);
  });

  it('prints a line of another category with its own attributes', async () => {
    const cat = await tiles();
    expect(await post(`/orders/${orderId}/products`, {
      description: 'Porcelain floor tile', quantity: '200', unit: 'PCS', unit_price: '12.50', line_type: 'goods', hs_code: '680293',
      category_id: String(cat), [`attr_${cat}`]: ['600x600 mm', 'Premium', ''],
    })).toBe('Product line added.');
    const line = await db.queryOne('SELECT * FROM order_products WHERE order_id = :o ORDER BY id DESC LIMIT 1', { o: orderId });
    expect(Number(line.category_id)).toBe(cat);

    const pi = await documentDataAssembler.assemble(orderId, 'PI');
    expect(pi.products[0].attributes).toEqual([{ label: 'Size', value: '600x600 mm' }, { label: 'Grade', value: 'Premium' }, { label: 'Colour', value: 'To Be Confirmed' }]);

    await documentGenerationService.generate(orderId, 'PI', userId, null, false, true);
    const text = renderedHtml.join('\n').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&middot;/g, '·').replace(/\s+/g, ' ');
    expect(text).toContain('1. Size: 600x600 mm · Grade: Premium · Colour: To Be Confirmed · HS Code: 680293 · Country of Origin: India');

    const rows = buildProductRows(pi.products);
    expect(rows[0].specText.map((part) => part[0]).join(''))
      .toBe('1. Size: 600x600 mm   ·   Grade: Premium   ·   Colour: To Be Confirmed   ·   HS Code: 680293   ·   Country of Origin: India');

    // A copy of the line keeps its category and values.
    await post(`/orders/${orderId}/products/${line.id}/duplicate`, {});
    const copy = await db.queryOne('SELECT * FROM order_products WHERE order_id = :o ORDER BY id DESC LIMIT 1', { o: orderId });
    expect([Number(copy.category_id), copy.attribute_values]).toEqual([cat, line.attribute_values]);
  });
});
