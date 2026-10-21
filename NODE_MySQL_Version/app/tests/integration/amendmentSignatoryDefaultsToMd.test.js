'use strict';

const db = require('../../src/config/db');
const signatoryRepository = require('../../src/repositories/signatoryRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');

/**
 * User's Q2 clarification: "Payment Terms Amendment document require that
 * the signatory specifically be the Managing Director - can we have
 * configuration on this - by default it will be MD, but we need to have an
 * option to choose other designation and signature and seal for that
 * designation."
 *
 * The generic three-layer resolution in
 * documentDataAssembler.signatoryBlock() (per-document-type default ->
 * global default -> legacy fallback) already covers this; this test locks
 * in (a) that the seeded default for the Amendment (AMD) document type
 * really does resolve to the Managing Director, and (b) that an admin can
 * repoint it at a user with any other designation, whose own
 * name/designation/seal then print instead.
 */
describe('Amendment document type signatory defaults to the MD, overridable (Q2)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function amdDocumentTypeId() {
    const row = await db.queryOne("SELECT id FROM document_types WHERE code = 'AMD'");
    return row.id;
  }

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  test('AMD document type defaults to the Managing Director signatory', async () => {
    const block = await documentDataAssembler.signatoryBlock(await amdDocumentTypeId());

    expect(block.name).toBe('Gulmohar Sontakke');
    expect(block.designation).toBe('Founder & Managing Director');
    expect(block.used_designation_seal).toBe(true);
  });

  test('admin can override the AMD signatory to a user with a different designation', async () => {
    const amdTypeId = await amdDocumentTypeId();

    const originalRow = await db.queryOne(
      'SELECT user_id, use_designation_seal FROM document_type_signatories WHERE document_type_id = :dt',
      { dt: amdTypeId }
    );

    const superAdmin = await db.queryOne(
      "SELECT id FROM users WHERE email = 'gulmohar.sontakke@nexacrestinternational.com'"
    );

    const financeHeadDesignationId = await signatoryRepository.createDesignation('Finance Head', superAdmin.id);
    const financeHeadUserId = await createTestUser('Export Executive');
    await db.execute('UPDATE users SET name = :name WHERE id = :id', { name: 'Priya Deshmukh', id: financeHeadUserId });
    await signatoryRepository.setEligibility(financeHeadUserId, true, financeHeadDesignationId, superAdmin.id);

    try {
      await signatoryRepository.setDocumentTypeSignatory(amdTypeId, financeHeadUserId, false, superAdmin.id);

      const block = await documentDataAssembler.signatoryBlock(amdTypeId);

      expect(block.name).toBe('Priya Deshmukh');
      expect(block.designation).toBe('Finance Head');
      expect(block.designation).not.toBe('Founder & Managing Director');
    } finally {
      if (originalRow) {
        await signatoryRepository.setDocumentTypeSignatory(
          amdTypeId,
          originalRow.user_id,
          !!originalRow.use_designation_seal,
          superAdmin.id
        );
      }
    }
  });
});
