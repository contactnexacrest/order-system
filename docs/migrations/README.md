# Database migrations

## Go-live (first install): no migrations

The first live installation needs **no migration**. Load the stack's own two
files into an empty database, in this order:

```bash
mysql -u <user> -p -e "CREATE DATABASE nexacrest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u <user> -p nexacrest < <stack>/docs/schema.sql
mysql -u <user> -p nexacrest < <stack>/docs/seed.sql
```

A fresh install never needs a migration — the tests FreshInstallCompleteTest / freshInstallComplete.test.js check that every file in docs/migrations is already in schema.sql/seed.sql.

`<stack>` is `PHP_MySQL_Version` or `NODE_MySQL_Version`. Then follow the
stack README (storage path replacement in `assets`, `user_signature_assets`
and `reference_library_documents`, the reference-library files, the admin
password, `.env`, the scheduled jobs).

Before go-live, every change made during development (120 migration files,
8 Oct – 2 Nov 2026) was folded into `schema.sql` and `seed.sql` and the files
were removed from this folder (decision D-241; they remain in git history).
A database built from `schema.sql` + `seed.sql` was compared with a
development database that had every migration applied: same tables, columns,
indexes, foreign keys and the same reference data (roles, permissions,
settings, drop-down lists, document types, clauses, departments, rules).
The same was done again on 14 Nov 2026 (D-372) after the live site was
installed fresh: the 25 migration files of 11–13 Nov 2026 were removed.

## After go-live: every change ships as a migration

From the day the live database holds real data, a schema or configuration
change must be delivered in **two places in the same change**:

1. a new file here, named `YYYY-MM-DD<letter>-short-name.sql` (letters keep
   several files of one day in order), which brings an existing database up to
   date; and
2. the same change in **both** stacks' `docs/schema.sql` / `docs/seed.sql`, so a
   fresh install never needs the migration.

Run new files on the live database in filename order, after a backup:

```bash
mysql -u <user> -p <database> < docs/migrations/<file>.sql
```

### Files in this folder (run in this order)

**None yet.** The live site was installed fresh from `schema.sql` + `seed.sql` on
14 Nov 2026 (owner decision D-372). Those two files already hold every earlier
change, so the 25 files of 11–13 Nov 2026 (`2026-11-11a` … `2026-11-13g`) were
removed from this folder; git history keeps them (last commit with them:
`cca06df3`). Numbering starts again with the next change, e.g.
`2026-12-01a-short-name.sql`, with a row in the table below and a row in
`docs/checks/check_all_migrations.sql`.

| File | Owner decisions | What it does |
|---|---|---|

### Rules for every migration file (enforced by tests)

- Header comment: what changes, which owner decision (D-number), and that it is
  safe to run twice.
- First statement after the header: `SET NAMES utf8mb4;` — without it the
  mysql client can store non-ASCII text (—, ₹, curly quotes) double-encoded.
  (`MigrationFileConventionsTest`)
- **MySQL-compatible**: the live host runs MySQL, development runs MariaDB.
  Never `ADD/DROP/CHANGE/MODIFY COLUMN IF [NOT] EXISTS` or
  `CREATE INDEX IF NOT EXISTS`. Guard through `information_schema` +
  `PREPARE` instead (see the pattern below). (`MigrationFileConventionsTest`,
  `mysqlCompatibleDdl.test.js`)
- **Safe to run twice**: every statement guarded (`CREATE TABLE IF NOT EXISTS`,
  `INSERT … WHERE NOT EXISTS`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, or an
  information_schema check). (`MigrationIdempotencyTest` applies every file
  twice.)
- Queries must pass `ONLY_FULL_GROUP_BY`.
- Data the app needs (permissions, role grants, settings, drop-down options)
  goes in the migration **and** in `seed.sql`.

### Guarded column pattern

```sql
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE some_table ADD COLUMN new_column VARCHAR(30) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'some_table' AND COLUMN_NAME = 'new_column');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'new_permission', 'New permission', 'What it allows.', 'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'new_permission');
```

The same pattern works for indexes (`information_schema.STATISTICS`) and
foreign keys (`information_schema.TABLE_CONSTRAINTS`).

## Which migrations does the live site still need?

Run `docs/checks/check_all_migrations.sql` in phpMyAdmin (your database › SQL › paste › Go). It only reads, changes nothing, and shows one row per migration: **already done**, **NEEDED** or **PARTLY done – run it again**. Every new migration gets a row in it in the same commit (D-371).
