-- Zoho integration Stage 3 (2026-10-29): what the app pays out goes to
-- Zoho Books too. Suppliers and logistics partners become Books vendors
-- (zoho_contact_id); a supplier or logistics partner's tax invoice becomes a
-- Books bill (zoho_bill_id); each payment to them becomes a Books vendor
-- payment (zoho_payment_id), applied to its bill (zoho_applied_at) — a
-- payment on a proforma is held as an advance until the tax invoice that
-- replaces it is in Books; each order expense becomes a Books expense
-- (zoho_expense_id), which the expense import then skips. Safe to run
-- more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'zoho_contact_id') = 0, 'ALTER TABLE suppliers ADD COLUMN zoho_contact_id VARCHAR(100) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logistics_partners' AND COLUMN_NAME = 'zoho_contact_id') = 0, 'ALTER TABLE logistics_partners ADD COLUMN zoho_contact_id VARCHAR(100) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'zoho_bill_id') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN zoho_bill_id VARCHAR(100) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bills' AND COLUMN_NAME = 'zoho_synced_at') = 0, 'ALTER TABLE order_vendor_bills ADD COLUMN zoho_synced_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bill_payments' AND COLUMN_NAME = 'zoho_payment_id') = 0, 'ALTER TABLE order_vendor_bill_payments ADD COLUMN zoho_payment_id VARCHAR(100) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bill_payments' AND COLUMN_NAME = 'zoho_synced_at') = 0, 'ALTER TABLE order_vendor_bill_payments ADD COLUMN zoho_synced_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_vendor_bill_payments' AND COLUMN_NAME = 'zoho_applied_at') = 0, 'ALTER TABLE order_vendor_bill_payments ADD COLUMN zoho_applied_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_expenses' AND COLUMN_NAME = 'zoho_expense_id') = 0, 'ALTER TABLE order_expenses ADD COLUMN zoho_expense_id VARCHAR(100) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_expenses' AND COLUMN_NAME = 'zoho_synced_at') = 0, 'ALTER TABLE order_expenses ADD COLUMN zoho_synced_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
