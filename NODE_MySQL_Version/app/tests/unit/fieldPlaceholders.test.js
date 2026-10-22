'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-03: "Fields without placeholders" (owner asked for
 * placeholders on every field) across ~20 pages — Users (name, phone),
 * Roles (name, description), Permission definitions, Reference
 * categories/edit, Annexure (name, description, technical notes), Reports
 * (save name), Signatories (title), Clients (unique-number override,
 * reason), Company Settings (all numeric settings). Port of PHP's
 * FieldPlaceholdersTest.
 */
describe('Fields without placeholders (External QA report U-03)', () => {
  function view(relativePath) {
    return fs.readFileSync(path.join(__dirname, '../../views', relativePath), 'utf8');
  }

  it('users create form name and phone have placeholders', () => {
    const html = view('users/index.njk');

    expect(html).toMatch(/name="name"[^\n]*?placeholder=/);
    expect(html).toMatch(/name="phone"[^\n]*?placeholder=/);
  });

  it('role edit form name and description have placeholders', () => {
    const html = view('permission_admin/role_edit.njk');

    expect(html).toMatch(/name="name"[^\n]*?placeholder=/);
    expect(html).toMatch(/name="description"[^\n]*?placeholder=/);
  });

  it('permission definition edit form has placeholders', () => {
    const html = view('permission_admin/permission_edit.njk');

    expect(html).toMatch(/name="name"[^\n]*?placeholder=/);
    expect(html).toMatch(/name="category"[^\n]*?placeholder=/);
    expect(html).toMatch(/name="description"[^\n]*?placeholder=/);
  });

  it('reference category name fields have placeholders', () => {
    const html = view('reference_docs/categories.njk');

    expect((html.match(/name="name"[^\n]*?placeholder=/g) || []).length).toBe(2);
  });

  it('annexure product fields have placeholders', () => {
    const html = view('annexure/index.njk');

    for (const field of ['name', 'description', 'technical_notes']) {
      const matches = html.match(new RegExp(`name="${field}"[^\\n]*?placeholder=`, 'g')) || [];
      expect(matches.length).toBe(2);
    }
  });

  it('saved report rename name field has a placeholder', () => {
    const html = view('reports/index.njk');

    expect(html).toMatch(/name="name"[^\n]*?placeholder=/);
  });

  it('signatory designation title field has a placeholder', () => {
    const html = view('signatories/index.njk');

    expect(html).toMatch(/name="title"[^\n]*?placeholder=/);
  });

  it('client unique number override and reason have placeholders', () => {
    const html = view('clients/show.njk');

    expect(html).toMatch(/name="client_unique_number"[^\n]*?placeholder=/);
    expect(html).toMatch(/class="override-reason" name="reason"[^\n]*?placeholder=/);
  });

  it('company settings numeric field gets a placeholder', () => {
    const html = view('settings/index.njk');

    expect(html).toContain("row.value_type == 'number'");
    expect(html).toContain('placeholder="e.g., 30"');
  });
});
