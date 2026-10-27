-- ================================================================
-- DB MIGRATION — 2026-10-15
-- Corresponds to: payment_presets.show_bl_instructions column
-- (90-item backlog #32 — admin-UI-controllable flag per payment
-- preset deciding whether the generated BL Instruction Sheet
-- includes the BL Type / BL Consignee Instruction rows)
-- Covers item: 32
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else.
--
-- SCHEMA change: adds one nullable-free column with a safe default,
-- so no backfill/data migration is required — every existing preset
-- (and therefore every existing order) gets show_bl_instructions = 1
-- (include), which reproduces the exact BL Instruction Sheet content
-- generated before this column existed. Admins can then uncheck the
-- new "Include BL Type / BL Consignee Instruction" checkbox on a
-- preset's edit screen for payment terms that don't need NexaCrest to
-- retain the BL as leverage (e.g. balance paid before shipment).
-- ================================================================


SET NAMES utf8mb4;
-- External QA report L-32: IF NOT EXISTS makes this safe to re-run.
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_presets' AND COLUMN_NAME = 'show_bl_instructions') = 0, 'ALTER TABLE payment_presets ADD COLUMN show_bl_instructions TINYINT(1) NOT NULL DEFAULT 1 AFTER requires_md_approval', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
