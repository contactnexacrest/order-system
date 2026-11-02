'use strict';

// The CAFIN PDF is rendered through pdfRenderService (headless Chrome), which
// Jest stubs; the mock captures the HTML so the receipts line can be checked.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
  closeBrowser: jest.fn(),
}));

const fs = require('fs');
const db = require('../../src/config/db');
const fileStoreRepository = require('../../src/repositories/fileStoreRepository');
const cafinExportService = require('../../src/services/cafinExportService');
const caFinancialSnapshotService = require('../../src/services/caFinancialSnapshotService');
const documentGenerationService = require('../../src/services/documentGenerationService');
const { renderPdfFromHtml } = require('../../src/services/pdfRenderService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CafinReceiptsBankChargesTest.php. Owner decision 2 Nov 2026
 * (D-236, confirmed twice): in the CA annexure's "Money received from the
 * buyer", a remittance the banks took charges from shows what the buyer paid,
 * what actually reached our bank and the charges beside it; the receipts INR
 * total is what reached the bank. An INR actual is already the credited
 * figure. Receipts with no charges print exactly as before.
 */
describe('CAFIN receipts with bank charges (D-236)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function orderWithAdvance(charges, inrActual = null) {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute(`UPDATE order_payment_status SET advance_amount = 1800, advance_exchange_rate = 84,
        advance_remittance_received_at = '2026-11-02 10:00:00', advance_inr_actual = :a WHERE order_id = :o`, { a: inrActual, o: orderId });
    await db.execute(`INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, bank_charges, bank_charges_inr)
        VALUES (:o, 'advance', :a, '2026-11-02', 84, 'UTR-CAFIN-BC', :c, :ci)`,
      { o: orderId, a: 1800 - charges, c: charges > 0 ? charges : null, ci: charges > 0 ? charges * 84 : null });
    return orderId;
  }

  function advanceRow(s) {
    const rows = s.receipts.filter((r) => r.leg === 'Advance');
    expect(rows).toHaveLength(1);
    return rows[0];
  }

  it('shows the credited rupees with the charges beside them', async () => {
    const s = await caFinancialSnapshotService.build(await orderWithAdvance(20));
    const r = advanceRow(s);
    expect(r.amount).toBe('1,800.00');
    expect(r.amount_display).toBe('1,800.00 (received 1,780.00 + bank charges 20.00)');
    expect(r.rate).toBe('84.0000');
    expect(r.inr).toBe('149,520.00');
    expect(r.inr_display).toBe('149,520.00 credited · bank charges ₹1,680.00');
    expect(s.receipts_inr).toBe('149,520.00');
    expect(s.receipts_foreign).toBe('1,800.00');
  });

  it('treats an INR actual as the credited amount already', async () => {
    const s = await caFinancialSnapshotService.build(await orderWithAdvance(20, 149000));
    const r = advanceRow(s);
    expect(r.inr).toBe('149,000.00');
    expect(r.inr_display).toBe('149,000.00 credited · bank charges ₹1,680.00');
    expect(r.basis).toBe('INR actual');
  });

  it('prints a receipt with no charges exactly as before', async () => {
    const s = await caFinancialSnapshotService.build(await orderWithAdvance(0));
    const r = advanceRow(s);
    expect(Object.keys(r)).toEqual(['leg', 'amount', 'rate', 'inr', 'received', 'cleared', 'basis']);
    expect(r.amount).toBe('1,800.00');
    expect(r.inr).toBe('151,200.00');
    expect(r.basis).toBe('at recorded rate');
  });

  it('keeps numbers in the Excel export and explains the charges in the note', async () => {
    const orderId = await orderWithAdvance(20);
    const rows = (await cafinExportService.detailRows([orderId]))
      .filter((r) => r[2] === 'A. Money received from the buyer' && r[3] === 'Advance');
    expect(rows).toHaveLength(1);
    expect(rows[0][6]).toBe('1800.00');
    expect(rows[0][8]).toBe('149520.00');
    expect(rows[0][9]).toBe(`at recorded rate · credited after bank charges ${rows[0][7]} 20.00 (₹1,680.00) — received 1,780.00`);
  });

  it('prints the credited line on the generated annexure', async () => {
    const orderId = await orderWithAdvance(20);
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const user = await db.execute(`INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
      VALUES ('Jest Test User', :e, NULL, 'x', :r, 1, 0, 0)`, { e: `jest-cafinbc-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, r: role.id });
    const result = await documentGenerationService.generateCaInternalAnnexure(orderId, user.insertId);
    const calls = renderPdfFromHtml.mock.calls;
    const html = calls[calls.length - 1][0];
    expect(html).toContain('1,800.00 (received 1,780.00 + bank charges 20.00)');
    expect(html).toContain('149,520.00 credited · bank charges ₹1,680.00');
    try { fs.unlinkSync((await fileStoreRepository.find(result.pdf_file_id)).server_path); } catch (e) { /* stub file */ }
  });
});
