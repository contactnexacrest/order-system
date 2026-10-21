-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: schema.sql supplier_factory_addresses table + seed.sql
-- dropdown_options('dimensional_tolerance') rows (user-reported batch
-- item 12)
-- Covers items: Dimensional Tolerance dropdown, Delivery Location as a
-- per-supplier factory-address dropdown
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- 1) Creates supplier_factory_addresses — a new, separate one-to-many
--    list of factory/delivery addresses per supplier, distinct from
--    suppliers.address (that stays the supplier's own single
--    registered/billing address). Empty on every existing database;
--    no data migrated into it, since no such list existed before.
--
-- 2) Adds 5 dropdown_options rows under list_key
--    'dimensional_tolerance' (±1 mm default, ±0.5 mm, ±2 mm, ±3 mm,
--    "As per approved sample") — the Supplier PO Terms form's
--    Dimensional Tolerance field moves from free text to this
--    admin-editable dropdown, same pattern as Unit/Surface Finish/GST
--    Rate on the same form. order_supplier_po.dimensional_tolerance
--    itself is untouched (still a plain VARCHAR, no schema change).
--
-- Idempotent (safe to re-run) — both changes are guarded.
-- ================================================================

SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_factory_addresses'
);
SET @ddl = IF(@tbl_exists = 0,
  'CREATE TABLE supplier_factory_addresses (
     id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     supplier_id BIGINT UNSIGNED NOT NULL,
     label       VARCHAR(100) NULL,
     address     VARCHAR(255) NOT NULL,
     created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
   ) ENGINE=InnoDB',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT v.list_key, v.option_value, v.sort_order, v.is_default, v.is_active
FROM (
  SELECT 'dimensional_tolerance' AS list_key, '±1 mm' AS option_value, 1 AS sort_order, 1 AS is_default, 1 AS is_active
  UNION ALL SELECT 'dimensional_tolerance', '±0.5 mm', 2, 0, 1
  UNION ALL SELECT 'dimensional_tolerance', '±2 mm', 3, 0, 1
  UNION ALL SELECT 'dimensional_tolerance', '±3 mm', 4, 0, 1
  UNION ALL SELECT 'dimensional_tolerance', 'As per approved sample', 5, 0, 1
) v
WHERE NOT EXISTS (
  SELECT 1 FROM dropdown_options d WHERE d.list_key = v.list_key AND d.option_value = v.option_value
);
