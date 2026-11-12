-- 2026-11-12g — Detailed product table on the documents, "No. of pieces" and
-- "Size note" on an order line
-- Owner decision D-339 (12 Nov 2026, owner "confirm 1" and "confirm 2 with option a";
-- mockups approved).
--
-- What changes:
--   * orders gains line_table_layout ('classic' / 'detailed', default 'detailed'):
--     which product table the order's Quotation, PI, OC, CI, Buyer PO and Packing
--     List print. 'detailed' = # | Material / Colour | Surface / Finish |
--     Dimensions L W H | measure per piece | No. of pcs | measure of all pieces |
--     price per unit | price per piece | total. 'classic' = the table as before.
--   * EVERY ORDER THAT EXISTS WHEN THE COLUMN IS ADDED IS SET TO 'classic', so its
--     documents, when generated again, print exactly as before. Orders created
--     afterwards get 'detailed'. The UPDATE runs only on the run that adds the column
--     (a second run changes nothing, so a new order is never turned back to classic).
--   * order_products gains pieces_count (No. of pieces; with the size given in a unit
--     worked out from it — CBM, SQM, SQFT — the quantity is worked out from the
--     pieces) and size_note (e.g. "Random sizes, as available"; printed under the
--     line on the documents).
--   * Issued documents never change (they keep the content they were issued with).
--
-- Safe to run twice: every change is guarded through information_schema + PREPARE
-- (MySQL-compatible, no IF [NOT] EXISTS on columns).
-- Run after 2026-11-12f, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12g-detailed-line-table.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- orders
-- Remember whether the column is being added on this run.
SET @add_layout := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'line_table_layout');

SET @s := IF(@add_layout,
  'ALTER TABLE orders ADD COLUMN line_table_layout ENUM(''classic'',''detailed'') NOT NULL DEFAULT ''detailed''',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Every order that exists now keeps the table it has always printed.
SET @s := IF(@add_layout, 'UPDATE orders SET line_table_layout = ''classic''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- order_products
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN pieces_count INT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'pieces_count');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN size_note VARCHAR(200) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'size_note');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
