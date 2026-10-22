-- ================================================================
-- DB MIGRATION — 2026-10-15
-- Corresponds to: seed.sql dropdown_options additions (90-item backlog
-- #43 / #66, batched together — "Unit" and "Package Type" dropdowns)
-- Covers items: 43 (product-line Unit field), 66 (Estimated Package
-- Type field)
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else.
--
-- Pure DATA change — no ALTER TABLE, no new table, no new permission.
-- The `dropdown_options` table and its admin CRUD screen
-- (/admin/dropdown-options, gated on the EXISTING manage_dropdown_options
-- permission) already existed; the defect was that Unit and Package Type
-- were plain free-text <input> fields instead of <select> dropdowns fed
-- from this table, so there were no option rows for either list_key yet.
-- This migration adds exactly the 14 rows seed.sql now ships for a fresh
-- install. Guarded with `WHERE NOT EXISTS` per-row so it is safe to run
-- more than once (re-running is a no-op once the rows exist, instead of
-- erroring on duplicate key as a single plain INSERT would — there's no
-- unique constraint on (list_key, option_value) to lean on instead).
-- ================================================================


SET NAMES utf8mb4;
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'PCS' AS option_value, 1 AS sort_order, 1 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'PCS');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'SQM' AS option_value, 2 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'SQM');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'SET' AS option_value, 3 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'SET');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'BOX' AS option_value, 4 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'BOX');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'SQFT' AS option_value, 5 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'SQFT');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'MT' AS option_value, 6 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'MT');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'KG' AS option_value, 7 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'KG');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'NOS' AS option_value, 8 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'NOS');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'unit' AS list_key, 'CBM' AS option_value, 9 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'unit' AND option_value = 'CBM');

-- Package Type default is 'Wooden Crates' — the exact literal PHP/Node
-- previously hard-defaulted this free-text field to, so an order's
-- package type is byte-identical to before this change until an admin
-- actually edits the list.
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'package_type' AS list_key, 'Wooden Crates' AS option_value, 1 AS sort_order, 1 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'package_type' AND option_value = 'Wooden Crates');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'package_type' AS list_key, 'Wooden Pallets' AS option_value, 2 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'package_type' AND option_value = 'Wooden Pallets');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'package_type' AS list_key, 'Cartons' AS option_value, 3 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'package_type' AND option_value = 'Cartons');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'package_type' AS list_key, 'Bundles' AS option_value, 4 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'package_type' AND option_value = 'Bundles');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'package_type' AS list_key, 'Loose (Break Bulk)' AS option_value, 5 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'package_type' AND option_value = 'Loose (Break Bulk)');
