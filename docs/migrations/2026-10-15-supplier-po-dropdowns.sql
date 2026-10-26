-- ================================================================
-- DB MIGRATION — 2026-10-15
-- Corresponds to: seed.sql dropdown_options changes (90-item backlog
-- #51a — Supplier PO Surface Finish/GST Rate dropdowns + Supplier Type
-- additions; the Unit dropdown and auto-calculation parts of #51a need
-- no DB change: Unit was already covered by the 2026-10-15 Unit/Package
-- Type migration, and auto-calc is pure client-side JS)
-- Covers item: 51a
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
-- Surface Finish and GST Rate were plain free-text <input>s on the
-- Supplier PO form; both now read from the existing dropdown_options
-- table via the existing manage_dropdown_options admin screen, same
-- pattern as Container Type/Unit/Package Type.
--
-- Three parts, each idempotent (safe to re-run):
--  1. Add new 'Factory Owner' supplier_type option (new row).
--  2. Rename the existing 'Processor' supplier_type option to
--     'Processor (Job-Work Basis)' — this is a genuine wording CHANGE to
--     an existing option (not an add), done because "Processor" alone
--     read as ambiguous once "Factory Owner" exists alongside it (both
--     could be misread as "owns the processing facility"). Any supplier
--     row that already recorded the old literal text 'Processor' is
--     updated to the new text in the same step, so no existing supplier
--     record is left referencing a label that no longer appears in the
--     dropdown (the options table has no FK to `suppliers`, so without
--     this second UPDATE such a row would silently go out of sync with
--     the option list — same risk the "never delete, only deactivate"
--     convention in docs/SOP/15-admin-settings.md exists to avoid).
--  3. Add the new 'surface_finish' and 'gst_rate' option lists (9 rows).
-- ================================================================

-- --- Part 1: new Factory Owner option ---------------------------------

SET NAMES utf8mb4;
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'supplier_type' AS list_key, 'Factory Owner' AS option_value, 4 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'supplier_type' AND option_value = 'Factory Owner');

-- --- Part 2: clarify "Processor" (rename option + any existing supplier rows) ---
UPDATE dropdown_options
SET option_value = 'Processor (Job-Work Basis)'
WHERE list_key = 'supplier_type' AND option_value = 'Processor';

-- Guarded: 2026-10-26-suppliers-directory.sql later moves supplier types
-- into supplier_type_links and drops suppliers.supplier_type.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'supplier_type');
SET @sql := IF(@c = 1, 'UPDATE suppliers SET supplier_type = ''Processor (Job-Work Basis)'' WHERE supplier_type = ''Processor''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- Part 3: new surface_finish / gst_rate option lists ---------------
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Polished' AS option_value, 1 AS sort_order, 1 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Polished');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Honed' AS option_value, 2 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Honed');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Flamed' AS option_value, 3 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Flamed');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Natural/Sandblasted' AS option_value, 4 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Natural/Sandblasted');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Rock-Pitched' AS option_value, 5 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Rock-Pitched');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Bush-Hammered' AS option_value, 6 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Bush-Hammered');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'surface_finish' AS list_key, 'Leather/Satin' AS option_value, 7 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'surface_finish' AND option_value = 'Leather/Satin');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'gst_rate' AS list_key, '0' AS option_value, 1 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'gst_rate' AND option_value = '0');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'gst_rate' AS list_key, '5' AS option_value, 2 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'gst_rate' AND option_value = '5');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'gst_rate' AS list_key, '12' AS option_value, 3 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'gst_rate' AND option_value = '12');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'gst_rate' AS list_key, '18' AS option_value, 4 AS sort_order, 1 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'gst_rate' AND option_value = '18');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'gst_rate' AS list_key, '28' AS option_value, 5 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'gst_rate' AND option_value = '28');
