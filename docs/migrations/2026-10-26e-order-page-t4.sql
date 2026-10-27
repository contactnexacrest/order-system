-- Owner requests (2026-10-26, t4). Safe to run more than once.
SET NAMES utf8mb4;

-- Step 12 (Closure — Financials, Benefits & Costs) can be skipped with a
-- reason and marked complete; no amount is written, so nothing reads as 0.
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_skipped_at') = 0, 'ALTER TABLE orders ADD COLUMN financials_skipped_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_skipped_by') = 0, 'ALTER TABLE orders ADD COLUMN financials_skipped_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_skip_reason') = 0, 'ALTER TABLE orders ADD COLUMN financials_skip_reason VARCHAR(500) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;


-- Step 7c: the supplier uploads their signed PO / acknowledgment through a
-- no-login Supplier Action Link (like the Buyer Action Link), or staff
-- record that the supplier did not acknowledge (reason required).
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'supplier_action_token_hash') = 0, 'ALTER TABLE order_supplier_po ADD COLUMN supplier_action_token_hash CHAR(64) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'supplier_action_token_plain') = 0, 'ALTER TABLE order_supplier_po ADD COLUMN supplier_action_token_plain TEXT NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'supplier_action_token_expires_at') = 0, 'ALTER TABLE order_supplier_po ADD COLUMN supplier_action_token_expires_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'ack_skipped_at') = 0, 'ALTER TABLE order_supplier_po ADD COLUMN ack_skipped_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'ack_skipped_by') = 0, 'ALTER TABLE order_supplier_po ADD COLUMN ack_skipped_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'ack_skip_reason') = 0, 'ALTER TABLE order_supplier_po ADD COLUMN ack_skip_reason VARCHAR(500) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND INDEX_NAME = 'idx_supplier_po_action_token') = 0, 'ALTER TABLE order_supplier_po ADD INDEX idx_supplier_po_action_token (supplier_action_token_hash)', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
