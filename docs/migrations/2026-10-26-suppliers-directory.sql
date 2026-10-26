-- ================================================================
-- DB MIGRATION — 2026-10-26
-- Suppliers directory (owner request): a dedicated Suppliers admin
-- screen, built the same way as Logistics Partners — list with a type
-- filter, add, edit, deactivate/reactivate, and delete (only for a
-- supplier never used on an order). Suppliers were previously only ever
-- created from the order page's Stage 5 inline "Add Supplier" form,
-- which is retired.
--
-- 1. suppliers gains the contact fields Logistics Partners already has
--    (email, WhatsApp, city, state, contact person phone/WhatsApp,
--    notes) plus created_by/updated_at.
-- 2. A supplier can now hold several types (e.g. Quarry Owner AND
--    Factory Owner): new supplier_type_links table. Every supplier's
--    existing single suppliers.supplier_type is copied into it, then
--    that column is dropped.
-- 3. The order stays self-contained: order_supplier_po already
--    snapshots the supplier's identity when the Supplier PO terms are
--    saved; it now also snapshots the new contact fields. supplier_type
--    there now holds the ONE type chosen for that order.
-- 4. New permission manage_suppliers — Admin/MD/ED (and Super Admin)
--    by default, same tier as manage_logistics_partners.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): every ADD COLUMN / CREATE TABLE / DROP
-- COLUMN is guarded by an information_schema check, the type backfill
-- only runs while the old column still exists (and INSERT IGNORE skips
-- rows already copied), and the permission inserts are INSERT IGNORE.
-- ================================================================

SET NAMES utf8mb4;

-- 1. New contact columns on suppliers (one guarded ADD per column).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'email');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN email VARCHAR(255) NULL AFTER phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'whatsapp_number');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER email', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'city');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN city VARCHAR(120) NULL AFTER address', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'state');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN state VARCHAR(120) NULL AFTER city', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'contact_person_phone');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN contact_person_phone VARCHAR(30) NULL AFTER contact_person', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'contact_person_whatsapp');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN contact_person_whatsapp VARCHAR(30) NULL AFTER contact_person_phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'notes');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN notes VARCHAR(1000) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'created_by');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN created_by BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users(id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@c = 0, 'ALTER TABLE suppliers ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Multiple types per supplier. Values are the text of a
-- dropdown_options('supplier_type') option — no FK, same convention as
-- every other column that stores a dropdown option's text.
SET @c := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_type_links');
SET @sql := IF(@c = 0,
  'CREATE TABLE supplier_type_links (
    supplier_id   BIGINT UNSIGNED NOT NULL,
    supplier_type VARCHAR(50) NOT NULL,
    PRIMARY KEY (supplier_id, supplier_type),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Copy each supplier's existing single type, then drop the old column.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'supplier_type');
SET @sql := IF(@c = 1,
  'INSERT IGNORE INTO supplier_type_links (supplier_id, supplier_type)
   SELECT id, TRIM(supplier_type) FROM suppliers WHERE supplier_type IS NOT NULL AND TRIM(supplier_type) <> ''''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@c = 1, 'ALTER TABLE suppliers DROP COLUMN supplier_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. The order's own snapshot of the supplier's new contact fields.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_supplier_po' AND COLUMN_NAME = 'supplier_email');
SET @sql := IF(@c = 0,
  'ALTER TABLE order_supplier_po
     ADD COLUMN supplier_email                   VARCHAR(255) NULL AFTER supplier_phone,
     ADD COLUMN supplier_whatsapp_number         VARCHAR(30)  NULL AFTER supplier_email,
     ADD COLUMN supplier_city                    VARCHAR(120) NULL AFTER supplier_address,
     ADD COLUMN supplier_state                   VARCHAR(120) NULL AFTER supplier_city,
     ADD COLUMN supplier_contact_person_phone    VARCHAR(30)  NULL AFTER supplier_contact_person,
     ADD COLUMN supplier_contact_person_whatsapp VARCHAR(30)  NULL AFTER supplier_contact_person_phone',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. manage_suppliers permission — Admin/MD/ED by default (Super Admin
-- bypasses permission checks entirely).
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_suppliers', 'Manage suppliers', 'Add, edit, deactivate and delete suppliers (and their types and factory addresses) in the Suppliers directory the Supplier PO picks from. Same tier as manage_logistics_partners: Admin/MD/ED and Super Admin only by default.', 'catalog');
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director')
  AND p.permission_key = 'manage_suppliers';
