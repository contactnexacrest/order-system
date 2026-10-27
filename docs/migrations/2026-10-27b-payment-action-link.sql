-- Owner request (2026-10-27): a no-login Payment Action Link the client uses
-- to send their payment details (transaction ID, amount, date, screenshot)
-- for the advance (against the PI), freight (against the Debit Note) and
-- balance (against the Commercial Invoice) — payment only, nothing else.
-- Same hash / encrypted copy / expiry convention as the Buyer Action Link.
-- Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_action_token_hash') = 0, 'ALTER TABLE orders ADD COLUMN payment_action_token_hash CHAR(64) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_action_token_plain') = 0, 'ALTER TABLE orders ADD COLUMN payment_action_token_plain TEXT NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_action_token_expires_at') = 0, 'ALTER TABLE orders ADD COLUMN payment_action_token_expires_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_payment_action_token') = 0, 'ALTER TABLE orders ADD INDEX idx_orders_payment_action_token (payment_action_token_hash)', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
