-- ================================================================
-- DB MIGRATION — 2026-10-23 (l)
-- Reported by the business owner: a "Complaint / Process Issue" is NOT
-- the same thing as a Dispute, and tagging it onto the disputes table
-- (Section BV, 2026-10-23j) conflated two very different flows:
--   - Dispute: the client wants to cancel/not proceed with THIS order.
--     Only reachable from the portal when staff explicitly enable the
--     "Raise a Dispute" button for that order (dispute_button_visible_to_client).
--     This logic is UNCHANGED by this migration.
--   - Complaint / Process Issue: the client does NOT want a refund or
--     cancellation — the order is proceeding or already succeeded, and
--     this is just a two-way conversation about something staff should
--     fix next time. Always available on any order, regardless of the
--     dispute toggle, because it is never about canceling.
--
-- This migration gives Complaints their own table/thread (order_complaints
-- + order_complaint_replies, a two-way conversation like order_comments,
-- not the staff-only dispute_replies), moves any existing
-- dispute_type = 'complaint_damage' rows across, and then drops
-- dispute_type from disputes entirely — Dispute goes back to meaning
-- one thing.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
-- ================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_complaints (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL,
  client_id     BIGINT UNSIGNED NOT NULL,
  description   TEXT NOT NULL,
  status        ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  resolved_at   TIMESTAMP NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_complaint_replies (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id      BIGINT UNSIGNED NOT NULL,
  author_type       ENUM('staff','client') NOT NULL,
  author_user_id    BIGINT UNSIGNED NULL,
  author_client_id  BIGINT UNSIGNED NULL,
  body              TEXT NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (complaint_id) REFERENCES order_complaints(id),
  FOREIGN KEY (author_user_id) REFERENCES users(id),
  FOREIGN KEY (author_client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

-- New permissions, same tiering as manage_disputes/respond_to_disputes.
-- External QA report L-32 established INSERT IGNORE as the safe-to-rerun
-- idiom for this.
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_complaints', 'Manage complaints',
   'View complaints, acknowledge/resolve them, and log one on a client''s behalf. Not auto-inherited from manage_orders — only Admin/MD/ED and Super Admin get it by default, same tier as manage_disputes.',
   'orders'),
  ('respond_to_complaints', 'Respond to complaints',
   'Post a reply in a complaint''s conversation thread — kept separate from manage_complaints so sales roles can answer without also managing its status, same split as respond_to_disputes.',
   'orders');

-- Admin, Managing Director, Executive Director already hold every
-- permission via seed.sql's wildcard grant at initial seed time — that
-- CROSS JOIN only runs once, so a permission added later has to be
-- granted to those three roles explicitly here.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director')
  AND p.permission_key IN ('manage_complaints', 'respond_to_complaints');

-- Export Executive: same default as respond_to_disputes — day-to-day
-- sales can answer a complaint without also managing it.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Export Executive'
  AND p.permission_key = 'respond_to_complaints';

-- Move any existing dispute_type = 'complaint_damage' rows (and their
-- replies/documents) into the new table, then drop the column — all
-- guarded so this block is a no-op on a second run once the column is
-- already gone.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'disputes' AND COLUMN_NAME = 'dispute_type'
);

SET @sql1 = IF(@col_exists > 0,
  "INSERT INTO order_complaints (order_id, client_id, description, status, created_at)
   SELECT d.order_id, o.client_id, d.description,
          CASE WHEN d.status = 'Resolved' THEN 'resolved' ELSE 'open' END,
          d.created_at
   FROM disputes d JOIN orders o ON o.id = d.order_id
   WHERE d.dispute_type = 'complaint_damage'",
  'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @sql2 = IF(@col_exists > 0,
  "DELETE dr FROM dispute_replies dr JOIN disputes d ON d.id = dr.dispute_id WHERE d.dispute_type = 'complaint_damage'",
  'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @sql3 = IF(@col_exists > 0,
  "DELETE dd FROM dispute_documents dd JOIN disputes d ON d.id = dd.dispute_id WHERE d.dispute_type = 'complaint_damage'",
  'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SET @sql4 = IF(@col_exists > 0,
  "DELETE FROM disputes WHERE dispute_type = 'complaint_damage'",
  'SELECT 1');
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

SET @sql5 = IF(@col_exists > 0,
  "ALTER TABLE disputes DROP COLUMN dispute_type",
  'SELECT 1');
PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;
