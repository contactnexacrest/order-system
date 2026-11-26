'use strict';

/**
 * D-435 (owner 26 Nov 2026): the brand catalogue — brand → main category → sub-category → product. A sub-category
 * follows its main category's settings until it sets its own; a price setting still empty follows the brand's pricing,
 * then the company's. A product a brand manager adds waits for approval ("Approve new products", never your own);
 * until then it cannot be quoted or seen by associates. Brand managers change only their brand's catalogue;
 * company-wide categories and products are view-only for them. PHP twin: tests/Integration/BrandCatalogueD435Test.php.
 *
 * (Helpers as in brandManagersD432.test.js.) D-432 (owner 26 Nov 2026): brand managers. A staff member given a brand (Admin › Users › Brand, "Manage divisions")
 * sees and changes only that brand: its associates, their pages and actions, the brand's rules and ladder, its
 * registrations, price requests, report and payout lines. Never another brand, never brand details, never a
 * company-wide setting, never moving an associate between brands. Empty = all brands, as before.
 * PHP twin: tests/Integration/BrandManagersD432Test.php.
 */
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const repo = require('../../src/repositories/associateBrandRepository');
const brands = require('../../src/services/associateBrands');
const pcr = require('../../src/repositories/productCategoryRepository');
const productRepository = require('../../src/repositories/productRepository');
const productApproval = require('../../src/services/productApproval');
const priceRequestService = require('../../src/services/priceRequestService');

describe('Brand catalogue (D-435)', () => {
  const PASSWORD = 'JestD435!12345';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const unescape = (s) => s.trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  let md;
  let tag;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, brandId = null) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin, brand_id)
       VALUES (:name, :email, NULL, :hash, :r, 1, 0, 0, 0, :b)`,
      { name: `Jest D435 ${roleName}`, email: `jest-d435-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id, b: brandId },
    )).insertId;
  }

  beforeEach(async () => {
    tag = crypto.randomBytes(3).toString('hex');
    md = await user('Managing Director');
  });

  async function staff(userId) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  async function post(agent, url, fields = {}) {
    const csrf = csrfOf((await agent.get('/').redirects(5)).text);
    const res = await agent.post(url).type('form').send({ _csrf: csrf, ...fields });
    let flash = { type: null, message: null };
    if (res.status === 302 && res.headers.location) {
      const m = (await agent.get(res.headers.location.split('#')[0])).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/g);
      const pick = m ? (m.find((x) => /alert-(success|error)"/.test(x)) || m[m.length - 1]) : null;
      const last = pick ? pick.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/) : null;
      if (last) flash = { type: last[1], message: unescape(last[2]) };
    }
    return { status: res.status, ...flash };
  }

  async function brand(prefix) {
    const id = await repo.save(null, { code: `${prefix}${tag}`, name: `${prefix[0].toUpperCase()}${prefix.slice(1)} Brand ${tag}`, tagline: brands.DEFAULT_TAGLINE,
      products: null, website: null, email: null, status: 'active' }, md);
    return repo.find(id);
  }

  async function associate(brandId) {
    const id = await associateRepository.create({ name: `D435 Associate ${tag}${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `associate-${crypto.randomBytes(4).toString('hex')}@email.com`, status: 'active' }, md);
    if (brandId !== null) await repo.setAssociateBrand(id, brandId);
    return associateRepository.find(id);
  }

  async function mainCategory(name, brandId, settings = {}) {
    const id = await pcr.create(`${name} ${tag}`, ['Size', 'Finish'], md, null, brandId);
    const keys = Object.keys(settings);
    if (keys.length) await db.execute(`UPDATE product_categories SET ${keys.map((k) => `${k} = :${k}`).join(', ')} WHERE id = :id`, { ...settings, id });
    return id;
  }

  async function product(name, categoryId, userId, u = null) {
    const id = Number(await productRepository.create({ name: `${name} ${tag}`, specifications: 'Test product', hs_code: '680293', is_active: 1 }, userId));
    if (categoryId !== null) await db.execute('UPDATE catalog_products SET category_id = :c WHERE id = :id', { c: categoryId, id });
    await productApproval.afterCreate(id, userId, u || { brand_id: null });
    return id;
  }

  test('a sub-category follows its main category, then the brand, then the company', async () => {
    const stone = await brand('stone');
    const main = await mainCategory('Natural Stones', Number(stone.id), { default_hs_code: '680293', packing_type: 'Wooden crates' });
    const sub = await pcr.create(`Granite ${tag}`, [], md, main);
    const own = await pcr.findOwn(sub);
    expect(Number(own.brand_id)).toBe(Number(stone.id));
    expect(own.attributes).toEqual([]);
    let eff = await pcr.find(sub);
    expect(String(eff.default_hs_code)).toBe('680293');
    expect(String(eff.packing_type)).toBe('Wooden crates');
    expect(eff.attributes).toEqual(['Size', 'Finish']);
    expect(pcr.displayName(eff)).toBe(`Natural Stones ${tag} › Granite ${tag}`);
    await db.execute('UPDATE product_categories SET packing_type = :p WHERE id = :id', { p: 'Bundles', id: sub });
    expect(String((await pcr.find(sub)).packing_type)).toBe('Bundles');

    expect((await pcr.find(sub)).margin_pct ?? null).toBeNull();
    await repo.saveSettings(Number(stone.id), { pricing_margin_pct: '31' }, md);
    eff = await pcr.find(sub);
    expect(Number(eff.margin_pct)).toBe(31);
    expect(eff.margin_pct_from_brand).toBe(true);
    await db.execute('UPDATE product_categories SET margin_pct = 28 WHERE id = :id', { id: main });
    expect(Number((await pcr.find(sub)).margin_pct)).toBe(28);
  });

  test('the Categories screens add main and sub-categories with their brand', async () => {
    const stone = await brand('stone');
    const agent = await staff(md);
    let r = await post(agent, '/products/categories', { name: `Stones ${tag}`, 'attributes[]': ['Size'], brand_id: String(stone.id) });
    expect(r.message).toContain('added with Size');
    const mainId = Number((await db.queryOne('SELECT id FROM product_categories WHERE name = :n', { n: `Stones ${tag}` })).id);
    expect(Number((await pcr.findOwn(mainId)).brand_id)).toBe(Number(stone.id));

    r = await post(agent, '/products/categories', { name: `Marble ${tag}`, parent_id: String(mainId), 'attributes[]': ['', ''] });
    expect(r.message).toContain(`Sub-category "Marble ${tag}" added under`);
    const subId = Number((await db.queryOne('SELECT id FROM product_categories WHERE name = :n', { n: `Marble ${tag}` })).id);
    expect(Number((await pcr.findOwn(subId)).parent_id)).toBe(mainId);

    const page = unescape((await agent.get('/products/categories')).text);
    expect(page).toContain('Add a sub-category');
    expect(page).toContain(`sub-category of Stones ${tag}`);
    expect(unescape((await agent.get(`/products/categories/${subId}`)).text)).toContain(`Sub-category: Marble ${tag}`);

    r = await post(agent, '/products/categories', { name: `Deeper ${tag}`, parent_id: String(subId), 'attributes[]': [''] });
    expect(r.message).toBe('Choose the main category for the sub-category.');
  });

  test("a brand manager changes only their brand's catalogue", async () => {
    const stone = await brand('stone');
    const textile = await brand('textile');
    const mineCat = await mainCategory('Stone cat', Number(stone.id));
    const theirCat = await mainCategory('Textile cat', Number(textile.id));
    const companyCat = await mainCategory('Company cat', null);
    const agent = await staff(await user('Managing Director', Number(stone.id)));

    const list = unescape((await agent.get('/products/categories')).text);
    expect(list).toContain(`Stone cat ${tag}`);
    expect(list).toContain(`Company cat ${tag}`);
    expect(list).not.toContain(`Textile cat ${tag}`);

    expect((await agent.get(`/products/categories/${theirCat}`)).status).toBe(404);
    expect((await agent.get(`/products/categories/${companyCat}`)).status).toBe(200);
    expect((await post(agent, `/products/categories/${companyCat}`, { name: `Renamed ${tag}`, 'attributes[]': ['Size'] })).status).toBe(404);
    expect((await post(agent, `/products/categories/${theirCat}/settings`, { default_unit: 'SQM', reason: 'Not mine to change' })).status).toBe(404);
    expect((await post(agent, `/products/categories/${mineCat}`, { name: `Stone cat ${tag} B`, 'attributes[]': ['Size'] })).message).toContain('saved');

    await post(agent, '/products/categories', { name: `Mine new ${tag}`, 'attributes[]': ['Size'], brand_id: String(textile.id) });
    expect(Number((await db.queryOne('SELECT brand_id FROM product_categories WHERE name = :n', { n: `Mine new ${tag}` })).brand_id)).toBe(Number(stone.id));
    await post(agent, '/products/categories', { name: `Sneaky ${tag}`, parent_id: String(theirCat), 'attributes[]': [''] });
    expect(await db.queryOne('SELECT id FROM product_categories WHERE name = :n', { n: `Sneaky ${tag}` })).toBeFalsy();
    await post(agent, `/admin/brands/${stone.id}/pricing`, { action: 'save', margin_pct: '40', reason: 'Trying my own pricing' });
    expect(await repo.settings(Number(stone.id))).toEqual({});
    expect((await post(agent, '/pricing/settings', { margin_pct: '10' })).status).toBe(404);
  });

  test('a product a brand manager adds waits for approval', async () => {
    const stone = await brand('stone');
    const cat = await mainCategory('Stone cat', Number(stone.id));
    const managerId = await user('Managing Director', Number(stone.id));
    const manager = { id: managerId, brand_id: Number(stone.id) };
    const pid = await product('Pending granite', cat, managerId, manager);
    let p = await productRepository.find(pid);
    expect(p.approval_status).toBe('pending');
    expect(await productRepository.isQuotable(pid)).toBe(false);
    expect((await productRepository.forPicker()).map((r) => Number(r.id))).not.toContain(pid);
    expect(productApproval.refusal(p, managerId, { approve_new_products: true })).toBe('Someone else approves a product you added.');

    const mdAgent = await staff(md);
    const inbox = (await mdAgent.get('/approvals?tab=products')).text;
    expect(inbox).toContain(`/products/${pid}#approval`);

    let r = await post(mdAgent, `/products/${pid}/approval`, { decision: 'reject', reason: 'short' });
    expect(r.message).toMatch(/^Give the reason the product is not approved/);
    r = await post(mdAgent, `/products/${pid}/approval`, { decision: 'reject', reason: 'HS code should be 680223 for this granite' });
    expect(r.message).toContain('is not approved');
    expect((await productRepository.find(pid)).approval_status).toBe('rejected');

    await post(await staff(managerId), `/products/${pid}/resubmit`);
    expect((await productRepository.find(pid)).approval_status).toBe('pending');
    r = await post(mdAgent, `/products/${pid}/approval`, { decision: 'approve', reason: '' });
    expect(r.message).toContain('is approved');
    p = await productRepository.find(pid);
    expect(p.approval_status).toBe('approved');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE entity_id = :id AND entity_type = 'catalog_products' AND action_type = 'PRODUCT_APPROVED'", { id: pid })).n)).toBe(1);

    expect((await productRepository.find(await product('Staff product', cat, md))).approval_status).toBe('approved');
    await db.execute("UPDATE company_settings SET setting_value = '0' WHERE setting_key = 'brand_products_need_approval'");
    try {
      expect((await productRepository.find(await product('Setting off', cat, managerId, manager))).approval_status).toBe('approved');
    } finally {
      await db.execute("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'brand_products_need_approval'");
    }
  });

  test('associates see only approved products of their brand and company-wide', async () => {
    const stone = await brand('stone');
    const textile = await brand('textile');
    const a = await associate(Number(stone.id));
    const stoneP = await productRepository.find(await product('Stone product', await mainCategory('Stone cat', Number(stone.id)), md));
    const textileP = await productRepository.find(await product('Textile product', await mainCategory('Textile cat', Number(textile.id)), md));
    const companyP = await productRepository.find(await product('Company product', await mainCategory('Company cat', null), md));
    expect(await priceRequestService.inAssociateBrand(a, stoneP)).toBe(true);
    expect(await priceRequestService.inAssociateBrand(a, companyP)).toBe(true);
    expect(await priceRequestService.inAssociateBrand(a, textileP)).toBe(false);
    expect(await priceRequestService.inAssociateBrand(a, { ...stoneP, approval_status: 'pending' })).toBe(false);
  });

  test('brand pricing, and moving products within a brand', async () => {
    const stone = await brand('stone');
    const textile = await brand('textile');
    const main = await mainCategory('Stone cat', Number(stone.id));
    const sub = await pcr.create(`Sub ${tag}`, [], md, main);
    const other = await mainCategory('Textile cat', Number(textile.id));
    const agent = await staff(md);

    let r = await post(agent, `/admin/brands/${stone.id}/pricing`, { action: 'save', margin_pct: '33.5', other_cost_pct: '', buffer_pct: '4', reason: 'Stone margins agreed for 2027' });
    expect(r.message).toBe('Brand pricing saved. It applies to prices worked out from now on.');
    const st = await repo.settings(Number(stone.id));
    expect([st.pricing_buffer_pct, st.pricing_margin_pct]).toEqual(['4', '33.5']);
    expect((await agent.get(`/admin/brands/${stone.id}`)).text).toContain('data-brand-pricing');
    r = await post(agent, `/admin/brands/${stone.id}/pricing`, { action: 'save', margin_pct: '95', reason: 'Far too high margin' });
    expect(r.message).toContain('Margin % must be a number from 0 to 89.99');

    const pid = await product('Movable', main, md);
    r = await post(agent, '/products/move-category', { 'product_ids[]': [String(pid)], category_id: String(sub), return_to: '/products' });
    expect(r.message).toMatch(/^1 product\(s\) moved to/);
    expect(Number((await productRepository.find(pid)).category_id)).toBe(Number(sub));
    r = await post(agent, '/products/move-category', { 'product_ids[]': [String(pid)], category_id: String(other), return_to: '/products' });
    expect(r.message).toContain('Not moved (another brand');
    expect(Number((await productRepository.find(pid)).category_id)).toBe(Number(sub));
  });
});
