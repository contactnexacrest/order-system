-- Agreements register (owner decision 2 Nov 2026, point 4 "yes"): one register for the
-- agreements signed with clients, suppliers, logistics partners, associates and staff —
-- type, signed copy, signed date, expiry date, reminders 30 and 7 days before expiry and on
-- the expiry day, and an "expiring soon" list.
--
-- The client commercial agreement (clients.agreement_*) and the associate agreement
-- (associates.agreement_*) are NOT copied into this table: they stay where they are (the client
-- footer on documents and the associate reminders read them) and the register lists them
-- alongside its own rows, read-only, with a link to the page that manages them.
--
-- MySQL-safe (no ADD COLUMN IF NOT EXISTS / CREATE INDEX IF NOT EXISTS), safe to run twice.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS agreements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_type ENUM('client','supplier','logistics_partner','associate','staff') NOT NULL,
  party_id BIGINT UNSIGNED NOT NULL,
  agreement_type VARCHAR(100) NOT NULL,
  title VARCHAR(200) NULL,
  signed_on DATE NOT NULL,
  starts_on DATE NULL,
  expires_on DATE NULL,
  file_id BIGINT UNSIGNED NULL,
  renews_agreement_id BIGINT UNSIGNED NULL,
  terminated_on DATE NULL,
  terminated_reason VARCHAR(500) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_agreements_party (party_type, party_id),
  KEY idx_agreements_expires (expires_on),
  CONSTRAINT fk_agreements_file FOREIGN KEY (file_id) REFERENCES file_store(id),
  CONSTRAINT fk_agreements_renews FOREIGN KEY (renews_agreement_id) REFERENCES agreements(id),
  CONSTRAINT fk_agreements_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- One reminder per agreement per threshold (30 / 7 / 0 days), so a re-run never repeats it.
-- agreement_key: 'agreement:<id>', 'client:<id>' or 'associate:<id>'.
CREATE TABLE IF NOT EXISTS agreement_alerts_sent (
  agreement_key VARCHAR(40) NOT NULL,
  expires_on DATE NOT NULL,
  threshold_days INT NOT NULL,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agreement_key, expires_on, threshold_days)
) ENGINE=InnoDB;

INSERT INTO permissions (permission_key, name, description, category)
SELECT k, n, d, 'Agreements' FROM (
  SELECT 'manage_agreements' AS k, 'Manage agreements' AS n, 'Add, edit, renew and terminate agreements with clients, suppliers, logistics partners, associates and staff; sees staff NDAs; gets the expiry reminders' AS d
  UNION ALL SELECT 'view_agreements', 'View agreements', 'See the agreements register and download signed copies (not staff agreements)') p
WHERE NOT EXISTS (SELECT 1 FROM permissions x WHERE x.permission_key = p.k);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE ((p.permission_key IN ('manage_agreements', 'view_agreements') AND r.name IN ('Admin', 'Managing Director', 'Executive Director'))
     OR (p.permission_key = 'view_agreements' AND r.name IN ('Sales Director', 'Procurement Director', 'Logistics Director', 'Finance Director')))
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'agreement_file', 'pdf,doc,docx,jpg,jpeg,png', 15728640, 'The signed copy of an agreement in the Agreements register (client, supplier, logistics partner, associate or staff).'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'agreement_file');

-- Agreement types: an admin-managed list (Admin → Dropdown Option Lists, list "agreement_type").
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT t.list_key, t.option_value, t.sort_order, 0, 1 FROM (
  SELECT 'agreement_type' AS list_key, 'Sales & Supply Agreement' AS option_value, 1 AS sort_order
  UNION ALL SELECT 'agreement_type', 'Commercial NDA', 2
  UNION ALL SELECT 'agreement_type', 'Staff NDA', 3
  UNION ALL SELECT 'agreement_type', 'Supply Agreement (Quarry/Factory)', 4
  UNION ALL SELECT 'agreement_type', 'Logistics Services Agreement', 5
  UNION ALL SELECT 'agreement_type', 'Associate Agreement', 6
  UNION ALL SELECT 'agreement_type', 'Other', 7) t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options d WHERE d.list_key = t.list_key AND d.option_value = t.option_value);
