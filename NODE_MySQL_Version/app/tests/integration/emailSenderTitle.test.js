'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const emailDispatchService = require('../../src/services/emailDispatchService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-5 (EML-08 — external QA report cross-verification): {sender_title}
 * (and the {sender_signature} fallback that embeds it) always resolved to
 * the company-wide md_title setting ("Founder & Managing Director"),
 * regardless of who actually sent the email. A Logistics or Accounts
 * Executive's approved send to a buyer would go out signed with the MD's
 * own title, misrepresenting who at the company the buyer is actually
 * dealing with.
 */
describe('Email sender title (QA-5 EML-08)', () => {
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

  async function createTestDesignation(title) {
    const result = await db.execute(
      'INSERT INTO designations (title) VALUES (:title)',
      { title: `${title}-${crypto.randomBytes(3).toString('hex')}` }
    );
    return result.insertId;
  }

  async function setClientEmail(clientId, email) {
    await db.execute('UPDATE clients SET email = :email WHERE id = :id', { email, id: clientId });
  }

  it('uses the sender\'s own designation in the send preview, not the MD\'s title', async () => {
    const mdTitle = (await companySettingsRepository.get('md_title')) || '';
    expect(mdTitle).not.toBe('');

    const designationId = await createTestDesignation('Export Manager');
    const senderId = await createTestUser('Export Executive');
    await db.execute('UPDATE users SET designation_id = :did WHERE id = :id', { did: designationId, id: senderId });

    const clientId = await createTestClient();
    await setClientEmail(clientId, 'buyer@example.test');
    const orderId = await createTestOrder(clientId);

    const preview = await emailDispatchService.buildPreview(orderId, null, 'payment_followup', senderId);

    expect(preview.body).toEqual(expect.stringContaining('Export Manager'));
    expect(preview.body).not.toEqual(expect.stringContaining(mdTitle));
  });

  it('falls back to the MD title when the sender has no designation', async () => {
    const mdTitle = (await companySettingsRepository.get('md_title')) || '';
    const senderId = await createTestUser('Export Executive'); // designation_id left NULL

    const clientId = await createTestClient();
    await setClientEmail(clientId, 'buyer@example.test');
    const orderId = await createTestOrder(clientId);

    const preview = await emailDispatchService.buildPreview(orderId, null, 'payment_followup', senderId);

    expect(preview.body).toEqual(expect.stringContaining(mdTitle));
  });
});
