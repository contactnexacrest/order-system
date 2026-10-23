-- ================================================================
-- DB MIGRATION — 2026-10-23 (i)
-- Export process steps 11a-11e: closing an order used to be one step
-- (couriering the full document set immediately marked it 'complete'
-- AND locked it) — but government export benefits (RoDTEP etc.) are
-- claimed and received AFTER that point, and locking the order right
-- then meant staff needed the "Override locked data" permission just
-- to record an entirely normal, expected part of closing out an order.
--
-- Splits this into two phases: 'shipped_docs_sent' (documents couriered
-- to the buyer — the client now sees this order as done, but it stays
-- UNLOCKED internally so benefit claims/receipts and cost entries can
-- still be recorded freely) -> 'complete' (benefits received, nothing
-- left to do — the real final lock, same as before).
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- A plain MODIFY COLUMN is naturally idempotent (re-running the
-- identical definition is a no-op, not an error).
-- ================================================================

SET NAMES utf8mb4;

SET @already_has_value = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'status'
    AND COLUMN_TYPE LIKE '%shipped_docs_sent%'
);
SET @ddl = IF(@already_has_value = 0,
  "ALTER TABLE orders MODIFY COLUMN status ENUM('active','shipped_docs_sent','complete','disputed','lost','cancelled') NOT NULL DEFAULT 'active'",
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
