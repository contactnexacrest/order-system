'use strict';
// Owner D-371 (13 Nov 2026): every migration file has a row in the read-only check script the
// live site runs in phpMyAdmin. Twin of MigrationCheckScriptCoverageTest.php.
const fs = require('fs');
const path = require('path');

describe('D-371 migration check script coverage', () => {
  it('every migration file has a row in docs/checks/check_all_migrations.sql', () => {
    const root = path.join(__dirname, '..', '..', '..', '..');
    const check = fs.readFileSync(path.join(root, 'docs', 'checks', 'check_all_migrations.sql'), 'utf8');
    const missing = fs.readdirSync(path.join(root, 'docs', 'migrations'))
      .filter((f) => f.endsWith('.sql'))
      .filter((f) => {
        const m = f.match(/^(\d{4}-\d{2})-(\d{2}[a-z]+)-/);
        return m && !check.includes(`'${m[1]}-${m[2]}'`) && !check.includes(`'${m[2]}'`);
      });
    expect(missing).toEqual([]);
  });
});
