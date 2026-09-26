'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const dotenv = require('dotenv');
const bcrypt = require('bcrypt');

// Host/user/password come from the real app/.env — never duplicated or
// hardcoded here. Only the database name is test-specific.
const realEnv = dotenv.parse(fs.readFileSync(path.join(__dirname, '..', '..', '.env')));
const DB_HOST = realEnv.DB_HOST || '127.0.0.1';
const DB_PORT = realEnv.DB_PORT || '3306';
const DB_USER = realEnv.DB_USERNAME;
const DB_PASSWORD = realEnv.DB_PASSWORD || '';

const DB_NAME = 'nexacrest_node_jest';

// Known credentials for integration tests that need a logged-in session.
// The plaintext lives only here and in tests that import it — never in the
// disposable DB's dump, never reused for any real account.
const TEST_ADMIN_EMAIL = 'jest-admin@nexacrest.test';
const TEST_ADMIN_PASSWORD = 'JestTest123!';

function runSqlFile(database, filePath) {
  // schema.sql declares trigger bodies with `DELIMITER $$`, which only the
  // mysql CLI understands (not a real SQL statement mysql2 can execute) —
  // so shell out to the CLI, exactly like loading it by hand.
  execFileSync('mysql', ['-h', DB_HOST, '-P', DB_PORT, '-u', DB_USER, `-p${DB_PASSWORD}`, database], {
    stdio: ['pipe', 'pipe', 'pipe'],
    input: fs.readFileSync(filePath),
  });
}

function runSql(database, sql) {
  execFileSync('mysql', ['-h', DB_HOST, '-P', DB_PORT, '-u', DB_USER, `-p${DB_PASSWORD}`, database], {
    stdio: ['pipe', 'pipe', 'pipe'],
    input: sql,
  });
}

module.exports = async function globalSetup() {
  // Fully disposable: rebuilt from scratch on every test run, never reused
  // across runs, and never the same database as nexacrest_node_test (the
  // interactively-used dev/demo DB with real sample data).
  runSql('', `DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;`);

  const docsDir = path.join(__dirname, '..', '..', '..', 'docs');
  runSqlFile(DB_NAME, path.join(docsDir, 'schema.sql'));
  runSqlFile(DB_NAME, path.join(docsDir, 'seed.sql'));

  const passwordHash = await bcrypt.hash(TEST_ADMIN_PASSWORD, 10);
  const insertSqlPath = path.join(os.tmpdir(), `jest-test-admin-${process.pid}.sql`);
  fs.writeFileSync(
    insertSqlPath,
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     SELECT 'Jest Test Admin', '${TEST_ADMIN_EMAIL}', NULL, '${passwordHash}', r.id, 1, 0, 0
     FROM roles r WHERE r.name = 'Admin' LIMIT 1;`
  );
  try {
    runSqlFile(DB_NAME, insertSqlPath);
  } finally {
    fs.unlinkSync(insertSqlPath);
  }
};

module.exports.DB_NAME = DB_NAME;
module.exports.TEST_ADMIN_EMAIL = TEST_ADMIN_EMAIL;
module.exports.TEST_ADMIN_PASSWORD = TEST_ADMIN_PASSWORD;
