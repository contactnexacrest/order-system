-- 2026-11-12c — "As per annexure" order lines, Annexure B (as shipped) and the
-- annexure printed with the Commercial Invoice and Packing List
-- Owner decision D-336 (12 Nov 2026, owner confirmed twice; mockups approved).
--
-- What changes:
--   * order_products gains as_per_annexure (1 = the line's size is given in the
--     annexure: Length / Width / Thickness stay empty and the documents print
--     "Dimensions: As per Annexure A/B" and "<qty> (as per Annexure A/B)").
--   * order_annexure_products and order_annexure_terms gain `which` ('A' = Annexure A
--     as quoted, 'B' = Annexure B as shipped). Every existing row stays 'A'.
--   * order_annexure_terms: its UNIQUE(order_id) becomes UNIQUE(order_id, `which`)
--     (one Additional Terms block per annexure). The new key is added first, then
--     the old one-column key is dropped (the order_id foreign key keeps an index).
--   * orders gains include_annexure_b, annexure_b_mode (SPEC / TERMS / BOTH, like
--     annexure_mode for Annexure A) and ci_annexure (A / B / NONE; NULL = the
--     default: Annexure B when it is included and has content, else Annexure A when
--     it is included and has content, else none).
--   * No data change: existing orders print exactly as before.
--
-- Safe to run twice: every change is guarded through information_schema + PREPARE
-- (MySQL-compatible, no IF [NOT] EXISTS on columns or indexes).
-- Run after 2026-11-12b, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12c-annexure-b-as-per-annexure.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- order_products
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN as_per_annexure TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'as_per_annexure');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- annexure tables
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_annexure_products ADD COLUMN `which` CHAR(1) NOT NULL DEFAULT ''A''',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_annexure_products' AND COLUMN_NAME = 'which');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_annexure_terms ADD COLUMN `which` CHAR(1) NOT NULL DEFAULT ''A''',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_annexure_terms' AND COLUMN_NAME = 'which');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- New key (order_id, which) first ...
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_annexure_terms ADD UNIQUE KEY uq_order_annexure_terms_which (order_id, `which`)',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_annexure_terms' AND INDEX_NAME = 'uq_order_annexure_terms_which');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ... then the old one-column unique key on order_id (named `order_id` by CREATE TABLE).
SET @s := (SELECT IF(COUNT(*) > 0,
  'ALTER TABLE order_annexure_terms DROP INDEX order_id',
  'SELECT 1') FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_annexure_terms' AND INDEX_NAME = 'order_id' AND NON_UNIQUE = 0);
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- orders
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN include_annexure_b TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'include_annexure_b');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN annexure_b_mode ENUM(''SPEC'',''TERMS'',''BOTH'') NOT NULL DEFAULT ''SPEC''',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'annexure_b_mode');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE orders ADD COLUMN ci_annexure ENUM(''A'',''B'',''NONE'') NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'ci_annexure');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
