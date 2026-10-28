-- QA Round 5, R5-03 (owner decision 2026-10-28, Option A): an order on a
-- payment preset marked "Requires MD approval" needs its terms approved by
-- someone holding the new "Approve payment terms" permission (default
-- Admin, MD, ED — editable per role or person) before the Quotation can be
-- generated or sent. Also: "Extended Terms — Before Shipment" no longer
-- needs MD approval (owner decision). Safe to run more than once.
SET NAMES utf8mb4;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_terms_approved_by') = 0, 'ALTER TABLE orders ADD COLUMN payment_terms_approved_by BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_terms_approved_at') = 0, 'ALTER TABLE orders ADD COLUMN payment_terms_approved_at DATETIME NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_terms_approved_by' AND REFERENCED_TABLE_NAME = 'users') = 0, 'ALTER TABLE orders ADD FOREIGN KEY (payment_terms_approved_by) REFERENCES users(id)', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('approve_payment_terms', 'Approve payment terms', 'Approve an order''s payment terms when its preset is marked "Requires MD approval" — needed before the Quotation can be generated or sent. Default: Admin, MD, ED.', 'orders');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'approve_payment_terms'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

UPDATE payment_presets SET requires_md_approval = 0 WHERE preset_name = 'Extended Terms — Before Shipment';

-- Orders that already have a Quotation were quoted before this rule
-- existed: count their terms as approved so nothing already in progress
-- is held up (recorded with no approver).
UPDATE orders o
JOIN payment_presets pp ON pp.id = o.payment_preset_id
SET o.payment_terms_approved_at = o.created_at
WHERE pp.requires_md_approval = 1
  AND o.payment_terms_approved_at IS NULL
  AND EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE d.order_id = o.id AND dt.code = 'QT');
