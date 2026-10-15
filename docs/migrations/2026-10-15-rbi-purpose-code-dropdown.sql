-- ================================================================
-- DB MIGRATION — 2026-10-15
-- Corresponds to: seed.sql dropdown_options addition (90-item backlog
-- #31 — RBI Purpose Code fields on the Settings page)
-- Covers item: 31
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
-- company_settings.rbi_purpose_code_advance/balance/freight were plain
-- free-text settings; they now read from the existing dropdown_options
-- table (list_key='rbi_purpose_code'), special-cased by setting_key in
-- the Settings view rather than adding a generic select-type setting
-- field (see settings/index.php's own comment). The 3 values below are
-- exactly the codes each setting already defaulted to in seed.sql
-- (P0103/P0102/P0602) — deliberately not inventing further RBI/FEMA
-- purpose codes here; see the seed.sql comment for why. Nothing in
-- company_settings itself changes — the existing setting_value for each
-- of the 3 keys is untouched and stays selectable in the new dropdown
-- regardless of whether it matches one of these 3 seeded options.
-- ================================================================

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'rbi_purpose_code' AS list_key, 'P0102' AS option_value, 1 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'rbi_purpose_code' AND option_value = 'P0102');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'rbi_purpose_code' AS list_key, 'P0103' AS option_value, 2 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'rbi_purpose_code' AND option_value = 'P0103');

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT * FROM (SELECT 'rbi_purpose_code' AS list_key, 'P0602' AS option_value, 3 AS sort_order, 0 AS is_default, 1 AS is_active) AS t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'rbi_purpose_code' AND option_value = 'P0602');
