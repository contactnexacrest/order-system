'use strict';

// Sample loading generates real documents, which renders PDFs — puppeteer
// is stubbed under Jest, so fake the render (same as documentSupersede.test.js).
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const db = require('../../src/config/db');
const sampleDataService = require('../../src/services/sampleDataService');

/**
 * Port of SampleDataLoadClearTest.php. "Clear sample data" failed on every
 * installation: sampleDataRepository.clearAll() never deleted
 * order_assignees, order_compliance_tasks and ~16 other order-scoped tables
 * added after it was written, so its final DELETE FROM orders hit a foreign
 * key — and every sample order has an assignee and compliance tasks. Also
 * pins the sample PI intakes matching where their orders actually are (owner
 * report 2026-10-25: Order B was at Stage 8 with its Step 5 intake still
 * pending).
 */
describe('Sample data load / clear', () => {
  let userId;

  beforeAll(async () => {
    const admin = await db.queryOne("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Admin' ORDER BY u.id LIMIT 1");
    userId = admin.id;
  });

  afterEach(async () => {
    if (await sampleDataService.isLoaded()) {
      await sampleDataService.clear();
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('clears loaded sample data completely', async () => {
    await sampleDataService.load(userId);
    expect(await sampleDataService.isLoaded()).toBe(true);

    const result = await sampleDataService.clear();

    expect(result.orders).toBe(10);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS c FROM orders WHERE is_sample_data = 1')).c)).toBe(0);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS c FROM clients WHERE is_sample_data = 1')).c)).toBe(0);
    expect(await sampleDataService.isLoaded()).toBe(false);
  });

  it('gives each sample PI intake a status matching its order', async () => {
    await sampleDataService.load(userId);

    const rows = await db.query(
      `SELECT p.status, sm.stage_number
       FROM pi_intake_submissions p
       JOIN orders o ON o.id = p.order_id
       JOIN stages_master sm ON sm.id = o.current_stage_id
       WHERE o.is_sample_data = 1
       ORDER BY sm.stage_number`
    );

    expect(rows).toHaveLength(2);
    expect([rows[0].status, Number(rows[0].stage_number)]).toEqual(['pending_review', 3]);
    expect([rows[1].status, Number(rows[1].stage_number)]).toEqual(['applied', 8]);
  });
});
