-- 2026-11-11d — Products and pricing: thickness prices, CHA + transport, price rules,
-- daily exchange rate, one master for HS code and supplier
-- Owner decisions D-300 to D-306 (11 Nov 2026, points 12, 4 (exchange rate), 11, 15, 14, 19, 20).
--
-- What changes:
--   * D-300 price rules per category and product: thick_k_factor (50 mm+ per CBM =
--     20 mm price per m² × K; Natural Stone 40), container_cbm (8) and container_max_t
--     (27); catalog_products.cha_transport_per_container (the product's default).
--   * Supplier prices (catalog_product_suppliers): price_basis gains 'grid' (one row
--     holds every thickness), includes_packing_loading (ticked by default),
--     cha_transport_per_container, supplier_id (FK to the Suppliers directory).
--   * suppliers.details_pending (a supplier quick-added from a price screen).
--   * New tables: catalog_supplier_thickness_prices (the TYPED thickness prices; the
--     CALCULATED ones are never stored), catalog_supplier_price_history, fx_rates (the
--     daily USD→INR rate, its source and the MD's decision on a jump over 2 %).
--   * Pricing settings fx_mode = auto and fx_cushion_pct = 2.0 (pricing rate = market
--     rate − 2 %). Until the first rate is fetched, pricing_fx_rate is used as before.
--   * DATA: existing prices of products whose category has thickness rules (Natural
--     Stone) and whose reference unit converts to m² (CBM, m², sq ft) become one
--     thickness row each: the base price becomes the TYPED 20 mm price per m² (per CBM
--     × 0.020, per sq ft × 10.7639, per m² as it is) — this assumes the old base price
--     was the 20 mm price; a per-CBM (thick stone) price becomes the TYPED "50 mm and
--     above" price. BEHAVIOUR CHANGE: a 30 mm price on a product priced per CBM is now
--     worked out from the 20 mm price (e.g. 2,160 per m² ÷ 0.03 = 72,000 per CBM; before
--     80,000 × 1.35). Check Products › Price rules and a few product prices after running.
--   * DATA: typed supplier names on product prices are linked to the Suppliers directory
--     where exactly one active supplier has that name (spaces and capitals ignored).
--     The rest is linked on Products › Link suppliers; product HS codes that are not on
--     the HS Code Master List are fixed on Products › HS codes › Fix HS codes on products.
--
-- Safe to run twice: every column, ENUM change and foreign key is guarded through
-- information_schema + PREPARE (MySQL-compatible, no IF [NOT] EXISTS on columns or
-- indexes, no stored procedure), CREATE TABLE IF NOT EXISTS, INSERT IGNORE, and the
-- data steps touch only rows not yet converted.
-- Run after 2026-11-11c, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11d-thickness-prices-fx-hs-suppliers.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- columns
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN thick_k_factor DECIMAL(8,3) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'thick_k_factor');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN container_cbm DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'container_cbm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN container_max_t DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'container_max_t');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN thick_k_factor DECIMAL(8,3) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'thick_k_factor');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN container_cbm DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'container_cbm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN container_max_t DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'container_max_t');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN cha_transport_per_container DECIMAL(12,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'cha_transport_per_container');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD COLUMN supplier_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'supplier_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD COLUMN includes_packing_loading TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'includes_packing_loading');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD COLUMN cha_transport_per_container DECIMAL(12,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'cha_transport_per_container');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE suppliers ADD COLUMN details_pending TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'details_pending');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 'grid' price basis (only while the ENUM does not have it yet)
SET @s := (SELECT IF(COUNT(*) > 0,
  "ALTER TABLE catalog_product_suppliers MODIFY COLUMN price_basis ENUM('unit','cbm','combo','grid') NOT NULL DEFAULT 'unit'",
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'price_basis' AND COLUMN_TYPE NOT LIKE '%grid%');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- the directory supplier of a price
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD CONSTRAINT fk_catalog_product_suppliers_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND CONSTRAINT_NAME = 'fk_catalog_product_suppliers_supplier');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- tables
CREATE TABLE IF NOT EXISTS catalog_supplier_thickness_prices (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_supplier_id BIGINT UNSIGNED NOT NULL,
  thickness_mm        INT NOT NULL,
  price_inr           DECIMAL(14,2) NOT NULL,
  updated_by          BIGINT UNSIGNED NULL,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_thickness (product_supplier_id, thickness_mm),
  CONSTRAINT fk_supplier_thickness_row FOREIGN KEY (product_supplier_id) REFERENCES catalog_product_suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_thickness_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS catalog_supplier_price_history (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_supplier_id BIGINT UNSIGNED NOT NULL,
  what                VARCHAR(80) NOT NULL,
  old_value           VARCHAR(80) NULL,
  new_value           VARCHAR(80) NULL,
  changed_by          BIGINT UNSIGNED NULL,
  changed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_supplier_price_history (product_supplier_id, changed_at),
  CONSTRAINT fk_supplier_price_history_row FOREIGN KEY (product_supplier_id) REFERENCES catalog_product_suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_supplier_price_history_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fx_rates (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_date       DATE NOT NULL,
  market_rate     DECIMAL(12,4) NULL,
  source          VARCHAR(60) NULL,
  status          ENUM('in_use','waiting_md','rejected','failed') NOT NULL,
  change_pct      DECIMAL(7,3) NULL,
  note            VARCHAR(255) NULL,
  fetched_at      DATETIME NOT NULL,
  decided_by      BIGINT UNSIGNED NULL,
  decided_at      DATETIME NULL,
  decision_reason VARCHAR(255) NULL,
  UNIQUE KEY uq_fx_rates_date (rate_date),
  CONSTRAINT fk_fx_rates_decided_by FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- settings (as seed.sql)
UPDATE product_categories SET thick_k_factor = 40.000, container_cbm = 8.00, container_max_t = 27.00
 WHERE is_system = 1 AND thick_k_factor IS NULL;
INSERT IGNORE INTO pricing_settings (setting_key, setting_value) VALUES ('fx_mode', 'auto'), ('fx_cushion_pct', '2.0');

-- ---------------------------------------------------------------- data: existing prices → one row for every thickness
-- (a) the per-CBM price used for thick stone joins the product's base price used for pricing
INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr)
SELECT u.id, 0, c.fob_value
  FROM catalog_product_suppliers u
  JOIN catalog_product_suppliers c ON c.product_id = u.product_id AND c.price_basis = 'cbm' AND c.is_cbm_primary = 1 AND c.fob_source = 'direct' AND c.fob_value > 0
  JOIN catalog_products p ON p.id = u.product_id
  JOIN product_categories pc ON pc.id = COALESCE(p.category_id, (SELECT x.id FROM product_categories x WHERE x.is_system = 1 ORDER BY x.id LIMIT 1))
 WHERE u.price_basis = 'unit' AND u.is_primary = 1 AND u.fob_source = 'direct' AND u.fob_value > 0 AND pc.thick_k_factor IS NOT NULL
   AND UPPER(TRIM(COALESCE(p.reference_unit, ''))) IN ('CBM', 'M3', 'CUM', 'SQM', 'M2', 'SQ M', 'SQMT', 'SQFT', 'SQ FT', 'SFT')
   AND NOT EXISTS (SELECT 1 FROM catalog_supplier_thickness_prices t WHERE t.product_supplier_id = u.id);

-- (b) base prices → TYPED 20 mm per m²
INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr)
SELECT u.id, 20, ROUND(u.fob_value * CASE
         WHEN UPPER(TRIM(p.reference_unit)) IN ('CBM', 'M3', 'CUM') THEN 0.020
         WHEN UPPER(TRIM(p.reference_unit)) IN ('SQFT', 'SQ FT', 'SFT') THEN 10.7639
         ELSE 1 END, 2)
  FROM catalog_product_suppliers u
  JOIN catalog_products p ON p.id = u.product_id
  JOIN product_categories pc ON pc.id = COALESCE(p.category_id, (SELECT x.id FROM product_categories x WHERE x.is_system = 1 ORDER BY x.id LIMIT 1))
 WHERE u.price_basis = 'unit' AND u.fob_source = 'direct' AND u.fob_value > 0 AND pc.thick_k_factor IS NOT NULL
   AND UPPER(TRIM(COALESCE(p.reference_unit, ''))) IN ('CBM', 'M3', 'CUM', 'SQM', 'M2', 'SQ M', 'SQMT', 'SQFT', 'SQ FT', 'SFT')
   AND NOT EXISTS (SELECT 1 FROM catalog_supplier_thickness_prices t WHERE t.product_supplier_id = u.id AND t.thickness_mm = 20);

UPDATE catalog_product_suppliers u
  JOIN catalog_supplier_thickness_prices t ON t.product_supplier_id = u.id AND t.thickness_mm = 20
   SET u.price_basis = 'grid'
 WHERE u.price_basis = 'unit';

-- (c) per-CBM prices → TYPED "50 mm and above"
INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr)
SELECT c.id, 0, c.fob_value
  FROM catalog_product_suppliers c
  JOIN catalog_products p ON p.id = c.product_id
  JOIN product_categories pc ON pc.id = COALESCE(p.category_id, (SELECT x.id FROM product_categories x WHERE x.is_system = 1 ORDER BY x.id LIMIT 1))
 WHERE c.price_basis = 'cbm' AND c.fob_source = 'direct' AND c.fob_value > 0 AND pc.thick_k_factor IS NOT NULL
   AND UPPER(TRIM(COALESCE(p.reference_unit, ''))) IN ('CBM', 'M3', 'CUM', 'SQM', 'M2', 'SQ M', 'SQMT', 'SQFT', 'SQ FT', 'SFT')
   AND NOT EXISTS (SELECT 1 FROM catalog_supplier_thickness_prices t WHERE t.product_supplier_id = c.id);

-- a product with only a per-CBM price used for pricing: that row becomes the price used for pricing.
-- (The products that already have one are listed in a temporary table first, so MySQL never reads
-- the table it is updating — error 1093.)
DROP TEMPORARY TABLE IF EXISTS tmp_d300_priced_products;
CREATE TEMPORARY TABLE tmp_d300_priced_products AS
SELECT DISTINCT product_id FROM catalog_product_suppliers WHERE is_primary = 1 AND price_basis IN ('unit', 'grid');
UPDATE catalog_product_suppliers c
   SET c.is_primary = 1
 WHERE c.price_basis = 'cbm' AND c.is_cbm_primary = 1
   AND EXISTS (SELECT 1 FROM catalog_supplier_thickness_prices t WHERE t.product_supplier_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM tmp_d300_priced_products q WHERE q.product_id = c.product_id);
DROP TEMPORARY TABLE IF EXISTS tmp_d300_priced_products;

UPDATE catalog_product_suppliers c
  JOIN catalog_supplier_thickness_prices t ON t.product_supplier_id = c.id AND t.thickness_mm = 0
   SET c.price_basis = 'grid', c.is_cbm_primary = 0
 WHERE c.price_basis = 'cbm';

UPDATE catalog_product_suppliers SET is_cbm_primary = 0 WHERE price_basis = 'grid' AND is_cbm_primary = 1;

-- ---------------------------------------------------------------- data: typed supplier names → the Suppliers directory
-- Exact names only (spaces and capitals ignored, one active directory supplier with that name).
-- The rest is linked on Products › Link suppliers.
UPDATE catalog_product_suppliers cps
  JOIN (SELECT LOWER(TRIM(supplier_legal_name)) AS k, MIN(id) AS id, MIN(supplier_legal_name) AS nm
          FROM suppliers WHERE is_active = 1
         GROUP BY LOWER(TRIM(supplier_legal_name)) HAVING COUNT(*) = 1) d ON d.k = LOWER(TRIM(cps.supplier_name))
   SET cps.supplier_id = d.id, cps.supplier_name = d.nm
 WHERE cps.supplier_id IS NULL;
