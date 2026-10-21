-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql order_shipping.container_count column
-- Covers: Phase 1 item 8 — "1-container vs 2-container / multi-container
-- handling" (user chose "add a container-count field" when asked how
-- this should work)
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds order_shipping.container_count (nullable INT) — how many
-- containers this shipment uses, recorded alongside the existing
-- container_type/container_no fields on the Stage 7 Shipping Details
-- form. Distinct from container_no, which stays a free-text field that
-- may already list more than one container number — this is the first
-- place a shipment's container COUNT is captured as its own structured
-- value, shown on the BL Instruction Sheet and Commercial Invoice.
--
-- NULL on every existing order (no data migrated — no such value
-- existed before). Idempotent (safe to re-run).
-- ================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_shipping' AND COLUMN_NAME = 'container_count'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE order_shipping ADD COLUMN container_count INT UNSIGNED NULL AFTER container_type',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
