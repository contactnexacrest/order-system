-- 2026-11-11a — Quote in any unit, piece size, line options, full Duplicate
-- Owner decisions D-270 to D-273 (11 Nov 2026, points 25, 13, 24 and 17).
--
-- What changes:
--   * order_products: piece size Length × Width × Thickness in cm (piece_length_cm,
--     piece_width_cm, piece_thickness_cm) used to convert a line priced in PCS, RMT,
--     m², sq ft or Ton into the product's reference unit for the price check; and
--     copied_from_id ("copy of line N") for a duplicated line (FK to itself).
--   * product_categories / catalog_products: weight_t_per_cbm (tonnes per CBM, for
--     lines priced per Ton; a product may override its category).
--   * Drop-down list "unit": TON and RMT added.
--   * Natural Stone (the system category) gets its line units CBM, SQM, SQFT, PCS,
--     TON, RMT and 2.7 t per CBM — only where the category has no unit list yet.
--     After this, a goods line in another unit (BOX, NOS, SET, KG, MT …) is refused
--     on its next edit until the MD adds that unit in Products › Categories.
--
-- Safe to run twice: every column and the foreign key are guarded through
-- information_schema + PREPARE (MySQL-compatible, no IF [NOT] EXISTS on columns),
-- the INSERT has WHERE NOT EXISTS and the UPDATE touches only unset rows.
-- Run after a backup, in filename order:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11a-quote-any-unit-piece-size.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN piece_length_cm DECIMAL(7,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'piece_length_cm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN piece_width_cm DECIMAL(7,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'piece_width_cm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN piece_thickness_cm DECIMAL(7,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'piece_thickness_cm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN copied_from_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'copied_from_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD CONSTRAINT fk_order_products_copied_from FOREIGN KEY (copied_from_id) REFERENCES order_products(id) ON DELETE SET NULL',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND CONSTRAINT_NAME = 'fk_order_products_copied_from');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN weight_t_per_cbm DECIMAL(6,3) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'weight_t_per_cbm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN weight_t_per_cbm DECIMAL(6,3) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'weight_t_per_cbm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Units Ton and running metre (as seed.sql).
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'unit', u.v, u.s, 0, 1 FROM (SELECT 'TON' AS v, 14 AS s UNION ALL SELECT 'RMT', 15) u
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options d WHERE d.list_key = 'unit' AND d.option_value = u.v);

-- Natural Stone: its line units and its weight per CBM (only where not set yet).
UPDATE product_categories SET allowed_units = 'CBM,SQM,SQFT,PCS,TON,RMT', weight_t_per_cbm = 2.700
 WHERE is_system = 1 AND allowed_units IS NULL;
