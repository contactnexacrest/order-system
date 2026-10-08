'use strict';

const db = require('../../src/config/db');
const paymentPresetRepository = require('../../src/repositories/paymentPresetRepository');

/**
 * Payment Preset CRUD (mirrors PHP's PaymentPresetRepository) — covers
 * the one real business rule beyond plain CRUD: exactly one preset may
 * be is_default=1 (clearOtherDefaults), and is_protected blocks
 * toggleActive until unlocked via Field Protection.
 */
describe('paymentPresetRepository', () => {
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

  async function defaultCurrencyId() {
    const row = await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'");
    return row.id;
  }

  function minimalData(currencyId, overrides = {}) {
    return {
      preset_name: `Jest Test Preset ${Math.random().toString(16).slice(2, 8)}`,
      is_default: false,
      advance_pct: 40,
      advance_trigger_text: 'against Proforma Invoice before production commences',
      balance_pct: 60,
      balance_trigger_option: 'A_BEFORE_SHIPMENT',
      balance_days: 3,
      balance_trigger_wording: null,
      currency_id: currencyId,
      requires_md_approval: false,
      ...overrides,
    };
  }

  it('create then find returns all fields including currency_code from the join', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const id = await paymentPresetRepository.create(minimalData(currencyId), userId);

    const row = await paymentPresetRepository.find(id);
    expect(row.advance_pct).toBe('40.00');
    expect(row.balance_pct).toBe('60.00');
    expect(row.balance_trigger_option).toBe('A_BEFORE_SHIPMENT');
    expect(row.currency_code).toBe('USD');
    expect(row.is_active).toBe(1);
    expect(row.is_protected).toBe(0);
  });

  it('update overwrites fields including balance_trigger_wording', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const id = await paymentPresetRepository.create(minimalData(currencyId), userId);

    await paymentPresetRepository.update(id, minimalData(currencyId, {
      preset_name: 'Jest Renamed Preset',
      advance_pct: 20,
      balance_pct: 80,
      balance_trigger_wording: 'Within {days} Calendar Days of custom wording.',
    }));

    const row = await paymentPresetRepository.find(id);
    expect(row.preset_name).toBe('Jest Renamed Preset');
    expect(row.advance_pct).toBe('20.00');
    expect(row.balance_pct).toBe('80.00');
    expect(row.balance_trigger_wording).toBe('Within {days} Calendar Days of custom wording.');
  });

  it('is_default=1 on create clears is_default on every other preset', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const firstId = await paymentPresetRepository.create(minimalData(currencyId, { is_default: true }), userId);
    const secondId = await paymentPresetRepository.create(minimalData(currencyId, { is_default: true }), userId);

    expect((await paymentPresetRepository.find(firstId)).is_default).toBe(0);
    expect((await paymentPresetRepository.find(secondId)).is_default).toBe(1);
  });

  it('is_default=1 on update also clears every other preset default flag', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const firstId = await paymentPresetRepository.create(minimalData(currencyId, { is_default: true }), userId);
    const secondId = await paymentPresetRepository.create(minimalData(currencyId), userId);

    await paymentPresetRepository.update(secondId, minimalData(currencyId, { is_default: true }));

    expect((await paymentPresetRepository.find(firstId)).is_default).toBe(0);
    expect((await paymentPresetRepository.find(secondId)).is_default).toBe(1);
  });

  it('toggleActive flips both ways for an unprotected preset', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const id = await paymentPresetRepository.create(minimalData(currencyId), userId);
    expect((await paymentPresetRepository.find(id)).is_active).toBe(1);

    await paymentPresetRepository.toggleActive(id);
    expect((await paymentPresetRepository.find(id)).is_active).toBe(0);

    await paymentPresetRepository.toggleActive(id);
    expect((await paymentPresetRepository.find(id)).is_active).toBe(1);
  });

  it('toggleActive throws for a protected preset and leaves is_active unchanged', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const id = await paymentPresetRepository.create(minimalData(currencyId), userId);
    await db.execute('UPDATE payment_presets SET is_protected = 1 WHERE id = :id', { id });

    await expect(paymentPresetRepository.toggleActive(id)).rejects.toThrow(/protected/i);
    expect((await paymentPresetRepository.find(id)).is_active).toBe(1);
  });

  it('isInUse reflects whether any order references the preset', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const id = await paymentPresetRepository.create(minimalData(currencyId), userId);
    expect(await paymentPresetRepository.isInUse(id)).toBe(false);
  });

  it('all excludes inactive by default but includes them when includeInactive is true', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();
    const activeId = await paymentPresetRepository.create(minimalData(currencyId), userId);
    const inactiveId = await paymentPresetRepository.create(minimalData(currencyId), userId);
    await paymentPresetRepository.toggleActive(inactiveId);

    const activeOnlyIds = (await paymentPresetRepository.all(false)).map((r) => r.id);
    expect(activeOnlyIds).toContain(activeId);
    expect(activeOnlyIds).not.toContain(inactiveId);

    const withInactiveIds = (await paymentPresetRepository.all(true)).map((r) => r.id);
    expect(withInactiveIds).toContain(activeId);
    expect(withInactiveIds).toContain(inactiveId);
  });
});
