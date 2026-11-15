-- 2026-11-15a — Owner decision D-378 (15 Nov 2026): sales targets. The company target (monthly costs
-- and profit), the target period and multiple, designation and personal targets with a build-up for
-- new joiners, associate targets, the Targets page, the cockpit and dashboard boxes and three daily
-- alerts. New tables sales_target_settings, sales_target_company, sales_targets, sales_target_credits
-- (CREATE TABLE IF NOT EXISTS: a second run changes nothing); new empty columns users.joined_on,
-- orders.lost_category and orders.sales_credit_user_id; the default settings and the permission
-- "Manage sales targets" for the MD and the ED (INSERT … WHERE NOT EXISTS). Order data is only read.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): phpMyAdmin on the shared host refuses the information_schema +
-- PREPARE guard. A second run only stops with "#1060 Duplicate column name", which is harmless — the
-- column ALTERs come last, after the tables, settings and permission.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS sales_target_settings (
  setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales_target_company (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  effective_from DATE NOT NULL,
  costs_monthly  DECIMAL(14,2) NULL,
  profit_monthly DECIMAL(14,2) NULL,
  reason         VARCHAR(500) NOT NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sales_target_company_from (effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales_targets (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope           ENUM('designation','user','associate') NOT NULL,
  ref_id          BIGINT UNSIGNED NOT NULL,
  containers      DECIMAL(6,1) NULL,
  new_buyers      INT UNSIGNED NULL,
  quotations      INT UNSIGNED NULL,
  multiple        DECIMAL(4,1) NULL,
  buildup_enabled TINYINT(1) NULL,
  reason          VARCHAR(500) NULL,
  reviewed_at     DATETIME NULL,
  updated_by      BIGINT UNSIGNED NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_target (scope, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales_target_credits (
  order_id             BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  client_id            BIGINT UNSIGNED NOT NULL,
  confirmed_on         DATE NOT NULL,
  how                  ENUM('advance','lc','po_pi') NOT NULL,
  containers_confirmed INT NOT NULL,
  shipped_on           DATE NULL,
  containers_shipped   INT NULL,
  reversed_on          DATE NULL,
  reversed_why         VARCHAR(20) NULL,
  is_new_buyer         TINYINT(1) NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sales_target_credits_client (client_id, confirmed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO sales_target_settings (setting_key, setting_value)
SELECT k.setting_key, k.setting_value FROM (
  SELECT 'period' AS setting_key, 'quarter' AS setting_value
  UNION ALL SELECT 'multiple', '3'
  UNION ALL SELECT 'buildup_months', '3'
  UNION ALL SELECT 'review_months', '12'
  UNION ALL SELECT 'alert_pct', '60'
  UNION ALL SELECT 'alert_behind_recipients', NULL
  UNION ALL SELECT 'alert_review_recipients', NULL
  UNION ALL SELECT 'alert_no_owner_recipients', NULL
) k WHERE NOT EXISTS (SELECT 1 FROM sales_target_settings s WHERE s.setting_key = k.setting_key);
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'manage_sales_targets', 'Manage sales targets', 'Set the company target, the target multiple, designation and personal targets, move an order''s target credit; see the Targets page (D-378).', 'reports'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'manage_sales_targets');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_sales_targets'
 WHERE r.name IN ('Managing Director', 'Founder & Managing Director', 'Executive Director', 'Founder & Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
-- SOP chapter 33 (Sales targets): on, and ticked for the management roles (D-266: new chapters are hidden
-- from other staff until assigned).
INSERT INTO sop_chapters (slug, is_enabled) SELECT '33-sales-targets', 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM sop_chapters WHERE slug = '33-sales-targets');
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, '33-sales-targets' FROM roles r
  WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director', 'System Administrator')
    AND NOT EXISTS (SELECT 1 FROM sop_role_chapters x WHERE x.role_id = r.id AND x.slug = '33-sales-targets');

ALTER TABLE users ADD COLUMN joined_on DATE NULL;
ALTER TABLE orders ADD COLUMN lost_category VARCHAR(30) NULL;
ALTER TABLE orders ADD COLUMN sales_credit_user_id BIGINT UNSIGNED NULL;
