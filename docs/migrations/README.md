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

| File | Owner decisions | What it does |
|---|---|---|
| `2026-11-11a-quote-any-unit-piece-size.sql` | D-270–D-273 | Order-line piece size (L × W × T cm), "copy of line N", weight per CBM, units TON and RMT, Natural Stone line units |
| `2026-11-11b-email-settings-sessions.sql` | D-280–D-283 | `email_settings`, `compliance_alert_owners`; `mail_sending_enabled` → `email_testing_mode`; `session_timeout_minutes` → three idle timeouts + staff 12-hour limit; remembered office computer 30 days; buyer login-code e-mail; invite gives the login page |
| `2026-11-11c-associates-kyc-commission-bank.sql` | D-290–D-294 | KYC document type and address-proof grace (60 days), own ladder, commission-rule permission, payout day "last day of the month", bank-details confirmation (associates and suppliers), "Bank details" self-approval rule |
| `2026-11-11d-thickness-prices-fx-hs-suppliers.sql` | D-300–D-306 | Thickness price grid, CHA + transport per container, price rules, `fx_rates` and the 2 % cushion, supplier prices linked to the Suppliers directory; converts existing prices |
| `2026-11-11e-approvals-setup-defaults.sql` | D-310, D-312, D-313 | `short_payment_requests`, `setup_checklist_ticks`, document clean-up and password-expiry defaults (first run only), Test Mode e-mail, national holidays |
| `2026-11-11f-plain-language-email-templates.sql` | D-330 | Plain-language buyer e-mail texts (only templates still at the seeded text) |
| `2026-11-11g-order-comment-email-status.sql` | D-268 | `order_comments.email_status` |
| `2026-11-11h-tt-clause-bank-name.sql` | D-331 | T/T definition clause: fixed "State Bank of India" → `{bank_name}`, filled from Settings › Company › Bank name (owner confirmed twice; prints as before with today's bank) |
| `2026-11-12a-settings-plain-labels.sql` | D-332 | Settings › Company in plain words: 60 setting descriptions rewritten as one plain sentence (only rows still at the old seeded text; an empty protected setting is skipped — the database refuses changes to it, and the screen shows the plain sentence anyway). Keys and values unchanged |
| `2026-11-12b-supplier-prices-base-and-calculated.sql` | D-335 | Supplier prices: `catalog_supplier_thickness_prices` gains `how` (typed / calculated), `calc_from_mm`, `quoted_on`; `catalog_product_suppliers` gains `base_thickness_mm` (the BASE for orders; 0 = 50 mm and above per CBM) and `auto_fill` (1 = older rows still work out missing thicknesses on the fly). One time: grid rows get their base (50 mm+ for a product sold per CBM with a typed per-CBM price, else 20 mm); "add up the cost parts" rows become typed prices with the same total. No price changes |
| `2026-11-12c-annexure-b-as-per-annexure.sql` | D-336 | Order lines "As per annexure" (`order_products.as_per_annexure`); Annexure B (as shipped): `which` ('A' / 'B') on `order_annexure_products` and `order_annexure_terms` (UNIQUE(order_id) becomes UNIQUE(order_id, which) — new key added first, old one dropped); `orders.include_annexure_b`, `annexure_b_mode`, `ci_annexure` (A / B / NONE, NULL = default). No data change: existing rows stay Annexure A, documents print as before |
| `2026-11-12d-buyer-details-refresh-approval.sql` | D-337 | Buyer details on orders follow the client: new table `order_buyer_detail_requests` (requests to update an order that already has an issued document; PENDING / APPROVED / REFUSED / LAPSED); permission `approve_buyer_details_change` (MD, ED); Admin › Self-approval rules `buyer_details_change` (MD only; not re-added if an Admin changed the rule). No data change |
| `2026-11-12e-follow-ups-d338.sql` | D-338 | Reorder request lines "As per annexure": `order_reorder_request_products.as_per_annexure` (carried onto `order_products.as_per_annexure` when the request becomes an order). No data change. The other D-338 follow-ups (duplicate keeps Annexure A, one wording for an annexure line's missing size, no new "add up the cost parts" price, a worked-out CBM price goes to the MD, Pricing settings in Title Case) need no database change |

Each file was checked on 11 Nov 2026 on a scratch copy of the database as it was
before the 11 Nov build (schema.sql + seed.sql of the last pushed version): all
seven files applied twice without an error, under `ONLY_FULL_GROUP_BY`, and the
result had exactly the tables, columns, keys, settings, permissions and e-mail
texts of the new `schema.sql` + `seed.sql`. The only differences are on purpose:
the company's own identity, bank, GSTIN, LUT and RCMC values are kept (a fresh
install gets placeholders), and `email_testing_mode` is on where
`mail_sending_enabled` was 0.

`2026-11-12a` was checked on 12 Nov 2026 the same way: a scratch database from the
`schema.sql` + `seed.sql` of the last commit, the file applied twice without an error;
its descriptions then match the new `seed.sql` except the empty protected Zoho keys
(skipped on purpose, see the file header). Applied twice to both development databases.

`2026-11-12b` was checked on 12 Nov 2026: applied twice without an error to both development
databases, and a scratch database from `schema.sql` + `seed.sql` plus every migration equals a
fresh install (FreshInstallCompleteTest / freshInstallComplete.test.js).

`2026-11-12c` was checked on 12 Nov 2026: applied twice without an error to both development
databases and to a scratch database built from the previous `schema.sql` + `seed.sql`; its structure
then equals a fresh install from the new `schema.sql` + `seed.sql` (mysqldump --no-data compared).

`2026-11-12d` was checked on 12 Nov 2026: applied twice without an error to both development
databases and to a scratch database built from the previous `schema.sql` + `seed.sql`; its structure
then equals a fresh install from the new `schema.sql` + `seed.sql` (mysqldump --no-data compared), and
so do the seeded permissions, role grants and self-approval rules.

`2026-11-12e` was checked on 12 Nov 2026: applied twice without an error to both development
databases, and a scratch database from the previous `schema.sql` + `seed.sql` (commit 7487150f) with
the file applied twice equals a fresh install from the new `schema.sql` + `seed.sql` on both stacks
(mysqldump --no-data compared); the scratch databases were dropped.

The live-site order of work (backup, files, cron job, settings to check) is in
`docs/guides/GO_LIVE_CHECKLIST.md`, section "Upgrade of 11 Nov 2026".

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
