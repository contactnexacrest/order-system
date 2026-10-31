-- Owner decision D-214 (2026-10-31), phase 3: Procurement works on its own.
-- Sales raises a purchase requirement from the order's products (what, how
-- much, by when — no selling price); Procurement collects supplier quotes,
-- compares them, awards one (an award above the designation's approval
-- limit waits for a higher approver), follows production, records receipt
-- and the quality check. A supplier's record (quotes won, on time, QC)
-- is its performance. New tables and permissions only; safe to run twice.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_requirements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  order_product_id BIGINT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  specification VARCHAR(1000) NULL,
  quantity DECIMAL(14,3) NOT NULL,
  unit VARCHAR(20) NULL,
  needed_by DATE NOT NULL,
  status ENUM('open','quoted','awarded','approved','received','cancelled') NOT NULL DEFAULT 'open',
  awarded_quote_id BIGINT UNSIGNED NULL,
  awarded_by BIGINT UNSIGNED NULL,
  awarded_at DATETIME NULL,
  approval_needed TINYINT(1) NOT NULL DEFAULT 0,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  received_quantity DECIMAL(14,3) NULL,
  received_on DATE NULL,
  qc_result ENUM('passed','failed','passed_with_remarks') NULL,
  qc_note VARCHAR(500) NULL,
  received_by BIGINT UNSIGNED NULL,
  raised_by BIGINT UNSIGNED NOT NULL,
  raised_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(500) NULL,
  KEY idx_pr_order (order_id),
  KEY idx_pr_status (status),
  CONSTRAINT fk_pr_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_quotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  requirement_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  unit_price_inr DECIMAL(14,2) NOT NULL,
  gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  lead_time_days INT NOT NULL,
  valid_until DATE NULL,
  quote_reference VARCHAR(100) NULL,
  note VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pq_requirement (requirement_id),
  KEY idx_pq_supplier (supplier_id),
  CONSTRAINT fk_pq_requirement FOREIGN KEY (requirement_id) REFERENCES purchase_requirements(id),
  CONSTRAINT fk_pq_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('raise_purchase_requirements', 'Raise purchase requirements', 'Ask Procurement for what an order needs (product, quantity, needed by) — no prices. D-214: Sales, management.', 'procurement'),
  ('manage_purchasing', 'Work purchase requirements', 'Record supplier quotes, compare and award, follow production, record receipt and the quality check. D-214: Procurement.', 'procurement'),
  ('approve_purchases', 'Approve purchases above a limit', 'Approve an award above the awarding designation''s approval limit (up to this designation''s own limit; no limit = any amount). D-214: Procurement Manager / Director, MD/ED.', 'procurement');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'raise_purchase_requirements'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Export Executive', 'Sales/Export Manager', 'Sales Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_purchasing'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Procurement Executive', 'Procurement Manager', 'Procurement Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'approve_purchases'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Procurement Manager', 'Procurement Director');

-- Starting approval limits (Admin → Departments changes them): every award
-- by a Procurement Executive is approved by a manager; a Procurement Manager
-- approves up to ₹5,00,000; the Director has no limit. Only set where none is set yet.
UPDATE department_designations dd JOIN departments d ON d.id = dd.department_id
   SET dd.approval_limit_inr = CASE dd.title WHEN 'Procurement Executive' THEN 0 WHEN 'Procurement Manager' THEN 500000 END
 WHERE d.code = 'PROC' AND dd.title IN ('Procurement Executive', 'Procurement Manager') AND dd.approval_limit_inr IS NULL;
