'use strict';

/**
 * D-432 (owner 26 Nov 2026): brand managers. A staff member given a brand (Admin › Users › Brand, "Manage divisions")
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

describe('Brand managers (D-432)', () => {
  const PASSWORD = 'JestD432!12345';
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
      { name: `Jest D432 ${roleName}`, email: `jest-d432-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id, b: brandId },
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
    const id = await associateRepository.create({ name: `D432 Associate ${tag}${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `associate-${crypto.randomBytes(4).toString('hex')}@email.com`, status: 'active' }, md);
    if (brandId !== null) await repo.setAssociateBrand(id, brandId);
    return associateRepository.find(id);
  }

  test('only staff who see all brands give someone a brand', async () => {
    const stone = await brand('stone');
    const clerk = await user('Export Executive');
    const mdAgent = await staff(md);
    const r = await post(mdAgent, `/users/${clerk}/brand`, { brand_id: String(stone.id) });
    expect(r.message).toMatch(/^Saved: this person now looks after/);
    expect(Number((await db.queryOne('SELECT brand_id FROM users WHERE id = :id', { id: clerk })).brand_id)).toBe(Number(stone.id));
    expect((await mdAgent.get(`/users/${clerk}/edit`)).text).toContain('data-testid="user-brand"');

    const manager = await staff(await user('Managing Director', Number(stone.id)));
    expect((await post(manager, `/users/${clerk}/brand`, { brand_id: '' })).message).toBe('Only staff who look after all brands can give people a brand.');
  });

  test('a brand manager sees and changes only their brand', async () => {
    const stone = await brand('stone');
    const textile = await brand('textile');
    const mine = await associate(Number(stone.id));
    const theirs = await associate(Number(textile.id));
    const none = await associate(null);
    const agent = await staff(await user('Managing Director', Number(stone.id)));

    const list = unescape((await agent.get('/associates?view=list')).text);
    expect(list).toContain(mine.name);
    expect(list).not.toContain(theirs.name);
    expect(list).not.toContain(none.name);

    const page = (await agent.get(`/associates/${mine.id}`)).text;
    expect(page).toContain('data-associate-brand');
    expect(page).not.toContain('data-brand-change'); // never moves an associate between brands
    expect((await agent.get(`/associates/${theirs.id}`)).status).toBe(404);
    expect((await agent.get(`/associates/${none.id}`)).status).toBe(404);
    expect((await post(agent, `/associates/${theirs.id}/status`, { status: 'suspended', reason: 'Not my brand at all' })).status).toBe(404);
    expect((await associateRepository.find(Number(theirs.id))).status).toBe('active');
    expect((await post(agent, `/associates/${mine.id}/brand`, { brand_id: String(textile.id), reason: 'Trying to move them away' })).status).toBe(404);
    expect(Number((await associateRepository.find(Number(mine.id))).brand_id)).toBe(Number(stone.id));

    const index = unescape((await agent.get('/admin/brands')).text);
    expect(index).toContain(stone.name);
    expect(index).not.toContain(textile.name);
    expect(index).not.toContain('Add brand');
    expect((await agent.get(`/admin/brands/${textile.id}`)).status).toBe(404);
    await post(agent, `/admin/brands/${stone.id}/rules`, { action: 'save', group_days: '20', reason: 'Stone orders come in batches' });
    expect(await repo.settings(Number(stone.id))).toEqual({ group_days: '20' });
    expect((await post(agent, `/admin/brands/${textile.id}/rules`, { action: 'save', group_days: '20', reason: 'Not my brand to change' })).status).toBe(404);
    expect(await repo.settings(Number(textile.id))).toEqual({});
    expect((await post(agent, `/admin/brands/${stone.id}`, { name: 'Renamed', code: `renamed${tag}`, status: 'active' })).status).toBe(404);

    expect((await post(agent, '/associates/ladder', { associate_group_days: '20' })).status).toBe(404);
    expect((await post(agent, '/admin/brands', { name: `New ${tag}`, code: `new${tag}`, status: 'active' })).status).toBe(404);
  });

  test("a brand manager's registration links are for their brand", async () => {
    const stone = await brand('stone');
    const agent = await staff(await user('Managing Director', Number(stone.id)));
    await post(agent, '/associates/registrations/links', { note: `Fair ${tag}`, brand_id: '' });
    const row = await db.queryOne('SELECT brand_id FROM associate_registration_links WHERE note = :n', { n: `Fair ${tag}` });
    expect(Number(row.brand_id)).toBe(Number(stone.id));
  });

  test('staff with no brand see everything as before', async () => {
    const stone = await brand('stone');
    const textile = await brand('textile');
    const a = await associate(Number(stone.id));
    const b = await associate(Number(textile.id));
    const agent = await staff(md);
    const list = unescape((await agent.get('/associates?view=list')).text);
    expect(list).toContain(a.name);
    expect(list).toContain(b.name);
    expect((await agent.get(`/associates/${b.id}`)).status).toBe(200);
  });
});
