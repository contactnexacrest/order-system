-- Owner decisions 2026-10-29 (Zoho Books, on Claude's recommendation):
-- 1. Order cost entries that are real paid costs (ECGC, due diligence,
--    documentation, commission, packing, inspection fee, other) go to
--    Zoho Books as expenses. Each now says what it was paid from.
-- 2. LC orders record the money the bank actually pays under the LC
--    (date, amount in the order currency, rupees credited, bank charges
--    deducted); it goes to Books as a customer payment applied to the CI.
-- 3. Export benefits go to Books as a journal when received (Duty
--    Drawback into the bank account; RoDTEP / RoSCTL as e-scrips).
-- Each table remembers what is already in Books. Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'paid_from') = 0, 'ALTER TABLE order_cost_entries ADD COLUMN paid_from ENUM(''company_account'',''company_card'') NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'zoho_expense_id') = 0, 'ALTER TABLE order_cost_entries ADD COLUMN zoho_expense_id VARCHAR(50) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'zoho_synced_at') = 0, 'ALTER TABLE order_cost_entries ADD COLUMN zoho_synced_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'zoho_pushed_hash') = 0, 'ALTER TABLE order_cost_entries ADD COLUMN zoho_pushed_hash CHAR(64) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_received_at') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_received_at DATE NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_received_amount') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_received_amount DECIMAL(14,2) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_inr_credited') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_inr_credited DECIMAL(14,2) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_bank_charges_inr') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_bank_charges_inr DECIMAL(14,2) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_received_by') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_received_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_zoho_payment_id') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_zoho_payment_id VARCHAR(50) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_zoho_synced_at') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_zoho_synced_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_zoho_applied_at') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_zoho_applied_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'zoho_journal_id') = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN zoho_journal_id VARCHAR(50) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'zoho_synced_at') = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN zoho_synced_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ca_export_benefits' AND COLUMN_NAME = 'zoho_pushed_hash') = 0, 'ALTER TABLE ca_export_benefits ADD COLUMN zoho_pushed_hash CHAR(64) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
