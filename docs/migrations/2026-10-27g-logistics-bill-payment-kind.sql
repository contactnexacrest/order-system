-- Owner decision (2026-10-27): a logistics partner's bill is either an
-- Advance (payable any time) or the Final bill (on CFR/CIF, payable once
-- the buyer's Debit Note payment has cleared — Step 9d). Every existing
-- bill is a Final bill. Supplier bills keep using bill_for (advance /
-- balance) and ignore this column. Safe to run more than once.
SET NAMES utf8mb4;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'payment_kind') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN payment_kind VARCHAR(10) NOT NULL DEFAULT ''final''', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
