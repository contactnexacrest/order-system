'use strict';

const db = require('../../src/config/db');
const hsCodeRepository = require('../../src/repositories/hsCodeRepository');
const hsCodeProductGuideRepository = require('../../src/repositories/hsCodeProductGuideRepository');

/**
 * Port of HsCodeBulkImportTest.php. Point 4 — bulk-importing a real
 * customs reference sheet (the business's own granite/marble HS Code
 * Quick Reference, seeded via docs/seed.sql) instead of typing codes in
 * one at a time. Covers the actual pasted-from-Excel format (Tab
 * separated), the hand-typed fallback (comma separated), and every way a
 * line can legitimately fail without taking the whole import down with
 * it.
 *
 * Fixture codes/product names here are deliberately fake and distinct
 * from anything in docs/seed.sql's real HS code import — the disposable
 * test DB carries that real seed data, so a test fixture must never
 * collide with it.
 */
describe('HS code bulk import (Point 4)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function findGuideRowByProduct(productDescription) {
    const rows = await hsCodeProductGuideRepository.all();
    return rows.find((r) => r.product_description === productDescription) || null;
  }

  it('imports Tab-separated lines matching an Excel paste', async () => {
    const userId = await createTestUser();
    const raw = '921001\tJest Test Code One\n921002\tJest Test Code Two';

    const result = await hsCodeRepository.bulkImport(raw, userId);

    expect(result.inserted).toEqual(['921001', '921002']);
    expect(result.skipped).toEqual([]);
    expect(await hsCodeRepository.findByCode('921001')).not.toBeNull();
    expect((await hsCodeRepository.findByCode('921002')).description).toBe('Jest Test Code Two');
  });

  it('accepts comma-separated lines for hand typing', async () => {
    const userId = await createTestUser();
    const result = await hsCodeRepository.bulkImport('921003,Jest test, comma, separated', userId);

    expect(result.inserted).toEqual(['921003']);
    expect(result.skipped).toEqual([]);
    expect((await hsCodeRepository.findByCode('921003')).description).toBe('Jest test, comma, separated');
  });

  it('skips an invalid code format but keeps importing other lines', async () => {
    const userId = await createTestUser();
    const raw = '9210.04\tBad dotted format\n921005\tGood code';

    const result = await hsCodeRepository.bulkImport(raw, userId);

    expect(result.inserted).toEqual(['921005']);
    expect(result.skipped).toHaveLength(1);
    expect(result.skipped[0]).toContain('9210.04');
    expect(result.skipped[0]).toContain('6 or 8 digits');
  });

  it('skips a code that already exists', async () => {
    const userId = await createTestUser();
    await hsCodeRepository.create('921006', 'Already here', userId);

    const result = await hsCodeRepository.bulkImport('921006\tDuplicate attempt', userId);

    expect(result.inserted).toEqual([]);
    expect(result.skipped).toHaveLength(1);
    expect(result.skipped[0]).toContain('already exists');
  });

  it('skips a line with no separator at all', async () => {
    const userId = await createTestUser();
    const result = await hsCodeRepository.bulkImport('JustOneWordWithNoDescription', userId);

    expect(result.inserted).toEqual([]);
    expect(result.skipped).toHaveLength(1);
    expect(result.skipped[0]).toContain('Tab or a comma');
  });

  it('ignores blank lines rather than counting them as skipped', async () => {
    const userId = await createTestUser();
    const raw = '921007\tJest blank-line test A\n\n\n921008\tJest blank-line test B';

    const result = await hsCodeRepository.bulkImport(raw, userId);

    expect(result.inserted).toHaveLength(2);
    expect(result.skipped).toEqual([]);
  });

  it('updateDescription also stores a usage note', async () => {
    const userId = await createTestUser();
    const id = await hsCodeRepository.create('921009', 'Jest usage-note test', userId);

    await hsCodeRepository.updateDescription(id, 'Jest usage-note test', 'Use this one when the SKU is X.');

    const updated = await hsCodeRepository.findByCode('921009');
    expect(updated.usage_note).toBe('Use this one when the SKU is X.');
  });

  it('product guide import parses Tab-separated product, code, and note', async () => {
    const userId = await createTestUser();
    const raw = 'Jest Test Product Vase\t921010\tCarved 3D article\nJest Test Product Slab\t921011';

    const result = await hsCodeProductGuideRepository.bulkImport(raw, userId);

    expect(result.inserted).toBe(2);
    expect(result.skipped).toEqual([]);

    const vase = await findGuideRowByProduct('Jest Test Product Vase');
    expect(vase.code_reference).toBe('921010');
    expect(vase.note).toBe('Carved 3D article');

    const slab = await findGuideRowByProduct('Jest Test Product Slab');
    expect(slab.note).toBeNull();
  });

  it('product guide import accepts pipe-separated for hand typing', async () => {
    const userId = await createTestUser();
    const result = await hsCodeProductGuideRepository.bulkImport('Jest Test Product Monument|921012 / 921013|Check per SKU', userId);

    expect(result.inserted).toBe(1);
    const row = await findGuideRowByProduct('Jest Test Product Monument');
    expect(row.code_reference).toBe('921012 / 921013');
    expect(row.note).toBe('Check per SKU');
  });

  it('product guide delete removes the entry', async () => {
    const userId = await createTestUser();
    await hsCodeProductGuideRepository.bulkImport('Jest Test Product Delete Me\t921014', userId);
    const row = await findGuideRowByProduct('Jest Test Product Delete Me');
    expect(row).not.toBeNull();

    await hsCodeProductGuideRepository.remove(row.id);

    expect(await findGuideRowByProduct('Jest Test Product Delete Me')).toBeNull();
  });
});
