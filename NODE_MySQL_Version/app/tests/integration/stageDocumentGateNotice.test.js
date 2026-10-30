'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of StageDocumentGateNoticeTest.php. Owner decision 2026-10-30: each
 * stage's gating button says, before the click, what the stage's documents
 * still need (the rule stageDocumentBlockedBy() enforces), and stays
 * disabled until then.
 */
describe('Stage document notice on the order page', () => {
  let mailBefore;

  beforeEach(async () => {
    mailBefore = await companySettingsRepository.get('mail_sending_enabled');
  });

  afterEach(async () => {
    await companySettingsRepository.set('mail_sending_enabled', String(mailBefore ?? '0'), null);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('the gate says what each document still needs', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await companySettingsRepository.set('mail_sending_enabled', '1', null);
    expect(await documentGenerationService.stageDocumentGate(orderId, 3)).toEqual([{ name: 'Proforma Invoice', todo: 'generate', buyer_facing: true }]);

    await createTestDocument(orderId, 'PI', 'in_review');
    expect((await documentGenerationService.stageDocumentGate(orderId, 3))[0].todo).toBe('approve_send');
    await companySettingsRepository.set('mail_sending_enabled', '0', null);
    expect((await documentGenerationService.stageDocumentGate(orderId, 3))[0].todo).toBe('approve'); // with email off, approval is enough

    await companySettingsRepository.set('mail_sending_enabled', '1', null);
    await createTestDocument(orderId, 'PI', 'approved');
    expect((await documentGenerationService.stageDocumentGate(orderId, 3))[0].todo).toBe('send');
    await createTestDocument(orderId, 'PI', 'sent');
    expect(await documentGenerationService.stageDocumentGate(orderId, 3)).toEqual([]);
    expect(await documentGenerationService.stageDocumentBlockedBy(orderId, 3)).toEqual([]); // the notice and the server check agree

    await createTestDocument(orderId, 'SUPPO', 'in_review');
    expect((await documentGenerationService.stageDocumentGate(orderId, 5))[0])
      .toEqual({ name: 'Supplier PO — Material Procurement', todo: 'approve', buyer_facing: false }); // an internal document only needs approval
  });

  it('the order page shows the notice and disables the button until the document is done', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // Stage 2 open (the quotation stage passed), so Step 4d's form shows.
    await db.execute(
      "UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'unlocked', os.unlocked_at = NOW() WHERE os.order_id = :o AND sm.stage_number = 2",
      { o: orderId }
    );
    // ...and the buyer's signed PO copy is attached (Step 4c).
    await db.execute('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:o, :f)', { o: orderId, f: await createTestFile(orderId, clientId) });

    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });

    let html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toContain('Before you can confirm the buyer&#039;s PO received:');
    expect(html).toMatch(/<button type="submit" disabled title="See the note above[^"]*" class="btn-sm">Confirm Buyer PO Received<\/button>/);

    await companySettingsRepository.set('mail_sending_enabled', '0', null);
    await createTestDocument(orderId, 'BUYERPO', 'approved');
    html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).not.toContain('Before you can confirm the buyer&#039;s PO received:');
    expect(html).toContain('<button type="submit" class="btn-sm">Confirm Buyer PO Received</button>');
  });
});
