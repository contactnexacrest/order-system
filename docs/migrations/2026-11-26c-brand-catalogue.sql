-- 2026-11-26c — Owner decision D-435 (26 Nov 2026, built on D-433): brand catalogue.
--  - product_categories.parent_id: a sub-category (e.g. Natural Stones › Granite). Empty = a main category.
--    A sub-category follows its main category's settings; each setting it sets itself overrides.
--  - product_categories.brand_id: the brand a main category belongs to (a sub-category carries its main category's brand).
--    Empty = company-wide (every brand), exactly as today.
--  - catalog_products.approval_status / approval_reason / submitted_by / approved_by / approved_at: a product added by a
--    brand manager waits for approval (setting below, on by default). Every existing product is 'approved'.
--  - Permission approve_new_products (MD, ED by default; any role or person in Admin › Roles).
--  - Setting brand_products_need_approval = 1 (Admin › Settings).
--  - Brand pricing (margin %, other costs %, buffer %) is kept in associate_brand_settings (no new table).
-- PLAIN ALTER (owner D-371): the ALTER statements come last; a second run stops at "Duplicate column name", which is harmless
-- (the INSERTs before them are guarded with WHERE NOT EXISTS). Needs 2026-11-26a first (associate_brands).
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'approve_new_products', 'Approve new products', 'Approve or reject a product a brand manager added, before it can be quoted, ordered or seen by associates (reason kept)', 'catalog'
WHERE NOT EXISTS (SELECT 1 FROM permissions x WHERE x.permission_key = 'approve_new_products');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'approve_new_products'
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'brand_products_need_approval', '1', 'boolean', 'products', 'On (default) = a product a brand manager adds waits in Approvals › New products until someone with "Approve new products" (MD, ED) approves it; until then it cannot be quoted, ordered or seen by associates. Off = it is live at once (the Audit Log still records it).', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'brand_products_need_approval');

ALTER TABLE product_categories ADD COLUMN parent_id BIGINT UNSIGNED NULL;
ALTER TABLE product_categories ADD CONSTRAINT fk_product_categories_parent FOREIGN KEY (parent_id) REFERENCES product_categories(id);
ALTER TABLE product_categories ADD COLUMN brand_id BIGINT UNSIGNED NULL;
ALTER TABLE product_categories ADD CONSTRAINT fk_product_categories_brand FOREIGN KEY (brand_id) REFERENCES associate_brands(id);
ALTER TABLE catalog_products ADD COLUMN approval_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved';
ALTER TABLE catalog_products ADD COLUMN approval_reason VARCHAR(300) NULL;
ALTER TABLE catalog_products ADD COLUMN submitted_by BIGINT UNSIGNED NULL;
ALTER TABLE catalog_products ADD COLUMN approved_by BIGINT UNSIGNED NULL;
ALTER TABLE catalog_products ADD COLUMN approved_at DATETIME NULL;
