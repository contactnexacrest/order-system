-- ================================================================
-- DB MIGRATION — 2026-10-23 (k)
-- Export process step 11a: "Record 5/7/10% of the total order value
-- as order expenses - it includes all the travelling expenses for
-- that order - for every order we need to have 3-4 trip to factory
-- which is min 175km away from office - if it is 500km then we will
-- record as 15%." The exact distance-to-percentage boundaries are a
-- business judgement call made per order, not a fixed formula, so
-- staff records the factory distance (for reference) and chooses the
-- percentage themselves (a preset 5/7/10/15% or a custom one); the
-- system computes and records the expense amount against the order's
-- own FOB value.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
-- ================================================================

SET NAMES utf8mb4;

SET @already_has_value = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'category'
    AND COLUMN_TYPE LIKE '%travel_expenses%'
);
SET @ddl = IF(@already_has_value = 0,
  "ALTER TABLE order_cost_entries MODIFY COLUMN category ENUM('ecgc_insurance','due_diligence','inland_transport','cha_charges','documentation','port_charges','bank_charges','commission','packing_crates','bank_loan_interest','inspection_fee','travel_expenses','other') NOT NULL",
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS order_travel_expenses (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            BIGINT UNSIGNED NOT NULL UNIQUE,
  factory_distance_km DECIMAL(8,2) NULL,
  expense_pct         DECIMAL(5,2) NOT NULL,
  expense_amount       DECIMAL(14,2) NOT NULL,
  cost_entry_id        BIGINT UNSIGNED NULL,
  recorded_by          BIGINT UNSIGNED NULL,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (cost_entry_id) REFERENCES order_cost_entries(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;
