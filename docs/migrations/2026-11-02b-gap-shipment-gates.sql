-- Gap list answers (owner, 1 Nov 2026: "G-1 to G-5 yes, G-6 keep"), part A:
-- G-1 the balance of a "before shipment" order is recorded and cleared
--     before the Bill of Lading, which waits for it;
-- G-3 the pre-shipment inspection is recorded as Passed or Failed with its
--     report, and the Bill of Lading waits for a Passed one;
-- a manager (override_shipment_gates) can release the Bill of Lading step
-- with a written reason. G-6 needs no table change.
-- MySQL-safe, safe to run twice.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_inspections (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id        BIGINT UNSIGNED NOT NULL UNIQUE,
  result          ENUM('passed','failed') NOT NULL,
  inspected_on    DATE NOT NULL,
  agency_name     VARCHAR(150) NOT NULL,
  report_file_id  BIGINT UNSIGNED NOT NULL,
  note            VARCHAR(500) NULL,
  recorded_by     BIGINT UNSIGNED NULL,
  recorded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_inspections_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_order_inspections_file FOREIGN KEY (report_file_id) REFERENCES file_store(id),
  CONSTRAINT fk_order_inspections_user FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_shipment_gate_overrides (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    BIGINT UNSIGNED NOT NULL,
  gates       VARCHAR(60) NOT NULL,          -- 'balance', 'inspection' or 'balance,inspection'
  reason      VARCHAR(500) NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_shipment_gate_overrides_order (order_id),
  CONSTRAINT fk_shipment_gate_overrides_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_shipment_gate_overrides_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- data: upload context, permission
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'inspection_report', 'pdf,jpg,jpeg,png', 10485760, 'The third-party pre-shipment inspection report (order_inspections.report_file_id) — gap list G-3.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'inspection_report');

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'override_shipment_gates', 'Override shipment gates', 'Override shipment gates — release the BL step with a written reason when the pre-shipment balance or the inspection is not cleared', 'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'override_shipment_gates');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'override_shipment_gates'
 WHERE r.name IN ('Managing Director', 'Executive Director', 'Admin')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
