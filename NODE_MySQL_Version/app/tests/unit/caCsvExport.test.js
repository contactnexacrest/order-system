'use strict';

const caController = require('../../src/controllers/caController');

/**
 * Port of CaCsvExportTest.php. 90-item backlog item #13: the CA module
 * had no CSV export anywhere — the only CSV-handling code there was for
 * IMPORTING a bank statement, not exporting any of the CA screens. Drives
 * the extracted *CsvRows() pure functions directly, same as the PHP side.
 */
describe('CA CSV export (90-item backlog item #13)', () => {
  it('settlementRegisterCsvRows matches the on-screen columns', () => {
    const legRows = [
      { buyer_inquiry_ref: 'NC/2026/0042', company_legal_name: 'Acme Importers Ltd', leg: 'advance', cleared_at: '2026-10-05', foreign_amount: 1000.0, currency_code: 'USD', inr_actual: 83250.0, forex_gain_loss: 250.0 },
    ];

    const result = caController.settlementRegisterCsvRows(legRows);

    expect(result.headers).toEqual(['Order Ref', 'Client', 'Leg', 'Cleared On', 'Foreign Amount', 'Currency', 'INR Actual', 'Forex Gain/(Loss)']);
    expect(result.rows[0].Leg).toBe('Advance');
    expect(result.rows[0]['Order Ref']).toBe('NC/2026/0042');
    expect(result.rows[0]['Forex Gain/(Loss)']).toBe(250.0);
  });

  it('expensesCsvRows falls back for an unlinked expense and no TDS', () => {
    const expenses = [
      { expense_date: '2026-09-15', category: 'Freight', vendor_name: 'XYZ Logistics', description: 'Inland haulage', amount: 15000.0, currency_code: 'INR', order_id: 7, order_reference: 'NC/2026/0007', is_tds_applicable: true, tds_amount: 300.0 },
      { expense_date: '2026-09-20', category: 'Office', vendor_name: null, description: null, amount: 500.0, currency_code: 'INR', order_id: null, order_reference: null, is_tds_applicable: false, tds_amount: null },
    ];

    const result = caController.expensesCsvRows(expenses);

    expect(result.headers).toEqual(['Date', 'Category', 'Vendor', 'Description', 'Amount', 'Currency', 'Order Ref', 'TDS Applicable', 'TDS Amount']);
    expect(result.rows[0]['TDS Applicable']).toBe('Yes');
    expect(result.rows[0]['TDS Amount']).toBe(300.0);
    expect(result.rows[0]['Order Ref']).toBe('NC/2026/0007');
    expect(result.rows[1]['TDS Applicable']).toBe('No');
    expect(result.rows[1].Vendor).toBe('');
    expect(result.rows[1]['Order Ref']).toBe('');
  });

  it('exportBenefitsCsvRows reflects claimed vs received status', () => {
    const benefits = [
      { claimed_at: '2026-08-01', scheme_name: 'RODTEP', order_id: 3, order_reference: 'NC/2026/0003', reference_number: 'SB-2026-00417', claimed_amount: 15000.0, currency_code: 'INR', received_amount: 14800.0, received_at: '2026-09-10' },
      { claimed_at: '2026-10-01', scheme_name: 'RODTEP', order_id: null, order_reference: null, reference_number: null, claimed_amount: 5000.0, currency_code: 'INR', received_amount: null, received_at: null },
    ];

    const result = caController.exportBenefitsCsvRows(benefits);

    expect(result.headers).toEqual(['Claimed On', 'Scheme', 'Order Ref', 'Reference', 'Claimed Amount', 'Currency', 'Received Amount', 'Received On', 'Status']);
    expect(result.rows[0].Status).toBe('Received');
    expect(result.rows[0]['Received Amount']).toBe(14800.0);
    expect(result.rows[1].Status).toBe('Claimed');
    expect(result.rows[1]['Received Amount']).toBe('');
    expect(result.rows[1]['Order Ref']).toBe('');
  });
});
