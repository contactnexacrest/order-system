-- Owner decision D-215 (5a, confirmed twice): product categories with their
-- own attributes, set in Products → Categories. "Natural Stone" is today's
-- products: its attributes are the existing Dimensions and Finish, so stone
-- lines and every document print word for word as before. An order line of
-- another category keeps its own attribute values, printed in the same
-- place on the documents. Safe to run twice.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_category_attributes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(60) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_pca_category_label (category_id, label),
  CONSTRAINT fk_pca_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO product_categories (name, is_system, is_active, sort_order)
SELECT 'Natural Stone', 1, 1, 0 WHERE NOT EXISTS (SELECT 1 FROM product_categories WHERE is_system = 1);

INSERT INTO product_category_attributes (category_id, label, sort_order)
SELECT c.id, a.label, a.sort_order FROM product_categories c
JOIN (SELECT 'Dimensions' AS label, 1 AS sort_order UNION ALL SELECT 'Finish', 2) a
WHERE c.is_system = 1
  AND NOT EXISTS (SELECT 1 FROM product_category_attributes x WHERE x.category_id = c.id AND x.label = a.label);

-- The order line's category (NULL = Natural Stone, as every existing line) and, for another category, its attribute values.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'category_id');
SET @sql := IF(@col = 0, 'ALTER TABLE order_products ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER sac_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_products' AND COLUMN_NAME = 'attribute_values');
SET @sql := IF(@col = 0, 'ALTER TABLE order_products ADD COLUMN attribute_values TEXT NULL AFTER category_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Owner decision D-215 (5a): Products → Categories is managed by whoever manages the product catalogue (manage_product_catalog) — no new permission.
