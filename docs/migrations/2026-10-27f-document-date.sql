-- Owner request (2026-10-27): the Commercial Invoice can be dated on a
-- chosen (today or earlier) date so it can match the BL. NULL = the day it
-- was generated (every existing document). Safe to run more than once.
SET NAMES utf8mb4;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'document_date') = 0, 'ALTER TABLE documents ADD COLUMN document_date DATE NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
