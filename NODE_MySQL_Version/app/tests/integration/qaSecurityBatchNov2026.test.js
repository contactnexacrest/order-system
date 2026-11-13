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

  describe('HNT-003 remittances recorded at the same moment', () => {
    const { todayIst } = require('../../src/helpers/dbDateTime');

    async function orderWithAdvanceDue() {
      const { createTestClient, createTestOrder, allowHandTypedGoodsLines } = require('../support/fixtures');
      const orderProductRepository = require('../../src/repositories/orderProductRepository');
      await allowHandTypedGoodsLines();
      const orderId = await createTestOrder(await createTestClient());
      await orderProductRepository.add(orderId, 1, 'TEST-QA granite slab', '60x30', 'Polished', '10', false, 'PCS', '250.00');
      await db.execute('UPDATE orders SET advance_pct = 40 WHERE id = :id', { id: orderId });
      await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
      return orderId; // advance due USD 1,000.00 of USD 2,500.00
    }

    it('HNT-003: two advance remittances of the full advance sent at once — only one is recorded', async () => {
      const orderId = await orderWithAdvanceDue();
      const a = await user('Managing Director');
      const b = await user('Executive Director');
      const csrfA = (await a.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      const csrfB = (await b.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      const fields = (ref, inr) => ({ advance_amount: '1000.00', advance_received_at: todayIst(), advance_inr_credited: inr, advance_bank_reference: ref });
      await Promise.all([
        a.agent.post(`/orders/${orderId}/payment/advance`).type('form').send({ _csrf: csrfA, ...fields('TEST-UTR-RACE-0', '84000') }),
        b.agent.post(`/orders/${orderId}/payment/advance`).type('form').send({ _csrf: csrfB, ...fields('TEST-UTR-RACE-1', '84001') }),
      ]);
      const n = await db.queryOne("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS t FROM order_payment_receipts WHERE order_id = :o AND leg = 'advance'", { o: orderId });
      expect(Number(n.n)).toBe(1);
      expect(Number(n.t)).toBe(1000);
    });

    it('HNT-003: a payment milestone recorded twice at the same moment is recorded once', async () => {
      const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
      const orderId = await orderWithAdvanceDue();
      await paymentMilestoneRepository.replaceSchedule(orderId, [{ pct: 10, due_text: 'on shipment' }], 30);
      await require('../support/fixtures').createTestDocument(orderId, 'PI', 'sent');
      const m = (await paymentMilestoneRepository.forOrder(orderId))[0];
      const a = await user('Managing Director');
      const b = await user('Executive Director');
      const csrfA = (await a.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      const csrfB = (await b.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      const fields = (ref, inr) => ({ amount: '250.00', received_on: todayIst(), inr_credited: inr, bank_reference: ref });
      const rs = await Promise.all([
        a.agent.post(`/orders/${orderId}/payment/milestones/${m.id}`).type('form').send({ _csrf: csrfA, ...fields('TEST-UTR-MS-0', '21000') }),
        b.agent.post(`/orders/${orderId}/payment/milestones/${m.id}`).type('form').send({ _csrf: csrfB, ...fields('TEST-UTR-MS-1', '21001') }),
      ]);
      expect(rs.map((r) => r.status)).toEqual([302, 302]);
      const n = await db.queryOne("SELECT COUNT(*) AS n FROM order_payment_receipts WHERE order_id = :o AND leg = 'milestone'", { o: orderId });
      expect(Number(n.n)).toBe(1);
    });
  });

  describe('HNT-005 public Payment Action form', () => {
    it('HNT-005: an over-large amount or a 2099 / 0000 / 1900 date is refused beside the form, before any file is stored; never a 500', async () => {
      const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');
      const orderRepository = require('../../src/repositories/orderRepository');
      const orderProductRepository = require('../../src/repositories/orderProductRepository');
      const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
      const clientId = await createTestClient();
      const orderId = await createTestOrder(clientId);
      await db.execute("UPDATE orders SET payment_method = 'CASH_ADVANCE' WHERE id = :id", { id: orderId });
      await orderProductRepository.add(orderId, 1, 'Absolute black granite', null, null, '50', false, 'PCS', '150.00');
      await orderPaymentStatusRepository.initializeForOrder(orderId);
      const token = await orderRepository.generatePaymentActionToken(orderId);
      const fileId = await createTestFile(orderId, clientId);
      const file = await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: fileId });
      fs.mkdirSync(path.dirname(file.server_path), { recursive: true });
      fs.writeFileSync(file.server_path, '%PDF-1.4 proforma');
      const documentId = await createTestDocument(orderId, 'PI', 'sent');
      await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: fileId, id: documentId });

      const files = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM file_store WHERE order_id = :o', { o: orderId })).n);
      const before = await files();
      const agent = request.agent(app);
      const form = (await agent.get(`/payment-action/${token}`)).text;
      expect(form).toContain('max="999999999999.99"');
      expect(form).toContain('min="2000-01-01"');
      for (const [amount, date, message] of [
        ['1e20', '2026-11-10', /amount paid/i],
        ['100', '2099-01-01', /today or earlier/],
        ['100', '0000-01-01', /today or earlier|cannot be before/],
        ['100', '1900-01-01', /cannot be before/],
      ]) {
        const page = await agent.get(`/payment-action/${token}`);
        const r = await agent.post(`/payment-action/${token}/report`)
          .field('_csrf', page.text.match(/name="_csrf"\s+value="([^"]+)"/)[1]).field('transaction_ref', 'TEST-UTR-4').field('amount', amount).field('payment_date', date)
          .attach('screenshot', Buffer.from('%PDF-1.4 remittance'), 'remittance.pdf');
        expect([amount, date, r.status]).toEqual([amount, date, 302]);
        expect((await agent.get(`/payment-action/${token}`)).text).toMatch(message);
      }
      expect(await files()).toBe(before);
      expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM client_payment_reports WHERE order_id = :o', { o: orderId })).n)).toBe(0);
    });
  });

  describe('HNT-007 unreadable multipart upload', () => {
    it('HNT-007: a file name holding a NUL byte gets "We could not accept that file", not HTTP 500', async () => {
      const { createTestClient, createTestOrder } = require('../support/fixtures');
      const orderId = await createTestOrder(await createTestClient());
      const md = await user('Managing Director');
      const csrf = (await md.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      const b = 'XqaSecBoundary';
      const body = Buffer.concat([
        Buffer.from(`--${b}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n${csrf}\r\n`),
        Buffer.from(`--${b}\r\nContent-Disposition: form-data; name="title"\r\n\r\nTEST-nul\r\n`),
        Buffer.from(`--${b}\r\nContent-Disposition: form-data; name="document"; filename="x.pdf`), Buffer.from([0]), Buffer.from(`.html"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.4\r\n`),
        Buffer.from(`--${b}--\r\n`),
      ]);
      const r = await md.agent.post(`/orders/${orderId}/additional-documents`).set('Content-Type', `multipart/form-data; boundary=${b}`).send(body);
      expect(r.status).not.toBe(500);
      expect([302, 400]).toContain(r.status);
      if (r.status === 302) {
        expect((await md.agent.get(r.headers.location)).text).toContain('We could not accept that file');
      }
    });
  });

  describe('HNT-006 upload content check, same rule on both stacks', () => {
    const fileUploadService = require('../../src/services/fileUploadService');
    const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8ffff3f0005fe02fea7d6a4b40000000049454e44ae426082', 'hex');

    it('HNT-006: a BOM before <html><script> is caught on Node and PHP alike', () => {
      expect(fileUploadService.looksLikeDangerousContent(Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from('<html><script>alert(1)</script>')]))).toBe(true);
      expect(fileUploadService.looksLikeDangerousContent(Buffer.from('%PDF-1.4 <html>'))).toBe(false);
    });

    it('HNT-006: an image extension must carry that image format\'s signature', () => {
      const m = fileUploadService.imageContentMismatch;
      expect(m(PNG, 'png')).toBe(false);
      expect(m(Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0, 0x10]), 'jpg')).toBe(false);
      expect(m(Buffer.from('GIF89a....'), 'gif')).toBe(false);
      expect(m(Buffer.from('RIFF\x10\x00\x00\x00WEBPVP8 '), 'webp')).toBe(false);
      expect(m(Buffer.from('\x00\x00\x00\x18ftypheic'), 'heic')).toBe(false);
      expect(m(Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'png')).toBe(true);
      expect(m(Buffer.from('not really a jpeg'), 'jpg')).toBe(true);
      expect(m(Buffer.from('anything'), 'pdf')).toBe(false); // only image types are checked
    });

    it('HNT-006: through the real upload, a BOM+HTML ".jpg" and an SVG ".png" are refused, a real PNG is kept', async () => {
      const { createTestClient, createTestOrder } = require('../support/fixtures');
      const orderId = await createTestOrder(await createTestClient());
      const md = await user('Managing Director');
      const count = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM file_store WHERE order_id = :o', { o: orderId })).n);
      const before = await count();
      for (const [name, content] of [
        ['x.jpg', Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from('<html><script>alert(1)</script>')])],
        ['y.png', Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
      ]) {
        const csrf = (await md.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
        await md.agent.post(`/orders/${orderId}/additional-documents`).field('_csrf', csrf).field('title', 'TEST-upload').attach('document', content, name);
      }
      expect(await count()).toBe(before);
      const csrf = (await md.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      await md.agent.post(`/orders/${orderId}/additional-documents`).field('_csrf', csrf).field('title', 'TEST-upload real').attach('document', PNG, 'real.png');
      expect(await count()).toBe(before + 1);
    });
  });

  describe('HNT-010 database refusing connections (parity with PHP)', () => {
    it('HNT-010: a refused connection is treated as "please try again" (503), an ordinary error is not', async () => {
      const mysql = require('mysql2/promise');
      const { isTransientDbError } = require('../../src/helpers/dbResilience');
      let err = null;
      try {
        await mysql.createConnection({ host: '127.0.0.1', port: 3399, user: 'x', password: 'x' });
      } catch (e) {
        err = e;
      }
      expect(err).not.toBeNull();
      expect(isTransientDbError(err)).toBe(true);
      expect(isTransientDbError(new Error('boom'))).toBe(false);
    });
  });

  describe('UI-009 back to the form when the site runs on a port (parity with PHP)', () => {
    it('UI-009: a refused upload goes back to the referring page on the same host:port', async () => {
      const { createTestClient, createTestOrder } = require('../support/fixtures');
      const orderId = await createTestOrder(await createTestClient());
      const md = await user('Managing Director');
      const csrf = (await md.agent.get(`/orders/${orderId}`)).text.match(/name="_csrf" value="([^"]+)"/)[1];
      const r = await md.agent.post(`/orders/${orderId}/additional-documents`)
        .set('Host', '127.0.0.1:8080').set('Referer', `http://127.0.0.1:8080/orders/${orderId}?tab=docs`)
        .field('_csrf', csrf).attach('not_the_right_field', Buffer.from('%PDF-1.4'), 'x.pdf');
      expect(r.status).toBe(302);
      expect(r.headers.location).toBe(`/orders/${orderId}?tab=docs`);
    });
  });
});
