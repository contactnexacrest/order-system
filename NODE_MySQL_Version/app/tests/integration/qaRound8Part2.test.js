'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const dailyDigestService = require('../../src/services/dailyDigestService');
const selfApprovalRules = require('../../src/services/selfApprovalRules');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of QaRound8Part2Test.php. QA Round 8, part 2 (1 Nov 2026): the
 * number of original BL copies (R8-N13), the reviewer list leaving out the
 * document's own generator (R8-N06) and the daily digest counting only
 * what really left (R8-N02). The form label / "*" / "Choose…" / min checks
 * (R8-N08) are in formLabelConventions.test.js.
 */
const BL_COUNT_MESSAGE = 'Enter how many original BL copies were received (1 to 10).';

describe('QA Round 8, part 2', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-r8p2-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function blOrder() {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 8; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    return orderId;
  }

  async function recordBlOriginals(orderId, count) {
    const req = { params: { id: String(orderId) }, body: { bl_originals_count: count }, user: { id: await createTestUser('Admin') }, session: {} };
    await ordersController.recordBlOriginalsReceived(req, { redirect: () => {} });
    return req.session._flash;
  }

  async function savedBlCount(orderId) {
    const row = await db.queryOne('SELECT bl_originals_received_count AS n FROM order_shipping WHERE order_id = :id', { id: orderId });
    return row && row.n !== null ? Number(row.n) : null;
  }

  // R8-N13: blank, 0, negative and over 10 were saved as 3 or as typed.
  test('the original BL count must be a whole number from 1 to 10', async () => {
    for (const bad of ['', '0', '-2', '11', '2.5', 'abc']) {
      const orderId = await blOrder();
      expect(await recordBlOriginals(orderId, bad)).toEqual([{ type: 'error', message: BL_COUNT_MESSAGE }]);
      expect(await savedBlCount(orderId)).toBeNull();
    }
    const orderId = await blOrder();
    expect((await recordBlOriginals(orderId, '3'))[0].type).toBe('success');
    expect(await savedBlCount(orderId)).toBe(3);
    await recordBlOriginals(orderId, '10');
    expect(await savedBlCount(orderId)).toBe(10);
  });

  test('the BL count field is required, 1 to 10, in the browser', () => {
    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    expect(view).toContain('<label>No. of Original BL Copies Received *<input type="number" name="bl_originals_count" value="3" required min="1" max="10" step="1" placeholder="e.g. 3"></label>');
  });

  async function documentAsShown(orderId, documentId) {
    const res = { redirect: () => {}, status: () => res, send: () => {} };
    res.renderView = (view, data) => { res.renderedData = data; };
    const req = { params: { id: String(orderId) }, permissions: new Proxy({}, { get: () => true }), session: {}, user: { id: await createTestUser('Admin') } };
    await ordersController.show(req, res);
    return res.renderedData.documents.find((d) => Number(d.id) === Number(documentId));
  }

  // R8-N06: the reviewer list offered the document's own generator.
  test('the generator is offered as reviewer only when self-review is allowed', async () => {
    const generator = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const orderId = await createTestOrder(await createTestClient());
    const doc = await createTestDocument(orderId, 'QT', 'draft');
    const setGenerator = (id) => db.execute('UPDATE documents SET generated_by = :g WHERE id = :d', { g: id, d: doc });

    // Default rules (MD + ED): an Export Executive may not review their own document; an MD may.
    await setGenerator(generator);
    expect((await documentAsShown(orderId, doc)).generator_may_self_review).toBe(false);
    await setGenerator(md);
    expect((await documentAsShown(orderId, doc)).generator_may_self_review).toBe(true);

    // Admin → Self-approval rules widened to Export Executive: now offered too.
    await setGenerator(generator);
    const before = (await db.query("SELECT role_id FROM self_approval_rules WHERE rule_key = 'document_review'")).map((r) => Number(r.role_id));
    const exportExec = await db.queryOne("SELECT id FROM roles WHERE name = 'Export Executive'");
    await selfApprovalRules.set('document_review', [...before, Number(exportExec.id)]);
    try {
      expect((await documentAsShown(orderId, doc)).generator_may_self_review).toBe(true);
    } finally {
      await selfApprovalRules.set('document_review', before);
    }

    // The list itself: the generator is skipped unless allowed, and then labelled.
    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    expect(view).toContain("{% set isGen = d.generated_by and (d.generated_by | int) == (u.id | int) %}\n                        {% if not isGen or d.generator_may_self_review %}");
    expect(view).toContain("{{ u.name }} ({{ u.role_name or '—' }}){{ ' (generated it — self-review)' if isGen else '' }}</option>");
  });

  async function personWithAReview() {
    const person = await createTestUser('Procurement Manager');
    const doc = await createTestDocument(await createTestOrder(await createTestClient()), 'QT', 'draft');
    await documentReviewRepository.assign(doc, person);
    return person;
  }

  async function digestRows(userId, date) {
    const row = await db.queryOne('SELECT COUNT(*) AS n FROM daily_digest_log WHERE user_id = :u AND digest_date = :d', { u: userId, d: date });
    return Number(row.n);
  }

  const day = (month) => `2098-${month}-${String(10 + Math.floor(Math.random() * 19))}`;

  // R8-N02: with mail off the digest said "sent" and used up the day.
  test('with e-mail sending off the digest sends nothing and keeps the day', async () => {
    const person = await personWithAReview();
    const date = day('04');
    const before = await companySettingsRepository.get('mail_sending_enabled');
    await companySettingsRepository.set('mail_sending_enabled', '0');
    let result;
    try {
      dailyDigestService.setSender(null);
      result = await dailyDigestService.run(date);
    } finally {
      await companySettingsRepository.set('mail_sending_enabled', before);
    }
    expect(result).toEqual({ sent: 0, skipped: 0, failed: 0, mailOff: true });
    expect(dailyDigestService.summary(result)).toBe('Daily digest: e-mail sending is off — nothing sent.');
    expect(await digestRows(person, date)).toBe(0);
  });

  // R8-N02: a failed send was counted as sent and never retried.
  test('a failed digest is not counted and is retried on the next run', async () => {
    const person = await personWithAReview();
    const date = day('05');
    const to = [];
    dailyDigestService.setSender(async (email) => { to.push(email); return false; });
    let failed;
    try {
      failed = await dailyDigestService.run(date);
    } finally {
      dailyDigestService.setSender(null);
    }
    expect(failed.sent).toBe(0);
    expect(failed.failed).toBe(to.length);
    expect(failed.failed).toBeGreaterThan(0);
    expect(await digestRows(person, date)).toBe(0);
    expect(dailyDigestService.summary(failed)).toContain(`, ${failed.failed} not sent (will be retried on the next run).`);

    // The next run sends it, once; a third run the same day sends nothing.
    let sent = 0;
    dailyDigestService.setSender(async () => { sent++; return true; });
    let retry;
    let again;
    try {
      retry = await dailyDigestService.run(date);
      again = await dailyDigestService.run(date);
    } finally {
      dailyDigestService.setSender(null);
    }
    expect(retry.sent).toBe(sent);
    expect(retry.sent).toBeGreaterThan(0);
    expect(again.sent).toBe(0);
    expect(await digestRows(person, date)).toBe(1);
    expect(dailyDigestService.summary(retry)).toBe(`Daily digest: ${retry.sent} sent, ${retry.skipped} with nothing waiting or already sent.`);
  // Three full digest runs over every user in the shared test database — on the slower
  // GitHub runner, late in the suite, that takes longer than the default 20 s.
  }, 120000);
});
