'use strict';

/**
 * Owner request (11 Nov 2026): "make sure schema and seed will always be
 * ready for a fresh install". A fresh install is docs/schema.sql then
 * docs/seed.sql and NOTHING else — the files in docs/migrations (repo root,
 * shared by both stacks) exist only to bring an existing live database up
 * to date, so every change they make must already be in schema.sql/seed.sql.
 *
 * This test proves it: it builds a throwaway database from this stack's
 * schema.sql + seed.sql, takes a deterministic mysqldump snapshot, applies
 * every migration file in filename order (each must succeed), snapshots
 * again and asserts both snapshots are identical (timestamp literals
 * normalised, since a migration may touch updated_at). A difference means a
 * migration changes something a fresh install does not have — add it to
 * schema.sql/seed.sql (both stacks).
 *
 * Parity: PHP_MySQL_Version/app/tests/Integration/FreshInstallCompleteTest.php.
 * Never touches nexacrest_node_jest or any dev database.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const dotenv = require('dotenv');

// Host/user/password from the real app/.env, as tests/support/globalSetup.js does.
const realEnv = dotenv.parse(fs.readFileSync(path.join(__dirname, '..', '..', '.env')));
const CREDENTIALS = [
  '-h', realEnv.DB_HOST || '127.0.0.1',
  '-P', realEnv.DB_PORT || '3306',
  '-u', realEnv.DB_USERNAME,
  `-p${realEnv.DB_PASSWORD || ''}`,
];
const DB_NAME = `zz_freshcheck_node_${process.pid}`;
const STACK_DOCS = path.join(__dirname, '..', '..', '..', 'docs');
const MIGRATIONS_DIR = path.join(__dirname, '..', '..', '..', '..', 'docs', 'migrations');

function run(command, args, input) {
  const result = spawnSync(command, args, { input: input || '', maxBuffer: 512 * 1024 * 1024 });
  if (result.error) throw result.error;
  return { status: result.status, stdout: result.stdout.toString('utf8'), stderr: result.stderr.toString('utf8') };
}

// schema.sql declares triggers with DELIMITER, which only the mysql CLI understands.
function runMysql(database, sql, plainAlter = false) {
  // Owner D-371 (13 Nov 2026): run every statement of a "PLAIN ALTER" file, as phpMyAdmin would
  // after "Duplicate column name", so the rest of the file is still checked.
  const base = plainAlter ? [...CREDENTIALS, '--force'] : CREDENTIALS;
  const args = database ? [...base, database] : base;
  const { status, stderr } = run('mysql', args, sql);
  // A "PLAIN ALTER" file on a fresh install may only fail with 1060 Duplicate column name
  // (its columns are already in schema.sql) — any other error still fails the test.
  if (status !== 0 && plainAlter && /ERROR 1060 \(42S21\)/.test(stderr) && !/ERROR (?!1060)\d+/.test(stderr)) {
    return '';
  }
  return status === 0 ? '' : `exit ${status}: ${stderr}`;
}

function loadFile(filePath) {
  const sql = fs.readFileSync(filePath, 'utf8');
  const error = runMysql(DB_NAME, sql, sql.includes('PLAIN ALTER (owner D-371'));
  if (error) throw new Error(`${path.basename(filePath)} failed to load: ${error}`);
}

function snapshot() {
  const { status, stdout, stderr } = run('mysqldump', [
    ...CREDENTIALS,
    '--skip-comments', '--skip-dump-date', '--order-by-primary', '--skip-extended-insert',
    '--routines', '--triggers', DB_NAME,
  ]);
  if (status !== 0) throw new Error(`mysqldump failed: ${stderr}`);
  // A migration may legitimately touch updated_at, and a guarded insert that finds its row
  // already there still moves a table's AUTO_INCREMENT counter; neither is content.
  return stdout
    .replace(/'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}'/g, "'TS'")
    .replace(/ AUTO_INCREMENT=\d+/g, '');
}

function tableCount() {
  const { status, stdout, stderr } = run('mysql', [...CREDENTIALS, '-N', '-B', '-e',
    `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${DB_NAME}' AND TABLE_TYPE = 'BASE TABLE'`]);
  if (status !== 0) throw new Error(`table count failed: ${stderr}`);
  return Number(stdout.trim());
}

/** First ~20 lines that differ, as "-" (fresh) / "+" (after migrations). */
function firstDifferences(fresh, migrated, limit = 20) {
  const a = fresh.split('\n');
  const b = migrated.split('\n');
  const inA = new Set(a);
  const inB = new Set(b);
  const out = [
    ...a.filter((l) => !inB.has(l)).map((l) => `- ${l}`),
    ...b.filter((l) => !inA.has(l)).map((l) => `+ ${l}`),
  ];
  if (out.length === 0) out.push('(same lines, different order or count)');
  const shown = out.slice(0, limit).map((l) => (l.length > 300 ? `${l.slice(0, 299)}…` : l));
  return shown.join('\n') + (out.length > limit ? `\n… and ${out.length - limit} more` : '');
}

describe('fresh install (schema.sql + seed.sql) already holds every migration', () => {
  beforeAll(() => {
    const error = runMysql('', `DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;`);
    if (error) throw new Error(`could not create ${DB_NAME}: ${error}`);
  });

  afterAll(() => {
    runMysql('', `DROP DATABASE IF EXISTS \`${DB_NAME}\`;`);
  });

  test('applying every docs/migrations file changes nothing on a fresh install', () => {
    expect(fs.existsSync(path.join(STACK_DOCS, 'schema.sql'))).toBe(true);
    expect(fs.existsSync(path.join(STACK_DOCS, 'seed.sql'))).toBe(true);
    expect(fs.existsSync(MIGRATIONS_DIR)).toBe(true);

    loadFile(path.join(STACK_DOCS, 'schema.sql'));
    loadFile(path.join(STACK_DOCS, 'seed.sql'));

    const fresh = snapshot();
    // Sanity: the snapshot holds every table of the fresh database.
    expect((fresh.match(/^CREATE TABLE /gm) || []).length).toBe(tableCount());

    const files = fs.readdirSync(MIGRATIONS_DIR).filter((f) => f.endsWith('.sql')).sort();
    files.forEach((f) => loadFile(path.join(MIGRATIONS_DIR, f)));

    const migrated = snapshot();
    if (fresh !== migrated) {
      throw new Error(
        'A migration in docs/migrations changes a fresh install (schema.sql + seed.sql), so schema.sql/seed.sql '
        + "are missing that change. Put it into both stacks' docs/schema.sql / docs/seed.sql.\n"
        + 'Lines only in the fresh install (-) / only after the migrations (+):\n'
        + firstDifferences(fresh, migrated),
      );
    }
    expect(migrated).toBe(fresh);
  });
});
