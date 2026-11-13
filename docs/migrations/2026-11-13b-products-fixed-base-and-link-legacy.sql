-- 2026-11-13b — Products: the base price is fixed, legacy supplier prices linked (Push 3 of 3)
-- Owner decision D-361 (13 Nov 2026, owner "go with recommendation").
--
-- What it does (NO selling price changes):
--   1. The configured base price never changes by itself (owner decision 1). Every product that
--      is priced today gets a FIXED base price at exactly the price it uses today:
--        * first the stored copy is refreshed from the supplier price "used for pricing" (only a
--          usable one: the offer switched on and its supplier active), the same way as 13a;
--        * then configured_base_follows_supplier = 0 for every product that has a base price.
--      When a supplier's price changes later, the product shows "Supplier prices changed: highest
--      is now ₹X" with a "Use ₹X" button — nothing changes until a manager clicks it.
--   2. Supplier prices typed before the Suppliers directory (D-303, no supplier record linked)
--      are linked automatically where the typed name equals exactly one ACTIVE supplier's legal
--      name (spaces at the ends and capitals ignored). The rest are listed on Products › "Link
--      these prices (N)" for a manager to link by hand. Each automatic link gets a price-history
--      row ("Linked to supplier").
--   3. catalog_products.legacy_no_supplier = 1 for the products that exist now and have no
--      linked supplier (first run only): they stay active and quotable with a red
--      "No supplier yet" badge until a supplier is linked.
--   4. order_supplier_po: supplier_offer_id, quoted_unit_price_inr, price_override_reason — the
--      supplier offer chosen on the Supplier PO terms form, its quoted price, and the reason
--      when staff agreed a different price (also in the Audit Log). Issued POs are not touched.
--   5. One Audit Log row MIGRATION_2026_11_13B (with the counts) marks the migration as done.
--
-- Safe to run twice: columns through information_schema + PREPARE (MySQL-safe, no IF [NOT]
-- EXISTS on columns or indexes); the refresh only touches products that still follow the
-- supplier; the link only touches rows with no supplier; the legacy flag and the marker only on
-- the first run. ONLY_FULL_GROUP_BY-safe. A fresh install (schema.sql + seed.sql) already has the
-- end state. Run after 2026-11-13a, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13b-products-fixed-base-and-link-legacy.sql
SET NAMES utf8mb4;
SET @db := DATABASE();

-- ---------------------------------------------------------------- columns
SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN legacy_no_supplier TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'legacy_no_supplier');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_supplier_po ADD COLUMN supplier_offer_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'supplier_offer_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_supplier_po ADD COLUMN quoted_unit_price_inr DECIMAL(14,2) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'quoted_unit_price_inr');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_supplier_po ADD COLUMN price_override_reason VARCHAR(500) NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'price_override_reason');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- First run? (the marker row is written at the end)
SET @first := (SELECT IF(COUNT(*) = 0, 1, 0) FROM audit_log WHERE action_type = 'MIGRATION_2026_11_13B');

-- ---------------------------------------------------------------- 1. the base price is fixed at today's price
-- (a) A price for every thickness used for pricing (usable): the stored price at the row's base
--     thickness (its own, else the category's base thickness option, else 20 mm).
UPDATE catalog_products p
  JOIN catalog_product_suppliers cps ON cps.product_id = p.id AND cps.is_primary = 1 AND cps.price_basis = 'grid'
   AND cps.offer_active = 1
   AND (cps.supplier_id IS NULL OR EXISTS (SELECT 1 FROM suppliers sd WHERE sd.id = cps.supplier_id AND sd.is_active = 1))
  JOIN catalog_supplier_thickness_prices tp ON tp.product_supplier_id = cps.id
   AND tp.thickness_mm = COALESCE(cps.base_thickness_mm,
         (SELECT MIN(o.thickness_mm) FROM spec_options o JOIN spec_definitions d ON d.id = o.spec_id
           WHERE d.kind = 'thickness' AND d.product_id IS NULL AND o.product_id IS NULL AND o.is_base = 1
             AND d.category_id = COALESCE(p.category_id, (SELECT MIN(c.id) FROM product_categories c WHERE c.is_system = 1))),
         20)
   SET p.configured_base_price_inr = tp.price_inr,
       p.configured_base_thickness_mm = tp.thickness_mm,
       p.configured_base_unit = IF(tp.thickness_mm = 0, 'CBM', 'm2'),
       p.configured_at = COALESCE(p.configured_at, NOW())
 WHERE p.configured_base_follows_supplier = 1;

-- (b) A plain price per unit used for pricing (usable): the supplier's own price, or the sum of its cost parts.
UPDATE catalog_products p
  JOIN catalog_product_suppliers cps ON cps.product_id = p.id AND cps.is_primary = 1 AND cps.price_basis = 'unit'
   AND cps.offer_active = 1
   AND (cps.supplier_id IS NULL OR EXISTS (SELECT 1 FROM suppliers sd WHERE sd.id = cps.supplier_id AND sd.is_active = 1))
   SET p.configured_base_price_inr = IF(cps.fob_source = 'direct', cps.fob_value,
         COALESCE(cps.factory_cost, p.default_factory_cost, 0) + COALESCE(cps.transportation_cost, p.default_transportation_cost, 0)
         + COALESCE(cps.packing_cost, p.default_packing_cost, 0) + COALESCE(cps.loading_cost, p.default_loading_cost, 0)
         + COALESCE(cps.cha_cost, p.default_cha_cost, 0)),
       p.configured_base_thickness_mm = NULL,
       p.configured_base_unit = COALESCE(NULLIF(p.reference_unit, ''), 'unit'),
       p.configured_at = COALESCE(p.configured_at, NOW())
 WHERE p.configured_base_follows_supplier = 1
   AND (cps.fob_source <> 'direct' OR cps.fob_value IS NOT NULL);

-- (c) Fixed: every product with a base price no longer follows the supplier price.
SET @fixed := (SELECT COUNT(*) FROM catalog_products WHERE configured_base_follows_supplier = 1 AND configured_base_price_inr IS NOT NULL);
UPDATE catalog_products
   SET configured_base_follows_supplier = 0, configured_at = COALESCE(configured_at, NOW())
 WHERE configured_base_follows_supplier = 1 AND configured_base_price_inr IS NOT NULL;

-- ---------------------------------------------------------------- 2. legacy supplier prices linked by name
-- Exactly one supplier with that legal name (ends trimmed, capitals ignored), and it is active.
DROP TEMPORARY TABLE IF EXISTS tmp_13b_names;
CREATE TEMPORARY TABLE tmp_13b_names (k VARCHAR(255) NOT NULL PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
INSERT INTO tmp_13b_names (k, supplier_id)
SELECT n.k, MIN(n.id) FROM (SELECT LOWER(TRIM(s.supplier_legal_name)) AS k, s.id, s.is_active FROM suppliers s) n
 GROUP BY n.k HAVING COUNT(*) = 1 AND MIN(n.is_active) = 1;

DROP TEMPORARY TABLE IF EXISTS tmp_13b_links;
CREATE TEMPORARY TABLE tmp_13b_links (offer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB;
INSERT INTO tmp_13b_links (offer_id, supplier_id)
SELECT cps.id, t.supplier_id FROM catalog_product_suppliers cps JOIN tmp_13b_names t ON t.k = LOWER(TRIM(cps.supplier_name))
 WHERE cps.supplier_id IS NULL;

UPDATE catalog_product_suppliers cps JOIN tmp_13b_links l ON l.offer_id = cps.id SET cps.supplier_id = l.supplier_id WHERE cps.supplier_id IS NULL;
INSERT INTO catalog_supplier_price_history (product_supplier_id, what, old_value, new_value, changed_by)
SELECT l.offer_id, 'Linked to supplier (migration 13b)', 'typed name only', LEFT(s.supplier_legal_name, 80), NULL
  FROM tmp_13b_links l JOIN suppliers s ON s.id = l.supplier_id;
SET @linked := (SELECT COUNT(*) FROM tmp_13b_links);
SET @unlinked := (SELECT COUNT(*) FROM catalog_product_suppliers WHERE supplier_id IS NULL);
DROP TEMPORARY TABLE IF EXISTS tmp_13b_links;
DROP TEMPORARY TABLE IF EXISTS tmp_13b_names;

-- ---------------------------------------------------------------- 3. products with no supplier yet (first run only)
UPDATE catalog_products p
   SET p.legacy_no_supplier = 1
 WHERE @first = 1
   AND NOT EXISTS (SELECT 1 FROM catalog_product_suppliers cps WHERE cps.product_id = p.id AND cps.supplier_id IS NOT NULL);
SET @legacy := (SELECT COUNT(*) FROM catalog_products WHERE legacy_no_supplier = 1);

-- ---------------------------------------------------------------- 4. done (the marker the read-only check looks for)
INSERT INTO audit_log (user_id, action_type, entity_type, entity_id, field_name, old_value, new_value, reason)
SELECT NULL, 'MIGRATION_2026_11_13B', 'catalog_products', NULL, 'configured_base_follows_supplier', NULL,
       CONCAT('Base prices fixed at today''s price: ', @fixed, ' products; legacy supplier prices linked by name: ', @linked,
              '; still to link by hand: ', @unlinked, '; products with no supplier yet: ', @legacy),
       'Owner decision D-361 (13 Nov 2026)'
  FROM DUAL WHERE @first = 1;

SELECT @fixed AS products_fixed_now, @linked AS prices_linked_now, @unlinked AS prices_to_link_by_hand, @legacy AS products_no_supplier_yet;
