'use strict';

const db = require('../../src/config/db');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * docs/seed.sql company_settings.show_generated_document_disclaimer
 * (90-item backlog #21) — the "system-generated document" disclaimer
 * under the signature block now defaults to OFF on a fresh install,
 * not ON. Pure seed-default/data change — documentDataAssembler's
 * companyBlock() logic itself is unchanged, it just now reads a
 * different stored value.
 */
describe('company_settings.show_generated_document_disclaimer default (backlog #21)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('fresh seed defaults the disclaimer to off', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const data = await documentDataAssembler.assemble(orderId);

    expect(data.company.show_generated_document_disclaimer).toBe(false);
  });
});
