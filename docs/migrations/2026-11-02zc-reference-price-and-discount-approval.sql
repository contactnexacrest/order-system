-- Owner decision (2 Nov 2026, "reference price yes with approval", D-240):
--  * catalog_products.reference_price / reference_currency / reference_unit — the
--    product's reference SELLING price (seen only by view_selling_prices holders);
--  * order_products.catalog_product_id — the Products-menu product a line was picked
--    from (nullable, ON DELETE SET NULL), and order_products.price_set_by — who last
--    set the line's unit price (for the self-approval rule);
--  * table order_price_approvals — the MD/ED approval of a discount below the warning
--    level, with a snapshot of the approved lines;
--  * settings ref_price_note_enabled (1), ref_price_note_pct (5), ref_price_warning_pct (10)
--    in Admin → Settings → "Products & pricing";
--  * permission approve_price_discount (Managing Director, Executive Director) and the
--    self-approval rule 'price_discount' (default MD + ED).
-- MySQL-safe (information_schema + PREPARE, no IF NOT EXISTS on columns), safe to run twice.
SET NAMES utf8mb4;

SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN reference_price DECIMAL(14,2) NULL AFTER default_cha_cost',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'reference_price');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN reference_currency VARCHAR(10) NULL AFTER reference_price',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'reference_currency');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN reference_unit VARCHAR(30) NULL AFTER reference_currency',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'reference_unit');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN catalog_product_id BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'catalog_product_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD COLUMN price_set_by BIGINT UNSIGNED NULL',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'price_set_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_products ADD CONSTRAINT fk_order_products_catalog_product FOREIGN KEY (catalog_product_id) REFERENCES catalog_products(id) ON DELETE SET NULL',
  'SELECT 1') FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_products' AND CONSTRAINT_NAME = 'fk_order_products_catalog_product');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS order_price_approvals (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     BIGINT UNSIGNED NOT NULL,
  approved_by  BIGINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  snapshot     TEXT NOT NULL,          -- JSON: [{line_id, line_no, unit_price, reference_price, pct}]
  approved_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lapsed_at    DATETIME NULL,
  KEY idx_order_price_approvals_order (order_id),
  CONSTRAINT fk_order_price_approvals_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'ref_price_note_enabled', '1', 'boolean', 'products & pricing', 'Show the difference from the reference price on order lines (only to people who may see selling prices; never on a document or in the client portal).', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'ref_price_note_enabled');
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'ref_price_note_pct', '5', 'number', 'products & pricing', 'Show "X% below reference price" only when the line price is at least this many % below the product''s reference price.', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'ref_price_note_pct');
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'ref_price_warning_pct', '10', 'number', 'products & pricing', 'At this many % below the reference price the note turns red and the Proforma Invoice needs an MD/ED discount approval first.', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'ref_price_warning_pct');

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'approve_price_discount', 'Approve price discounts', 'Approve an order whose line price is below the product''s reference price by the warning % or more, so its Proforma Invoice can be generated (D-240).', 'Orders'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'approve_price_discount');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'approve_price_discount'
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT 'price_discount', r.id FROM roles r
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = 'price_discount')
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action_type = 'SELF_APPROVAL_RULE_CHANGED' AND a.field_name = 'price_discount');
