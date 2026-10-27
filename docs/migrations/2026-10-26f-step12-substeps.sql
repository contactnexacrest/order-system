-- Owner request (2026-10-26): Step 12 split into guided sub-steps 12a–12d.
-- Records when staff marked the order's costs complete (12a) and Step 12
-- itself complete (12d). Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_costs_confirmed_at') = 0, 'ALTER TABLE orders ADD COLUMN financials_costs_confirmed_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_costs_confirmed_by') = 0, 'ALTER TABLE orders ADD COLUMN financials_costs_confirmed_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_completed_at') = 0, 'ALTER TABLE orders ADD COLUMN financials_completed_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'financials_completed_by') = 0, 'ALTER TABLE orders ADD COLUMN financials_completed_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
