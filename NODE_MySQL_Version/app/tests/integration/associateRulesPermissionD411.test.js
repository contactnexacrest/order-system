'use strict';

/**
 * Owner decision D-411 (24 Nov 2026): "Manage associate rules". The associate commission rules (commission levels and
 * the ladder, what the client's price includes, an associate's own rule, Associates › Settings, payment advice
 * corrections, a client's associate, the portal access levels) are changed by the MD, the ED and anyone given the
 * permission in Admin › Roles (or one person) — not by Admin by default, not by other staff.
 * PHP twin: tests/Integration/AssociateRulesPermissionD411Test.php.
 */
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const userRepository = require('../../src/repositories/userRepository');
const associateRules = require('../../src/services/associateRules');

describe('Manage associate rules (D-411)', () => {
  const PASSWORD = 'JestD411!12345';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];
  const unescape = (s) => s.trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');
  let md;
  let ed;
  let admin;
  let exec;

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    return (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES (:name, :email, NULL, :hash, :r, 1, 0, 0, 0)`,
      { name: `Jest D411 ${roleName}`, email: `jest-d411-${crypto.randomBytes(4).toString('hex')}@email.com`, hash: await bcrypt.hash(PASSWORD, 4), r: role.id },
    )).insertId;
  }

  beforeEach(async () => {
    md = await user('Managing Director');
    ed = await user('Executive Director');
    admin = await user('Admin');
    exec = await user('Export Executive');
  });

  async function staff(userId) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const email = (await db.queryOne('SELECT email FROM users WHERE id = :id', { id: userId })).email;
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return agent;
  }

  /** Posts with the agent's CSRF token (taken from the home page); returns the status and the flash. */
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

  /** The POST routes that change an associate rule (D-411). */
  const rulePosts = (associateId) => [
    '/pricing/settings/associate-price-rule',
    '/pricing/commission-levels',
    '/pricing/commission-ladder',
    '/associates/access-levels',
    `/associates/${associateId}/commission-rule`,
    `/associates/${associateId}/payment-advices/999999/revise`,
  ];

  it('the MD and the ED hold the permission, and Admin does not', async () => {
    const roles = (await db.query(`SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                                    WHERE p.permission_key = 'manage_associate_rules' AND rp.is_enabled = 1 ORDER BY r.name`)).map((r) => r.name);
    expect(roles).toEqual(['Executive Director', 'Managing Director']);
    expect((await db.queryOne("SELECT name FROM permissions WHERE permission_key = 'manage_associate_rules'")).name).toBe('Manage associate rules');
    expect(await associateRules.canManage(await userRepository.findById(ed))).toBe(true);
    expect(await associateRules.canManage(await userRepository.findById(admin))).toBe(false);
  });

  it('only people with the permission pass the rule routes', async () => {
    const a = await associateRepository.find(await associateRepository.create({ name: `D411 Associate ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', currency_code: 'INR',
      email: null, phone: null, tax_id: null, agreement_signed_on: '2025-12-01', status: 'active', notes: null }, md));
    const agents = { admin: await staff(admin), exec: await staff(exec), md: await staff(md), ed: await staff(ed) };
    for (const path of rulePosts(a.id)) {
      for (const who of ['admin', 'exec']) {
        expect([path, who, (await post(agents[who], path)).status]).toEqual([path, who, 403]);
      }
      for (const who of ['md', 'ed']) {
        expect((await post(agents[who], path)).status).not.toBe(403);
      }
    }
  });

  it('the ED changes what the client price includes, with a reason', async () => {
    const agent = await staff(ed);
    let r = await post(agent, '/pricing/settings/associate-price-rule', { associate_price_rule: 'fixed', associate_price_fixed_pct: '6.00' });
    expect([r.type, r.message]).toEqual(['error', expect.stringContaining("Give the reason for changing what the client's price includes")]);
    r = await post(agent, '/pricing/settings/associate-price-rule', { associate_price_rule: 'fixed', associate_price_fixed_pct: '6.00', associate_price_reason: 'Margin review by the ED, November' });
    expect(r.type).toBe('success');
    expect(String((await pricingRepository.settings()).associate_price_fixed_pct)).toBe('6.00');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE user_id = :u AND action_type = 'PRICING_SETTING_CHANGED' AND new_value LIKE '%Margin review by the ED%'", { u: ed })).n)).toBe(1);
    await pricingRepository.setSetting('associate_price_fixed_pct', '5.00', md);
  });

  it('the general settings form no longer changes the associate rule', async () => {
    const agent = await staff(md);
    await post(agent, '/pricing/settings', { ...(await pricingRepository.settings()), associate_price_rule: 'ladder', associate_price_reason: 'Should be ignored by this form' });
    expect((await associateRules.companyPriceRule()).rule).toBe('fixed');
  });

  it('the ED sees the forms; other pricing settings stay with Manage pricing', async () => {
    const agent = await staff(ed);
    const page = (await agent.get('/pricing/settings')).text;
    expect(page).toContain('action="/pricing/settings/associate-price-rule"');
    expect(page).toContain("Save what the client's price includes");
    expect(page).toContain('Only the MD can change these settings.');
    const levels = (await agent.get('/pricing/commission-levels')).text;
    expect(levels).toContain('Save ladder');
    expect(levels).not.toContain('data-rules-read-only');
  });

  it('the permission can be given to one person', async () => {
    let agent = await staff(exec);
    expect((await post(agent, '/pricing/commission-ladder')).status).toBe(403);
    await db.execute(`INSERT INTO user_permissions (user_id, permission_id, is_enabled, granted_by, reason)
                      SELECT :u, id, 1, :by, 'D-411 test: one person may manage the associate rules' FROM permissions WHERE permission_key = 'manage_associate_rules'`,
    { u: exec, by: md });
    agent = await staff(exec);
    expect((await post(agent, '/pricing/commission-ladder')).status).not.toBe(403);
    expect(await associateRules.canManage(await userRepository.findById(exec))).toBe(true);
  });
});
