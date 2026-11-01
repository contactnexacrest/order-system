-- QA Round 8 (1 Nov 2026), owner-approved plan.
-- R8-N09: Admin → Self-approval rules covers every self-approval in the app. Four more rules — order
--   cancellation, purchase award, amendment MD-approval, short-payment clearing — default Managing
--   Director and Executive Director. A rule is seeded only if it has no roles AND was never changed on the
--   Admin page (so a rule a Super Admin emptied on purpose is not filled again).
-- R8-N10: the new permission manage_approval_limits (Managing Director, Executive Director): only its
--   holders change a designation's approval limit or make it 'director'.
-- Safe to run more than once.
SET NAMES utf8mb4;

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT k.rule_key, r.id
  FROM (SELECT 'cancellation' AS rule_key UNION ALL SELECT 'purchase_award' UNION ALL SELECT 'amendment' UNION ALL SELECT 'short_payment') k
  JOIN roles r ON r.name IN ('Managing Director', 'Executive Director')
 WHERE NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = k.rule_key)
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action_type = 'SELF_APPROVAL_RULE_CHANGED' AND a.field_name = k.rule_key);

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'manage_approval_limits', 'Manage approval limits', 'Change a designation''s purchase approval limit or make it a ''director'' (no limit). Never on a designation one holds oneself. QA Round 8, R8-N10.', 'Admin'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'manage_approval_limits');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_approval_limits'
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
