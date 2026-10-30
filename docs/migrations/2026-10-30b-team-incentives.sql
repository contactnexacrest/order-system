-- Owner decision 2026-10-30 (mockups approved): the team incentive program
-- and the associate register. An order whose buyer has fully paid and whose
-- profit (after any associate commission) reaches the minimum earns the team
-- a reward, from the reward table or the amount set on that order, split by
-- role; associates earn their contract rate on the clients they introduced.
-- Each becomes the order's cost (a commission cost entry) when the buyer has
-- fully paid, and is paid in the incentive month (incentive cut-off day).
-- Safe to run more than once.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS incentive_settings (
  setting_key   VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO incentive_settings (setting_key, setting_value) VALUES
  ('program_enabled', '0'),
  ('program_start_date', ''),
  ('min_profit_inr', '300000'),
  ('reward_table', '[{"from":300000,"reward":40000},{"from":500000,"reward":60000}]'),
  ('new_client_bonus_inr', '10000'),
  ('role_split', '{"sales":40,"export":20,"marketing":15,"logistics":15,"accounts":10}'),
  ('cutoff_day', '20');

CREATE TABLE IF NOT EXISTS user_incentive_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role    ENUM('sales','export','marketing','logistics','accounts') NOT NULL,
  PRIMARY KEY (user_id, role),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS associates (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(150) NOT NULL,
  based_in            ENUM('india','abroad') NOT NULL DEFAULT 'india',
  country             VARCHAR(80) NULL,
  currency_code       CHAR(3) NOT NULL DEFAULT 'INR',
  email               VARCHAR(190) NULL,
  phone               VARCHAR(40) NULL,
  tax_id              VARCHAR(50) NULL,
  agreement_signed_on DATE NULL,
  rate_1_5            DECIMAL(5,2) NOT NULL DEFAULT 0.70,
  rate_6_10           DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  rate_11_20          DECIMAL(5,2) NOT NULL DEFAULT 1.50,
  rate_21_40          DECIMAL(5,2) NOT NULL DEFAULT 1.75,
  rate_41_plus        DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  notes               VARCHAR(500) NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'associate_id') = 0, 'ALTER TABLE clients ADD COLUMN associate_id BIGINT UNSIGNED NULL, ADD COLUMN associate_registered_on DATE NULL, ADD CONSTRAINT fk_clients_associate FOREIGN KEY (associate_id) REFERENCES associates(id)', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_cost_entries' AND COLUMN_NAME = 'incentive_kind') = 0, 'ALTER TABLE order_cost_entries ADD COLUMN incentive_kind ENUM(''team'',''associate'') NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

CREATE TABLE IF NOT EXISTS order_incentives (
  order_id                BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  reward_override_inr     DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_excluded             TINYINT(1) NOT NULL DEFAULT 0,
  associate_mode          ENUM('auto','yes','no') NOT NULL DEFAULT 'auto',
  people_customised       TINYINT(1) NOT NULL DEFAULT 0,
  locked_at               TIMESTAMP NULL,
  fully_paid_on           DATE NULL,
  incentive_month         CHAR(7) NULL,
  profit_basis_inr        DECIMAL(14,2) NULL,
  team_total_inr          DECIMAL(14,2) NULL,
  new_client_bonus_inr    DECIMAL(14,2) NULL,
  associate_id            BIGINT UNSIGNED NULL,
  associate_order_number  INT NULL,
  associate_rate_pct      DECIMAL(5,2) NULL,
  associate_amount_fc     DECIMAL(14,2) NULL,
  associate_currency      CHAR(3) NULL,
  associate_amount_inr    DECIMAL(14,2) NULL,
  team_cost_entry_id      BIGINT UNSIGNED NULL,
  associate_cost_entry_id BIGINT UNSIGNED NULL,
  updated_by              BIGINT UNSIGNED NULL,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (associate_id) REFERENCES associates(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_incentive_people (
  order_id BIGINT UNSIGNED NOT NULL,
  role     ENUM('sales','export','marketing','logistics','accounts') NOT NULL,
  user_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (order_id, role, user_id),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incentive_payouts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        BIGINT UNSIGNED NOT NULL,
  payee_kind      ENUM('staff','associate') NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  associate_id    BIGINT UNSIGNED NULL,
  role            VARCHAR(20) NOT NULL,
  amount_inr      DECIMAL(14,2) NOT NULL,
  amount_fc       DECIMAL(14,2) NULL,
  currency_code   CHAR(3) NULL,
  incentive_month CHAR(7) NOT NULL,
  status          ENUM('due','paid') NOT NULL DEFAULT 'due',
  paid_on         DATE NULL,
  paid_inr        DECIMAL(14,2) NULL,
  tds_inr         DECIMAL(14,2) NULL,
  paid_by         BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (associate_id) REFERENCES associates(id),
  FOREIGN KEY (paid_by) REFERENCES users(id),
  INDEX idx_incentive_payouts_month (incentive_month),
  INDEX idx_incentive_payouts_user (user_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_incentives', 'Manage team incentives', 'See and change the incentive settings, each order''s incentive and profit basis, the associate register and every payout. Owner decision 2026-10-30: you and the MD only.', 'incentives'),
  ('pay_incentives', 'Pay incentives', 'See the incentive payouts list (person, order, amount) and record payments. No profit, no settings, cannot change amounts.', 'incentives'),
  ('view_own_incentives', 'View own incentives', 'See your own incentive amounts once the buyer has fully paid.', 'incentives');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_incentives' AND r.name IN ('Admin', 'Managing Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'pay_incentives' AND r.name IN ('Admin', 'Managing Director', 'Accounts Executive');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_own_incentives'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Export Executive', 'Accounts Executive', 'Logistics Executive');
