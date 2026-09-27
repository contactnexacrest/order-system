'use strict';

const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');
const hsCodeRepository = require('../../src/repositories/hsCodeRepository');
const watermarkSettingsRepository = require('../../src/repositories/watermarkSettingsRepository');
const companyHolidayRepository = require('../../src/repositories/companyHolidayRepository');
const emailTemplateRepository = require('../../src/repositories/emailTemplateRepository');
const productRepository = require('../../src/repositories/productRepository');
const internalReferenceDocRepository = require('../../src/repositories/internalReferenceDocRepository');
const signatoryRepository = require('../../src/repositories/signatoryRepository');

/**
 * QA-4 P3 (docs/QA/TEST_PLAN.md Section 6): standard create/read/update/
 * delete correctness for the modules with no state-machine or
 * cross-tenant dimension of their own — Clients, Product Catalog,
 * Reference Docs, HS Codes, Watermarks, Holidays, Email Templates,
 * Signatories.
 */
describe('Core CRUD correctness per module (QA-4 P3)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('client CRUD', async () => {
    const userId = await createTestUser('Export Executive');
    const id = await clientRepository.create(
      {
        company_legal_name: 'Jest CRUD Client',
        billing_address: 'Address v1',
        consignee_name: 'Consignee v1',
        consignee_address: 'Consignee Addr v1',
        email: 'crud-client@example.test',
      },
      userId,
      `CRUD-TEST-${Math.random().toString(16).slice(2, 10)}`
    );

    const found = await clientRepository.find(id);
    expect(found.company_legal_name).toBe('Jest CRUD Client');
    expect(found.billing_address).toBe('Address v1');
    expect(Number(found.is_active)).toBe(1);

    await clientRepository.update(id, {
      company_legal_name: 'Jest CRUD Client Updated',
      billing_address: 'Address v2',
      consignee_name: 'Consignee v1',
      consignee_address: 'Consignee Addr v1',
      email: 'crud-client@example.test',
    });
    const updated = await clientRepository.find(id);
    expect(updated.company_legal_name).toBe('Jest CRUD Client Updated');
    expect(updated.billing_address).toBe('Address v2');

    await clientRepository.setActive(id, false);
    expect(Number((await clientRepository.find(id)).is_active)).toBe(0);
    const inactiveIds = (await clientRepository.allInactive()).map((r) => r.id);
    expect(inactiveIds).toContain(id);

    await clientRepository.setActive(id, true);
    expect(Number((await clientRepository.find(id)).is_active)).toBe(1);
  });

  it('HS code CRUD', async () => {
    const userId = await createTestUser('Export Executive');
    const code = String(Math.floor(100000 + Math.random() * 900000));
    const id = await hsCodeRepository.create(code, 'Jest test description', userId);

    const found = await hsCodeRepository.findByCode(code);
    expect(found.description).toBe('Jest test description');
    expect(await hsCodeRepository.isActiveCode(code)).toBe(true);

    await hsCodeRepository.updateDescription(id, 'Updated description');
    expect((await hsCodeRepository.findByCode(code)).description).toBe('Updated description');

    await hsCodeRepository.toggleActive(id);
    expect(await hsCodeRepository.isActiveCode(code)).toBe(false);
    await hsCodeRepository.toggleActive(id);
    expect(await hsCodeRepository.isActiveCode(code)).toBe(true);

    expect(await hsCodeRepository.usageCount(code)).toBe(0);

    await hsCodeRepository.remove(id);
    expect(await hsCodeRepository.findByCode(code)).toBeNull();
  });

  function watermarkData(textContent, opacity) {
    return {
      mode: 'text', text_content: textContent, font: 'Helvetica', font_size: 48,
      color: '#888888', opacity, angle: 45,
      image_asset_id: null, image_opacity: null, image_position: null,
    };
  }

  it('watermark settings upsert is genuinely an upsert, not a duplicate insert', async () => {
    const userId = await createTestUser('Export Executive');
    // 'global' draft-mode is a real, shared, company-wide row (the one
    // documentGenerationService's draftWatermark() actually reads) —
    // capture and restore it rather than leaving test data behind.
    const before = await watermarkSettingsRepository.findGlobal(true);

    await watermarkSettingsRepository.upsertGlobal(true, watermarkData('DRAFT v1', 30), userId);
    const first = await watermarkSettingsRepository.findGlobal(true);
    expect(first.text_content).toBe('DRAFT v1');

    await watermarkSettingsRepository.upsertGlobal(true, watermarkData('DRAFT v2', 45), userId);
    const second = await watermarkSettingsRepository.findGlobal(true);
    expect(second.text_content).toBe('DRAFT v2');
    expect(Number(second.id)).toBe(Number(first.id));

    if (before) {
      await watermarkSettingsRepository.upsertGlobal(
        true,
        {
          mode: before.mode, text_content: before.text_content, font: before.font,
          font_size: before.font_size, color: before.color, opacity: before.opacity,
          angle: before.angle, image_asset_id: before.image_asset_id,
          image_opacity: before.image_opacity, image_position: before.image_position,
        },
        userId
      );
    }
  });

  it('company holiday CRUD', async () => {
    const date = '2030-01-26';
    const id = await companyHolidayRepository.create(date, 'Jest Test Holiday', null);

    const found = await companyHolidayRepository.find(id);
    expect(found.description).toBe('Jest Test Holiday');
    const inRange = await companyHolidayRepository.datesBetween('2030-01-01', '2030-01-31');
    const inRangeStrings = inRange.map((d) => (d instanceof Date ? d.toISOString().slice(0, 10) : String(d).slice(0, 10)));
    expect(inRangeStrings).toContain(date);

    await companyHolidayRepository.update(id, date, 'Updated Holiday Name');
    expect((await companyHolidayRepository.find(id)).description).toBe('Updated Holiday Name');

    await companyHolidayRepository.remove(id);
    expect(await companyHolidayRepository.find(id)).toBeNull();
  });

  it('email template CRUD', async () => {
    const userId = await createTestUser('Export Executive');
    const key = `jest_test_template_${Math.random().toString(16).slice(2, 8)}`;
    expect(await emailTemplateRepository.keyExists(key)).toBe(false);

    const id = await emailTemplateRepository.create(key, 'Subject v1', 'Body v1', null, userId);
    expect(await emailTemplateRepository.keyExists(key)).toBe(true);

    const found = await emailTemplateRepository.find(key);
    expect(found.subject).toBe('Subject v1');
    expect(Number(found.is_active)).toBe(1);

    await emailTemplateRepository.update(id, 'Subject v2', 'Body v2', 'Footer v2', userId);
    const updated = await emailTemplateRepository.find(key);
    expect(updated.subject).toBe('Subject v2');
    expect(updated.footer).toBe('Footer v2');

    await emailTemplateRepository.setActive(id, false, userId);
    expect(Number((await emailTemplateRepository.find(key)).is_active)).toBe(0);
  });

  it('product catalog CRUD', async () => {
    const userId = await createTestUser('Export Executive');
    const id = await productRepository.create(
      { name: 'Jest Test Product', specifications: 'Spec v1', hs_code: '6802.93' },
      userId
    );

    const found = await productRepository.find(id);
    expect(found.name).toBe('Jest Test Product');
    expect(Number(found.is_active)).toBe(1);

    await productRepository.update(
      id,
      { name: 'Jest Test Product Updated', specifications: 'Spec v2', hs_code: '6802.93', is_active: 0 },
      userId
    );
    const updated = await productRepository.find(id);
    expect(updated.name).toBe('Jest Test Product Updated');
    expect(Number(updated.is_active)).toBe(0);

    const activeOnlyIds = (await productRepository.all(true)).map((r) => r.id);
    expect(activeOnlyIds).not.toContain(id);

    await productRepository.remove(id);
    expect(await productRepository.find(id)).toBeNull();
  });

  it('internal reference doc upsert is genuinely an upsert', async () => {
    const userId = await createTestUser('Export Executive');
    const before = await internalReferenceDocRepository.findByCode('WALLREF');
    const documentTypeId = Number(before.document_type_id);

    await internalReferenceDocRepository.upsert(documentTypeId, 'Jest content v1', userId);
    expect((await internalReferenceDocRepository.findByCode('WALLREF')).content).toBe('Jest content v1');

    await internalReferenceDocRepository.upsert(documentTypeId, 'Jest content v2', userId);
    expect((await internalReferenceDocRepository.findByCode('WALLREF')).content).toBe('Jest content v2');

    // Restore whatever real content existed before this test touched it —
    // WALLREF is a real, shared, company-wide row, not a disposable
    // per-test fixture.
    await internalReferenceDocRepository.upsert(documentTypeId, before.content || '', userId);
  });

  it('signatory designation CRUD', async () => {
    const userId = await createTestUser('Export Executive');
    const id = await signatoryRepository.createDesignation('Jest Test Designation', userId);

    const found = await signatoryRepository.findDesignation(id);
    expect(found.title).toBe('Jest Test Designation');
    expect(Number(found.is_active)).toBe(1);
    expect(await signatoryRepository.designationUsageCount(id)).toBe(0);

    await signatoryRepository.updateDesignationTitle(id, 'Updated Designation Title');
    expect((await signatoryRepository.findDesignation(id)).title).toBe('Updated Designation Title');

    await signatoryRepository.toggleDesignationActive(id);
    expect(Number((await signatoryRepository.findDesignation(id)).is_active)).toBe(0);
    await signatoryRepository.toggleDesignationActive(id);
    expect(Number((await signatoryRepository.findDesignation(id)).is_active)).toBe(1);

    await signatoryRepository.deleteDesignation(id);
    expect(await signatoryRepository.findDesignation(id)).toBeNull();
  });
});
