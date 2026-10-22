'use strict';

const auditLogController = require('../../src/controllers/auditLogController');

/**
 * External QA report M-03: "One-click export of an order's audit log
 * (privileged only)." Port of PHP's AuditLogOrderExportTest.
 */
describe('Audit log order CSV export row mapping (External QA report M-03)', () => {
  it('rows map to the expected CSV columns', () => {
    const rows = [
      { created_at: '2026-10-01 09:00:00', user_name: 'Jane Staff', action_type: 'FIELD_EDIT', entity_type: 'orders', entity_id: 5, field_name: 'incoterm_id', old_value: '1', new_value: '2', reason: 'Buyer changed terms' },
      { created_at: '2026-10-01 09:05:00', user_name: null, action_type: 'DOCUMENT_GENERATED', entity_type: 'documents', entity_id: 9, field_name: null, old_value: null, new_value: null, reason: null },
    ];

    const result = auditLogController.orderAuditLogCsvRows(rows);

    expect(result[0].User).toBe('Jane Staff');
    expect(result[0].Entity).toBe('orders #5');
    expect(result[1].User).toBe('system');
    expect(result[1].Entity).toBe('documents #9');
    expect(result[1].Field).toBe('');
  });
});
