'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');

/**
 * QA round 13 Nov 2026 — security, access, data-integrity and robustness
 * fixes. Each test names the bug id from the QA register. The PHP suite has
 * the same checks in QaSecurityBatchNov2026Test.php.
 */
describe('QA Nov 2026 security batch', () => {
  const PASSWORD = 'JestQaSec!2026';

  afterAll(async () => {
    await db.pool.end();
  });

  async function user(roleName, kanban = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-qasec-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest QA Sec User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    await userRepository.updateUiTemplatePreference(id, kanban ? (await uiTemplateRepository.findByKey('kanban')).id : null);
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return { id, agent, email };
  }

  describe('UI-001 reflected XSS on Kanban boards', () => {
    it('UI-001: the Complaints board URL-encodes ?status= in "Switch to list view"', async () => {
      const md = await user('Managing Director', true);
      const res = await md.agent.get('/complaints?status=' + encodeURIComponent('"><script>window.__x=1</script>'));
      expect(res.status).toBe(200);
      expect(res.text).not.toContain('<script>window.__x=1</script>');
      expect(res.text).toContain('/complaints?view=list&amp;status=%22%3E%3Cscript%3Ewindow.__x%3D1%3C%2Fscript%3E');
    });

    it('UI-001: no view concatenates a raw variable into markup and marks it | safe', () => {
      // Pattern: ('literal' + someVar) | safe — the variable must be urlencoded/escaped first.
      const offenders = [];
      const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
          const full = path.join(dir, entry.name);
          if (entry.isDirectory()) walk(full);
          else if (entry.name.endsWith('.njk')) {
            fs.readFileSync(full, 'utf8').split('\n').forEach((line, i) => {
              for (const m of line.matchAll(/\(\s*'[^']*'\s*\+\s*([A-Za-z_][\w.]*)\s*\)\s*\|\s*safe/g)) {
                offenders.push(`${path.relative(dir, full)}:${i + 1} ${m[0]}`);
              }
            });
          }
        }
      };
      walk(path.join(__dirname, '../../views'));
      expect(offenders).toEqual([]);
    });
  });

  describe('HNT-001 login-code tries are limited even when sent in parallel', () => {
    const authService = require('../../src/services/authService');
    const twoFactorService = require('../../src/services/twoFactorService');
    const associatePortalService = require('../../src/services/associatePortalService');

    /** A request whose session is a copy of `session` — as each parallel request reads the same stored session row. */
    function reqWith(session) {
      const req = { ip: '127.0.0.1', headers: {} };
      const s = JSON.parse(JSON.stringify(session || {}));
      Object.defineProperty(s, 'regenerate', { enumerable: false, value: (cb) => { req.session = {}; Object.defineProperty(req.session, 'regenerate', { enumerable: false, value: s.regenerate }); cb(); } });
      req.session = s;
      return req;
    }

    it('HNT-001: 20 wrong staff codes sent at once use up the 5 tries; the right code afterwards is refused', async () => {
      const { email } = await user('Export Executive');
      const start = reqWith({});
      const r = await authService.attemptLogin(start, email, PASSWORD);
      expect(r.status).toBe('requires_2fa');
      const wrong = r.dev_code === '000000' ? '111111' : '000000';
      const stored = JSON.parse(JSON.stringify(start.session));
      await Promise.all(Array.from({ length: 20 }, () => twoFactorService.verify(reqWith(stored), wrong)));
      // The right code, sent with the session as it was stored before the burst (last write wins at best for the attacker).
      const after = reqWith(stored);
      expect((await authService.completeTwoFactor(after, r.dev_code)).status).not.toBe('ok');
      expect(authService.currentUserId(after)).toBeNull();
    });

    it('HNT-001: buyer portal code — same rule', async () => {
      const { createTestClient, createTestClientLogin } = require('../support/fixtures');
      const clientPortalService = require('../../src/services/clientPortalService');
      const bEmail = `qasec-buyer-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
      await createTestClientLogin(await createTestClient(bEmail), PASSWORD);
      const start = reqWith({});
      const r = await clientPortalService.attemptLogin(start, bEmail, PASSWORD);
      const wrong = r.dev_code === '000000' ? '111111' : '000000';
      const stored = JSON.parse(JSON.stringify(start.session));
      await Promise.all(Array.from({ length: 20 }, () => twoFactorService.verify(reqWith(stored), wrong, 'client')));
      expect(await twoFactorService.verify(reqWith(stored), r.dev_code, 'client')).toBe(false);
    });

    it('HNT-001: sequential tries still allow the right code within 5', async () => {
      const { email } = await user('Export Executive');
      const req = reqWith({});
      const r = await authService.attemptLogin(req, email, PASSWORD);
      const wrong = r.dev_code === '000000' ? '111111' : '000000';
      for (let i = 0; i < 4; i++) expect(await twoFactorService.verify(req, wrong)).toBe(false);
      expect((await authService.completeTwoFactor(req, r.dev_code)).status).toBe('ok');
    });

    it('HNT-001: associate portal code — parallel wrong codes cannot exceed 5 tries', async () => {
      const associateId = (await db.execute("INSERT INTO associates (name, status) VALUES ('TEST-QA Sec Associate', 'active')")).insertId;
      const code = '246813';
      await db.execute("INSERT INTO associate_tokens (associate_id, purpose, token_hash, expires_at) VALUES (:a, 'login_code', :h, DATE_ADD(NOW(), INTERVAL 10 MINUTE))",
        { a: associateId, h: crypto.createHash('sha256').update(code).digest('hex') });
      const stored = { _associate_portal_pending_id: Number(associateId) };
      await Promise.all(Array.from({ length: 20 }, () => associatePortalService.verifyCode(reqWith(stored), '135790')));
      const row = await db.queryOne("SELECT attempts FROM associate_tokens WHERE associate_id = :a AND purpose = 'login_code'", { a: associateId });
      expect(Number(row.attempts)).toBe(5);
      expect(await associatePortalService.verifyCode(reqWith(stored), code)).toBe(false);
    });

    it('HNT-001: associate bank-change confirmation code — tries claimed atomically, never past the limit', async () => {
      const associateBankRepository = require('../../src/repositories/associateBankRepository');
      const associateId = (await db.execute("INSERT INTO associates (name, status) VALUES ('TEST-QA Sec Bank Associate', 'active')")).insertId;
      const bankId = (await db.execute(
        "INSERT INTO associate_bank_accounts (associate_id, holder_name, account_number_enc, account_last4, bank_name) VALUES (:a, 'TEST-QA Holder', 'x', '1234', 'TEST Bank')",
        { a: associateId }
      )).insertId;
      const results = await Promise.all(Array.from({ length: 12 }, () => associateBankRepository.claimCodeAttempt(bankId, 5)));
      expect(results.filter(Boolean).length).toBe(5);
      expect(Number((await db.queryOne('SELECT confirm_code_attempts AS n FROM associate_bank_accounts WHERE id = :id', { id: bankId })).n)).toBe(5);
    });
  });

  describe('MTX-001 order sub-pages check that the user may open the order', () => {
    it('MTX-001: a role with only ca_module_manage + manage_pricing, assigned to the order, gets 404 on its Zoho check and line price approval', async () => {
      const { createTestClient, createTestOrder } = require('../support/fixtures');
      const orderProductRepository = require('../../src/repositories/orderProductRepository');
      const roleRepository = require('../../src/repositories/roleRepository');
      const orderId = await createTestOrder(await createTestClient());
      const lineId = await orderProductRepository.add(orderId, 1, 'TEST-QA granite slab', '60x30', 'Polished', '10', false, 'PCS', '250.00');
      const roleId = await roleRepository.create(`TEST-MX-only ${crypto.randomBytes(3).toString('hex')}`, null);
      for (const k of ['ca_module_manage', 'manage_pricing']) {
        await db.execute('INSERT INTO role_permissions (role_id, permission_id, is_enabled) SELECT :r, id, 1 FROM permissions WHERE permission_key = :k', { r: roleId, k });
      }
      const u = await user('Admin');
      await db.execute('UPDATE users SET role_id = :r WHERE id = :u', { r: roleId, u: u.id });
      await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: u.id });
      const zoho = await u.agent.get(`/orders/${orderId}/zoho-check`);
      expect(zoho.status).toBe(404);
      const price = await u.agent.get(`/orders/${orderId}/products/${lineId}/price-approval`);
      expect(price.status).toBe(404);
      expect(price.text).not.toContain('TEST-QA granite slab');

      // The MD (who may open the order) still sees the price-approval page.
      const md = await user('Managing Director');
      expect((await md.agent.get(`/orders/${orderId}/products/${lineId}/price-approval`)).status).toBe(200);
    });
  });

  describe('HNT-008 / PLN-001 every record-id route parameter is checked', () => {
    it('HNT-008: changeId, specId, lineId, milestoneId, offerId and pid are validated like :id', () => {
      const names = app.routeIdParams();
      for (const n of ['id', 'changeId', 'specId', 'lineId', 'milestoneId', 'offerId', 'pid', 'productId', 'supplierId']) expect(names).toContain(n);
      const fromSource = new Set([...fs.readFileSync(path.join(__dirname, '../../src/server.js'), 'utf8').matchAll(/\/:(\w+)/g)].map((m) => m[1]).filter((n) => n === 'id' || n === 'pid' || n.endsWith('Id')));
      expect([...fromSource].filter((n) => !names.includes(n))).toEqual([]);
    });

    it('HNT-008: "/orders/2/payment/milestones/1abc", "1.5" and "1x9" answer 404 and reach no handler', async () => {
      const md = await user('Managing Director');
      for (const bad of ['1abc', '1.5', '1x9', '0']) {
        const r = await md.agent.post(`/orders/2/payment/milestones/${bad}`).type('form').send({ amount: '1' });
        expect([bad, r.status]).toEqual([bad, 404]);
      }
      expect((await md.agent.post('/domestic/orders/5/lines/7abc/delete').type('form').send({})).status).toBe(404);
      expect((await md.agent.post('/suppliers/offers/3abc/active').type('form').send({})).status).toBe(404);
      expect((await md.agent.post('/products/1abc/own-specs/2/delete').type('form').send({})).status).toBe(404);
    });
  });

  describe('HNT-002 draft documents on a locked order', () => {
    it('HNT-002: deleting a draft document on a lost (locked) order needs "Override locked data"', async () => {
      const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');
      const orderId = await createTestOrder(await createTestClient());
      const docId = await createTestDocument(orderId, 'QT', 'draft');
      await db.execute("UPDATE orders SET is_locked = 1, status = 'lost' WHERE id = :id", { id: orderId });
      const exec = await user('Export Executive');
      await db.execute('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)', { o: orderId, u: exec.id });
      const page = await exec.agent.get(`/orders/${orderId}`);
      const csrf = (page.text.match(/name="_csrf" value="([^"]+)"/) || [])[1];
      const r = await exec.agent.post(`/orders/${orderId}/documents/${docId}/delete`).type('form').send({ _csrf: csrf, reason: 'TEST-QA deleting a draft on a lost order' });
      expect(r.status).toBe(302);
      expect(await db.queryOne('SELECT id FROM documents WHERE id = :id', { id: docId })).toBeTruthy();
    });
  });

  describe('HNT-004 reviews on a lost / cancelled order', () => {
    it('HNT-004: a pending Quotation review cannot be approved once the order is lost; it can still be rejected', async () => {
      const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');
      const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
      const ed = await user('Executive Director');
      for (const status of ['lost', 'cancelled']) {
        const orderId = await createTestOrder(await createTestClient());
        const docId = await createTestDocument(orderId, 'QT', 'in_review');
        const reviewId = (await db.execute('INSERT INTO document_reviews (document_id, reviewer_id) VALUES (:d, :u)', { d: docId, u: ed.id })).insertId;
        await db.execute('UPDATE orders SET status = :s, is_locked = 1 WHERE id = :id', { s: status, id: orderId });
        await expect(reviewWorkflowService.approve(reviewId, ed.id, null)).rejects.toThrow(/can no longer be approved/);
        const doc = await db.queryOne('SELECT status FROM documents WHERE id = :id', { id: docId });
        expect(doc.status).not.toBe('sent');
        const order = await db.queryOne('SELECT prices_fixed_at FROM orders WHERE id = :id', { id: orderId });
        expect(order.prices_fixed_at).toBeNull();
        await reviewWorkflowService.reject(reviewId, ed.id, 'TEST-QA order lost, closing this review');
        expect((await db.queryOne('SELECT status FROM document_reviews WHERE id = :id', { id: reviewId })).status).toBe('rejected');
      }
    });
  });
});
