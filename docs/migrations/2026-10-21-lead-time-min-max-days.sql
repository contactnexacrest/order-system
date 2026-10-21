-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql orders.lead_time_min_days/lead_time_max_days
-- (90-item backlog "dual lead-time fields in days")
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds two nullable INT UNSIGNED columns to `orders`:
--   lead_time_min_days, lead_time_max_days
-- These are purely additive, internal-reporting/sorting fields.
-- The existing est_lead_time_text free-text column is completely
-- unchanged and remains the only lead-time wording printed on the
-- Quotation or any other customer-facing document. Every existing
-- order keeps both new columns NULL until staff fill them in via the
-- Order Edit screen.
--
-- Idempotent (safe to re-run) — each ADD COLUMN is guarded.
-- ================================================================

SET @min_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'lead_time_min_days'
);
SET @ddl_min = IF(@min_exists = 0, 'ALTER TABLE orders ADD COLUMN lead_time_min_days INT UNSIGNED NULL AFTER est_lead_time_text', 'SELECT 1');
PREPARE stmt_min FROM @ddl_min;
EXECUTE stmt_min;
DEALLOCATE PREPARE stmt_min;

SET @max_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'lead_time_max_days'
);
SET @ddl_max = IF(@max_exists = 0, 'ALTER TABLE orders ADD COLUMN lead_time_max_days INT UNSIGNED NULL AFTER lead_time_min_days', 'SELECT 1');
PREPARE stmt_max FROM @ddl_max;
EXECUTE stmt_max;
DEALLOCATE PREPARE stmt_max;
