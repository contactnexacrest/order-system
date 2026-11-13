-- 2026-11-13d — Bank reference on order expenses and order costs
-- Owner decision 13 Nov 2026 (QA ZOH-005, Option A): rule D-212 applies to order
-- expenses and order costs too.
--
-- What changes:
--   * order_expenses.bank_reference and order_cost_entries.bank_reference (UTR /
--     transaction ID, up to 150 characters): required on the form when the money
--     went out of the company account, optional for the company card or staff.
--     The Zoho Books description of the expense says what it is, for which order,
--     what it was paid from and the UTR.
--   * order_expenses.zoho_pushed_hash: what was last sent to Zoho Books, so an
--     expense already in Books is updated when its description changes. Every
--     expense already in Books is sent once more on the next sync with the new
--     description (amount, date and accounts unchanged); order costs already in
--     Books are updated the same way through their existing zoho_pushed_hash.
--   * Existing rows keep bank_reference empty: no data is changed here.
--
-- Safe to run twice: every column is guarded through information_schema + PREPARE
-- (MySQL-compatible, no IF [NOT] EXISTS on columns). Every ALTER names the database
-- (`<db>`.table), so it also works in phpMyAdmin, which may switch to information_schema
-- after the check query (fix of 13 Nov 2026: "#1044 Access denied … information_schema"). The last line shows "3 of 3".
-- Run after a backup, in filename order:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13d-expense-cost-bank-reference.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  CONCAT('ALTER TABLE `', @db, '`.order_expenses ADD COLUMN bank_reference VARCHAR(150) NULL'),
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_expenses' AND COLUMN_NAME = 'bank_reference');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  CONCAT('ALTER TABLE `', @db, '`.order_expenses ADD COLUMN zoho_pushed_hash CHAR(64) NULL'),
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_expenses' AND COLUMN_NAME = 'zoho_pushed_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  CONCAT('ALTER TABLE `', @db, '`.order_cost_entries ADD COLUMN bank_reference VARCHAR(150) NULL'),
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'bank_reference');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SELECT CONCAT(COUNT(*), ' of 3') AS `Columns in place`
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND ((TABLE_NAME = 'order_expenses' AND COLUMN_NAME IN ('bank_reference', 'zoho_pushed_hash'))
    OR (TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'bank_reference'));
