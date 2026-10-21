-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql Section BM — order_supplier_po
-- advance_paid_at/advance_paid_by/balance_paid_at/balance_paid_by
-- Covers: Phase 2 item 1 — "customer-wise / product-wise / country-wise
-- reports; today's incoming/supplier payments" (the supplier half)
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Adds, per supplier PO: advance_paid_at/advance_paid_by and
-- balance_paid_at/balance_paid_by. Previously only the AMOUNT owed to
-- a supplier was recorded (advance_amount_inr/balance_amount_inr) —
-- nothing tracked whether or when it was actually paid, unlike the
-- buyer side (order_payment_status.advance_cleared_at/etc.), so
-- "today's supplier payments" had no data to report on at all.
--
-- NULL on every existing supplier PO (no data migrated — no such value
-- existed before). Idempotent (safe to re-run).
-- ================================================================

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'advance_paid_at'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE order_supplier_po
     ADD COLUMN advance_paid_at DATE NULL,
     ADD COLUMN advance_paid_by BIGINT UNSIGNED NULL,
     ADD COLUMN balance_paid_at DATE NULL,
     ADD COLUMN balance_paid_by BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk1_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND CONSTRAINT_NAME = 'fk_spo_advance_paid_by'
);
SET @fk1_ddl = IF(@fk1_exists = 0,
  'ALTER TABLE order_supplier_po ADD CONSTRAINT fk_spo_advance_paid_by FOREIGN KEY (advance_paid_by) REFERENCES users(id)',
  'SELECT 1');
PREPARE fk1_stmt FROM @fk1_ddl;
EXECUTE fk1_stmt;
DEALLOCATE PREPARE fk1_stmt;

SET @fk2_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND CONSTRAINT_NAME = 'fk_spo_balance_paid_by'
);
SET @fk2_ddl = IF(@fk2_exists = 0,
  'ALTER TABLE order_supplier_po ADD CONSTRAINT fk_spo_balance_paid_by FOREIGN KEY (balance_paid_by) REFERENCES users(id)',
  'SELECT 1');
PREPARE fk2_stmt FROM @fk2_ddl;
EXECUTE fk2_stmt;
DEALLOCATE PREPARE fk2_stmt;
