'use strict';

/**
 * D-431 (owner 25–26 Nov 2026): Brands for divisions, associate side only.
 *  - Admin › Brands: name only (no logo), the line under it, login address code; Rules (empty = company setting,
 *    "Copy all company settings", "Clear all") and an own ladder, each change with a reason.
 *  - Rules resolve: the associate's own value, then the brand's, then the company's.
 *  - The associate login, portal and e-mails carry the brand name; buyers never see it (the buyer form keeps a clean
 *    address bar). One associate = one brand; only "Manage divisions" moves an associate, with a reason.
 * PHP twin: tests/Integration/AssociateBrandsD431Test.php.
 */
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const env = require('../../src/config/env');
const associateRepository = require('../../src/repositories/associateRepository');
const repo = require('../../src/repositories/associateBrandRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const associateRegistrationRepository = require('../../src/repositories/associateRegistrationRepository');
const brands = require('../../src/services/associateBrands');
const associateRules = require('../../src/services/associateRules');
const associateCommission = require('../../src/services/associateCommission');
const associateCodeService = require('../../src/services/associateCodeService');
const associatePortalService = require('../../src/services/associatePortalService');
const associateService = require('../../src/services/associateService');
const priceRequestService = require('../../src/services/priceRequestService');

describe('Brands for divisions (D-431)', () => {
  const PASSWORD = 'JestD431!12345';
  const ASSOCIATE_PASSWORD = 'Brand1234567';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const unescape = (s) => s.trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  let md;
  let tag;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES (:name, :email, NULL, :hash, :r, 1, 0, 0, 0)`,
      { name: `Jest D431 ${roleName}`, email: `jest-d431-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id },
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

  /** Posts with the agent's CSRF token; returns the status and the flash. */
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

  async function brand(extra = {}) {
    const id = await repo.save(null, {
      code: `st${tag}`, name: `Stone Division ${tag}`, tagline: brands.DEFAULT_TAGLINE, products: 'Natural Stone', website: null, email: null, status: 'active', ...extra,
    }, md);
    return repo.find(id);
  }

  async function associate(brandId = null) {
    const id = await associateRepository.create({ name: `D431 Associate ${tag}${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: `associate-${crypto.randomBytes(4).toString('hex')}@email.com`, status: 'active', portal_enabled: 1 }, md);
    await db.execute('UPDATE associates SET portal_enabled = 1 WHERE id = :id', { id });
    await associateCodeService.issue(id, `B${tag.toUpperCase()}${String(id).slice(-2)}`);
    if (brandId !== null) await repo.setAssociateBrand(id, brandId);
    return associateRepository.find(id);
  }

  async function portal(a) {
    const agent = request.agent(app);
    const token = await associatePortalService.issueToken(Number(a.id), 'set_password', 60);
    const csrf = csrfOf((await agent.get(`/associate/set-password/${token}`)).text);
    await agent.post(`/associate/set-password/${token}`).type('form').send({ _csrf: csrf, new_password: ASSOCIATE_PASSWORD, confirm_password: ASSOCIATE_PASSWORD });
    const loginCsrf = csrfOf((await agent.get('/associate/login')).text);
    await agent.post('/associate/login').type('form').send({ _csrf: loginCsrf, email: a.email, password: ASSOCIATE_PASSWORD });
    const codePage = (await agent.get('/associate/login/code')).text;
    const code = codePage.match(/your login code is (\d{6})/)[1];
    await agent.post('/associate/login/code').type('form').send({ _csrf: csrfOf(codePage), code });
    return agent;
  }

  test('rules follow the company until the brand sets its own; the associate\'s own value comes first', async () => {
    const b = await brand();
    const a = await associate(Number(b.id));
    const plain = await associate();
    const company = await brands.companyValues();
    expect(await associateRules.minOrderInr(Number(a.id))).toBe(Number(company.min_order_inr)); // empty = the company setting
    expect(await associateRules.groupDays(Number(a.id))).toBe(await associateRules.companyGroupDays());

    await repo.saveSettings(Number(b.id), { min_order_inr: '1000000.00', group_days: '30', price_requests_per_day: '3', price_valid_days: '14',
      price_rule: 'fixed', price_fixed_pct: '4.00' }, md);
    expect(await associateRules.minOrderInr(Number(a.id))).toBe(1000000); // the brand's own minimum
    expect(await associateRules.groupDays(Number(a.id))).toBe(30);
    expect(await associateRules.minOrderInr(Number(plain.id))).toBe(Number(company.min_order_inr)); // no brand: the company setting
    const fresh = await associateRepository.find(Number(a.id));
    expect(await priceRequestService.dailyLimit(fresh)).toBe(3);
    expect(await priceRequestService.validDays(fresh)).toBe(14);
    const rule = await associateRules.priceRuleFor(fresh);
    expect([rule.rule, rule.pct, rule.brand]).toEqual(['fixed', 4, true]);

    await db.execute('UPDATE associates SET price_request_daily_limit = 7 WHERE id = :id', { id: a.id });
    expect(await priceRequestService.dailyLimit(await associateRepository.find(Number(a.id)))).toBe(7);
  });

  test('the brand ladder replaces the company ladder', async () => {
    const b = await brand();
    const a = await associate(Number(b.id));
    const level = Number((await db.queryOne('SELECT id FROM commission_levels WHERE is_active = 1 ORDER BY id LIMIT 1')).id);
    expect(await associateCommission.ladderFor(a)).toEqual(await pricingRepository.ladder()); // empty = the company ladder
    await repo.replaceLadder(Number(b.id), [{ from: 1, to: null, level_id: level }], md);
    const steps = await associateCommission.ladderFor(a);
    expect(steps).toHaveLength(1);
    expect(Number(steps[0].level_id)).toBe(level);
  });

  test('Admin › Brands screen, and rules changed only with a reason', async () => {
    const agent = await staff(md);
    expect((await agent.get('/admin/brands')).text).toContain('data-brands-intro');
    const r = await post(agent, '/admin/brands', { name: `Textile Division ${tag}`, code: `tx${tag}`, tagline: '', status: 'active' });
    expect(r.message).toMatch(/^Brand Textile Division/);
    const b = await repo.findByCode(`tx${tag}`);
    expect(b.tagline).toBe(brands.DEFAULT_TAGLINE); // an empty line gets the company default
    expect((await post(agent, '/admin/brands', { name: `Other ${tag}`, code: `tx${tag}`, status: 'active' })).message).toContain('Another brand already uses this login address code.');
    expect((await post(agent, '/admin/brands', { name: `Other ${tag}`, code: 'code', status: 'active' })).message).toContain('login address code'); // "code" is a portal page

    const id = Number(b.id);
    expect((await agent.get(`/admin/brands/${id}`)).text).toContain('data-brand-rules-table');
    expect((await post(agent, `/admin/brands/${id}/rules`, { action: 'copy_all', reason: 'short' })).message).toContain('Give the reason');
    await post(agent, `/admin/brands/${id}/rules`, { action: 'copy_all', reason: 'Start from the company settings for this division' });
    expect((await repo.settings(id)).group_days).toBe((await brands.companyValues()).group_days); // Copy all fills every rule
    await post(agent, `/admin/brands/${id}/rules`, { action: 'clear_all', reason: 'Follow the company settings again' });
    expect(await repo.settings(id)).toEqual({}); // Clear all = follow the company
    await post(agent, `/admin/brands/${id}/rules`, { action: 'save', min_order_inr: '750000', group_days: '', reason: 'Textile orders are smaller than stone orders' });
    expect(await repo.settings(id)).toEqual({ min_order_inr: '750000.00' });
    const audit = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'ASSOCIATE_BRAND_RULE_CHANGED' AND entity_id = :id", { id });
    expect(Number(audit.n)).toBeGreaterThan(0);
  });

  test('only "Manage divisions" moves an associate to a brand, with a reason', async () => {
    const b = await brand();
    const a = await associate();
    const clerk = await staff(await user('Export Executive'));
    await post(clerk, `/associates/${a.id}/brand`, { brand_id: String(b.id), reason: 'Joined through the stone division' });
    expect((await associateRepository.find(Number(a.id))).brand_id).toBeNull();

    const agent = await staff(md);
    const r = await post(agent, `/associates/${a.id}/brand`, { brand_id: String(b.id), reason: 'Joined through the stone division' });
    expect(r.message).toMatch(/^Brand changed to/);
    expect(Number((await associateRepository.find(Number(a.id))).brand_id)).toBe(Number(b.id));
    expect((await agent.get(`/associates/${a.id}`)).text).toContain('data-associate-brand');
  });

  test('the associate sees the brand; buyers do not', async () => {
    const b = await brand();
    const a = await associate(Number(b.id));
    // The brand's login address remembers the brand for the login page.
    const visitor = request.agent(app);
    const go = await visitor.get(`/associate/login/${b.code}`);
    expect(go.status).toBe(302);
    expect(go.headers.location).toBe('/associate/login');
    const login = (await visitor.get('/associate/login')).text;
    expect(unescape(login)).toContain(`For ${b.name} associates.`);
    expect(login).toContain('data-portal-brand-footer');

    const home = unescape((await (await portal(a)).get('/associate')).text);
    expect(home).toContain('data-portal-brand');
    expect(home).toContain(b.name);
    expect(home).toContain('Your agreement and your payments are with NexaCrest International Private Limited.');
    expect(home).toContain(brands.BUYER_TIP);

    const [subject, body] = await brands.email(a, 'Your NexaCrest login code', 'Your login code is 123456.\n\nNexaCrest International Private Limited');
    expect(subject).toBe(`Your ${b.name} login code`);
    expect(body.startsWith(`${b.name} — ${brands.DEFAULT_TAGLINE}`)).toBe(true);
    expect(body.endsWith(brands.legalLine(b))).toBe(true);
    expect(await associateService.loginUrlFor(a)).toBe(`${String(env.get('APP_URL', '')).replace(/\/+$/, '')}/associate/login/${b.code}`);

    // The buyer's form: the code is kept for the visit, the address bar shows only /quotation-details, and no brand.
    const buyer = request.agent(app);
    const r = await buyer.get(`/quotation-details?code=${encodeURIComponent(a.personal_code)}`);
    expect(r.status).toBe(303);
    expect(r.headers.location).toBe('/quotation-details');
    const form = (await buyer.get('/quotation-details')).text;
    expect(form).toContain(`name="associate_code" value="${a.personal_code}"`);
    expect(form).not.toContain(b.name); // a buyer never sees the brand
  });

  test('the registration link carries the brand', async () => {
    const b = await brand();
    const raw = await associateRegistrationRepository.createLink('Trade fair', md, Number(b.id));
    const page = unescape((await request(app).get(`/associate-register/${raw}`)).text);
    expect(page).toContain(`Register as a ${b.name} associate`);
    expect(page).toContain('Your agreement and your payments are with NexaCrest International Private Limited.');
  });
});
