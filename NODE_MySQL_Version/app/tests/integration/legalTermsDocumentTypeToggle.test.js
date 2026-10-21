'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

async function createTestLegalTermsClause() {
  const result = await db.execute(
    "INSERT INTO tc_clauses (clause_title, clause_text, clause_group, status) VALUES ('Jest Test Clause', 'Test clause text.', 'legal_terms', 'active')"
  );
  return result.insertId;
}

async function documentTypeId(code) {
  const row = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code });
  return row.id;
}

async function linkedDocumentTypeIds(clauseId) {
  const rows = await db.query('SELECT document_type_id FROM tc_clause_documents WHERE clause_id = :id ORDER BY document_type_id', { id: clauseId });
  return rows.map((r) => r.document_type_id);
}

/**
 * Port of LegalTermsDocumentTypeToggleTest.php. 90-item backlog "per-doc-
 * type Legal Terms toggle": tc_clause_documents already let a Legal Terms
 * clause be scoped to specific document types, but the admin screen never
 * offered a way to see or change which document types a given clause
 * reached. This adds that toggle, restricted server-side to customer-
 * facing document types regardless of what a tampered POST tries to
 * submit.
 */
describe('Legal Terms per-document-type toggle (90-item backlog "per-doc-type Legal Terms toggle")', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('index renders a checkbox per customer-facing document type reflecting current links', async () => {
    const clauseId = await createTestLegalTermsClause();
    const qtId = await documentTypeId('QT');
    await db.execute('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :dt_id)', { clause_id: clauseId, dt_id: qtId });

    const res = await agent.get('/admin/overrides');
    expect(res.status).toBe(200);

    expect(res.text).toContain(`document_types[f${clauseId}][]`);
    expect(res.text).toMatch(new RegExp(`name="document_types\\[f${clauseId}\\]\\[\\]" value="${qtId}" checked`));
    const supPoId = await documentTypeId('SUPPO');
    expect(res.text).not.toContain(`value="${supPoId}"`);
  });

  it('update adds and removes document type links', async () => {
    const clauseId = await createTestLegalTermsClause();
    const qtId = await documentTypeId('QT');
    const piId = await documentTypeId('PI');
    await db.execute('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :dt_id)', { clause_id: clauseId, dt_id: qtId });

    const page = await agent.get('/admin/overrides');
    const csrf = extractCsrf(page.text);
    const res = await agent
      .post('/admin/overrides/tc-clauses')
      .type('form')
      .send({
        _csrf: csrf,
        reason: 'Jest test — switching this clause from QT to PI.',
        [`document_types[f${clauseId}][]`]: String(piId),
      });
    expect(res.status).toBe(302);

    expect(await linkedDocumentTypeIds(clauseId)).toEqual([piId]);

    const log = await db.queryOne(
      "SELECT old_value, new_value FROM audit_log WHERE entity_type = 'tc_clause_documents' AND entity_id = :id ORDER BY id DESC LIMIT 1",
      { id: clauseId }
    );
    expect(log).toBeTruthy();
    expect(log.old_value).toBe('QT');
    expect(log.new_value).toBe('PI');
  });

  it('a tampered POST cannot link a clause to a non-customer-facing document type', async () => {
    const clauseId = await createTestLegalTermsClause();
    const supPoId = await documentTypeId('SUPPO'); // category = 'procurement'

    const page = await agent.get('/admin/overrides');
    const csrf = extractCsrf(page.text);
    const res = await agent
      .post('/admin/overrides/tc-clauses')
      .type('form')
      .send({
        _csrf: csrf,
        reason: 'Jest test — attempting to link to a non-customer-facing type.',
        [`document_types[f${clauseId}][]`]: String(supPoId),
      });
    expect(res.status).toBe(302);

    expect(await linkedDocumentTypeIds(clauseId)).toEqual([]);
  });

  it('a document-type change on a protected clause is blocked without unlock', async () => {
    const clauseId = await createTestLegalTermsClause();
    await db.execute('UPDATE tc_clauses SET is_protected = 1 WHERE id = :id', { id: clauseId });
    const qtId = await documentTypeId('QT');

    const page = await agent.get('/admin/overrides');
    const csrf = extractCsrf(page.text);
    const res = await agent
      .post('/admin/overrides/tc-clauses')
      .type('form')
      .send({
        _csrf: csrf,
        reason: 'Jest test — trying to bypass the unlock gesture.',
        [`document_types[f${clauseId}][]`]: String(qtId),
      });
    expect(res.status).toBe(302);

    expect(await linkedDocumentTypeIds(clauseId)).toEqual([]);
  });
});
