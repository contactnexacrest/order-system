-- ================================================================
-- DB MIGRATION — 2026-10-23 (f)
-- Owner's export-process sequence, steps 5-1a/5-2a/5-3a: when a client
-- opts for a Letter of Credit, record the final LC document + LC number
-- against the order; if a bank loan was taken against that LC, record
-- the loan amount/interest rate/interest paid, and let that interest be
-- recorded as an order expense (order_cost_entries) the same way every
-- other order cost already is.
--
-- Run this ONCE against each production database this app uses (the PHP
-- stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run).
-- ================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_lc_details (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                BIGINT UNSIGNED NOT NULL UNIQUE,
  lc_number               VARCHAR(100) NULL,
  lc_file_id              BIGINT UNSIGNED NULL,
  bank_loan_amount        DECIMAL(14,2) NULL,
  bank_loan_interest_rate DECIMAL(6,3) NULL,
  bank_loan_interest_paid DECIMAL(14,2) NULL,
  interest_cost_entry_id  BIGINT UNSIGNED NULL,   -- the order_cost_entries row (category='bank_loan_interest') kept in sync with bank_loan_interest_paid
  recorded_by             BIGINT UNSIGNED NULL,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (lc_file_id) REFERENCES file_store(id),
  FOREIGN KEY (interest_cost_entry_id) REFERENCES order_cost_entries(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- A plain MODIFY COLUMN is naturally idempotent (re-running the identical
-- definition is a no-op, not an error), unlike ADD COLUMN/ADD CONSTRAINT —
-- so this needs none of the information_schema existence-check dance.
ALTER TABLE order_cost_entries
  MODIFY COLUMN category ENUM('ecgc_insurance','due_diligence','inland_transport','cha_charges',
                               'documentation','port_charges','bank_charges','commission',
                               'packing_crates','bank_loan_interest','other') NOT NULL;

INSERT IGNORE INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description) VALUES
  ('order_lc_copy', 'pdf,jpg,jpeg,png', 10485760, 'Export process step 5-1a: the final Letter of Credit document for this order, when the client opted for LC payment.');
