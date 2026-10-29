-- Owner decisions 2026-10-29 (Zoho Books GST, on Claude's recommendation):
-- 1. The shipping bill's number, date and the customs exchange rate (INR
--    per unit of the order currency, as printed on the shipping bill) are
--    recorded on the order. The Commercial Invoice and the Debit Note go to
--    Zoho Books at that rate, so the GST return matches the shipping bill
--    (ICEGATE) — what the export refund is checked against.
-- 2. A transport bill from a goods transport agency that charges no GST is
--    marked "reverse charge": NexaCrest pays the 5 % GST itself, and Zoho
--    Books books it as reverse charge. Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_shipping' AND COLUMN_NAME = 'shipping_bill_no') = 0, 'ALTER TABLE order_shipping ADD COLUMN shipping_bill_no VARCHAR(30) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_shipping' AND COLUMN_NAME = 'shipping_bill_date') = 0, 'ALTER TABLE order_shipping ADD COLUMN shipping_bill_date DATE NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_shipping' AND COLUMN_NAME = 'customs_exchange_rate') = 0, 'ALTER TABLE order_shipping ADD COLUMN customs_exchange_rate DECIMAL(12,4) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'gst_reverse_charge') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN gst_reverse_charge TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
