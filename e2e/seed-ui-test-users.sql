-- External QA report TI-10: the 6 ui-test-* role accounts tests/
-- support/auth.js's ROLES map expects (every role except the two real
-- seeded people, admin/executiveDirector, which already exist in
-- docs/seed.sql) were created by hand, directly in each dev DB, with no
-- committed script and no README mention — so a fresh clone's e2e run
-- failed at login for 6 of 8 roles with no clue why. Idempotent (an
-- INSERT ... WHERE NOT EXISTS per row), so safe to re-run.
--
-- Run this ONCE against each stack's dev database, after schema.sql +
-- seed.sql (see e2e/README.md "Prerequisites"):
--   mysql -u nexacrest_app -p<password> <db_name> < e2e/seed-ui-test-users.sql
--
-- All 6 share tests/support/auth.js's PASSWORD constant
-- ('PlaywrightTest#2026') — never a real password, dev-only test
-- accounts. The two hashes below are of that same string; use whichever
-- one matches the stack you're seeding (PHP's password_hash() produces
-- $2y$, Node's bcrypt package produces $2b$ — see e2e/README.md on why
-- they aren't interchangeable). Regenerate either with:
--   php -r "echo password_hash('PlaywrightTest#2026', PASSWORD_BCRYPT, ['cost'=>12]);"
--   node -e "console.log(require('bcrypt').hashSync('PlaywrightTest#2026', 12))"

SET NAMES utf8mb4;

-- PHP stack hash. For the Node stack, replace every occurrence of this
-- value below with: $2b$12$Tb2UC0wLHBzKY7PXdnVqoOMhROikcCYJI4ss9LJ0SUyaNuceb9SHy
SET @pw = '$2y$12$O/WmqLt5R2BPvkZl5i5voeamH6FYjQgINUFIQl7Kv.WGZPMcwh.p6';

INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'UI Test Admin', 'ui-test-admin@nexacrest.test', NULL, @pw, r.id, 1, 0, 0
FROM roles r WHERE r.name = 'Admin'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'ui-test-admin@nexacrest.test');

INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'UI Test Export Exec', 'ui-test-export-exec@nexacrest.test', NULL, @pw, r.id, 1, 0, 0
FROM roles r WHERE r.name = 'Export Executive'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'ui-test-export-exec@nexacrest.test');

INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'UI Test Accounts Exec', 'ui-test-accounts-exec@nexacrest.test', NULL, @pw, r.id, 1, 0, 0
FROM roles r WHERE r.name = 'Accounts Executive'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'ui-test-accounts-exec@nexacrest.test');

INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'UI Test Logistics Exec', 'ui-test-logistics-exec@nexacrest.test', NULL, @pw, r.id, 1, 0, 0
FROM roles r WHERE r.name = 'Logistics Executive'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'ui-test-logistics-exec@nexacrest.test');

INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'UI Test Viewer Auditor', 'ui-test-viewer@nexacrest.test', NULL, @pw, r.id, 1, 0, 0
FROM roles r WHERE r.name = 'Viewer / Auditor'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'ui-test-viewer@nexacrest.test');

INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'UI Test CA', 'ui-test-ca@nexacrest.test', NULL, @pw, r.id, 1, 0, 0
FROM roles r WHERE r.name = 'CA / Chartered Accountant'
  AND NOT EXISTS (SELECT 1 FROM users WHERE email = 'ui-test-ca@nexacrest.test');
