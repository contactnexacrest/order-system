-- ================================================================
-- DB MIGRATION — 2026-10-26b
-- Order page flow (owner request 2026-10-26):
--
-- 1. orders.pi_form_skipped_at/_by/skip_reason — Step 5c (Generate PI) now
--    stays locked until the client's PI-stage form is applied (5b); when a
--    client won't use the form, staff record a skip (reason required),
--    which completes 5a/5b.
-- 2. compliance_task_types.phase — the Compliance Checklist is split by
--    when each task is done: 'order' (with the Supplier PO, Step 7 — Due
--    Diligence, ECGC Cover) or 'shipment' (before shipment, Step 10 —
--    Fumigation, Phytosanitary, Pre-Shipment Inspection). New task types
--    default to 'shipment'; Admins change it on Compliance Task Types.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): every ADD COLUMN is guarded by an
-- information_schema check, and the phase backfill only runs when the
-- column is first added.
-- ================================================================

SET NAMES utf8mb4;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pi_form_skipped_at');
SET @sql := IF(@c = 0,
  'ALTER TABLE orders
     ADD COLUMN pi_form_skipped_at  TIMESTAMP NULL,
     ADD COLUMN pi_form_skipped_by  BIGINT UNSIGNED NULL,
     ADD COLUMN pi_form_skip_reason VARCHAR(500) NULL,
     ADD CONSTRAINT fk_orders_pi_form_skipped_by FOREIGN KEY (pi_form_skipped_by) REFERENCES users(id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_task_types' AND COLUMN_NAME = 'phase');
SET @sql := IF(@c = 0, 'ALTER TABLE compliance_task_types ADD COLUMN phase ENUM(''order'',''shipment'') NOT NULL DEFAULT ''shipment'' AFTER name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@c = 0, 'UPDATE compliance_task_types SET phase = ''order'' WHERE name IN (''Due Diligence'', ''ECGC Cover'')', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
