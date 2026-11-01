-- Owner decision 1 Nov 2026 (D-221): Admin → Self-approval rules. Per rule, the roles whose holders may
-- approve their own work: payment terms of an order they created, a document they generated, an e-mail
-- send they requested. Defaults: Managing Director and Executive Director on all three.
-- Safe to run more than once.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS self_approval_rules (
  rule_key VARCHAR(40) NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (rule_key, role_id),
  CONSTRAINT fk_self_approval_rules_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT k.rule_key, r.id FROM (SELECT 'payment_terms' AS rule_key UNION ALL SELECT 'document_review' UNION ALL SELECT 'email_send') k
JOIN roles r ON r.name IN ('Managing Director', 'Executive Director')
WHERE NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = k.rule_key);
