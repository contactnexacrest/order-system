-- ================================================================
-- DB MIGRATION — 2026-10-15
-- Corresponds to: docs/seed.sql company_settings.show_generated_document_disclaimer
-- default change (90-item backlog #21)
-- Covers item: 21
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else.
--
-- Pure DATA change — no ALTER TABLE, no new column. The "system-
-- generated document" disclaimer under the signature block now
-- defaults to OFF on a fresh install (seed.sql), not ON. This
-- statement brings an already-seeded database in line with that new
-- default. If an admin has already deliberately turned this setting
-- back ON via the Settings screen since install, re-running this
-- migration would silently undo that choice — check the current value
-- first if this is being run against a database that has been live
-- for a while.
-- ================================================================


SET NAMES utf8mb4;
UPDATE company_settings
SET setting_value = '0'
WHERE setting_key = 'show_generated_document_disclaimer' AND setting_value = '1';
