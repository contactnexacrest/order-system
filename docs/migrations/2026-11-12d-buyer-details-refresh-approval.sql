-- 2026-11-12d — Buyer details on an order follow the client (D-337)
-- Owner decision D-337 (12 Nov 2026, "will go with recommendation").
--
-- An order keeps its own copy of the buyer's details (rule R25). From now on:
--   * Case 1 (automatic): when staff save a client, every running order of that
--     client with NOTHING ISSUED yet (no documents, or only drafts / documents in
--     review) takes the client's current details at once. Audited per order and
--     field (ORDER_BUYER_DETAILS_REFRESHED); the holders of the new permission
--     get an FYI notification.
--   * Case 2 (with approval): an order that already has an issued document shows
--     the differences and "Ask to update buyer details from the client" (reason
--     10–500 characters). The request waits in the new table
--     order_buyer_detail_requests (one open request per order) until a holder of
--     the new permission approve_buyer_details_change approves or refuses it.
--     Issued documents never change.
--
-- What changes:
--   * New table order_buyer_detail_requests.
--   * New permission approve_buyer_details_change, granted to Managing Director
--     and Executive Director (Admin can grant it like any other permission).
--   * Admin › Self-approval rules: new rule buyer_details_change, default
--     Managing Director only (not re-added if an Admin has changed the rule).
--   * No data change: existing orders keep the details they have.
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS and INSERT … WHERE NOT EXISTS
-- (MySQL-compatible, no IF [NOT] EXISTS on columns or indexes).
-- Run after 2026-11-12c, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-12d-buyer-details-refresh-approval.sql
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_buyer_detail_requests (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id             BIGINT UNSIGNED NOT NULL,
  requested_by         BIGINT UNSIGNED NULL,
  reason               VARCHAR(500) NOT NULL,
  changes_json         TEXT NOT NULL,
  client_snapshot_hash CHAR(64) NOT NULL,
  status               ENUM('PENDING','APPROVED','REFUSED','LAPSED') NOT NULL DEFAULT 'PENDING',
  decided_by           BIGINT UNSIGNED NULL,
  decided_at           DATETIME NULL,
  decision_note        VARCHAR(500) NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_buyer_detail_requests_status (order_id, status),
  CONSTRAINT fk_order_buyer_detail_requests_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_buyer_detail_requests_req FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_buyer_detail_requests_dec FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'approve_buyer_details_change', 'Approve buyer details change', 'Approve or refuse a request to copy the client''s current details onto an order that already has an issued document (D-337). Issued documents never change; documents generated afterwards show the new details.', 'orders'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'approve_buyer_details_change');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'approve_buyer_details_change'
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT 'buyer_details_change', r.id FROM roles r
 WHERE r.name = 'Managing Director'
   AND NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = 'buyer_details_change')
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action_type = 'SELF_APPROVAL_RULE_CHANGED' AND a.field_name = 'buyer_details_change');
