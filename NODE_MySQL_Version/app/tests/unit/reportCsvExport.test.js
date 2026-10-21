'use strict';

const reportController = require('../../src/controllers/reportController');

/**
 * Port of ReportCsvExportTest.php. 90-item backlog "CSV export for the
 * 6 non-CA reports": Trends, Staff Productivity, Supplier Performance,
 * Conversion Rate, Sales Performance, and the per-Order report were the
 * 6 reports in the non-CA Reports module with no CSV export at all.
 * Drives the extracted *CsvRows() pure functions directly, same as the
 * PHP side.
 */
describe('Report CSV export (90-item backlog "CSV export for the 6 non-CA reports")', () => {
  it('trendsCsvRows formats multi-currency FOB and zero months', () => {
    const months = [
      { month: '2026-09', orders_created: 3, quotations_sent: 2, pi_sent: 1, lost: 0, fob_by_currency: { USD: 1234.5, EUR: 500.0 } },
      { month: '2026-10', orders_created: 0, quotations_sent: 0, pi_sent: 0, lost: 0, fob_by_currency: {} },
    ];

    const result = reportController.trendsCsvRows(months);

    expect(result.headers).toEqual(['Month', 'Orders Created', 'Quotations Sent', 'PI Sent', 'Lost', 'Total FOB Value']);
    expect(result.rows[0]['Total FOB Value']).toBe('USD 1234.50; EUR 500.00');
    expect(result.rows[1]['Total FOB Value']).toBe('—');
  });

  it('staffCsvRows formats per-type breakdown', () => {
    const rows = [
      { user_name: 'Jane Staff', by_type: { QT: 3, PI: 1 }, documents_total: 4, audit_actions: 12 },
      { user_name: 'No Activity User', by_type: {}, documents_total: 0, audit_actions: 0 },
    ];

    const result = reportController.staffCsvRows(rows);

    expect(result.rows[0]['Documents Generated (by type)']).toBe('QT: 3; PI: 1');
    expect(result.rows[1]['Documents Generated (by type)']).toBe('—');
  });

  it('suppliersCsvRows handles null averages and percentages', () => {
    const rows = [
      { supplier_name: 'Acme Granite', po_count: 5, signed_count: 5, avg_signing_days: 2.4, delivery_tracked_count: 3, on_time_pct: 66.7 },
      { supplier_name: 'No Signings Yet', po_count: 1, signed_count: 0, avg_signing_days: null, delivery_tracked_count: 0, on_time_pct: null },
    ];

    const result = reportController.suppliersCsvRows(rows);

    expect(result.rows[0]['On-Time %']).toBe('66.7%');
    expect(result.rows[1]['Avg Signing Days']).toBe('—');
    expect(result.rows[1]['On-Time %']).toBe('—');
  });

  it('conversionCsvRows is a Metric/Value table', () => {
    const conversion = {
      quotations_sent: 20,
      quotation_to_pi_pct: 50.0,
      quotation_lost_pct: 10.0,
      pi_sent: 10,
      pi_to_confirmed_pct: 80.0,
      pi_lost_pct: 5.0,
      overall_quotation_to_confirmed_pct: 40.0,
    };

    const result = reportController.conversionCsvRows(conversion);

    expect(result.headers).toEqual(['Metric', 'Value']);
    expect(result.rows).toHaveLength(7);
    expect(result.rows[0].Metric).toBe('Quotations sent');
    expect(result.rows[0].Value).toBe(20);
    expect(result.rows[1].Value).toBe('50%');
  });

  it('performanceCsvRows exports Lost Orders detail', () => {
    const report = {
      lost_orders: [
        { order_reference: 'SC/ORD/2026/001-1', company_legal_name: 'Lost Buyer Ltd', lost_at: '2026-10-01 10:00:00', reached_pi: true, lost_reason: 'Price too high' },
        { order_reference: 'SC/ORD/2026/002-1', company_legal_name: 'Another Lost Buyer', lost_at: '2026-10-05 10:00:00', reached_pi: false, lost_reason: null },
      ],
    };

    const result = reportController.performanceCsvRows(report);

    expect(result.rows[0]['Stage Reached']).toBe('PI');
    expect(result.rows[0].Reason).toBe('Price too high');
    expect(result.rows[1]['Stage Reached']).toBe('Quotation');
    expect(result.rows[1].Reason).toBe('—');
  });

  it('orderAuditCsvRows formats Old -> New and falls back for system actions', () => {
    const audit = [
      { created_at: '2026-10-01 09:00:00', user_name: 'Jane Staff', action_type: 'FIELD_EDIT', field_name: 'incoterm_id', old_value: '1', new_value: '2', reason: 'Buyer changed terms' },
      { created_at: '2026-10-01 09:05:00', user_name: null, action_type: 'DOCUMENT_GENERATED', field_name: null, old_value: null, new_value: null, reason: null },
    ];

    const result = reportController.orderAuditCsvRows(audit);

    expect(result.rows[0]['Old -> New']).toBe('1 -> 2');
    expect(result.rows[1].User).toBe('System');
    expect(result.rows[1]['Old -> New']).toBe('—');
  });
});
