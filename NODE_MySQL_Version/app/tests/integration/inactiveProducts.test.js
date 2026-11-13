'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const productRepository = require('../../src/repositories/productRepository');
const { createTestClient, createTestOrder, configureProductForQuoting } = require('../support/fixtures');

/**
 * Port of InactiveProductsTest.php — owner, 12 Nov 2026 (D-334, "fix this" — a deactivated product disappeared):
 *  - Products › All products filters Active · Inactive · All (with counts), on the list and on a search;
 *  - an inactive product says where it is used (productRepository.usage());
 *  - Reactivate / Deactivate (audited);
 *  - POST /products/:id/delete refuses an active or used product, server-side, and deletes an inactive unused one.
 */
describe('D-334 inactive products', () => {
  const PASSWORD = 'JestD334!1';
  const made = [];
  let admin;
  let agent;
  let tag;

  beforeAll(async () => {
    admin = await user('Admin');
    agent = await login(admin);
  });

  beforeEach(() => {
    tag = `D334 ${crypto.randomBytes(4).toString('hex')}`;
  });

  afterAll(async () => {
    for (const id of made) {
      await db.execute('DELETE FROM price_requests WHERE product_id = :id', { id });
      await db.execute('UPDATE order_products SET catalog_product_id = NULL WHERE catalog_product_id = :id', { id });
      await db.execute('DELETE FROM catalog_products WHERE id = :id', { id });
    }
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-d334-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email, roleId: role.id };
  }

  async function login(u) {
    const a = request.agent(app);
    const csrf = (await a.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await a.post('/login').type('form').send({ _csrf: csrf, email: u.email, password: PASSWORD });
    return a;
  }

  /** Posts a form and returns the flash message shown after the redirect. */
  async function post(from, url, body = {}) {
    const csrf = (await agent.get(from)).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...body });
    // D-362: an empty Products tab redirects again (the flash is kept) — follow to the page that shows it.
    let page = await agent.get(res.headers.location);
    for (let hops = 0; hops < 5 && page.status === 302 && page.headers.location; hops++) page = await agent.get(page.headers.location);
    const m = page.text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? m[2].trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&') : '';
  }

  async function product(name, active) {
    const id = await productRepository.create({ name: `${tag} ${name}`, specifications: 'Granite slab', hs_code: '68022310', is_active: active ? 1 : 0 }, admin.id);
    made.push(id);
    return id;
  }

  async function audits(action, id) {
    return Number((await db.queryOne('SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :a AND entity_id = :id', { a: action, id })).n);
  }

  /** One order line picked from the product (and one procurement line for it). */
  async function useOnOrder(productId, withProcurement = false) {
    const order = await createTestOrder(await createTestClient());
    const line = (await db.execute(
      "INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, catalog_product_id) VALUES (:o, 1, 'D334 line', 10, 'SQM', 50, :p)",
      { o: order, p: productId }
    )).insertId;
    if (withProcurement) {
      await db.execute("INSERT INTO purchase_requirements (order_id, order_product_id, description, quantity, needed_by, raised_by) VALUES (:o, :l, 'D334 buy', 10, CURDATE(), :u)", { o: order, l: line, u: admin.id });
    }
  }

  test('the list and the search filter by status, with counts', async () => {
    await product('Active slab', true);
    await product('Old slab', false);

    // QA 13 Nov 2026 (D-361 stale test): the counts also carry the configuration filters.
    expect(await productRepository.statusCounts(tag)).toEqual({ active: 1, inactive: 1, all: 2, not_configured: 2, no_active_supplier: 0, no_supplier_yet: 0 });
    expect((await productRepository.search(tag)).map((p) => p.name)).toEqual([`${tag} Active slab`]);
    expect((await productRepository.search(tag, 'inactive')).map((p) => p.name)).toEqual([`${tag} Old slab`]);
    expect(await productRepository.search(tag, 'all')).toHaveLength(2);
    expect(productRepository.statusFilter('rubbish')).toBe('active');

    let page = (await agent.get(`/products?q=${encodeURIComponent(tag)}`)).text;
    expect(page).toContain('Active (1)');
    expect(page).toContain('Inactive (1)');
    expect(page).toContain('All (2)');
    expect(page).not.toContain('Old slab');
    expect(page).toContain('Products are picked on order lines and used for price checks.');
    expect(page).not.toContain('completely independent of the order pipeline');

    page = (await agent.get(`/products?q=${encodeURIComponent(tag)}&status=inactive`)).text;
    expect(page).toContain('Old slab');
    expect(page).not.toContain('Active slab');
    expect(page).toContain('>Inactive</span>');
    expect(page).toContain('not used anywhere');
    expect(page).toContain('Reactivate');
    expect(page).toContain('It is not used anywhere. Its supplier prices, images and price history are deleted too. This cannot be undone.');

    page = (await agent.get('/products?status=inactive')).text;
    expect(page).toContain('Old slab');
  });

  test('usage counts orders, price requests and procurement', async () => {
    const id = await product('Used slab', false);
    expect(await productRepository.usage(id)).toEqual({ orders: 0, order_lines: 0, price_requests: 0, procurement: 0, total: 0 });
    expect(productRepository.usageText(await productRepository.usage(id))).toBe('not used anywhere');

    await useOnOrder(id, true);
    await useOnOrder(id);
    await useOnOrder(id);
    const u = await productRepository.usage(id);
    expect(u.orders).toBe(3);
    expect(u.procurement).toBe(1);
    expect(productRepository.usageText(u)).toBe('3 orders · 1 procurement line');
    expect(productRepository.deleteRefusal(await productRepository.find(id), u)).toBe('Used on 3 orders · 1 procurement line — kept so those orders stay complete.');
    expect(productRepository.usageText({ orders: 1, price_requests: 2, procurement: 0 })).toBe('1 order · 2 price requests');
    expect(productRepository.deleteRefusal({ is_active: 0 }, { orders: 0, price_requests: 1, procurement: 0, total: 1 }))
      .toBe('Used on 1 price request — kept so those price requests stay complete.');

    const page = (await agent.get(`/products?q=${encodeURIComponent(tag)}&status=inactive`)).text;
    expect(page).toContain('3 orders · 1 procurement line');
    expect(page).toContain('Used on 3 orders · 1 procurement line — kept so those orders stay complete.');
    expect(page).not.toContain('Delete…');

    const show = (await agent.get(`/products/${id}`)).text;
    expect(show).toContain('Used on: <strong>3 orders · 1 procurement line</strong>');
  });

  test('delete is refused for an active or a used product', async () => {
    const active = await product('Active slab', true);
    expect(await post(`/products/${active}`, `/products/${active}/delete`))
      .toBe('Not deleted: This product is active. Deactivate it first — only an inactive product that is not used anywhere can be deleted.');
    expect(await productRepository.find(active)).not.toBeNull();
    expect(await audits('PRODUCT_DELETED', active)).toBe(0);

    const used = await product('Used slab', false);
    await useOnOrder(used);
    expect(await post(`/products/${used}`, `/products/${used}/delete`)).toBe('Not deleted: Used on 1 order — kept so those orders stay complete.');
    expect(await productRepository.find(used)).not.toBeNull();
    expect(await audits('PRODUCT_DELETED', used)).toBe(0);

    const show = (await agent.get(`/products/${active}`)).text;
    expect(show).not.toContain(`/products/${active}/delete`);
    expect(show).toContain(`/products/${active}/deactivate`);
  });

  test('an inactive unused product is deleted', async () => {
    const id = await product('Spare slab', false);
    expect(await post(`/products/${id}`, `/products/${id}/delete`)).toContain('deleted');
    expect(await productRepository.find(id)).toBeNull();
    expect(await audits('PRODUCT_DELETED', id)).toBe(1);
  });

  test('deactivate and reactivate are audited', async () => {
    const id = await product('Toggle slab', true);
    await post(`/products/${id}`, `/products/${id}/deactivate`);
    expect(Number((await productRepository.find(id)).is_active)).toBe(0);
    expect(await audits('PRODUCT_DEACTIVATED', id)).toBe(1);

    // QA 13 Nov 2026 (D-361 stale test): a product that is not configured is not switched Active.
    expect(await post(`/products/${id}`, `/products/${id}/reactivate`, { return_to: 'list' })).toContain('cannot be switched Active yet: Not configured yet — ask the product manager.');
    expect(Number((await productRepository.find(id)).is_active)).toBe(0);
    expect(await audits('PRODUCT_REACTIVATED', id)).toBe(0);

    // Configured with an active supplier offer, it is.
    const offer = await require('../../src/repositories/productSupplierRepository').create({ product_id: id, supplier_name: 'D334 Quarry', location: null, contact_person: null, contact_phone: null, contact_email: null,
      fob_source: 'direct', fob_value: 4000, factory_cost: null, transportation_cost: null, packing_cost: null, loading_cost: null, cha_cost: null, is_primary: 1, notes: null }, admin.id);
    await require('../../src/repositories/pricingRepository').setSupplierDates(offer, require('../../src/helpers/dbDateTime').todayIst(), null);
    await configureProductForQuoting(id, admin.id, false);
    expect(await post(`/products/${id}`, `/products/${id}/reactivate`, { return_to: 'list' })).toContain('is active again');
    expect(Number((await productRepository.find(id)).is_active)).toBe(1);
    expect(await audits('PRODUCT_REACTIVATED', id)).toBe(1);
  });
});
