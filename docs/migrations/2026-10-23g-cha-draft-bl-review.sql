-- ================================================================
-- DB MIGRATION — 2026-10-23 (g)
-- Export process sequence, steps 9h-9j: generate the BL Instruction
-- Sheet + packing list for the CHA (already built), send it to the CHA
-- and ask for a BL draft, then track the review loop — approve it, or
-- request a revision and have the CHA send a corrected draft — as a
-- distinct tracked mini-workflow from actually confirming BL issued
-- (which already exists at the Stage 7->8 gate).
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run). No new file_upload_contexts row needed —
-- 'draft_bl' is already seeded (docs/seed.sql).
-- ================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_draft_bl_reviews (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       BIGINT UNSIGNED NOT NULL,
  round_number   INT UNSIGNED NOT NULL,       -- 1, 2, 3... one row per draft the CHA sent for this order
  draft_file_id  BIGINT UNSIGNED NOT NULL,     -- the draft BL received from the CHA this round
  uploaded_by    BIGINT UNSIGNED NOT NULL,
  uploaded_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status         ENUM('pending','approved','revision_requested') NOT NULL DEFAULT 'pending',
  review_notes   TEXT NULL,                    -- what needs to change, when status = revision_requested
  reviewed_by    BIGINT UNSIGNED NULL,
  reviewed_at    TIMESTAMP NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (draft_file_id) REFERENCES file_store(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  UNIQUE KEY uq_order_draft_bl_round (order_id, round_number)
) ENGINE=InnoDB;
