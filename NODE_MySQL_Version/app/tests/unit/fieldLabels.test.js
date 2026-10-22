'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-04: "Fields without a visible label" (accessibility;
 * unclear for new staff) across ~14 pages — Holidays (date), Super Admin
 * (delegate, target, expiry), Permissions (user, permission), Signatories
 * (user selects), Reports (name, visibility), Reference doc edit (content),
 * Clients (copy-link box). Port of PHP's FieldLabelsTest.
 */
describe('Fields without a visible label (External QA report U-04)', () => {
  function view(relativePath) {
    return fs.readFileSync(path.join(__dirname, '../../views', relativePath), 'utf8');
  }

  it('holiday date fields have an accessible label', () => {
    const html = view('holidays/index.njk');

    expect((html.match(/name="holiday_date"[^\n]*?aria-label=/g) || []).length).toBe(2);
  });

  it('super admin delegate target and expiry have an accessible label', () => {
    const html = view('super_admin/index.njk');

    expect(html).toMatch(/name="target_id"[^\n]*?aria-label=/);
    expect(html).toMatch(/name="delegate_user_id"[^\n]*?aria-label=/);
    expect(html).toMatch(/name="expires_at"[^\n]*?aria-label=/);
  });

  it('grant extra permission user and permission have an accessible label', () => {
    const html = view('permission_admin/index.njk');

    expect(html).toMatch(/name="user_id"[^\n]*?aria-label=/);
    expect(html).toMatch(/name="permission_id"[^\n]*?aria-label=/);
  });

  it('signatory user selects have an accessible label', () => {
    const html = view('signatories/index.njk');

    expect((html.match(/name="user_id"[^\n]*?aria-label=/g) || []).length).toBe(2);
    expect(html).toMatch(/name="designation_id"[^\n]*?aria-label=/);
  });

  it('saved report name and visibility have an accessible label', () => {
    const html = view('reports/index.njk');

    expect(html).toMatch(/name="name"[^\n]*?aria-label=/);
    expect(html).toMatch(/name="visibility"[^\n]*?aria-label=/);

    const aggregateHtml = view('reports/aggregate.njk');
    expect(aggregateHtml).toContain('>Visibility');
  });

  it('reference doc edit content has a visible label', () => {
    const html = view('reference_docs/edit.njk');

    expect(html).toMatch(/<label[^>]*for="reference-doc-content"[^>]*>Content<\/label>/);
    expect(html).toContain('id="reference-doc-content"');
  });

  it('clients copy-link box has an accessible label', () => {
    const html = view('clients/index.njk');

    expect(html).toMatch(/readonly[^\n]*?aria-label=/);
  });
});
