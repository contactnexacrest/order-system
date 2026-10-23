-- ================================================================
-- DB MIGRATION — 2026-10-23 (j)
-- Export process steps 14-16:
--   14. Order Feedback — a 1-5 star rating + free-text comments the
--       client can leave per order, visible to staff on the order page.
--   15/16. The existing Disputes workflow (Section I) covered a formal
--       buyer dispute, but the business also raises/logs a "Complaint /
--       Damage Report" that needs the exact same response-due-date
--       tracking, status workflow and resolution notes — not a second,
--       parallel table. Adds a dispute_type tag instead of duplicating
--       the whole feature.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
-- ================================================================

SET NAMES utf8mb4;

-- disputes.dispute_type — idempotent ADD COLUMN via information_schema
-- guard (a bare ADD COLUMN is not itself idempotent, unlike MODIFY COLUMN).
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'disputes' AND COLUMN_NAME = 'dispute_type'
);
SET @ddl = IF(@col_exists = 0,
  "ALTER TABLE disputes ADD COLUMN dispute_type ENUM('dispute','complaint_damage') NOT NULL DEFAULT 'dispute' AFTER description",
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- order_feedback — one feedback row per order (client can revise their
-- own rating/comments; resubmission is an upsert, not blocked).
CREATE TABLE IF NOT EXISTS order_feedback (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL,
  client_id     BIGINT UNSIGNED NOT NULL,
  rating        TINYINT UNSIGNED NOT NULL,
  comments      TEXT NULL,
  submitted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_feedback_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT chk_order_feedback_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;
