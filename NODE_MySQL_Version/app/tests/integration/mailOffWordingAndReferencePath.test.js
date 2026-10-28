'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const env = require('../../src/config/env');
const flash = require('../../src/helpers/flash');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderCommentController = require('../../src/controllers/orderCommentController');
const referenceDocController = require('../../src/controllers/referenceDocController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of MailOffWordingAndReferencePathTest.php. QA Round 5:
 *  - R5-16: with email sending off, posting in Order Communication must
 *    not claim the client was emailed.
 *  - R5-18: seeded Reference Library documents keep the
 *    __STORAGE_BASE_PATH__ placeholder in their path; downloads resolve it.
 */
describe('Mail-off wording and seeded Reference Library paths (QA Round 5)', () => {
  let userId;

  beforeAll(async () => {
    userId = (await db.queryOne('SELECT id FROM users ORDER BY id LIMIT 1')).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  const setMail = (value) => db.execute("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'mail_sending_enabled'", { v: value });

  it('says the client was NOT emailed when email sending is off (R5-16)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const original = String((await companySettingsRepository.get('mail_sending_enabled')) ?? '0');
    await setMail('0');
    try {
      const req = { params: { id: String(orderId) }, body: { body: 'Your goods are being packed.' }, user: { id: userId }, files: [], session: {} };
      await orderCommentController.post(req, { redirect() {} });
      const message = flash.pull(req)[0].message;
      expect(message).toContain('NOT emailed');
      expect(message).not.toContain('has been emailed');
    } finally {
      await setMail(original);
    }
  });

  it('downloads a seeded Reference Library document whose path holds the placeholder (R5-18)', async () => {
    const name = `r5-18-${Math.random().toString(16).slice(2, 10)}.txt`;
    const realPath = path.join(env.storageBasePath(), name);
    fs.writeFileSync(realPath, 'reference library test');
    const result = await db.execute(
      "INSERT INTO reference_library_documents (title, file_path, file_original_name, file_mime_type) VALUES ('R5-18 test', :p, 'test.txt', 'text/plain')",
      { p: `__STORAGE_BASE_PATH__/${name}` }
    );

    const { Writable } = require('stream');
    const chunks = [];
    const res = new Writable({ write(chunk, enc, cb) { chunks.push(chunk); cb(); } });
    res.statusCode = 200;
    res.status = function (code) { this.statusCode = code; return this; };
    res.send = function (body) { this.body = body; };
    res.setHeader = () => {};
    const finished = new Promise((resolve) => res.on('finish', resolve));
    await referenceDocController.customDownload({ params: { id: String(result.insertId) }, permissions: {} }, res);
    if (res.statusCode === 200) await finished;
    fs.unlinkSync(realPath);

    expect(res.statusCode).toBe(200);
    expect(Buffer.concat(chunks).toString()).toBe('reference library test');
  });
});
