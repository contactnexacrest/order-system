-- Owner decision 2026-10-29 (CAFIN change confirmed twice): the money the
-- bank pays under an LC joins the INR Settlement Register as its own leg,
-- with its FIRC / eBRC realization proof like the advance, balance and
-- freight legs. Also the time the scheduled Zoho sync last checked the
-- records already in Books (that check runs once a day, owner decision
-- 2026-10-29; Sync Now always runs it). Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_firc_reference') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_firc_reference VARCHAR(100) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_firc_received_at') = 0, 'ALTER TABLE order_lc_details ADD COLUMN lc_firc_received_at DATE NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

INSERT IGNORE INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('zoho_books_restore_checked_at', '', 'string', 'zoho_books', 'Set by the app: when the scheduled Zoho sync last checked every record already in Zoho Books and put back what had been deleted or changed there (once a day; Sync Now always checks). Leave it as it is.', 0);
