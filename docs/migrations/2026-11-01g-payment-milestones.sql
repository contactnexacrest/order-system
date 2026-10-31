-- Owner decision D-215 (5a, confirmed twice; owner 1 Nov: "I will go with
-- your recommendations"): an order can have its own payment schedule. The
-- first step stays the advance and the last the balance (both as today);
-- the steps in between are stored here, each tracked as its own payment —
-- remittance with bank reference, cleared, pushed to Zoho Books. An order
-- without rows here works exactly as before. New table only; safe to run
-- twice.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_payment_milestones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  seq TINYINT UNSIGNED NOT NULL,
  pct DECIMAL(5,2) NOT NULL,
  due_text VARCHAR(200) NOT NULL,
  received_amount DECIMAL(14,2) NULL,
  received_on DATE NULL,
  exchange_rate DECIMAL(12,4) NULL,
  bank_reference VARCHAR(100) NULL,
  staff_note VARCHAR(255) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  recorded_at DATETIME NULL,
  cleared_on DATE NULL,
  cleared_by BIGINT UNSIGNED NULL,
  zoho_reference VARCHAR(64) NULL,
  zoho_applied_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_opm_order_seq (order_id, seq),
  CONSTRAINT fk_opm_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;
