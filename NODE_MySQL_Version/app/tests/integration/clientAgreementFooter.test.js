'use strict';

const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const clientsController = require('../../src/controllers/clientsController');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const docxComponents = require('../../src/services/docx/docxComponents');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function templateEnv() {
  const env = new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../templates')));
  env.addFilter('split', (str, sep, limit) => String(str).split(sep, limit));
  env.addFilter('nl2br', (v) => v);
  env.addFilter('money', (v) => Number(v).toFixed(2));
  env.addFilter('rate4', (v) => Number(v).toFixed(4));
  env.addFilter('maskEmail', (v) => v);
  env.addFilter('maskPhone', (v) => v);
  return env;
}

function minimalDocContext() {
  return {
    company: {}, assets: {}, watermark: { enabled: false }, meta: {},
    order: { quotation_ref: 'QT-1' }, financial: { advance_pct: 30 },
    terms: [], terms_section_number: 9, terms_section_title: 'TERMS & CONDITIONS',
    doc_title: 'commercial invoice',
  };
}

/**
 * Batch 3 #12 — a client-level agreement T&C footer (clients.agreement_footer_text),
 * shown as an extra note on every buyer-facing document in addition to the
 * standard numbered T&C list, including CI, which has zero seeded clauses.
 *
 * Deliberately NOT part of the client data-lock: it's a staff-authored
 * annotation of an externally-negotiated term, not a client-submitted
 * identity detail, so it has its own endpoint (updateAgreementFooter) that
 * bypasses is_data_locked entirely — unlike update(), which the main
 * Buyer/Consignee/Contact fields go through and which IS gated by the lock.
 */
describe('Client agreement footer (Batch 3 #12)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestStaffUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test Staff', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-staff-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function auditLogCount(field, entityId) {
    const row = await db.queryOne(
      "SELECT COUNT(*) AS cnt FROM audit_log WHERE action_type = 'CLIENT_UPDATED' AND entity_type = 'clients' AND entity_id = :entity_id AND field_name = :field",
      { entity_id: entityId, field }
    );
    return parseInt(row.cnt, 10);
  }

  function fakeReq(staffUserId, body = {}, params = {}) {
    return { user: { id: staffUserId }, body, params, session: {} };
  }

  function fakeRes() {
    const res = { redirectedTo: null, statusCode: 200, sentBody: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.sentBody = body; };
    return res;
  }

  test('updateAgreementFooter persists the text and writes one audit log entry', async () => {
    const clientId = await createTestClient();
    const staffId = await createTestStaffUser();

    await clientsController.updateAgreementFooter(
      fakeReq(staffId, { agreement_footer_text: 'Pre-shipment inspection permitted.\nSecond clause.' }, { id: String(clientId) }),
      fakeRes()
    );

    const client = await clientRepository.find(clientId);
    expect(client.agreement_footer_text).toBe('Pre-shipment inspection permitted.\nSecond clause.');
    expect(await auditLogCount('agreement_footer_text', clientId)).toBe(1);
  });

  test('updateAgreementFooter can clear the text back to null', async () => {
    const clientId = await createTestClient();
    const staffId = await createTestStaffUser();

    await clientsController.updateAgreementFooter(fakeReq(staffId, { agreement_footer_text: 'Some clause.' }, { id: String(clientId) }), fakeRes());
    expect((await clientRepository.find(clientId)).agreement_footer_text).toBe('Some clause.');

    await clientsController.updateAgreementFooter(fakeReq(staffId, { agreement_footer_text: '   ' }, { id: String(clientId) }), fakeRes());
    expect((await clientRepository.find(clientId)).agreement_footer_text).toBeNull();
  });

  test('no audit log entry is written when the text does not change', async () => {
    const clientId = await createTestClient();
    const staffId = await createTestStaffUser();

    await clientsController.updateAgreementFooter(fakeReq(staffId, { agreement_footer_text: 'Same clause.' }, { id: String(clientId) }), fakeRes());
    await clientsController.updateAgreementFooter(fakeReq(staffId, { agreement_footer_text: 'Same clause.' }, { id: String(clientId) }), fakeRes());

    expect(await auditLogCount('agreement_footer_text', clientId)).toBe(1);
  });

  test('the footer stays editable after the client is permanently data-locked', async () => {
    const clientId = await createTestClient();
    await clientRepository.lockData(clientId, 'PI-details confirmed by client');
    expect((await clientRepository.find(clientId)).is_data_locked).toBeTruthy();

    const staffId = await createTestStaffUser(); // ordinary staff, not Super Admin
    await clientsController.updateAgreementFooter(fakeReq(staffId, { agreement_footer_text: 'Added after lock.' }, { id: String(clientId) }), fakeRes());

    expect((await clientRepository.find(clientId)).agreement_footer_text).toBe('Added after lock.');
  });

  test('contrast: the main update() endpoint IS blocked once the client is locked', async () => {
    const clientId = await createTestClient();
    const client = await clientRepository.find(clientId);
    await clientRepository.lockData(clientId, 'PI-details confirmed by client');

    const staffId = await createTestStaffUser(); // ordinary staff, not Super Admin
    await clientsController.update(
      fakeReq(staffId, { company_legal_name: 'Attempted Rename Ltd', billing_address: client.billing_address }, { id: String(clientId) }),
      fakeRes()
    );

    expect((await clientRepository.find(clientId)).company_legal_name).toBe(client.company_legal_name);
  });

  test('updateAgreementFooter 404s for a missing client', async () => {
    const staffId = await createTestStaffUser();
    const res = fakeRes();
    await clientsController.updateAgreementFooter(fakeReq(staffId, { agreement_footer_text: 'x' }, { id: '999999999' }), res);
    expect(res.statusCode).toBe(404);
  });

  test('documentDataAssembler includes agreement_footer_text on the buyer object', async () => {
    const clientId = await createTestClient();
    await clientRepository.updateAgreementFooterText(clientId, 'Inspection clause.');
    const orderId = await createTestOrder(clientId);

    const context = await documentDataAssembler.assemble(orderId, 'QT');
    expect(context.buyer.agreement_footer_text).toBe('Inspection clause.');
  });

  test('documentDataAssembler buyer.agreement_footer_text is null when not set', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const context = await documentDataAssembler.assemble(orderId, 'QT');
    expect('agreement_footer_text' in context.buyer).toBe(true);
    expect(context.buyer.agreement_footer_text).toBeNull();
  });

  test('docx clientAgreementFooter returns nodes when text is set', () => {
    const nodes = docxComponents.clientAgreementFooter({ buyer: { agreement_footer_text: 'Clause one.\nClause two.' } });
    expect(nodes.length).toBeGreaterThan(0);
  });

  test('docx clientAgreementFooter returns no nodes when there is no footer text', () => {
    expect(docxComponents.clientAgreementFooter({ buyer: { agreement_footer_text: '   ' } })).toHaveLength(0);
    expect(docxComponents.clientAgreementFooter({ buyer: {} })).toHaveLength(0);
    expect(docxComponents.clientAgreementFooter({})).toHaveLength(0);
  });

  test('CI template shows footer even with zero seeded clauses', () => {
    const env = templateEnv();
    const ctx = { ...minimalDocContext(), buyer: { agreement_footer_text: 'Inspection permitted by buyer agent.' } };
    const html = env.render('CI/commercial_invoice.njk', ctx);

    expect(html).toContain('Special Terms (per Client Agreement)');
    expect(html).toContain('Inspection permitted by buyer agent.');
    expect(html).not.toContain('9. TERMS');
  });

  test('CI template omits footer when not set', () => {
    const env = templateEnv();
    const ctx = { ...minimalDocContext(), buyer: { agreement_footer_text: null } };
    const html = env.render('CI/commercial_invoice.njk', ctx);

    expect(html).not.toContain('Special Terms (per Client Agreement)');
  });

  test('QT template shows footer alongside numbered terms', () => {
    const env = templateEnv();
    const ctx = {
      ...minimalDocContext(),
      terms: ['30: Clause thirty text', '60: Clause sixty text'],
      buyer: { agreement_footer_text: 'Special QT clause.' },
    };
    const html = env.render('QT/quotation.njk', ctx);

    expect(html).toContain('Special Terms (per Client Agreement)');
    expect(html).toContain('Special QT clause.');
  });

  test('BUYERPO template shows footer at its own separate insertion point', () => {
    const env = templateEnv();
    const ctx = {
      ...minimalDocContext(),
      terms: ['30: Clause thirty text'],
      buyer: { agreement_footer_text: 'Special BUYERPO clause.' },
    };
    const html = env.render('BUYERPO/buyer_po.njk', ctx);

    expect(html).toContain('Special Terms (per Client Agreement)');
    expect(html).toContain('Special BUYERPO clause.');
  });
});
