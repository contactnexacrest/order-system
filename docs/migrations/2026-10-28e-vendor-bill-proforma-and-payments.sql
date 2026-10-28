-- Owner decision (2026-10-28, Option B): a supplier / logistics partner bill
-- is either a Proforma (an advance request — not a cost) or a Tax invoice
-- (the real bill — the cost). A tax invoice can replace an earlier proforma
-- from the same vendor, so the advance paid against the proforma counts
-- towards it. One bill can take more than one payment, so the payments move
-- to their own table (every existing payment copied across). Every existing
-- bill is a Tax invoice, so today's figures do not change.
-- Safe to run more than once; MySQL and MariaDB.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'doc_kind') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN doc_kind VARCHAR(10) NOT NULL DEFAULT ''invoice''', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'requested_amount_inr') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN requested_amount_inr DECIMAL(14,2) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'replaces_bill_id') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN replaces_bill_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND CONSTRAINT_NAME = 'fk_vendor_bill_replaces') = 0, 'ALTER TABLE order_vendor_bills ADD CONSTRAINT fk_vendor_bill_replaces FOREIGN KEY (replaces_bill_id) REFERENCES order_vendor_bills(id) ON DELETE SET NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

CREATE TABLE IF NOT EXISTS order_vendor_bill_payments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_id       BIGINT UNSIGNED NOT NULL,
  leg           VARCHAR(10)   NULL,
  paid_at       DATE          NOT NULL,
  amount_inr    DECIMAL(14,2) NOT NULL,
  payment_mode  VARCHAR(30)   NOT NULL,
  payment_ref   VARCHAR(100)  NOT NULL,
  paid_by       BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vendor_bill_payment_bill FOREIGN KEY (bill_id) REFERENCES order_vendor_bills(id) ON DELETE CASCADE,
  CONSTRAINT fk_vendor_bill_payment_user FOREIGN KEY (paid_by) REFERENCES users(id),
  INDEX idx_vendor_bill_payments_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO order_vendor_bill_payments (bill_id, leg, paid_at, amount_inr, payment_mode, payment_ref, paid_by)
SELECT b.id, CASE WHEN b.vendor_kind = 'supplier' THEN b.bill_for ELSE NULL END, b.paid_at, COALESCE(b.paid_amount_inr, b.total_inr),
       COALESCE(b.payment_mode, ''), COALESCE(b.payment_ref, ''), b.paid_by
FROM order_vendor_bills b
WHERE b.paid_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM order_vendor_bill_payments p WHERE p.bill_id = b.id);
