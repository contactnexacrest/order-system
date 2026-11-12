-- 2026-11-12f — Self-approval rule "buyer_details_change": Managing Director AND
-- Executive Director (owner, 12 Nov 2026, on D-337: "should be MD and ED and
-- privileged (role + permission) persons").
--
-- What changes:
--   * Admin › Self-approval rules › buyer_details_change gets the Executive Director
--     next to the Managing Director, like every other rule. It is only for a database
--     that already ran 2026-11-12d when that file still ticked the MD alone.
--   * Who approves a request is unchanged: holders of the permission
--     "Approve buyer details change" (MD and ED; Admin can grant it to other roles).
--   * Not added if an Admin has already changed this rule (their choice stands).
--   * No order data changes.
--
-- Safe to run twice: the row is added only when it is missing.
SET NAMES utf8mb4;

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT 'buyer_details_change', r.id FROM roles r
 WHERE r.name = 'Executive Director'
   AND EXISTS (SELECT 1 FROM (SELECT x.rule_key FROM self_approval_rules x WHERE x.rule_key = 'buyer_details_change') m)
   AND NOT EXISTS (SELECT 1 FROM (SELECT y.role_id FROM self_approval_rules y WHERE y.rule_key = 'buyer_details_change') e WHERE e.role_id = r.id)
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action_type = 'SELF_APPROVAL_RULE_CHANGED' AND a.field_name = 'buyer_details_change');
