-- 2026-11-12e — Follow-ups to D-335 / D-336 (owner decision D-338, 12 Nov 2026,
-- owner: "will go with recommendation")
--
-- What changes:
--   * order_reorder_request_products gains as_per_annexure (1 = the reorder line's
--     size is given in the annexure, like order_products.as_per_annexure: the
--     Dimensions stay empty, the quantity is required and "Qty To Be Confirmed" is
--     not ticked). It is carried onto order_products.as_per_annexure when the
--     request is approved and becomes an order.
--   * No data change: every existing reorder line stays 0 (sizes as typed).
--
-- Safe to run twice: the column is guarded through information_schema + PREPARE
-- (MySQL-compatible, no IF [NOT] EXISTS on columns).
-- Run after 2026-11-12c (and 2026-11-12d when present), after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12e-follow-ups-d338.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_reorder_request_products ADD COLUMN as_per_annexure TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_reorder_request_products' AND COLUMN_NAME = 'as_per_annexure');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
