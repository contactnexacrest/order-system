'use strict';

// Owner request (2 Nov 2026): "I need the complete payment screenshots for
// both advance/balance etc for supplier and logistic partner — my team needs
// to see this". GET /help/supplier-logistics-payments is an illustrated,
// static guide any staff member can open (an Export Executive and an
// Accounts Executive alike); a signed-out visitor is sent to /login. It is
// linked from Steps 7e, 10a, the Logistics Partner Bills card and Getting
// Started, and its text is the same as PHP's (HelpGuideTest.php).

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');

const PATH = '/help/supplier-logistics-payments';
const APP_DIR = path.join(__dirname, '..', '..');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

describe('Supplier / logistics payment guide (owner, 2 Nov 2026)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function loginAs(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-guide-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const password = 'JestGuide123!';
    await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Guide User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(password, 10), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = extractCsrf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    return agent;
  }

  it('any staff member sees the guide with its screenshots', async () => {
    for (const role of ['Export Executive', 'Accounts Executive']) {
      const res = await (await loginAs(role)).get(PATH);
      expect([role, res.status]).toEqual([role, 200]);
      expect(res.text).toContain('Supplier &amp; logistics partner payments — step by step');
      for (const section of ['A. Supplier advance — Step 7e', 'B. Supplier balance — Step 10a', 'C. CHA / transport — Logistics Partner Bills', 'D. Freight forwarder — ocean freight bill', 'E. Common mistakes']) {
        expect([role, section, res.text.includes(section.replace(/&/g, '&amp;'))]).toEqual([role, section, true]);
      }
      expect([role, res.text.split('src="/img/guide/').length - 1]).toEqual([role, 11]);
      expect(res.text).toContain('UTR / reference');
    }
  });

  it('a signed-out visitor is sent to login', async () => {
    const res = await request(app).get(PATH);
    expect(res.status).toBe(302);
    expect(res.headers.location).toMatch(/\/login/);
  });

  it('every screenshot exists and is small', () => {
    const view = fs.readFileSync(path.join(APP_DIR, 'views', 'help', 'supplier_logistics_payments.njk'), 'utf8');
    const files = [...view.matchAll(/src="\/img\/guide\/([^"]+)"/g)].map((m) => m[1]);
    expect(files).toHaveLength(11);
    for (const f of files) {
      const p = path.join(APP_DIR, 'public', 'img', 'guide', f);
      expect([f, fs.existsSync(p)]).toEqual([f, true]);
      expect([f, fs.statSync(p).size < 250 * 1024]).toEqual([f, true]);
    }
  });

  it('the guide text is the same as PHP’s', () => {
    const strip = (html, imgBase) => html.replace(/^<\?php[\s\S]*?\?>\s*|^\{#[\s\S]*?#\}\s*/, '').split(imgBase).join('__IMG__');
    const node = strip(fs.readFileSync(path.join(APP_DIR, 'views', 'help', 'supplier_logistics_payments.njk'), 'utf8'), '/img/guide');
    const php = strip(fs.readFileSync(path.join(APP_DIR, '..', '..', 'PHP_MySQL_Version', 'app', 'views', 'help', 'supplier_logistics_payments.php'), 'utf8'), '/assets/img/guide');
    expect(node).toBe(php);
  });

  it('the guide is linked from the bill cards and Getting Started', () => {
    const show = fs.readFileSync(path.join(APP_DIR, 'views', 'orders', 'show.njk'), 'utf8');
    expect(show).toContain(`href="/help/supplier-logistics-payments#guide-{{ 'a' if leg == 'advance' else 'b' }}" target="_blank"`);
    expect(show).toContain('href="/help/supplier-logistics-payments#guide-c" target="_blank"');
    const gs = fs.readFileSync(path.join(APP_DIR, 'views', 'getting-started', 'show.njk'), 'utf8');
    expect(gs).toContain('<a href="/help/supplier-logistics-payments">Step-by-step guide with screenshots</a>');
  });
});
