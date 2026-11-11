-- 2026-11-12b — Supplier prices: a price you typed always wins, a BASE price you
-- chose, and the price rules only as a calculator
-- Owner decision D-335 (12 Nov 2026, mockups approved "OK build").
--
-- What changes:
--   * catalog_supplier_thickness_prices gains how ('typed' / 'calculated'),
--     calc_from_mm (the thickness a CALCULATED price was worked out from; 0 = 50 mm
--     and above) and quoted_on (the date the supplier quoted a TYPED price).
--     Every row already there is a typed price (how = 'typed').
--   * catalog_product_suppliers gains base_thickness_mm (the BASE price of a supplier
--     price for every thickness: 0 = 50 mm and above per CBM; NULL = the category's
--     base thickness, as before) and auto_fill (1 = thicknesses without a stored price
--     are still worked out on the fly from the typed ones, as before; the app sets it
--     to 0 the first time a price is added with the new "Add / update a price" form —
--     from then on only stored prices exist).
--   * DATA (one time): every supplier price for every thickness (price_basis 'grid')
--     gets its base: "50 mm and above" when the product is sold per CBM and the
--     supplier's per-CBM price was typed; otherwise 20 mm. Only rows whose base is
--     still empty are touched.
--   * DATA (one time): a supplier price still set to "add up the cost parts"
--     (fob_source 'computed') becomes a typed price with exactly the same total —
--     factory + transport + packing + loading + CHA, each from the supplier price or
--     else the product's default (as ProductService::effectiveFobForSupplier adds
--     them up today), so no price changes.
--   * No column is dropped: the product's default factory / transportation / CHA cost
--     columns stay in the database, unused.
--
-- Safe to run twice: every column is guarded through information_schema + PREPARE
-- (MySQL-compatible, no IF [NOT] EXISTS on columns), and the data steps touch only
-- rows not yet converted.
-- Run after 2026-11-12a, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12b-supplier-prices-base-and-calculated.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- columns
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_supplier_thickness_prices ADD COLUMN how ENUM(''typed'',''calculated'') NOT NULL DEFAULT ''typed''',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_supplier_thickness_prices' AND COLUMN_NAME = 'how');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_supplier_thickness_prices ADD COLUMN calc_from_mm INT NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_supplier_thickness_prices' AND COLUMN_NAME = 'calc_from_mm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_supplier_thickness_prices ADD COLUMN quoted_on DATE NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_supplier_thickness_prices' AND COLUMN_NAME = 'quoted_on');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD COLUMN base_thickness_mm INT NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'base_thickness_mm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD COLUMN auto_fill TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'auto_fill');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- data (one time)
-- The base of every supplier price for every thickness: 50 mm and above (0) for a
-- product sold per CBM whose per-CBM price was typed, else 20 mm.
UPDATE catalog_product_suppliers s
  JOIN catalog_products p ON p.id = s.product_id
   SET s.base_thickness_mm = 0
 WHERE s.price_basis = 'grid' AND s.base_thickness_mm IS NULL
   AND UPPER(TRIM(COALESCE(p.reference_unit, ''))) IN ('CBM', 'M3', 'M³', 'CUM', 'CU M', 'CUBIC METRE', 'CUBIC METER')
   AND EXISTS (SELECT 1 FROM catalog_supplier_thickness_prices t WHERE t.product_supplier_id = s.id AND t.thickness_mm = 0);

UPDATE catalog_product_suppliers SET base_thickness_mm = 20
 WHERE price_basis = 'grid' AND base_thickness_mm IS NULL;

-- "Add up the cost parts" becomes a typed price with the same total.
UPDATE catalog_product_suppliers s
  JOIN catalog_products p ON p.id = s.product_id
   SET s.fob_value = COALESCE(s.factory_cost, p.default_factory_cost, 0)
                   + COALESCE(s.transportation_cost, p.default_transportation_cost, 0)
                   + COALESCE(s.packing_cost, p.default_packing_cost, 0)
                   + COALESCE(s.loading_cost, p.default_loading_cost, 0)
                   + COALESCE(s.cha_cost, p.default_cha_cost, 0),
       s.fob_source = 'direct'
 WHERE s.fob_source = 'computed';
