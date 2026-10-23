-- ================================================================
-- DB MIGRATION — 2026-10-23
-- Owner decision (2026-10-23): a per-user choice of UI template for
-- the application (Standard / Kanban), a company-wide default for new
-- users and anyone who never chose, and an admin on/off switch per
-- template.
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Resolution order at render time (see UiTemplateService/
-- uiTemplateService): the user's own preference, if that template is
-- still active -> the company default, if IT is still active ->
-- hard-coded 'standard', which can never itself be deactivated. A
-- user's preference pointing at a since-deactivated template falls
-- back to the company default, never straight to 'standard', unless
-- the default itself has also been deactivated.
--
-- Idempotent (safe to re-run).
-- ================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ui_templates (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key  VARCHAR(40) NOT NULL UNIQUE,
  name          VARCHAR(100) NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO ui_templates (template_key, name, is_active) VALUES
  ('standard', 'Standard', 1),
  ('kanban', 'Kanban Board', 1);

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'ui_template_preference_id'
);
SET @ddl = IF(@col_exists = 0,
  'ALTER TABLE users ADD COLUMN ui_template_preference_id BIGINT UNSIGNED NULL AFTER email_action_notifications_enabled',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_ui_template'
);
SET @fk_ddl = IF(@fk_exists = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_ui_template FOREIGN KEY (ui_template_preference_id) REFERENCES ui_templates(id)',
  'SELECT 1');
PREPARE fk_stmt FROM @fk_ddl;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'default_ui_template', 'standard', 'string', 'ui',
       'Which UI template (see Admin > UI Templates) a user sees when they have not chosen one for themselves on their own Account page. Must be an active template — picking it from the dropdown on this screen enforces that.',
       0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'default_ui_template');
