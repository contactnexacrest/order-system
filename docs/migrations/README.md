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
| `2026-11-12d-buyer-details-refresh-approval.sql` | D-337 | Buyer details on orders follow the client: new table `order_buyer_detail_requests` (requests to update an order that already has an issued document; PENDING / APPROVED / REFUSED / LAPSED); permission `approve_buyer_details_change` (MD, ED); Admin › Self-approval rules `buyer_details_change` (MD and ED; not re-added if an Admin changed the rule). No data change |
| `2026-11-12e-follow-ups-d338.sql` | D-338 | Reorder request lines "As per annexure": `order_reorder_request_products.as_per_annexure` (carried onto `order_products.as_per_annexure` when the request becomes an order). No data change. The other D-338 follow-ups (duplicate keeps Annexure A, one wording for an annexure line's missing size, no new "add up the cost parts" price, a worked-out CBM price goes to the MD, Pricing settings in Title Case) need no database change |
| `2026-11-12f-buyer-details-self-approval-md-ed.sql` | D-337 | Self-approval rule `buyer_details_change` gets the Executive Director next to the Managing Director (owner, 12 Nov 2026), for a database that ran `2026-11-12d` when it ticked the MD alone; not added if an Admin already changed the rule. No order data change. Checked 12 Nov 2026: applied twice to both development databases and on a fresh install (no change there). |
| `2026-11-12g-detailed-line-table.sql` | D-339 | The detailed product table on the Quotation, PI, OC, CI, Buyer PO and Packing List (Material / Colour, Surface / Finish, L W H, measure per piece, No. of pcs, measure of all pieces, price per unit, price per piece, total): `orders.line_table_layout` (`classic` / `detailed`, default `detailed`), `order_products.pieces_count` and `size_note`. Every order that exists when the column is added is set to `classic` (its documents print exactly as before) — only on the run that adds the column, so a second run never turns a new order back to classic. Checked 12 Nov 2026: applied twice to both development databases (all existing dev orders are now `classic`); scratch databases (old schema + seed + this file twice) equal a fresh schema + seed on both stacks (`mysqldump --no-data`). |
| `2026-11-12h-price-lock-and-products-amendment.sql` | D-342 | Prices and products fixed by the approved Quotation; the "Products & prices" amendment: `orders.prices_fixed_at`, `prices_fixed_by_document_id`, `pricing_fx_rate_at_qt`, `buyer_po_amended_by_amendment_id` / `_at` / `_by`; `order_products.qt_fixed_unit_price`; `amendments.amendment_type` (`payment_terms` default / `products`), `products_totals_json`, `activated_at`, `supplier_po_revision_needed`; new table `amendment_product_lines`; Procurement task kind `revise_supplier_po`. **Back-fill:** every order that already has an approved Quotation is fixed — `prices_fixed_at` = the first approved Quotation's last reviewer approval (else its generation time), the rate = `pricing_fx_rate_at_pi` when the order is past the PI, else the pricing rate in use when the file runs (Manual: the typed rate; Automatic: the newest rate in use × (1 − cushion)), and each active line's `qt_fixed_unit_price` = its unit price; from then on its lines change only through an amendment. Only orders not fixed yet are touched, so a second run changes nothing. Checked 12 Nov 2026: applied twice to both development databases; scratch databases (old schema + seed + this file twice) equal a fresh schema + seed on both stacks (`mysqldump --no-data`). |
| `2026-11-12i-monument-option-and-defaults.sql` | D-347 | Part 1: Natural Stone › Size / format option "Monument (cut to size)" (0 %, the base price applies). Part 2 (overwrites): `company_settings.email_testing_mode` = 1, `incentive_settings.program_enabled` = 1, `pricing_settings.other_cost_pct` = 5.50, `margin_pct` = 15.00 — the same as a fresh install (seed.sql). Checked 12 Nov 2026: applied twice to both development databases; fresh install has the option once. |
| `2026-11-12j-surface-finish-one-list.sql` | D-357 | One list of surface-finish names. New columns `spec_definitions.list_key` (`'surface_finish'` on the Surface finish specification only) and `spec_options.dropdown_option_id` (+ index `idx_spec_options_dropdown_option`). Merge: the base finish — Settings "Natural Polish", "Polished" or "Mirror Finish" (the live rename), else the default / sort-order-1 row when it is not another known finish name, else a new row — becomes "Natural Polish", the default, linked to the specification's base option (spec "Polished" → "Natural Polish"); Honed and Flamed by name; Settings "Natural/Sandblasted" → "Sandblasted" and "Leather/Satin" → "Leather" (left as they are when the new name already exists); Pricing options Settings lacks (e.g. "Brushed") are added to Settings with their on/off; every Settings row without an option (Rock-Pitched, Bush-Hammered, any value typed differently) gets one with no price change. Order 1–8: Natural Polish, Honed, Flamed, Sandblasted, Leather, Brushed, Rock-Pitched, Bush-Hammered; other values follow. Option names, order and on/off follow Settings; prices unchanged. `order_products.finish` "Polished" / "Mirror Finish" / "Natural/Sandblasted" / "Leather/Satin" → "Natural Polish" / "Natural Polish" / "Sandblasted" / "Leather" only on orders with `prices_fixed_at IS NULL` (Quotation not approved yet). Checked 12 Nov 2026: applied twice to both development databases; on a scratch copy with Settings "Mirror Finish", an extra Settings value and an extra Pricing option it merged as described; on a fresh schema + seed (both stacks) it changes nothing (dump identical). |
| `2026-11-13a-suppliers-products-configure.sql` | D-359 | Suppliers, products and the configured base price (Push 1). New columns `catalog_product_suppliers.offer_active` (default 1; an offer is used only when ON and its directory supplier is active); `catalog_products.configured_base_price_inr`, `configured_base_unit` ('m2' / 'CBM' / the product's unit), `configured_base_thickness_mm` (0 = 50 mm and above per CBM, NULL = per unit), `configured_base_follows_supplier` (default 1 = follows the usable supplier price "used for pricing", as before), `configured_at`, `configured_by`, `pricing_follow_global` (default 0); `product_categories.margin_pct`, `other_cost_pct`, `buffer_pct` (NULL = follow global). `pricing_settings` rows `weight_t_per_cbm` 2.70, `container_max_t` 27.00, `container_cbm` 8.00, `thick_k_factor` 40 (INSERT IGNORE). Backfill only where empty: the configured base price = the base-thickness price of the grid row used for pricing (its own base, else the category's base thickness option, else 20 mm), or the unit row's price (or the sum of its cost parts). The product's own other-costs value stays in `pricing_other_cost_mode` / `_value` (no new column). Checked 13 Nov 2026: applied twice to both development databases; every product's cost, minimum, staff lowest, staff quote and associate lowest identical before and after (base and every thickness option, 77 lines per stack); on a scratch copy of each database with the 13a columns and settings removed, the check script showed 13a NEEDED, then "already done" after running it (twice). |
| `2026-11-13b-products-fixed-base-and-link-legacy.sql` | D-361 | Products, Configure and the Supplier PO (Push 3). Data: every product with a base price gets `configured_base_follows_supplier` = 0 at exactly the price it uses today (the stored copy is first refreshed from the usable supplier price "used for pricing", as 13a) — no selling price changes; legacy supplier prices (`catalog_product_suppliers.supplier_id` NULL) linked where the typed name equals exactly one active supplier's legal name (trimmed, case ignored; a "Linked to supplier (migration 13b)" price-history row each); the rest go to Products › "Link these prices". New columns `catalog_products.legacy_no_supplier` (1 = existed before 13b with no linked supplier: active, quotable, "No supplier yet"; set on the first run only), `order_supplier_po.supplier_offer_id`, `quoted_unit_price_inr`, `price_override_reason`. Marker: Audit Log row `MIGRATION_2026_11_13B` with the counts (the read-only check looks for it). Guarded, re-runnable, MySQL-safe, ONLY_FULL_GROUP_BY-safe. Checked 13 Nov 2026: applied twice to both development databases; every product's cost, minimum, staff lowest, staff quote and associate lowest identical before and after (77 lines per stack, PHP = Node); on scratch copies the check showed 13b NEEDED, then "already done" (automatic linking checked there too). |
| `2026-11-13c-permission-descriptions.sql` | QA PLN-003/004/005 | Text only: the descriptions of three permissions on Users & roles › Roles and permissions — `record_market_prices` (also Edit price, base and thickness prices, linking prices, and with `manage_suppliers` the offer Active switch and "+ Add product"), `edit_order_post_confirmation` (product rows and prices are fixed from the first approved Quotation, D-342, and change only by a "Products & prices" amendment), `manage_compliance_task_types` (the checklist uses `manage_compliance_checklist`, D-226). No permission, grant or role changes. Plain UPDATEs by `permission_key`, re-runnable, MySQL-safe; the last line shows "3 of 3". Checked 13 Nov 2026: run twice on scratch databases of both stacks built from the previous seed; the texts then equal a fresh install's. |
| `2026-11-13d-expense-cost-bank-reference.sql` | QA ZOH-005 (owner decision 13 Nov 2026, Option A; rule D-212) | New empty columns `order_expenses.bank_reference`, `order_expenses.zoho_pushed_hash` and `order_cost_entries.bank_reference` (UTR / transaction ID, required on the forms when paid from the company account). No data change; on the next Zoho sync every order expense and order cost already in Books is updated once with the new description ("… · order expense paid from the company account · UTR …"), amounts, dates and accounts unchanged. Guarded through information_schema + PREPARE, re-runnable, MySQL-safe; the last line shows "3 of 3". Checked 13 Nov 2026: applied twice to scratch databases of both stacks built from the previous schema; the columns then equal a fresh install's. | **Plain ALTER (D-371):** a second run only says "Duplicate column name".
| `2026-11-13e-quantity-warning-levels.sql` | Owner decision item 10a (13 Nov 2026) | The six large-quantity levels per unit in `company_settings` (category `orders`, Settings › Orders): `quantity_warning_level_sqm` 30000, `_sqft` 300000, `_cbm` 500, `_ton` 600, `_pcs` 600000, `_rmt` 60000 (1 to 1,000,000). Above its unit's level one order line (create, add, edit) or Domestic Trade line saves only with "I confirm this quantity" ticked (audit `LARGE_QUANTITY_CONFIRMED`); above 1,000,000 in any unit it is refused (in the app, no table change). INSERT … WHERE NOT EXISTS: a level already changed is kept. Re-runnable, MySQL-safe; the last line shows "6 of 6". Checked 13 Nov 2026: run twice on scratch copies of both stacks' previous seed; the rows then equal a fresh install's. |
| `2026-11-13f-remember-office-computer-7-days.sql` | Owner decision D-369 (13 Nov 2026) | `login_remember_browser_days` 30 → 7 where still 30 (a changed value is kept) and its help text "default 7". Plain UPDATEs; safe to run twice. |
| `2026-11-13g-freeze-prices-at-pi.sql` | Owner decision D-370 (13 Nov 2026, "go"; QA HNT-L05, a gap in D-342) | No schema change. Orders that have an approved Proforma Invoice (status approved / sent / superseded) — or, failing a PI, an approved Order Confirmation, Buyer PO or Commercial Invoice — but `prices_fixed_at IS NULL` are fixed as the app now fixes them at the first approved PI: `prices_fixed_at` = that document's last reviewer approval (else its generation time), `prices_fixed_by_document_id` = that document, `pricing_fx_rate_at_qt` = `pricing_fx_rate_at_pi` when stored, else the pricing rate in use on that day (rate history minus the cushion; Manual: the typed rate), else the rate in use now; each active line's `qt_fixed_unit_price` = its unit price now. Run the read-only list below first. Only unfixed orders are touched (safe to run twice); no information_schema query, every statement names the database through `@db` (works in phpMyAdmin); ONLY_FULL_GROUP_BY-safe; the last line must say "0 still to fix". Checked 13 Nov 2026: on a scratch database (schema + seed + sample data, one sample order's PI set to sent with its Quotation a draft) the list showed 2 orders, the file fixed both (one by its PI, one by its sent Order Confirmation), run twice plain and twice after `USE information_schema`, the list was then empty; the scratch database was dropped. |

### Before `2026-11-13a`: two read-only checks

Run these on the live database before `2026-11-13a` and keep the output (they change
nothing). The go-live checklist (section H) says what to do with each row.

```sql
-- 1. Products priced from an INACTIVE supplier. From 13a on an offer is used only when it is
--    switched on AND its supplier is active, so these products show "The supplier price used
--    for pricing is switched off" until the supplier is reactivated or the product is
--    configured from an active supplier's price.
SELECT p.id, p.name AS product, s.supplier_legal_name AS supplier
  FROM catalog_product_suppliers cps
  JOIN catalog_products p ON p.id = cps.product_id
  JOIN suppliers s        ON s.id = cps.supplier_id
 WHERE cps.is_primary = 1 AND s.is_active = 0
 ORDER BY p.name;

-- 2. Active categories without their own stone weight or 50 mm+ factor. From 13a on they use
--    the global defaults in pricing_settings (weight_t_per_cbm 2.70, thick_k_factor 40).
SELECT c.id, c.name, c.weight_t_per_cbm, c.thick_k_factor,
       (SELECT COUNT(*) FROM catalog_products p WHERE p.category_id = c.id) AS products
  FROM product_categories c
 WHERE c.is_active = 1 AND (c.weight_t_per_cbm IS NULL OR c.thick_k_factor IS NULL)
 ORDER BY c.name;
```

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

The files from `2026-11-12f` on were checked as their rows above say. Decisions of 12–13 Nov 2026
that need no database change (D-340, D-341, D-343–D-346, D-348–D-356, D-358, D-360, D-362–D-365)
have no file here.

### Before `2026-11-13g`: one read-only list

Run this on the live database before `2026-11-13g` and show the output to the owner (it changes
nothing). Each row is an order that has an approved / sent Proforma Invoice (or a later document)
but whose prices are not fixed yet — `2026-11-13g` fixes them at that PI (D-370): its rate, each
line's price as it is now. An empty result means `2026-11-13g` changes nothing.

```sql
SELECT o.order_reference AS `Order no`, c.company_legal_name AS `Client`,
       COALESCE(sm.stage_name, '—') AS `Stage`,
       (SELECT CONCAT(dt.code, ' ', COALESCE(d.document_reference, CONCAT('#', d.id)), ' (', d.status, ')')
          FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
         WHERE d.order_id = o.id AND dt.code IN ('PI', 'OC', 'BUYERPO', 'CI')
           AND d.status IN ('approved', 'sent', 'superseded')
         ORDER BY (dt.code = 'PI') DESC, d.id LIMIT 1) AS `PI number`,
       (SELECT COUNT(*) FROM order_products op WHERE op.order_id = o.id AND op.is_active = 1) AS `Lines`
  FROM orders o
  JOIN clients c ON c.id = o.client_id
  LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
 WHERE o.prices_fixed_at IS NULL
   AND EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
                WHERE d.order_id = o.id AND dt.code IN ('PI', 'OC', 'BUYERPO', 'CI')
                  AND d.status IN ('approved', 'sent', 'superseded'))
 ORDER BY o.id;
```

"PI number" shows the document that will fix the order (a PI when there is one, e.g.
`PI SC/PI/26/1311002 (sent)`; else the first approved later document, e.g. an Order Confirmation).

The live-site order of work (backup, files `2026-11-11a` … `2026-11-13g`, the two checks above and the list before `2026-11-13g`,
the exchange-rate cron job at 07:05 IST, settings to check) is in
`docs/guides/GO_LIVE_CHECKLIST.md`, section "Upgrade of 11 Nov 2026" (it covers the builds of
11, 12 and 13 Nov 2026).

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
