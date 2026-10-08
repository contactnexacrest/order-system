'use strict';

const db = require('../../src/config/db');
const termsClauseRepository = require('../../src/repositories/termsClauseRepository');

/**
 * Legal Terms & Definitions (mirrors PHP's
 * TermsClauseRepository::forDocumentTypeCodeAndGroup) — covers the two
 * new behaviors beyond the existing 'standard' clause_group query:
 * filtering by clause_group (legal_terms/definitions, never leaking into
 * the plain T&C list) and the visibility_rule gate on a clause scoped to
 * one balance-trigger option.
 */
describe('termsClauseRepository — clause_group and visibility_rule', () => {
  let qtDocTypeId;
  const createdClauseIds = [];

  beforeAll(async () => {
    const dt = await db.queryOne("SELECT id FROM document_types WHERE code = 'QT'");
    qtDocTypeId = dt.id;
  });

  afterAll(async () => {
    for (const id of createdClauseIds) {
      await db.execute('DELETE FROM tc_clause_documents WHERE clause_id = :id', { id });
      await db.execute('DELETE FROM tc_clauses WHERE id = :id', { id });
    }
    await db.pool.end();
  });

  async function seedClause({ title, text, clauseGroup, visibilityRule, order }) {
    const result = await db.execute(
      `INSERT INTO tc_clauses (clause_title, clause_text, clause_order, status, clause_group, visibility_rule, is_locked)
       VALUES (:title, :text, :clause_order, 'active', :clause_group, :visibility_rule, 0)`,
      { title, text, clause_order: order, clause_group: clauseGroup, visibility_rule: visibilityRule }
    );
    const clauseId = result.insertId;
    createdClauseIds.push(clauseId);
    await db.execute(
      'INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :document_type_id)',
      { clause_id: clauseId, document_type_id: qtDocTypeId }
    );
    return clauseId;
  }

  it('forDocumentTypeCode (standard) never returns legal_terms/definitions rows', async () => {
    await seedClause({ title: 'Jest Legal Term', text: 'x', clauseGroup: 'legal_terms', visibilityRule: 'always', order: 900 });
    await seedClause({ title: 'Jest Definition', text: 'y', clauseGroup: 'definitions', visibilityRule: 'always', order: 901 });

    const standard = await termsClauseRepository.forDocumentTypeCode('QT');
    const titles = standard.map((c) => c.title);
    expect(titles).not.toContain('Jest Legal Term');
    expect(titles).not.toContain('Jest Definition');
  });

  it('forDocumentTypeCodeAndGroup returns only the requested clause_group', async () => {
    await seedClause({ title: 'Jest Legal Term 2', text: 'x', clauseGroup: 'legal_terms', visibilityRule: 'always', order: 902 });
    await seedClause({ title: 'Jest Definition 2', text: 'y', clauseGroup: 'definitions', visibilityRule: 'always', order: 903 });

    const legalTerms = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', null);
    expect(legalTerms.map((c) => c.title)).toContain('Jest Legal Term 2');
    expect(legalTerms.map((c) => c.title)).not.toContain('Jest Definition 2');

    const definitions = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'definitions', null);
    expect(definitions.map((c) => c.title)).toContain('Jest Definition 2');
    expect(definitions.map((c) => c.title)).not.toContain('Jest Legal Term 2');
  });

  it('visibility_rule balance_trigger_before_shipment only shows when the order is on A_BEFORE_SHIPMENT', async () => {
    await seedClause({
      title: 'Jest Before-Shipment Only', text: 'x', clauseGroup: 'legal_terms',
      visibilityRule: 'balance_trigger_before_shipment', order: 904,
    });

    const withMatchingOption = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'A_BEFORE_SHIPMENT');
    expect(withMatchingOption.map((c) => c.title)).toContain('Jest Before-Shipment Only');

    const withOtherOption = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'B_AGAINST_BL');
    expect(withOtherOption.map((c) => c.title)).not.toContain('Jest Before-Shipment Only');

    const withNullOption = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', null);
    expect(withNullOption.map((c) => c.title)).not.toContain('Jest Before-Shipment Only');
  });

  it('visibility_rule balance_trigger_against_bl only shows when the order is on B_AGAINST_BL', async () => {
    await seedClause({
      title: 'Jest Against-BL Only', text: 'x', clauseGroup: 'legal_terms',
      visibilityRule: 'balance_trigger_against_bl', order: 905,
    });

    const withMatchingOption = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'B_AGAINST_BL');
    expect(withMatchingOption.map((c) => c.title)).toContain('Jest Against-BL Only');

    const withOtherOption = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'A_BEFORE_SHIPMENT');
    expect(withOtherOption.map((c) => c.title)).not.toContain('Jest Against-BL Only');
  });

  it("visibility_rule 'always' shows regardless of balance trigger option, including null", async () => {
    await seedClause({ title: 'Jest Always Visible', text: 'x', clauseGroup: 'legal_terms', visibilityRule: 'always', order: 906 });

    for (const option of [null, 'A_BEFORE_SHIPMENT', 'B_AGAINST_BL']) {
      const rows = await termsClauseRepository.forDocumentTypeCodeAndGroup('QT', 'legal_terms', option);
      expect(rows.map((c) => c.title)).toContain('Jest Always Visible');
    }
  });
});
