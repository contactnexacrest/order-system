'use strict';

const fs = require('fs');
const path = require('path');
const { closeOrderBlockedBy } = require('../../src/helpers/closeOrderBlockedBy');

/**
 * Port of CloseOrderDocumentHintTest.php. 90-item backlog #57: Stage 9
 * gave no indication that the BL Instruction Sheet or COO Prep Sheet
 * hadn't been generated/approved yet. A hard block in
 * OrderController::closeOrder() (PHP) / closeOrder() (Node) was drafted
 * and then deliberately reverted: docs/SOP/09-stage9-despatch-closure.md
 * documents Stage 9's close gate as an intentional staff-trust decision
 * (commit 8543e0c already investigated and rejected exactly this kind of
 * hard gate), so this is a non-blocking hint only, the same "tracking
 * aid, not a lock" treatment the Compliance Checklist already gets in
 * this same section. Close Order itself must remain available regardless
 * of document state.
 */
describe('closeOrderBlockedBy helper (90-item backlog #57)', () => {
  it('flags both labels when neither document exists', () => {
    expect(closeOrderBlockedBy([])).toEqual(['BL Instruction Sheet', 'COO Prep Sheet']);
  });

  it('flags only the still-outstanding document', () => {
    const documents = [{ document_type_code: 'BLI', status: 'approved' }];
    expect(closeOrderBlockedBy(documents)).toEqual(['COO Prep Sheet']);
  });

  it('flags nothing once both are approved/sent', () => {
    const documents = [
      { document_type_code: 'BLI', status: 'approved' },
      { document_type_code: 'COOPREP', status: 'sent' },
    ];
    expect(closeOrderBlockedBy(documents)).toEqual([]);
  });

  it('uses the latest revision when a type has several documents (newest-first input)', () => {
    const documents = [
      { document_type_code: 'BLI', status: 'approved' },
      { document_type_code: 'BLI', status: 'draft' },
    ];
    expect(closeOrderBlockedBy(documents)).toEqual(['COO Prep Sheet']);
  });

  it('does not flag an unrelated document type', () => {
    const documents = [
      { document_type_code: 'QT', status: 'approved' },
      { document_type_code: 'BLI', status: 'approved' },
      { document_type_code: 'COOPREP', status: 'approved' },
    ];
    expect(closeOrderBlockedBy(documents)).toEqual([]);
  });
});

describe('Close Order hint is wired into Stage 9 without gating the form (90-item backlog #57)', () => {
  const templatePath = path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk');
  const source = fs.readFileSync(templatePath, 'utf8');

  it('renders the hint from closeOrderBlockedBy inside the close_orders branch', () => {
    const closeOrdersBranch = source.indexOf('{% if permissions.close_orders %}');
    const hintCall = source.indexOf('closeOrderBlockedBy(documents)');
    const closeForm = source.indexOf('action="/orders/{{ order.id }}/close"');

    expect(closeOrdersBranch).toBeGreaterThan(-1);
    expect(hintCall).toBeGreaterThan(closeOrdersBranch);
    expect(hintCall).toBeLessThan(closeForm);
  });

  it('the Close Order form itself carries no document-state condition', () => {
    const closeFormStart = source.indexOf('<form method="post" action="/orders/{{ order.id }}/close">');
    const closeFormEnd = source.indexOf('</form>', closeFormStart);
    const formMarkup = source.slice(closeFormStart, closeFormEnd);

    expect(formMarkup).not.toContain('{% if');
  });
});
