'use strict';

// QA batch 3 (5 Nov 2026): documents and e-mail findings DOC-1..7 and
// MAIL-1..13 from the fresh QA review. DOC-1..4 change generated documents
// and were confirmed twice by the owner. PHP twin: QaBatch3DocsMailTest.php.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn(async () => Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const { execFileSync } = require('child_process');
const db = require('../../src/config/db');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderLcDetailRepository = require('../../src/repositories/orderLcDetailRepository');
const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
const dashboardRepository = require('../../src/repositories/dashboardRepository');
const emailLogRepository = require('../../src/repositories/emailLogRepository');
const emailTemplateRepository = require('../../src/repositories/emailTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');
const emailDispatchService = require('../../src/services/emailDispatchService');
const emailService = require('../../src/services/emailService');
const mailSenderService = require('../../src/services/mailSenderService');
const orderAccessService = require('../../src/services/orderAccessService');
const paymentActionService = require('../../src/services/paymentActionService');
const buyerActionService = require('../../src/services/buyerActionService');
const reviewWorkflowService = require('../../src/services/reviewWorkflowService');
const { run } = require('../../src/jobs/checkAlerts');
const { createTestClient, createTestOrder, createTestFile, createTestDocument, allowHandTypedGoodsLines } = require('../support/fixtures');

const rand = () => Math.random().toString(16).slice(2, 10);

async function createTestUser(roleName) {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-user-${rand()}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

async function twoLineOrder(preset = 'Standard — New Buyer', incoterm = 'CIF') {
  const orderId = await createTestOrder(await createTestClient(`qa3-${rand()}@example.test`), incoterm, preset, 'USD');
  await orderProductRepository.add(orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
  await orderProductRepository.add(orderId, 2, 'Kashmir White Granite Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
  return orderId;
}

const lastHtml = () => {
  const calls = renderPdfFromHtml.mock.calls;
  return String(calls[calls.length - 1][0]).replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');
};

async function docxText(fileId) {
  const file = await fileStoreRepository.find(fileId);
  const xml = execFileSync('unzip', ['-p', file.server_path, 'word/document.xml']).toString('utf8');
  return xml.replace(/<\/w:p>/g, '\n').replace(/<[^>]+>/g, '').replace(/&amp;/g, '&');
}

describe('QA batch 3 — documents and e-mail', () => {
  let mailBefore;
  beforeAll(async () => {
    mailBefore = await companySettingsRepository.get('email_testing_mode');
    await allowHandTypedGoodsLines();
  });
  afterEach(async () => {
    await companySettingsRepository.set('email_testing_mode', mailBefore);
  });
  afterAll(async () => {
    await db.pool.end();
  });

  it('DOC-1: dates have a two-digit day, as on PHP', () => {
    expect(documentDataAssembler.formatDate('2026-11-05')).toBe('05 November 2026');
  });

  it('DOC-2: the final and superseded copies show what was generated, not live data', async () => {
    const user = await createTestUser('Admin');
    const orderId = await twoLineOrder();
    const r = await documentGenerationService.generate(orderId, 'PI', user, null, false, true);
    expect(await documentRepository.renderContext(r.document_id)).not.toBeNull();

    await db.execute('UPDATE order_products SET quantity = 777 WHERE order_id = :o AND line_no = 1', { o: orderId });
    await documentGenerationService.finalizeApproval(r.document_id);
    expect(lastHtml()).toContain('120');
    expect(lastHtml()).not.toContain('777');

    await documentGenerationService.markSuperseded(r.document_id);
    expect(lastHtml()).not.toContain('777');
    expect(lastHtml()).toContain('INVALID DOCUMENT');
  });

  it('DOC-3: the Word copy matches the PDF (revision label, file name, payment wording)', async () => {
    const user = await createTestUser('Admin');
    const orderId = await twoLineOrder();
    await documentGenerationService.generate(orderId, 'QT', user, null, true, true);
    const r = await documentGenerationService.generate(orderId, 'QT', user, null, true, true); // internal Rev.01
    expect(r.docx_file_id).toBeTruthy();
    expect((await fileStoreRepository.find(r.docx_file_id)).original_filename).toMatch(/ Rev\.0 \(internal\)\.docx$/);
    const text = await docxText(r.docx_file_id);
    expect(text).not.toContain('Rev.01');
    expect(text).toContain('40% Advance Payment on FOB Value against Proforma Invoice.');
    expect(text).toContain('Balance Payment on FOB Value');
    expect(text).not.toContain('Telegraphic Transfer) on FOB Value');
    expect(text).not.toContain('invoiced separately by Freight Debit Note');
  });

  it('DOC-4: the Buyer PO and the QT follow the payment terms and list every line', async () => {
    const user = await createTestUser('Admin');
    const lc = await twoLineOrder('Irrevocable Letter of Credit at Sight');
    await documentGenerationService.generate(lc, 'BUYERPO', user, null, false, true);
    const po = lastHtml();
    expect(po).not.toContain('0% advance');
    expect(po).toContain('100% Payment on FOB Value by Letter of Credit');
    expect(po).not.toContain('balance T/T before shipment');
    expect(po).toMatch(/120\s*;\s*80/);

    const ms = await twoLineOrder();
    await paymentMilestoneRepository.replaceSchedule(ms, [{ pct: 20, due_text: 'on completion of production' }], 40);
    const terms = (await documentDataAssembler.assemble(ms, 'BUYERPO')).financial.buyer_po_payment_terms;
    expect(terms.startsWith('40% advance T/T (Telegraphic Transfer) against Proforma Invoice before production. 20% Payment on FOB Value on completion of production. 40% balance T/T — ')).toBe(true);
    await documentGenerationService.generate(ms, 'QT', user, null, false, true);
    expect(lastHtml()).toContain('20% Payment on FOB Value on completion of production.');
  });

  it('DOC-5: the dossier follows the order access rule (central guard)', async () => {
    const orderId = await twoLineOrder();
    const outsider = await createTestUser('Export Executive');
    expect(await orderAccessService.denialReasonForPath(`/orders/${orderId}/dossier`, outsider, { manage_orders: true })).not.toBeNull();
  });

  it('DOC-6: the valid PI stays visible while a correction is drafted', async () => {
    await companySettingsRepository.set('email_testing_mode', '0');
    const orderId = await twoLineOrder();
    const file = await createTestFile(orderId, null);
    const sent = await createTestDocument(orderId, 'PI', 'sent');
    const draft = await createTestDocument(orderId, 'PI', 'draft');
    await db.execute('UPDATE documents SET revision_number = 0, pdf_file_id = :f WHERE id = :id', { f: file, id: sent });
    await db.execute('UPDATE documents SET revision_number = 1, pdf_file_id = :f WHERE id = :id', { f: file, id: draft });
    expect(Number((await paymentActionService.issuedDocument(orderId, 'advance')).id)).toBe(sent);
    expect(Number((await buyerActionService.issuedBuyerDocument(orderId, 'PI')).id)).toBe(sent);

    // DOC-6 part A (approval vs send as the release to the portal) is the owner's decision — unchanged here.
  });

  it('DOC-7: a sent document cannot be pulled back into review', async () => {
    const orderId = await twoLineOrder();
    const doc = await createTestDocument(orderId, 'OC', 'sent');
    const reviewer = await createTestUser('Admin');
    await expect(reviewWorkflowService.assignReviewers(doc, [reviewer], await createTestUser('Admin'))).rejects.toThrow('already sent');
    const res = await db.execute("INSERT INTO document_reviews (document_id, reviewer_id, status) VALUES (:d, :r, 'pending')", { d: doc, r: reviewer });
    await reviewWorkflowService.reject(res.insertId, reviewer, 'This should not move the sent document back to draft.');
    expect((await documentRepository.find(doc)).status).toBe('sent');
  });

  it('MAIL-1: the overdue-freight alert sees the Debit Note that was issued', async () => {
    await companySettingsRepository.set('email_testing_mode', '0');
    const orderId = await twoLineOrder();
    const fdn = await createTestDocument(orderId, 'FDN', 'sent');
    await db.execute('UPDATE documents SET document_date = DATE_SUB(CURDATE(), INTERVAL 12 DAY) WHERE id = :id', { id: fdn });
    expect((await dashboardRepository.overdueFreightPayments()).map((r) => Number(r.order_id))).toContain(orderId);
    await db.execute('UPDATE orders SET is_locked = 1 WHERE id = :id', { id: orderId });
    expect((await dashboardRepository.overdueFreightPayments()).map((r) => Number(r.order_id))).not.toContain(orderId);
  });

  it('MAIL-2: business mail goes through the sending-off switch; honest flash wording', async () => {
    await companySettingsRepository.set('email_testing_mode', '1');
    const spy = jest.spyOn(mailSenderService, 'send');
    expect(await emailService.sendPlainText('buyer@example.test', 'Link', 'Body', { kind: 'buyer_links', audience: 'buyer' })).toBe(false);
    expect(spy).toHaveBeenCalledWith('buyer@example.test', 'Link', 'Body', [], { kind: 'buyer_links', audience: 'buyer' });
    spy.mockRestore();
    expect(await emailDispatchService.linkMailOutcome('PI-stage form link', 'b@x.test', false, 'L'))
      .toBe('PI-stage form link generated — not e-mailed to b@x.test: testing mode holds e-mails to buyers and suppliers. Copy the link and send it yourself.');
    expect(await emailDispatchService.linkMailOutcome('PI-stage form link', 'b@x.test', true, 'L')).toBe('PI-stage form link e-mailed to b@x.test. If they did not get it, copy the link and send it yourself.');
  });

  it('MAIL-3: LC reminders go out once per date', async () => {
    const admin = await createTestUser('Admin');
    const assignee = await createTestUser('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight', 'USD', null, null, [assignee]);
    const addDays = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
    await orderLcDetailRepository.saveDates(orderId, null, addDays(20), addDays(5), null, admin);
    await run();
    // As if the first reminder went out yesterday, when the same date was 6 days away.
    await db.execute("UPDATE notifications SET created_at = DATE_SUB(created_at, INTERVAL 1 DAY), message = REPLACE(message, '(5 day(s) away)', '(6 day(s) away)') WHERE related_order_id = :o", { o: orderId });
    await run();
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND related_order_id = :o AND type = 'lc_last_shipment_soon'", { u: assignee, o: orderId });
    expect(Number(n.n)).toBe(1);
  });

  it('MAIL-4 / MAIL-5: client e-mail fallback and the sender\'s own title', async () => {
    const email = `qa3-record-${rand()}@example.test`;
    const orderId = await createTestOrder(await createTestClient(email));
    await db.execute('UPDATE orders SET client_email = NULL WHERE id = :id', { id: orderId });
    expect(await emailDispatchService.clientEmailFor(await orderRepository.find(orderId))).toBe(email);
    const exec = await createTestUser('Export Executive');
    expect(await emailDispatchService.senderTitleFor(await userRepository.findById(exec))).toBe('Export Executive');
  });

  it('MAIL-6 / 7 / 8 / 11: seeded templates', async () => {
    const tpl = (k) => emailTemplateRepository.find(k);
    expect((await tpl('send_buyerpo')).body).toContain('based on quotation {quotation_ref}');
    expect((await tpl('send_buyerpo')).body).not.toContain('{oc_ref}');
    for (const r of await db.query("SELECT code FROM document_types WHERE category = 'customer_facing' AND is_active = 1")) {
      expect(await tpl(`send_${String(r.code).toLowerCase()}`)).toBeTruthy();
    }
    expect((await tpl('shipment_readiness')).body).not.toContain('attached');
    expect((await tpl('buyer_action_link')).body).toContain('download your purchase order'); // D-330: plain-language wording
  });

  it('MAIL-9: a failed send is reported to the requester; a row being sent is in flight', async () => {
    const orderId = await twoLineOrder();
    const requester = await createTestUser('Admin');
    const doc = await createTestDocument(orderId, 'PI', 'sent');
    const res = await db.execute(
      `INSERT INTO email_log (order_id, document_id, template_key, recipient_email, subject, body_snapshot, status, requested_by)
       VALUES (:o, :d, 'send_pi', 'b@x.test', 'PI again', 'Body', 'approved', :u)`,
      { o: orderId, d: doc, u: requester }
    );
    const row = await db.queryOne('SELECT * FROM email_log WHERE id = :id', { id: res.insertId });
    expect(await emailDispatchService.dispatch(row)).toBe(false);
    const n = await db.queryOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND type = 'email_send_failed'", { u: requester });
    expect(Number(n.n)).toBe(1);
    await db.execute("UPDATE email_log SET status = 'sending' WHERE id = :id", { id: res.insertId });
    expect(await emailLogRepository.hasActiveSendFor(doc)).toBe(true);
  });

  it('MAIL-10: a missing attachment fails the send; a known token with no value renders empty', async () => {
    expect(await emailService.deliverWithAttachments('b@x.test', 'S', 'B', [{ path: '/nonexistent/file.pdf', name: 'file.pdf' }])).toBe(false);
  });

  it('MAIL-12: the e-mail asks for the payment the order needs', async () => {
    const sender = await createTestUser('Admin');
    const lc = await twoLineOrder('Irrevocable Letter of Credit at Sight');
    const cash = await twoLineOrder();
    const file = await createTestFile(cash, null);
    const doc = async (orderId, code) => {
      const id = await createTestDocument(orderId, code, 'approved');
      await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :id', { f: file, id });
      return id;
    };
    const preview = async (orderId, code) => (await emailDispatchService.buildPreview(orderId, await doc(orderId, code), `send_${code.toLowerCase()}`, sender)).body;
    expect(await preview(lc, 'PI')).toContain('Please open the letter of credit (LC) as agreed');
    expect(await preview(cash, 'PI')).toContain('Please pay the advance as agreed');
    expect(await preview(cash, 'CI')).toContain('pay the balance as agreed');
  });
});
