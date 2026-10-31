-- Owner decision D-214 (2026-10-31), phase 4: the system coordinates.
-- Each order gets one owner per department (picked by that department's
-- manager or director). Each department's work queue ("My tasks") is built
-- from the order's own state, so when one step finishes the next step shows
-- up in the next department's queue. Each kind of task has a standard
-- number of days (set in Admin): after it the task is overdue, and after
-- the escalation days it is escalated to management (the cockpit). The
-- optional Order Coordinator sees status and tasks across departments,
-- never money. New tables and rows only; safe to run twice.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_department_owners (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  department_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_department_owner (order_id, department_id),
  KEY idx_odo_user (user_id),
  CONSTRAINT fk_odo_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_odo_department FOREIGN KEY (department_id) REFERENCES departments(id),
  CONSTRAINT fk_odo_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS department_task_types (
  task_key VARCHAR(50) PRIMARY KEY,
  department_code VARCHAR(20) NOT NULL,
  title VARCHAR(150) NOT NULL,
  standard_days INT NOT NULL,
  escalate_after_days INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Every table this release added follows the database's own collation, like
-- the rest (the first copy of 2026-11-01a fixed the charset, so on some
-- databases those tables took another collation and could not be joined on
-- text columns). Converted only where they differ.
SET @db_collation := (SELECT default_collation_name FROM information_schema.schemata WHERE schema_name = DATABASE());
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'departments') <> @db_collation,
  CONCAT('ALTER TABLE departments CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'department_designations') <> @db_collation,
  CONCAT('ALTER TABLE department_designations CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_department_memberships') <> @db_collation,
  CONCAT('ALTER TABLE user_department_memberships CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'purchase_requirements') <> @db_collation,
  CONCAT('ALTER TABLE purchase_requirements CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'purchase_quotes') <> @db_collation,
  CONCAT('ALTER TABLE purchase_quotes CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'order_department_owners') <> @db_collation,
  CONCAT('ALTER TABLE order_department_owners CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'department_task_types') <> @db_collation,
  CONCAT('ALTER TABLE department_task_types CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @db_collation), 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO department_task_types (task_key, department_code, title, standard_days, escalate_after_days, sort_order) VALUES
  ('raise_purchase_requirement', 'SALES', 'Raise the purchase requirement (advance cleared)', 1, 2, 10),
  ('quote_and_award', 'PROC', 'Get supplier quotes and award', 3, 3, 20),
  ('approve_purchase', 'PROC', 'Approve the award (above the awarder''s limit)', 1, 2, 30),
  ('chase_supplier', 'PROC', 'Chase the supplier — goods due', 0, 3, 40),
  ('book_freight', 'LOG', 'Book freight and CHA (goods received)', 2, 3, 50),
  ('clear_buyer_payment', 'FIN', 'Clear the buyer''s payment (remittance received)', 1, 2, 60),
  ('pay_supplier_bill', 'FIN', 'Pay the supplier / logistics bill', 3, 4, 70),
  ('send_documents', 'LOG', 'Send the documents to the buyer (balance cleared)', 2, 3, 80);

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('assign_department_owner', 'Assign department owners', 'Choose who in a department owns an order for that department (a manager for their own department; MD/ED and Admin for any). D-214 phase 4.', 'orders'),
  ('view_cockpit', 'Management cockpit', 'Open, overdue and escalated work per department, orders by stage, supplier delays and overdue buyer payments — counts and status only. D-214 phase 4.', 'reports'),
  ('manage_task_types', 'Task due days', 'Set the standard and escalation days of each kind of department task. D-214 phase 4.', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'assign_department_owner'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Sales/Export Manager', 'Sales Director', 'Procurement Manager', 'Procurement Director',
                 'Logistics Manager', 'Logistics Director', 'Finance Manager', 'Finance Director', 'Order Coordinator', 'Operations Manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_cockpit'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Sales Director', 'Procurement Director', 'Logistics Director', 'Finance Director',
                 'Order Coordinator', 'Operations Manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_task_types'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

-- The Order Coordinator follows every order's status and tasks — no money.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_orders', 'view_all_orders')
  AND r.name IN ('Order Coordinator', 'Operations Manager');
