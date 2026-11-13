-- 2026-11-13a — Suppliers, products and the configured base price (Push 1 of 3)
-- Owner decision D-359 (13 Nov 2026, owner "go with recommendation").
--
-- What it adds (nothing visible changes and NO selling price changes):
--   catalog_product_suppliers
--     * offer_active            1 = this supplier's offer of this product is used; 0 = switched off.
--                               Every existing offer starts ON. An offer of an INACTIVE supplier
--                               (suppliers.is_active = 0) is never used, whatever this flag says.
--   catalog_products
--     * configured_base_price_inr      the product's base price in ₹ (its own, "configured")
--     * configured_base_unit           what the base price is per: 'm2' (a thickness in mm),
--                                      'CBM' (50 mm and above) or the product's own unit
--     * configured_base_thickness_mm   the thickness the base price is for (0 = 50 mm and above
--                                      per CBM; NULL = a plain price per unit)
--     * configured_base_follows_supplier  1 = the base price follows the supplier price "used for
--                                      pricing" (how every product priced until now; the Configure
--                                      screen, Push 3, sets 0 when staff fix the price themselves)
--     * configured_at / configured_by  when and by whom it was last set
--     * pricing_follow_global          1 = "Reset all to global values": the product follows the
--                                      Pricing settings and ignores its category's values
--     (the product's own "other costs" value already exists: pricing_other_cost_mode / _value)
--   product_categories
--     * margin_pct, other_cost_pct, buffer_pct   the category's own %s (empty = follow global)
--   pricing_settings
--     * weight_t_per_cbm 2.70, container_max_t 27.00, container_cbm 8.00, thick_k_factor 40 —
--       global physical defaults, used only when neither the product nor its category has a value.
--
-- Backfill (only where nothing is set yet, so a second run changes nothing):
--   * every existing offer is active (the column default);
--   * every product's configured base price = the price it is priced from today: the base-thickness
--     price of the supplier row "used for pricing" (a price for every thickness), or that row's
--     price per unit; configured_at is set for products that have such a price. Products follow
--     that supplier price (configured_base_follows_supplier = 1), exactly as before.
--
-- Safe to run twice: columns through information_schema + PREPARE (MySQL-safe, no IF [NOT] EXISTS
-- on columns or indexes); the settings with INSERT IGNORE; the backfill only fills empty values.
-- A fresh install (schema.sql + seed.sql) already has the end state.
-- Run after 2026-11-12j, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13a-suppliers-products-configure.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- columns
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_product_suppliers ADD COLUMN offer_active TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_product_suppliers' AND COLUMN_NAME = 'offer_active');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN configured_base_price_inr DECIMAL(14,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'configured_base_price_inr');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN configured_base_unit VARCHAR(20) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'configured_base_unit');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN configured_base_thickness_mm INT NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'configured_base_thickness_mm');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN configured_base_follows_supplier TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'configured_base_follows_supplier');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN configured_at DATETIME NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'configured_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN configured_by BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'configured_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN pricing_follow_global TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'pricing_follow_global');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN margin_pct DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'margin_pct');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN other_cost_pct DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'other_cost_pct');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE product_categories ADD COLUMN buffer_pct DECIMAL(6,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_categories' AND COLUMN_NAME = 'buffer_pct');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ---------------------------------------------------------------- global physical defaults
INSERT IGNORE INTO pricing_settings (setting_key, setting_value) VALUES
  ('weight_t_per_cbm', '2.70'),
  ('container_max_t', '27.00'),
  ('container_cbm', '8.00'),
  ('thick_k_factor', '40');

-- ---------------------------------------------------------------- backfill: configured base price
-- (a) A price for every thickness used for pricing: the stored price at the row's base thickness
--     (its own, else the category's base thickness option, else 20 mm).
UPDATE catalog_products p
  JOIN catalog_product_suppliers cps ON cps.product_id = p.id AND cps.is_primary = 1 AND cps.price_basis = 'grid'
  JOIN catalog_supplier_thickness_prices tp ON tp.product_supplier_id = cps.id
   AND tp.thickness_mm = COALESCE(cps.base_thickness_mm,
         (SELECT MIN(o.thickness_mm) FROM spec_options o JOIN spec_definitions d ON d.id = o.spec_id
           WHERE d.kind = 'thickness' AND d.product_id IS NULL AND o.product_id IS NULL AND o.is_base = 1
             AND d.category_id = COALESCE(p.category_id, (SELECT MIN(c.id) FROM product_categories c WHERE c.is_system = 1))),
         20)
   SET p.configured_base_price_inr = tp.price_inr,
       p.configured_base_thickness_mm = tp.thickness_mm,
       p.configured_base_unit = IF(tp.thickness_mm = 0, 'CBM', 'm2'),
       p.configured_at = NOW()
 WHERE p.configured_base_price_inr IS NULL;

-- (b) A plain price per unit used for pricing: the supplier's own price, or the sum of its cost parts.
UPDATE catalog_products p
  JOIN catalog_product_suppliers cps ON cps.product_id = p.id AND cps.is_primary = 1 AND cps.price_basis = 'unit'
   SET p.configured_base_price_inr = IF(cps.fob_source = 'direct', cps.fob_value,
         COALESCE(cps.factory_cost, p.default_factory_cost, 0) + COALESCE(cps.transportation_cost, p.default_transportation_cost, 0)
         + COALESCE(cps.packing_cost, p.default_packing_cost, 0) + COALESCE(cps.loading_cost, p.default_loading_cost, 0)
         + COALESCE(cps.cha_cost, p.default_cha_cost, 0)),
       p.configured_base_thickness_mm = NULL,
       p.configured_base_unit = COALESCE(NULLIF(p.reference_unit, ''), 'unit'),
       p.configured_at = NOW()
 WHERE p.configured_base_price_inr IS NULL
   AND (cps.fob_source <> 'direct' OR cps.fob_value IS NOT NULL);
