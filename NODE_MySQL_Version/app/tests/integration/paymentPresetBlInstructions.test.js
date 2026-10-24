'use strict';

const db = require('../../src/config/db');
const paymentPresetRepository = require('../../src/repositories/paymentPresetRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * docs/schema.sql payment_presets.show_bl_instructions (90-item backlog
 * #32) — an admin-UI-editable per-preset flag deciding whether the
 * generated BL Instruction Sheet includes the BL Type / BL Consignee
 * Instruction rows (company_settings bl_type_instruction /
 * bl_consignee_instruction). Gated in exactly one place,
 * documentDataAssembler's blBlock(), reached through
 * orderRepository.find()'s pp.show_bl_instructions column.
 */
describe('payment_presets.show_bl_instructions (backlog #32)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function setPresetShowBlInstructions(presetId, value) {
    await db.execute('UPDATE payment_presets SET show_bl_instructions = :v WHERE id = :id', {
      v: value ? 1 : 0,
      id: presetId,
    });
  }

  async function standardPresetId() {
    const row = await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'");
    return row.id;
  }

  async function defaultCurrencyId() {
    const row = await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'");
    return row.id;
  }

  async function createTestUser() {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('assemble includes BL instructions when the preset flag is on', async () => {
    const presetId = await standardPresetId();
    await setPresetShowBlInstructions(presetId, true);
    const orderId = await createTestOrder(await createTestClient());

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.bl.type_instruction).not.toBeNull();
    expect(data.bl.consignee_instruction).not.toBeNull();
    expect(data.bl.consignee_instruction).toContain('TO ORDER OF');
  });

  it('assemble omits BL instructions when the preset flag is off', async () => {
    const presetId = await standardPresetId();
    await setPresetShowBlInstructions(presetId, false);
    const orderId = await createTestOrder(await createTestClient());

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.bl.type_instruction).toBeNull();
    expect(data.bl.consignee_instruction).toBeNull();

    // Restore the shared seeded preset so other tests aren't affected.
    await setPresetShowBlInstructions(presetId, true);
  });

  it('paymentPresetRepository round-trips the show_bl_instructions flag', async () => {
    const userId = await createTestUser();
    const currencyId = await defaultCurrencyId();

    const baseData = {
      preset_name: `Jest Test Preset ${Math.random().toString(16).slice(2, 8)}`,
      is_default: false,
      advance_pct: 40,
      advance_trigger_text: 'against Proforma Invoice',
      payment_method: 'CASH_ADVANCE',
      balance_pct: 60,
      balance_trigger_option: 'A_BEFORE_SHIPMENT',
      balance_days: 3,
      balance_trigger_wording: null,
      currency_id: currencyId,
      requires_md_approval: false,
      show_bl_instructions: false,
    };

    const presetId = await paymentPresetRepository.create(baseData, userId);
    const created = await paymentPresetRepository.find(presetId);
    expect(created.show_bl_instructions).toBe(0);

    await paymentPresetRepository.update(presetId, { ...baseData, show_bl_instructions: true });
    const updated = await paymentPresetRepository.find(presetId);
    expect(updated.show_bl_instructions).toBe(1);
  });
});
