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

Since the fresh install: the files in the table below. The live site was installed fresh from `schema.sql` + `seed.sql` on
14 Nov 2026 (owner decision D-372). Those two files already hold every earlier
change, so the 25 files of 11–13 Nov 2026 (`2026-11-11a` … `2026-11-13g`) were
removed from this folder; git history keeps them (last commit with them:
`cca06df3`). Numbering starts again with the next change, e.g.
`2026-12-01a-short-name.sql`, with a row in the table below and a row in
`docs/checks/check_all_migrations.sql`.

| File | Owner decisions | What it does |
|---|---|---|
| `2026-11-14a-line-priced-rate.sql` | D-374 | New empty columns `order_products.priced_fx_rate` and `priced_at`: the pricing rate (₹ per USD) and the time a line's price was set, until the approved Quotation fixes the prices. **Plain ALTER (D-371):** a second run only says "Duplicate column name". Existing lines show "Rate not recorded". |
| `2026-11-15a-sales-targets.sql` | D-378 | Sales targets: new tables `sales_target_settings` (with the default settings), `sales_target_company`, `sales_targets`, `sales_target_credits`; new empty columns `users.joined_on`, `orders.lost_category`, `orders.sales_credit_user_id`; permission "Manage sales targets" for the MD and the ED. **Plain ALTER (D-371):** the column ALTERs come last; a second run only says "Duplicate column name". Order data is only read. |
| `2026-11-15b-report-access.sql` | D-379 | Report access per role: new tables `role_report_access` (the level of each role for each report: Off / Own / Department / All; no row = Off) and `user_report_extras` (a person's extra level for one report, with the reason); the default levels of every role; permission "Manage report access" for the MD and the ED. No column is added (no ALTER); every statement is guarded (`CREATE TABLE IF NOT EXISTS`, `INSERT … WHERE NOT EXISTS`), so a second run changes nothing and a level already set is never overwritten. Order data is only read. |
| `2026-11-18a-product-forms-and-hs.sql` | D-382, D-383, D-384, D-385, D-389 | Forms of stone and the specification list that sets an order line's HS code: new tables `product_forms` (8 forms with their units, starting unit, group and default finish), `hs_specifications` (10 specifications with their HS codes), `product_hs_specifications` (a product's "Sold as" ticks, mapped once from today's `catalog_products.forms`; none = every specification) and `surface_finish_forms` (which finishes a form offers); finishes "Roughly trimmed" and "Sawn" in Settings › Lists with their Surface finish options at no price change; HS 68022310 described "Granite blocks or tiles"; permission "Change a line's HS code from its specification, with a reason" for the MD, Founder & MD, ED and Founder & ED; "Monument (cut to size)" first in every Size / format; `order_products.hs_code` default `68029300` and new empty columns `hs_spec_id`, `hs_override_reason`. **Plain ALTER (D-371):** the column ALTERs come last; a second run only says "Duplicate column name". Lines already saved keep their HS code. |
| `2026-11-18b-cha-per-supplier.sql` | D-381, D-382 | CHA + transport per container per supplier: new empty columns `suppliers.cha_transport_per_container` (₹, factory → port) and `suppliers.cha_port`; a supplier-price row without its own figure uses its supplier's, else the product default; an older "computed" price never gets the CHA added. New empty columns `catalog_product_suppliers.entered_unit` / `entered_price` keep a block price typed per tonne (stored per CBM as before). **Plain ALTER (D-371):** a second run only says "Duplicate column name". No existing price changes until a supplier figure is entered. |
| `2026-11-18c-documents-bl-and-po.sql` | D-380, D-387 | Bill of Lading wording: the live master T&C clause 95 starts "The full set of original negotiable Bills of Lading, as issued by the shipping line, will be released to the buyer only after" (rest unchanged; documents already issued keep their text); the reference-library checklists say "full set of originals", "the balance" (no fixed 60 %) and HS code 68029300 (`REPLACE()` on the exact old text — a second run changes nothing). New empty column `order_supplier_po.product_form` (Supplier PO Section 3 "Product Form"). **Plain ALTER (D-371):** the column ALTER comes last; a second run only says "Duplicate column name". |
| `2026-11-20a-quick-quote-prospects.sql` | D-394 | Quick-quote prospects: new client columns `is_prospect` (0 for every existing client), `prospect_expires_on`, `prospect_reminded_on`, `prospect_expired_on`, `prospect_expired_reason`; settings `prospect_expiry_days` (60), `prospect_reminder_days` (7) and `prospect_auto_close` (On) in Settings › Quick-quote prospects; the Sales task "Quotation expiring — no reply from the prospect". **Plain ALTER (D-371):** the five columns are one ALTER that comes last; a second run only says "Duplicate column name". Nothing is deleted; no document changes. After running it, add the daily job `prospect_expiry.php` (PHP) — the Node app runs it itself (README, scheduled jobs). |
| `2026-11-20b-merchant-trade-executive-role.sql` | D-399 | New role **Merchant Trade Executive** (a whole Merchant Trade deal: buyer and supplier side, purchase prices, payments): the permissions, report access and SOP chapters of Sales/Export Manager except its two approvals (`approve_payment_terms`, `approve_documents`) plus `manage_purchasing` and `view_purchase_prices`, and SOP chapter 31. No other role changes, no user moved. All INSERTs guarded with WHERE NOT EXISTS; a second run changes nothing. |
| `2026-11-21a-marine-insurance.sql` | D-402 | Marine insurance on CIF orders: new product-category columns `insurance_rate_pct` and `insurance_cover_wording`; the system category (Natural Stone) gets 0.30 % and "ICC (A) incl. breakage", other categories stay empty (default rate). Six settings in Admin › Global pricing settings › Marine Insurance (cover 110 %, default rate 0.20 %, cover wording, minimum premium, rounding, GST), INSERT IGNORE. **Plain ALTER (D-371):** settings INSERT IGNORE first, then the ALTER, then the UPDATE; a second run only says "Duplicate column name". Existing orders keep their saved insurance; documents print the new lines (D-403, D-404) from the next generation. |
| `2026-11-22a-insurance-basis.sql` | D-405 | New column `orders.insurance_basis` (what the CIF insurance was worked out from). The insurance of a CIF order is recalculated whenever the products or the freight change, until the actual premium is billed. **Plain ALTER (D-371):** a second run only says "Duplicate column name". Existing orders keep their figures until their products or freight next change. |
| `2026-11-24a-associate-rules.sql` | D-410 | New associate commission rules: settings `associate_group_days` (15) and `associate_rules_from` (the day it runs — orders with an earlier Proforma Invoice keep the old rule), `associate_price_rule` = fixed and `associate_price_fixed_pct` = 5.00; new columns `associates.price_rule_override`, `associates.price_rule_fixed_pct`, `orders.associate_included_pct`. **Plain ALTER (D-371):** a second run only says "Duplicate column name". Staff prices and prices for clients without an associate do not change. |

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
