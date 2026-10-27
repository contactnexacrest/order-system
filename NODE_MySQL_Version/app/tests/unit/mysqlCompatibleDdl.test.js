'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Owner report (2026-10-27): a fresh install of schema.sql on the host's
 * MySQL failed with #1064 at `ADD COLUMN IF NOT EXISTS`. MariaDB accepts
 * IF [NOT] EXISTS on columns and indexes; MySQL does not. Schema files and
 * migrations guard these through information_schema plus PREPARE instead.
 */
describe('schema and migrations use MySQL-compatible DDL', () => {
  const root = path.join(__dirname, '../../..');
  const migrations = path.join(root, '../docs/migrations');
  const files = [
    path.join(root, 'docs/schema.sql'),
    ...fs.readdirSync(migrations).filter((f) => f.endsWith('.sql')).map((f) => path.join(migrations, f)),
  ];
  const pattern = /\b(?:(?:ADD|DROP|CHANGE|MODIFY)\s+(?:COLUMN|INDEX|KEY|UNIQUE\s+(?:INDEX|KEY))\s+IF\s+(?:NOT\s+)?EXISTS|CREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS)\b/i;

  test('no MariaDB-only IF [NOT] EXISTS on columns or indexes', () => {
    const offending = [];
    for (const file of files) {
      fs.readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, n) => {
        if (!line.trim().startsWith('--') && pattern.test(line)) offending.push(`${path.basename(file)}:${n + 1}`);
      });
    }
    expect(offending).toEqual([]);
  });
});
