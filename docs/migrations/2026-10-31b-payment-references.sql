-- Owner rule D-212 (2026-10-31): every money record has a correct description.
-- Batch 1 adds the bank / payment reference to the money records that had none:
-- buyer remittances (advance, balance, freight), the money paid under an LC,
-- incentive and associate payouts (how paid + reference), export benefits
-- received (bank UTR / e-scrip no.) and cancellation refunds (date + bank ref).
-- The app builds each record's description from these facts. Columns only; nothing
-- else changes, and records already saved keep their data (the new columns stay
-- empty for them). Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_status' AND COLUMN_NAME = 'advance_bank_reference') = 0, 'ALTER TABLE order_payment_status ADD COLUMN advance_bank_reference VARCHAR(100) NULL AFTER advance_firc_received_at', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_status' AND COLUMN_NAME = 'balance_bank_reference') = 0, 'ALTER TABLE order_payment_status ADD COLUMN balance_bank_reference VARCHAR(100) NULL AFTER balance_firc_received_at', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_status' AND COLUMN_NAME = 'freight_bank_reference') = 0, 'ALTER TABLE order_payment_status ADD COLUMN freight_bank_reference VARCHAR(100) NULL AFTER freight_firc_received_at', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_bank_reference') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_bank_reference VARCHAR(100) NULL AFTER lc_firc_reference', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'paid_via') = 0, 'ALTER TABLE incentive_payouts ADD COLUMN paid_via VARCHAR(20) NULL AFTER tds_inr', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incentive_payouts' AND COLUMN_NAME = 'paid_reference') = 0, 'ALTER TABLE incentive_payouts ADD COLUMN paid_reference VARCHAR(100) NULL AFTER paid_via', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'received_reference') = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN received_reference VARCHAR(100) NULL AFTER received_at', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cancellations' AND COLUMN_NAME = 'refund_paid_at') = 0, 'ALTER TABLE order_cancellations ADD COLUMN refund_paid_at DATE NULL AFTER refund_notes', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cancellations' AND COLUMN_NAME = 'refund_reference') = 0, 'ALTER TABLE order_cancellations ADD COLUMN refund_reference VARCHAR(100) NULL AFTER refund_paid_at', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;
