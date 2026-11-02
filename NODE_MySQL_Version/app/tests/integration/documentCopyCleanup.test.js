'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const cleanup = require('../../src/services/documentCopyCleanupService');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

// Port of DocumentCopyCleanupTest.php — owner points 5 / 8 / 9 (2 Nov 2026):
// Admin → Settings → Document copies clean-up. Each rule removes exactly the
// copies it describes and nothing it promises to keep.
describe('Document copies clean-up (owner points 5/8/9)', () => {
  const GENERATED_AT = '2023-07-01 10:00:00'; // FY 2023-24, a year no other test locks

  const setRules = async (draft, superseded, cafin) => {
    await companySettingsRepository.set(cleanup.KEY_DRAFT, String(draft));
    await companySettingsRepository.set(cleanup.KEY_SUPERSEDED, String(superseded));
    await companySettingsRepository.set(cleanup.KEY_CAFIN, cafin);
  };

  beforeEach(async () => setRules(0, 0, 'keep_all'));
  afterEach(async () => {
    await setRules(0, 0, 'keep_all');
    await db.execute("UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE financial_year = '2022-23' AND unlocked_at IS NULL");
  });
  afterAll(async () => {
    await db.pool.end();
  });

  const newOrder = async () => createTestOrder(await createTestClient());

  async function doc(orderId, code, status, generatedAt = GENERATED_AT) {
    const id = await createTestDocument(orderId, code, status);
    await db.execute('UPDATE documents SET generated_at = :g WHERE id = :id', { g: generatedAt, id });
    return id;
  }

  async function file(orderId, documentId, name, ageDays, mime = 'application/pdf', sent = false) {
    const p = path.join(os.tmpdir(), `jest-cleanup-${crypto.randomBytes(6).toString('hex')}${mime === 'application/pdf' ? '.pdf' : '.docx'}`);
    fs.writeFileSync(p, 'x'.repeat(2048));
    return (await db.execute(
      `INSERT INTO file_store (order_id, file_origin, generation_method, linked_document_id, sent_to_client, server_path, uuid_filename,
                               original_filename, file_size_bytes, mime_type, uploaded_at)
       VALUES (:o, 'GENERATED_AUTO', 'nunjucks+puppeteer/docx', :d, :s, :p, :u, :n, 2048, :m, DATE_SUB(NOW(), INTERVAL :age DAY))`,
      { o: orderId, d: documentId, s: sent ? 1 : 0, p, u: path.basename(p), n: name, m: mime, age: ageDays }
    )).insertId;
  }

  const setCurrent = (documentId, pdf, docx = null) =>
    db.execute('UPDATE documents SET pdf_file_id = :p, docx_file_id = :x WHERE id = :id', { p: pdf, x: docx, id: documentId });
  const candidateIds = async () => (await cleanup.candidates()).map((r) => Number(r.file_id));
  const active = async (id) => Number((await db.queryOne('SELECT is_active FROM file_store WHERE id = :id', { id })).is_active) === 1;

  async function approvedRevision(orderId, ageDays, status = 'approved', finalSent = false) {
    const d = await doc(orderId, 'QT', status);
    const draft = await file(orderId, d, 'QT draft.pdf', ageDays);
    const docx = await file(orderId, d, 'QT (internal).docx', ageDays, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    const final = await file(orderId, d, 'QT final.pdf', ageDays, 'application/pdf', finalSent);
    await setCurrent(d, final, docx);
    return { doc: d, draft, docx, final };
  }

  it('everything off removes nothing', async () => {
    const r = await approvedRevision(await newOrder(), 400);
    expect(await cleanup.candidates()).toEqual([]);
    expect((await cleanup.run(null, 'manual')).skipped_off).toBe(true);
    expect(await active(r.draft)).toBe(true);
  });

  it('the draft rule removes only the draft of an approved document after the chosen days', async () => {
    const order = await newOrder();
    const old = await approvedRevision(order, 40);
    const recent = await approvedRevision(order, 10);
    const inReview = await doc(order, 'QT', 'in_review');
    const onlyDraft = await file(order, inReview, 'QT in review.pdf', 200);
    await setCurrent(inReview, onlyDraft);
    const uploaded = await createTestFile(order, null);

    await setRules(30, 0, 'keep_all');
    const ids = await candidateIds();
    expect(ids).toContain(old.draft);
    for (const kept of [old.final, old.docx, recent.draft, recent.final, onlyDraft, uploaded]) expect(ids).not.toContain(kept);
  });

  it('the superseded rule keeps sent revisions and the SUPERSEDED-stamped copy', async () => {
    const order = await newOrder();
    const never = await approvedRevision(order, 100, 'superseded');
    const neverStamp = await file(order, never.doc, 'QT (SUPERSEDED).pdf', 100);
    await setCurrent(never.doc, neverStamp, never.docx);
    const sent = await approvedRevision(order, 100, 'superseded', true);
    const sentStamp = await file(order, sent.doc, 'QT (SUPERSEDED).pdf', 100);
    await setCurrent(sent.doc, sentStamp, sent.docx);
    const mailed = await approvedRevision(order, 100, 'superseded');
    const mailedStamp = await file(order, mailed.doc, 'QT (SUPERSEDED).pdf', 100);
    await setCurrent(mailed.doc, mailedStamp, mailed.docx);
    await db.execute(
      "INSERT INTO email_log (document_id, order_id, recipient_email, subject, body_snapshot, status, sent_at) VALUES (:d, :o, 'b@example.test', 'QT', 'QT attached', 'sent', NOW())",
      { d: mailed.doc, o: order }
    );

    await setRules(0, 90, 'keep_all');
    const ids = await candidateIds();
    for (const k of ['draft', 'docx', 'final']) {
      expect(ids).toContain(never[k]);
      expect(ids).not.toContain(sent[k]);
      expect(ids).not.toContain(mailed[k]);
    }
    for (const stamp of [neverStamp, sentStamp, mailedStamp]) expect(ids).not.toContain(stamp);
  });

  it('CAFIN latest-only keeps the latest per order', async () => {
    const daysAgo = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 19).replace('T', ' ');
    const order = await newOrder();
    const older = await doc(order, 'CAFIN', 'draft', daysAgo(90));
    const olderFile = await file(order, older, 'CAFIN old.pdf', 90);
    await setCurrent(older, olderFile);
    const newer = await doc(order, 'CAFIN', 'draft', daysAgo(40));
    const newerFile = await file(order, newer, 'CAFIN new.pdf', 40);
    await setCurrent(newer, newerFile);
    const order2 = await newOrder();
    const a = await doc(order2, 'CAFIN', 'draft', daysAgo(60));
    const aFile = await file(order2, a, 'CAFIN a.pdf', 60);
    await setCurrent(a, aFile);
    const b = await doc(order2, 'CAFIN', 'draft', daysAgo(5));
    await setCurrent(b, await file(order2, b, 'CAFIN b.pdf', 5));

    await setRules(0, 0, 'latest_only');
    const ids = await candidateIds();
    expect(ids).toContain(olderFile);
    expect(ids).not.toContain(newerFile);
    expect(ids).not.toContain(aFile);
  });

  it('an open dispute or a CA-locked financial year keeps everything', async () => {
    const disputed = await newOrder();
    const d = await approvedRevision(disputed, 200);
    await db.execute("INSERT INTO disputes (order_id, notice_date, from_party, description, status) VALUES (:o, CURDATE(), 'Buyer', 'Quality claim', 'Open')", { o: disputed });

    const lockedOrder = await newOrder();
    const lockedDoc = await doc(lockedOrder, 'QT', 'approved', '2022-08-01 10:00:00');
    const lockedDraft = await file(lockedOrder, lockedDoc, 'QT draft.pdf', 200);
    await setCurrent(lockedDoc, await file(lockedOrder, lockedDoc, 'QT final.pdf', 200));
    const admin = await db.queryOne("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Admin' LIMIT 1");
    await db.execute("INSERT INTO ca_fy_locks (financial_year, locked_by) VALUES ('2022-23', :u)", { u: admin.id });

    await setRules(30, 90, 'latest_only');
    const ids = await candidateIds();
    expect(ids).not.toContain(d.draft);
    expect(ids).not.toContain(lockedDraft);
  });

  it('the run removes exactly the preview and audits each file', async () => {
    const r = await approvedRevision(await newOrder(), 45);
    const p = (await db.queryOne('SELECT server_path FROM file_store WHERE id = :id', { id: r.draft })).server_path;

    await setRules(30, 0, 'keep_all');
    const preview = await candidateIds();
    expect(preview).toContain(r.draft);

    const result = await cleanup.run(null, 'manual');
    expect(result.skipped_off).toBe(false);
    expect(result.files).toBe(preview.length);
    expect(await candidateIds()).toEqual([]);

    expect(await active(r.draft)).toBe(false);
    expect(fs.existsSync(p)).toBe(false);
    expect(await active(r.final)).toBe(true);
    const row = await db.queryOne('SELECT removed_at, removed_reason FROM file_store WHERE id = :id', { id: r.draft });
    expect(row.removed_at).not.toBeNull();
    expect(row.removed_reason).toBe('Draft copy of an approved document');
    const audit = await db.queryOne("SELECT reason FROM audit_log WHERE action_type = 'DOCUMENT_COPY_REMOVED' AND entity_id = :id", { id: r.draft });
    expect(audit.reason).toContain('Draft copy of an approved document — QT');
    expect(Number((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'DOCUMENT_COPY_CLEANUP_RUN'")).n)).toBeGreaterThan(0);
  });

  it('sending a document flags the exact file so it is never removed', async () => {
    const order = await newOrder();
    const d = await doc(order, 'QT', 'approved');
    const final = await file(order, d, 'QT final.pdf', 1);
    await setCurrent(d, final);
    await documentRepository.markSent(d);
    const row = await db.queryOne('SELECT sent_to_client, sent_at FROM file_store WHERE id = :id', { id: final });
    expect(Number(row.sent_to_client)).toBe(1);
    expect(row.sent_at).not.toBeNull();
  });

  it('the estimate shows what an off rule would remove', async () => {
    await approvedRevision(await newOrder(), 45);
    const e = await cleanup.estimate();
    expect(e.draft.hypothetical).toBe(true);
    expect(e.draft.files).toBeGreaterThanOrEqual(1);
    expect(e.draft.bytes).toBeGreaterThanOrEqual(2048);
  });
});
