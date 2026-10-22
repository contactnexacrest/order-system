-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql order_packing.actual_quantity_unit column
-- Covers: Phase 2 item 13 — "Packing Actual Quantity needs unit
-- dropdown / default to PI's unit"
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds order_packing.actual_quantity_unit (nullable VARCHAR), from the
-- same admin-managed dropdown_options('unit') list product lines
-- already use. The Packing form already implicitly assumed this figure
-- shared the order's own product unit (the shortfall-tolerance message
-- already quoted that unit) — this makes the assumption explicit,
-- editable, and shown on the Packing List document, which previously
-- printed the bare number with no unit at all.
--
-- NULL on every existing order (no data migrated — no such value
-- existed before). Idempotent (safe to re-run).
-- ================================================================


SET NAMES utf8mb4;
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_packing' AND COLUMN_NAME = 'actual_quantity_unit'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE order_packing ADD COLUMN actual_quantity_unit VARCHAR(50) NULL AFTER actual_quantity_packed',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
