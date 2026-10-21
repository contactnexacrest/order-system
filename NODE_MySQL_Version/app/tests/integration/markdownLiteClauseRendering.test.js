'use strict';

jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of MarkdownLiteClauseRenderingTest.php. 90-item backlog Phase 5:
 * editor-level bold/emphasis control for the T&C Clause library. Covers
 * the markdown_lite Nunjucks filter actually wired into a real generated
 * document's pre-render HTML (end-to-end through
 * documentGenerationService, same HTML-capture pattern as
 * shippingContainerCount.test.js), and that the admin screen shows the
 * raw markdown source (never pre-rendered) so staff edit the markers directly.
 */
describe('markdownLite clause rendering (90-item backlog Phase 5)', () => {
  const createdClauseIds = [];

  afterEach(async () => {
    for (const id of createdClauseIds.splice(0)) {
      await db.execute('DELETE FROM tc_clause_documents WHERE clause_id = :id', { id });
      await db.execute('DELETE FROM tc_clauses WHERE id = :id', { id });
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function lastRenderedHtml() {
    const calls = renderPdfFromHtml.mock.calls;
    return calls[calls.length - 1][0];
  }

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-mdlite-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function seedClause(title, text, clauseGroup, order) {
    const qtId = (await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'")).id;
    const result = await db.execute(
      `INSERT INTO tc_clauses (clause_title, clause_text, clause_order, status, clause_group, is_locked)
       VALUES (:title, :text, :clause_order, 'active', :clause_group, 0)`,
      { title, text, clause_order: order, clause_group: clauseGroup }
    );
    const clauseId = result.insertId;
    createdClauseIds.push(clauseId);
    await db.execute('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :document_type_id)', { clause_id: clauseId, document_type_id: qtId });
    return clauseId;
  }

  it('generated QT HTML renders bold markers as real formatting from a standard clause', async () => {
    await seedClause('Jest Markdown Clause', 'Delivery must happen within the **agreed window**, no exceptions.', 'standard', 950);
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await documentGenerationService.generate(orderId, 'QT', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('<strong>agreed window</strong>');
    expect(html).not.toContain('**agreed window**');
  });

  it('generated QT HTML renders italic markers as real formatting from a legal_terms clause', async () => {
    await seedClause('Jest Markdown Legal Term', 'This clause is *strictly* binding on both parties.', 'legal_terms', 951);
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await documentGenerationService.generate(orderId, 'QT', userId, null, false, true);
    const html = lastRenderedHtml();

    expect(html).toContain('<em>strictly</em>');
    expect(html).not.toContain('*strictly*');
  });

  it('admin screen shows the raw markers unparsed for editing', async () => {
    await seedClause('Jest Markdown Admin Clause', 'Keep this **exact** wording.', 'standard', 952);

    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);

    const res = await agent.get('/admin/overrides');

    expect(res.text).toContain('Keep this **exact** wording.');
    expect(res.text).toContain('data-wrap="**"');
  });
});
