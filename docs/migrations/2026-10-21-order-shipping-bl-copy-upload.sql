-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql order_shipping.bl_copy_file_id column +
-- its file_store foreign key
-- Covers: Phase 2 item 15 — "Upload BL copy + record BL number once
-- issued by shipping company, lean UI"
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds order_shipping.bl_copy_file_id (nullable, FK to file_store) —
-- the actual scanned Bill of Lading, uploaded alongside the existing
-- BL Number/BL Date fields on the "Confirm BL Issued" form. Previously
-- staff could only type in the BL number/date; there was no way to
-- attach the shipping line's actual scanned BL document anywhere.
-- Distinct from the existing, unrelated draft_bl_file_id column (an
-- earlier pre-issuance draft-for-review step, a different step in the
-- real-world BL process, left untouched).
--
-- NULL on every existing order (no data migrated — no such value
-- existed before). Idempotent (safe to re-run).
-- ================================================================


SET NAMES utf8mb4;
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_shipping' AND COLUMN_NAME = 'bl_copy_file_id'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE order_shipping ADD COLUMN bl_copy_file_id BIGINT UNSIGNED NULL AFTER bl_date',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_shipping' AND CONSTRAINT_NAME = 'fk_os_bl_copy'
);
SET @fk_ddl = IF(@fk_exists = 0,
  'ALTER TABLE order_shipping ADD CONSTRAINT fk_os_bl_copy FOREIGN KEY (bl_copy_file_id) REFERENCES file_store(id)',
  'SELECT 1');
PREPARE fk_stmt FROM @fk_ddl;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
