-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: seed.sql order_ref_format change (90-item backlog
-- "order-ID prefix change to SC/ORD/{YYYY}/{NNN}")
-- Covers item: order-ID prefix change to SC/ORD/{YYYY}/{NNN}
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Pure DATA change — no ALTER TABLE, no new table.
-- Changes the order_ref_format company_settings row from the old
-- placeholder default 'SC/OC/{YYYY}/{NNN}' to 'SC/ORD/{YYYY}/{NNN}'.
-- Every order already created keeps its existing order_reference
-- unchanged — this only changes the format used for orders created
-- AFTER this migration runs.
--
-- Guarded to only touch the row if it still holds the original
-- placeholder value — if an admin has already customized
-- order_ref_format to something else (via the Settings screen's
-- unlock-and-edit flow), that customization is left untouched.
--
-- Idempotent (safe to re-run).
-- ================================================================


SET NAMES utf8mb4;
UPDATE company_settings
SET setting_value = 'SC/ORD/{YYYY}/{NNN}'
WHERE setting_key = 'order_ref_format'
  AND setting_value = 'SC/OC/{YYYY}/{NNN}';
