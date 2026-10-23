-- ================================================================
-- DB MIGRATION — 2026-10-23 (h)
-- Export process step 8: updating the estimated shipment date must
-- notify the client (and it must be visible in their portal) — reuses
-- the existing order-update/comment-to-client channel, which already
-- does both. Needs a real DATE (the existing est_shipment_date_text is
-- deliberately free text, e.g. "Week of 15 October 2026", for display
-- on documents) to drive step 8a's 7-day pre-shipment-inspection
-- reminder and the inspection fee payer/amount it records.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run).
-- ================================================================

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'est_shipment_date'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE orders ADD COLUMN est_shipment_date DATE NULL AFTER est_shipment_date_text',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- A plain MODIFY COLUMN is naturally idempotent (re-running the identical
-- definition is a no-op, not an error), unlike ADD COLUMN/ADD CONSTRAINT.
-- Forward-compatible with every later widening of this same ENUM
-- (travel_expenses) — see 2026-10-23k's own comment for why a replay
-- of an earlier MODIFY COLUMN must never narrow it back.
ALTER TABLE order_cost_entries
  MODIFY COLUMN category ENUM('ecgc_insurance','due_diligence','inland_transport','cha_charges',
                               'documentation','port_charges','bank_charges','commission',
                               'packing_crates','bank_loan_interest','inspection_fee','travel_expenses','other') NOT NULL;

CREATE TABLE IF NOT EXISTS order_inspection_fees (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL UNIQUE,
  fee_payer     ENUM('nexacrest','client') NOT NULL,
  fee_amount    DECIMAL(14,2) NULL,
  cost_entry_id BIGINT UNSIGNED NULL,   -- the order_cost_entries row (category='inspection_fee') kept in sync when fee_payer='nexacrest'
  recorded_by   BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (cost_entry_id) REFERENCES order_cost_entries(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;
