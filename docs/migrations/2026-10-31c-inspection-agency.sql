-- Owner rule D-212 (2026-10-31), Batch 2: a pre-shipment inspection fee that
-- NexaCrest pays names the inspection agency and its invoice, and the order's
-- cost line (and so the Zoho Books expense) is described from them. Columns
-- only; fees already saved keep their data. Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_inspection_fees' AND COLUMN_NAME = 'agency_name') = 0, 'ALTER TABLE order_inspection_fees ADD COLUMN agency_name VARCHAR(150) NULL AFTER fee_amount', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_inspection_fees' AND COLUMN_NAME = 'agency_invoice_no') = 0, 'ALTER TABLE order_inspection_fees ADD COLUMN agency_invoice_no VARCHAR(60) NULL AFTER agency_name', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;
