'use strict';

// Q3 QA mail matrix (5 Nov 2026). For a CIF order on a cash-advance preset and an
// FOB order on the LC preset: every "send_<code>" document template renders against
// its approved document with a subject, the buyer as recipient and no unreplaced
// {token}; the PI / Debit Note / CI mails carry the payment link; every generic
// order template renders cleanly or refuses with a business message (bl_copy_sent
// without a BL copy must refuse); with the approval queue on and sending off each
// document send queues, is approved and dispatches without an exception; the
// Supplier PO and CAFIN can never be mailed. PHP twin: QaMailMatrixTest.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// Runs on a database of its own, freshly built from schema.sql + seed.sql and dropped
// afterwards, so the orders, documents, e-mail rows and settings this matrix creates never
// reach the shared test database other tests read (same pattern as stoneDocumentsUnchanged).
const QA_DB = 'nexacrest_node_jest_qa_mail';
const realEnv = require('dotenv').parse(fs.readFileSync(path.join(__dirname, '..', '..', '.env')));
const mysqlArgs = ['-h', realEnv.DB_HOST || '127.0.0.1', '-P', realEnv.DB_PORT || '3306', '-u', realEnv.DB_USERNAME, `-p${realEnv.DB_PASSWORD || ''}`];
const runSql = (database, input) => execFileSync('mysql', database ? [...mysqlArgs, database] : mysqlArgs, { stdio: ['pipe', 'pipe', 'pipe'], input });
const docsDir = path.join(__dirname, '..', '..', '..', 'docs');
runSql('', `DROP DATABASE IF EXISTS \`${QA_DB}\`; CREATE DATABASE \`${QA_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;`);
runSql(QA_DB, fs.readFileSync(path.join(docsDir, 'schema.sql')));
runSql(QA_DB, fs.readFileSync(path.join(docsDir, 'seed.sql')));
process.env.DB_DATABASE = QA_DB;
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const DOC_TEMPLATES = { QT: 'send_qt', ANNEXA: 'send_annexa', BUYERPO: 'send_buyerpo', PI: 'send_pi', OC: 'send_oc', FDN: 'send_fdn', PL: 'send_pl', CI: 'send_ci' };
const GENERIC = ['payment_followup', 'shipment_readiness', 'balance_receipt_confirmation', 'bl_copy_sent'];
const setting = (k, v) => db.execute('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k', { k, v });
const approve = (id) => db.execute("UPDATE documents SET status = 'approved' WHERE id = :id", { id });
const isBusiness = (e) => !(e instanceof TypeError || e instanceof ReferenceError || e instanceof RangeError || e instanceof SyntaxError);

describe('Q3 mail matrix: every template and send condition', () => {
  afterAll(async () => { await db.pool.end(); runSql('', `DROP DATABASE IF EXISTS \`${QA_DB}\`;`); });

  it('renders, queues and refuses exactly as it should', async () => {
    const before = await db.query("SELECT setting_key, setting_value FROM company_settings WHERE setting_key IN ('email_testing_mode','mail_approval_queue_enabled')");
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const u = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest QA Mail', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-qa-mail-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    const userId = u.insertId;
    const md = await db.queryOne("SELECT id FROM roles WHERE name = 'Managing Director'");
    const a = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest QA Mail Approver', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-qa-mail-appr-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: md.id }
    );
    const approverId = a.insertId;
    const problems = [];
    try {
      await setting('email_testing_mode', '1');
      await setting('mail_approval_queue_enabled', '1');
      for (const [incoterm, preset] of [['CIF', 'Standard — New Buyer'], ['FOB', 'Irrevocable Letter of Credit at Sight']]) {
        const orderId = await createTestOrder(await createTestClient(), incoterm, preset, 'USD');
        await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        await db.execute("UPDATE orders SET buyers_po_ref = 'PO-QA-MAIL', contact_person = 'QA Buyer' WHERE id = :id", { id: orderId });
        const label = `${incoterm}/${preset}`;
        for (const [code, template] of Object.entries(DOC_TEMPLATES)) {
          let doc;
          try { doc = await documentGenerationService.generate(orderId, code, userId, null, false, true); } catch (e) {
            problems.push(`${label} ${code}: could not generate (${e.message})`);
            continue;
          }
          await approve(doc.document_id);
          let p;
          try { p = await emailDispatchService.buildPreview(orderId, doc.document_id, template, userId); } catch (e) {
            // An LC quotation waits for the MD/ED to approve the payment terms (expected).
            if (!(code === 'QT' && e.message.includes('need approval before the Quotation'))) problems.push(`${label} ${template}: preview refused: ${e.message}`);
            continue;
          }
          if (!String(p.subject).trim()) problems.push(`${label} ${template}: empty subject`);
          if (!String(p.recipient_email || '').includes('@')) problems.push(`${label} ${template}: no recipient`);
          const left = (`${p.subject}\n${p.body}`).match(/\{[a-z_]+\}/g);
          if (left) problems.push(`${label} ${template}: unreplaced ${[...new Set(left)].join(', ')}`);
          if (['PI', 'FDN', 'CI'].includes(code) && !p.body.includes('/payment-action/')) problems.push(`${label} ${template}: no payment link`);
          try {
            const sent = await emailDispatchService.requestSend(orderId, doc.document_id, template, null, userId);
            await emailDispatchService.approveSend(sent.id, approverId);
          } catch (e) {
            problems.push(`${label} ${template}: queue/approve/dispatch failed: ${e.name} ${e.message}`);
          }
        }
        for (const template of GENERIC) {
          try {
            const p = await emailDispatchService.buildPreview(orderId, null, template, userId);
            if (template === 'bl_copy_sent') problems.push(`${label} bl_copy_sent: rendered without a BL copy on file`);
            const left = (`${p.subject}\n${p.body}`).match(/\{[a-z_]+\}/g);
            if (left) problems.push(`${label} ${template}: unreplaced ${[...new Set(left)].join(', ')}`);
          } catch (e) {
            if (!isBusiness(e)) problems.push(`${label} ${template}: crashed: ${e.name} ${e.message}`);
            else if (template !== 'bl_copy_sent') problems.push(`${label} ${template}: refused: ${e.message}`);
          }
        }
        const suppo = await documentGenerationService.generate(orderId, 'SUPPO', userId, null, false, true);
        await approve(suppo.document_id);
        try {
          await emailDispatchService.buildPreview(orderId, suppo.document_id, 'send_suppo', userId);
          problems.push(`${label}: the Supplier PO could be mailed to the buyer`);
        } catch (e) { if (!isBusiness(e)) problems.push(`${label}: Supplier PO refusal crashed: ${e.message}`); }
        const cafin = await documentGenerationService.generateCaInternalAnnexure(orderId, userId);
        await approve(cafin.document_id);
        for (const t of ['send_cafin', 'send_ci', 'send_pi']) {
          try {
            await emailDispatchService.buildPreview(orderId, cafin.document_id, t, userId);
            problems.push(`${label}: CAFIN could be mailed with ${t}`);
          } catch (e) { if (!isBusiness(e)) problems.push(`${label}: CAFIN refusal crashed: ${e.message}`); }
        }
      }
    } finally {
      for (const r of before) await setting(r.setting_key, r.setting_value);
    }
    if (process.env.QA_REPORT) fs.writeFileSync(process.env.QA_REPORT, JSON.stringify({ stack: 'node', problems }, null, 2));
    expect(problems).toEqual([]);
  }, 300000);
});
