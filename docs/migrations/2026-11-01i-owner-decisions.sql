-- Owner decisions of 1 Nov 2026 ("A all yes, B all yes, C all as recommended").
-- MySQL-safe (no ADD COLUMN IF NOT EXISTS), safe to run twice.
--
-- A-8 (QA R7-N13): System Administrator is "no business money" — it loses the
--      full data export (every price, payment, CAFIN figure, Zoho secret and
--      password hash). MD/ED/Admin keep it.
-- C-8 (O-8): the supplier master is buying-side data — Procurement Executive
--      gets "Manage suppliers" (Procurement Manager and Director already have it).
-- C-4 (O-4, D-074): "advance payment will only and only be zero in case of LC" —
--      a preset runs without an advance only with its own switch, on for the
--      Letter of Credit presets.
-- C-7 (O-7): the daily per-person digest is sent once a day per person.

SET NAMES utf8mb4;

DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE r.name = 'System Administrator' AND p.permission_key = 'data_export_run';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_suppliers'
 WHERE r.name = 'Procurement Executive'
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_presets' AND COLUMN_NAME = 'allow_zero_advance');
SET @sql := IF(@col = 0, 'ALTER TABLE payment_presets ADD COLUMN allow_zero_advance TINYINT(1) NOT NULL DEFAULT 0 AFTER show_bl_instructions', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE payment_presets SET allow_zero_advance = 1 WHERE payment_method = 'LC' AND allow_zero_advance = 0;

CREATE TABLE IF NOT EXISTS daily_digest_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  digest_date DATE NOT NULL,
  items INT NOT NULL DEFAULT 0,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_daily_digest_user_date (user_id, digest_date),
  CONSTRAINT fk_daily_digest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- C-7 (O-7): every order has an assignee — an existing order with none gets its creator.
INSERT INTO order_assignees (order_id, user_id, assigned_by)
SELECT o.id, o.created_by, o.created_by FROM orders o
 JOIN users u ON u.id = o.created_by
 WHERE NOT EXISTS (SELECT 1 FROM order_assignees a WHERE a.order_id = o.id);
